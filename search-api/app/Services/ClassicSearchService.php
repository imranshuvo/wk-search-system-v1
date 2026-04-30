<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ClassicSearchService
{
    /**
     * Perform classic search with strict matching logic
     * Priority: Exact match → Partial match → Contains all words
     * 
     * @param string $tenant
     * @param string $query
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @param string $sort
     * @param bool $searchDescription - Whether to search in product descriptions
     * @return array
     */
    public function search(
        string $tenant,
        string $query,
        array $filters = [],
        int $limit = 20,
        int $offset = 0,
        string $sort = 'relevance',
        bool $searchDescription = false
    ): array {
        $q = trim($query);
        $words = $this->extractWords($q);
        
        // Build base query
        $baseQuery = DB::table('wk_index_products as p')
            ->where('p.tenant_id', $tenant);
        
        // Apply query matching with priority scoring
        if (!empty($q)) {
            $baseQuery = $this->applySearchLogic($baseQuery, $q, $words, $tenant, $searchDescription);
        }
        
        // Apply filters (same as advanced mode)
        $baseQuery = $this->applyFilters($baseQuery, $filters, $tenant);
        
        // Get total count
        $total = (int) (clone $baseQuery)->count(DB::raw('distinct p.id'));
        
        // Apply sorting
        $baseQuery = $this->applySorting($baseQuery, $sort, !empty($q), $q);
        
        // Get products with pagination
        // Use DISTINCT to avoid duplicates from JOIN operations (categories, tags, attributes)
        // Include p.description in SELECT because it's used in ORDER BY (for exact match prioritization)
        $products = $baseQuery
            ->distinct()
            ->select([
                'p.id', 'p.title', 'p.url', 'p.image', 'p.price', 'p.price_old',
                'p.currency', 'p.rating', 'p.in_stock as inStock', 'p.html',
                'p.created_at', 'p.popularity', 'p.brand', 'p.sku', 'p.description'
            ])
            ->offset($offset)
            ->limit($limit)
            ->get();
        
        return [
            'products' => $products,
            'total' => $total,
            'mode' => 'classic',
            'query_breakdown' => [
                'original' => $q,
                'words' => $words,
                'strategy' => 'strict_matching'
            ]
        ];
    }
    
    /**
     * Apply classic search logic - STRICT matching requires ALL words to be present
     * Matches products where ALL search words appear in title/brand/sku/description/categories
     */
    private function applySearchLogic($query, string $searchTerm, array $words, string $tenant, bool $searchDescription = false)
    {
        // Normalize the search term by removing all spaces for comparison
        $normalizedSearch = str_replace(' ', '', $searchTerm);
        
        // STRICT MODE: Require ALL words to be present
        // Each word must appear in at least one field (title, brand, sku, and optionally description, or category)
        foreach ($words as $word) {
            $query->where(function($w) use ($word, $tenant, $searchDescription) {
                $w->where('p.title', 'LIKE', '%' . $word . '%')
                  ->orWhere('p.brand', 'LIKE', '%' . $word . '%')
                  ->orWhere('p.sku', 'LIKE', '%' . $word . '%');
                
                // Only search description if enabled
                if ($searchDescription) {
                    $w->orWhere('p.description', 'LIKE', '%' . $word . '%');
                }
                
                // Always search categories
                $w->orWhereExists(function($sub) use ($word, $tenant) {
                      $sub->select(DB::raw(1))
                          ->from('wk_product_categories as pc2')
                          ->join('wk_categories as c2', 'c2.id', '=', 'pc2.category_id')
                          ->whereColumn('pc2.product_id', 'p.id')
                          ->where('pc2.tenant_id', $tenant)
                          ->where('c2.tenant_id', $tenant)
                          ->where(function($cx) use ($word) {
                              $cx->where('c2.name', 'LIKE', '%' . $word . '%')
                                 ->orWhere('c2.slug', 'LIKE', '%' . $word . '%');
                          });
                  });
                
                // Search attributes (product specifications like Size, Color, etc.)
                $w->orWhereExists(function($sub) use ($word, $tenant) {
                      $sub->select(DB::raw(1))
                          ->from('wk_product_attributes as pa')
                          ->whereColumn('pa.product_id', 'p.id')
                          ->where('pa.tenant_id', $tenant)
                          ->where('pa.attribute_value', 'LIKE', '%' . $word . '%');
                  });
            });
        }
        
        return $query;
    }
    
    /**
     * Normalize search term to handle spacing variations
     * Examples: "12kg" matches "12 kg", "12kg", "12  kg"
     */
    private function normalizeSearchTerm(string $term): string
    {
        // Just return the term as-is, normalization happens in SQL with REPLACE()
        return $term;
    }
    
    /**
     * Apply standard filters (same as advanced mode)
     */
    private function applyFilters($query, array $filters, string $tenant)
    {
        // Brand filters
        if (!empty($filters['brand'])) {
            $query->whereIn('p.brand', (array) $filters['brand']);
        }
        
        // Price range
        if (isset($filters['price_min']) && $filters['price_min'] !== null) {
            $query->where('p.price', '>=', (float) $filters['price_min']);
        }
        if (isset($filters['price_max']) && $filters['price_max'] !== null) {
            $query->where('p.price', '<=', (float) $filters['price_max']);
        }
        
        // Stock status
        if (isset($filters['in_stock']) && $filters['in_stock'] !== null && $filters['in_stock'] !== '') {
            $query->where('p.in_stock', (int) $filters['in_stock']);
        }
        
        // On sale
        if (isset($filters['on_sale']) && $filters['on_sale'] !== null && $filters['on_sale'] !== '') {
            $query->whereNotNull('p.price_old')
                  ->whereColumn('p.price_old', '>', 'p.price');
        }
        
        // Rating
        if (isset($filters['rating_min']) && $filters['rating_min'] !== null && $filters['rating_min'] !== '') {
            $query->where('p.rating', '>=', (float) $filters['rating_min']);
        }
        
        // Category filters
        if (!empty($filters['category'])) {
            $query->join('wk_product_categories as pc', 'pc.product_id', '=', 'p.id')
                  ->where('pc.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                  ->join('wk_categories as c', 'c.id', '=', 'pc.category_id')
                  ->where('c.tenant_id', $tenant)
                  ->where(function($w) use ($filters) {
                      $categories = (array) $filters['category'];
                      $ids = array_filter($categories, fn($v) => ctype_digit((string)$v));
                      $slugs = array_diff($categories, $ids);
                      if (!empty($ids)) {
                          $w->orWhereIn('c.id', $ids);
                      }
                      if (!empty($slugs)) {
                          $w->orWhereIn('c.slug', $slugs);
                      }
                  });
        }
        
        // Tag filters
        if (!empty($filters['tag'])) {
            $query->join('wk_product_tags as pt', 'pt.product_id', '=', 'p.id')
                  ->where('pt.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                  ->join('wk_tags as t', 't.id', '=', 'pt.tag_id')
                  ->where('t.tenant_id', $tenant)
                  ->where(function($w) use ($filters) {
                      $tags = (array) $filters['tag'];
                      $ids = array_filter($tags, fn($v) => ctype_digit((string)$v));
                      $slugs = array_diff($tags, $ids);
                      if (!empty($ids)) {
                          $w->orWhereIn('t.id', $ids);
                      }
                      if (!empty($slugs)) {
                          $w->orWhereIn('t.slug', $slugs);
                      }
                  });
        }
        
        // Attribute filters
        if (!empty($filters['attributes'])) {
            foreach ($filters['attributes'] as $name => $values) {
                if (!is_array($values) || empty($values)) continue;
                $query->whereExists(function($sub) use ($name, $values, $tenant) {
                    $sub->select(DB::raw(1))
                        ->from('wk_product_attributes as pa')
                        ->whereColumn('pa.product_id', 'p.id')
                        ->where('pa.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                        ->where('pa.attribute_name', $name)
                        ->whereIn('pa.attribute_value', $values);
                });
            }
        }
        
        return $query;
    }
    
    /**
     * Apply sorting logic
     * Classic mode: In-stock FIRST, then exact matches, then by selected sort
     * Priority: In-stock > Exact title > Title starts with > SKU match > Title contains > Out-of-stock at end
     */
    private function applySorting($query, string $sort, bool $hasSearchTerm, string $searchTerm = '')
    {
        // ALWAYS prioritize in-stock products FIRST (out-of-stock at end)
        $query->orderBy('p.in_stock', 'DESC');
        
        // If user is searching, prioritize exact matches within stock groups
        if ($hasSearchTerm && !empty($searchTerm)) {
            $normalizedSearch = str_replace([' ', '-'], '', $searchTerm);
            
            // Priority ordering: exact matches FIRST (within same stock status)
            $query->orderByRaw("
                CASE 
                    WHEN LOWER(p.title) = LOWER(?) THEN 1
                    WHEN LOWER(p.sku) = LOWER(?) THEN 2
                    WHEN LOWER(p.title) LIKE LOWER(CONCAT(?, '%')) THEN 3
                    WHEN REPLACE(REPLACE(LOWER(p.title), ' ', ''), '-', '') = LOWER(?) THEN 4
                    WHEN LOWER(p.title) LIKE LOWER(CONCAT('%', ?, '%')) THEN 5
                    WHEN LOWER(p.sku) LIKE LOWER(CONCAT('%', ?, '%')) THEN 6
                    WHEN LOWER(p.brand) LIKE LOWER(CONCAT('%', ?, '%')) THEN 7
                    WHEN LOWER(p.description) LIKE LOWER(CONCAT('%', ?, '%')) THEN 8
                    ELSE 9
                END ASC
            ", [
                $searchTerm,      // exact title
                $searchTerm,      // exact SKU
                $searchTerm,      // title starts with
                $normalizedSearch, // normalized match
                $searchTerm,      // title contains
                $searchTerm,      // SKU contains
                $searchTerm,      // brand contains
                $searchTerm       // description contains
            ]);
        }
        
        // Then apply user-selected sort (within each priority group)
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('p.price', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('p.price', 'desc');
                break;
            case 'rating_desc':
                $query->orderBy('p.rating', 'desc');
                break;
            case 'newest':
                $query->orderBy('p.created_at', 'desc');
                break;
            case 'popularity_desc':
                $query->orderBy('p.popularity', 'desc');
                break;
            default: // 'relevance' - in classic mode, use popularity after priority
                $query->orderBy('p.popularity', 'desc')
                      ->orderBy('p.id', 'asc');
                break;
        }
        
        return $query;
    }
    
    /**
     * Extract meaningful words from search query
     */
    private function extractWords(string $query): array
    {
        // Remove special characters, split by spaces
        $cleaned = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query);
        $words = array_filter(
            array_map('trim', explode(' ', $cleaned)),
            function($word) {
                return strlen($word) >= 2; // Minimum 2 characters
            }
        );
        
        return array_values(array_unique($words));
    }
    
    /**
     * Get all matching product IDs for facet computation (without pagination)
     * This is more efficient than fetching full product data
     */
    public function getMatchingIds(
        string $tenant,
        string $query,
        array $filters = [],
        bool $searchDescription = false
    ): array {
        $q = trim($query);
        $words = $this->extractWords($q);
        
        // Build base query
        $baseQuery = DB::table('wk_index_products as p')
            ->where('p.tenant_id', $tenant);
        
        // Apply query matching
        if (!empty($q)) {
            $baseQuery = $this->applySearchLogic($baseQuery, $q, $words, $tenant, $searchDescription);
        }
        
        // Apply filters
        $baseQuery = $this->applyFilters($baseQuery, $filters, $tenant);
        
        // Get just the IDs (much faster than fetching all product data)
        return $baseQuery->distinct()->pluck('p.id')->toArray();
    }
}

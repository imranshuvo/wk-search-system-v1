<?php

namespace App\Http\Controllers\Serve;

use App\Http\Controllers\Controller;
use App\Services\ClassicSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $userId = $request->header('X-User');
        $q = trim((string)($request->input('query') ?? ''));
        $page = max(1, (int)($request->input('page') ?? 1));
        $limit = min(100, max(10, (int)($request->input('limit') ?? 40)));
        $offset = ($page - 1) * $limit;
        
        // NEW: Check search mode (classic or advanced)
        $mode = strtolower((string)($request->input('mode') ?? 'advanced'));
        $mode = in_array($mode, ['classic', 'advanced']) ? $mode : 'advanced';

        $sort = (string)($request->input('sort') ?? 'popularity_desc');
        $brandFilters = (array)($request->input('brand') ?? []);
        $categoryFilters = (array)($request->input('category') ?? []); // ids or slugs
        
        // OPTIMIZATION: Expand category filters to include child categories
        // Since we now store only leaf categories, we need to find all descendants
        // when filtering by a parent category
        if (!empty($categoryFilters)) {
            $categoryFilters = $this->expandCategoryFilters($tenant, $categoryFilters);
        }
        
        $tagFilters = (array)($request->input('tag') ?? []); // ids or slugs
        $attributeFilters = (array)($request->input('attributes') ?? []); // { color: ["red","blue"], size:["M"] }
        $attributesMeta = (array)($request->input('attributes_meta') ?? []); // e.g. ['color','size'] selected in settings
        $priceMin = $request->input('price_min');
        $priceMax = $request->input('price_max');
        $inStock = $request->input('in_stock'); // 0/1 or null
        $ratingMin = $request->input('rating_min');
        
        // Get hide_out_of_stock from request parameter (WordPress sends this with every search)
        $hideOutOfStock = (bool)$request->input('hide_out_of_stock', false);
        
        // Apply the setting: if hideOutOfStock is true and user hasn't explicitly filtered, force in_stock=1
        if ($hideOutOfStock && $inStock === null) {
            $inStock = 1;
            \Log::info('Applied hide_out_of_stock: forcing in_stock=1');
        }
        // Optional: restrict search/facets to a fixed seed set of product IDs
        $restrictIds = (array)($request->input('restrict_ids') ?? []);
        // Normalize to integers and drop invalids
        $restrictIds = array_values(array_filter(array_map(function($v){ return (int)$v; }, $restrictIds), function($v){ return $v > 0; }));

        // Response cache (skip when bypass header present)
        $bypass = (string)$request->header('X-Bypass-Cache', '') !== '';
        $cacheTtl = (int) env('SEARCH_CACHE_TTL', 30);
        $cacheKey = 'search:' . $tenant . ':' . md5(json_encode([
            'q'=>$q,'page'=>$page,'limit'=>$limit,'sort'=>$sort,
            'brand'=>$brandFilters,'category'=>$categoryFilters,
            'price_min'=>$priceMin,'price_max'=>$priceMax,
            'in_stock'=>$inStock,'rating_min'=>$ratingMin,
            // include restrict_ids to avoid serving unscoped cached responses
            'restrict_ids'=> !empty($restrictIds) ? array_values($restrictIds) : []
        ]));

        if (!$bypass && $cacheTtl > 0) {
            if ($cached = cache()->get($cacheKey)) {
                $etag = 'W/"'.md5(json_encode($cached)).'"';
                $ifNone = $request->header('If-None-Match');
                if ($ifNone && trim($ifNone) === $etag) {
                    return response('', 304, [
                        'ETag' => $etag,
                        'Cache-Control' => 'public, max-age='.$cacheTtl
                    ]);
                }
                return response()->json($cached, 200, [
                    'ETag' => $etag,
                    'Cache-Control' => 'public, max-age='.$cacheTtl
                ]);
            }
        }

        // Business-critical: Redirects (zero-friction landings)
        if ($q !== '') {
            $redir = DB::table('wk_redirects')
                ->where('tenant_id',$tenant)
                ->where('query',$q)
                ->where('active',1)
                ->first();
            if ($redir) {
                $payload = [ 'redirect' => ['url' => $redir->url] ];
                $cacheTtl = (int) env('SEARCH_CACHE_TTL', 30);
                $etag = 'W/"'.md5(json_encode($payload)).'"';
                return response()->json($payload, 200, [ 'ETag'=>$etag, 'Cache-Control'=>'public, max-age='.$cacheTtl ]);
            }
        }
        
        // NEW: Route to Classic Search if mode is 'classic'
        if ($mode === 'classic') {
            return $this->executeClassicSearch($request, $tenant, $userId, $q, $page, $limit, $offset);
        }

        $base = DB::table('wk_index_products as p')->where('p.tenant_id', $tenant);
        if (!empty($restrictIds)) {
            $base->whereIn('p.id', $restrictIds);
        }
        $searchTerm = null;
        if ($q !== '') {
            // Strict initial match: do not expand synonyms on first pass
            $searchTerm = $q.'*';
            $base->where(function($w) use ($searchTerm, $q, $tenant) {
                $w->whereRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE)', [$searchTerm])
                  ->orWhere('p.title','like','%'.$q.'%')
                  ->orWhere('p.sku','like','%'.$q.'%')
                  ->orWhere('p.brand','like','%'.$q.'%')
                  ->orWhere('p.description','like','%'.$q.'%')
                  ->orWhereExists(function($sub) use ($q, $tenant) {
                      $sub->select(DB::raw(1))
                          ->from('wk_product_categories as pc2')
                          ->join('wk_categories as c2','c2.id','=','pc2.category_id')
                          ->whereColumn('pc2.product_id','p.id')
                          ->where('pc2.tenant_id',$tenant)  // CRITICAL: Multi-tenant isolation
                          ->where('c2.tenant_id',$tenant)
                          ->where(function($wx) use ($q){
                              $wx->where('c2.name','like','%'.$q.'%')
                                 ->orWhere('c2.slug','like','%'.$q.'%');
                          });
                  });
            });
        }
        if (!empty($brandFilters)) {
            $base->whereIn('p.brand', $brandFilters);
        }
        if ($priceMin !== null) {
            $base->where('p.price', '>=', (float)$priceMin);
        }
        if ($priceMax !== null) {
            $base->where('p.price', '<=', (float)$priceMax);
        }
        if ($inStock !== null && $inStock !== '') {
            $base->where('p.in_stock', (int)$inStock);
        }
        // On sale: price_old > price
        $onSale = $request->input('on_sale');
        if ($onSale !== null && $onSale !== '') {
            $base->whereNotNull('p.price_old')->whereColumn('p.price_old','>','p.price');
        }
        if ($ratingMin !== null && $ratingMin !== '') {
            $base->where('p.rating', '>=', (float)$ratingMin);
        }
        if (!empty($categoryFilters)) {
            $base->join('wk_product_categories as pc', 'pc.product_id', '=', 'p.id')
                 ->where('pc.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                 ->join('wk_categories as c', function($j){
                     $j->on('c.id', '=', 'pc.category_id');
                 })
                 ->whereExists(function($sub) use ($tenant){
                     $sub->select(DB::raw(1))
                         ->from('wk_categories as cx')
                         ->whereColumn('cx.id', 'c.id')
                         ->where('cx.tenant_id', $tenant);
                 })
                 ->where(function($w) use ($categoryFilters){
                     $ids = array_filter($categoryFilters, fn($v)=>ctype_digit((string)$v));
                     $slugs = array_diff($categoryFilters, $ids);
                     if (!empty($ids)) $w->orWhereIn('c.id', $ids);
                     if (!empty($slugs)) $w->orWhereIn('c.slug', $slugs);
                 });
        }

        if (!empty($tagFilters)) {
            $base->join('wk_product_tags as pt', 'pt.product_id', '=', 'p.id')
                 ->where('pt.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                 ->join('wk_tags as t', function($j){
                     $j->on('t.id', '=', 'pt.tag_id');
                 })
                 ->whereExists(function($sub) use ($tenant){
                     $sub->select(DB::raw(1))
                         ->from('wk_tags as tx')
                         ->whereColumn('tx.id', 't.id')
                         ->where('tx.tenant_id', $tenant);
                 })
                 ->where(function($w) use ($tagFilters){
                     $ids = array_filter($tagFilters, fn($v)=>ctype_digit((string)$v));
                     $slugs = array_diff($tagFilters, $ids);
                     if (!empty($ids)) $w->orWhereIn('t.id', $ids);
                     if (!empty($slugs)) $w->orWhereIn('t.slug', $slugs);
                 });
        }

        if (!empty($attributeFilters)) {
            foreach ($attributeFilters as $name => $values) {
                if (!is_array($values) || empty($values)) continue;
                $base->whereExists(function($sub) use ($name, $values, $tenant) {
                    $sub->select(DB::raw(1))
                        ->from('wk_product_attributes as pa')
                        ->whereColumn('pa.product_id', 'p.id')
                        ->where('pa.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                        ->where('pa.attribute_name', $name)
                        ->whereIn('pa.attribute_value', $values);
                });
            }
        }

        // Total count
        $countQuery = clone $base;
        $total = (int) $countQuery->count(DB::raw('distinct p.id'));

        // If strict pass yields zero, retry once with synonym expansion to improve recall
        if ($total === 0 && $q !== '') {
            $base = DB::table('wk_index_products as p')->where('p.tenant_id', $tenant);
            if (!empty($restrictIds)) { $base->whereIn('p.id', $restrictIds); }
            $expanded = $this->expandWithSynonyms($tenant, $q);
            $searchTerm = $expanded.'*';
            $base->where(function($w) use ($searchTerm, $tenant, $expanded){
                $w->whereRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE)', [$searchTerm])
                  ->orWhere('p.title','like','%'.$expanded.'%')
                  ->orWhere('p.sku','like','%'.$expanded.'%')
                  ->orWhere('p.brand','like','%'.$expanded.'%')
                  ->orWhere('p.description','like','%'.$expanded.'%')
                  ->orWhereExists(function($sub) use ($expanded, $tenant) {
                      $sub->select(DB::raw(1))
                          ->from('wk_product_categories as pc2')
                          ->join('wk_categories as c2','c2.id','=','pc2.category_id')
                          ->whereColumn('pc2.product_id','p.id')
                          ->where('c2.tenant_id',$tenant)
                          ->where(function($wx) use ($expanded){
                              $wx->where('c2.name','like','%'.$expanded.'%')
                                 ->orWhere('c2.slug','like','%'.$expanded.'%');
                          });
                  });
            });
            // copy over filters below (brand, price, stock, rating) and category/tag/attribute filters
            if (!empty($brandFilters)) { $base->whereIn('p.brand', $brandFilters); }
            if ($priceMin !== null) { $base->where('p.price','>=',(float)$priceMin); }
            if ($priceMax !== null) { $base->where('p.price','<=',(float)$priceMax); }
            if ($inStock !== null && $inStock !== '') { $base->where('p.in_stock',(int)$inStock); }
            if ($onSale !== null && $onSale !== '') { $base->whereNotNull('p.price_old')->whereColumn('p.price_old','>','p.price'); }
            if ($ratingMin !== null && $ratingMin !== '') { $base->where('p.rating','>=',(float)$ratingMin); }
            if (!empty($categoryFilters)) {
                $base->join('wk_product_categories as pc', 'pc.product_id', '=', 'p.id')
                     ->where('pc.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                     ->join('wk_categories as c', function($j){ $j->on('c.id','=','pc.category_id'); })
                     ->whereExists(function($sub) use ($tenant){ $sub->select(DB::raw(1))->from('wk_categories as cx')->whereColumn('cx.id','c.id')->where('cx.tenant_id',$tenant); })
                     ->where(function($w) use ($categoryFilters){
                         $ids = array_filter($categoryFilters, fn($v)=>ctype_digit((string)$v));
                         $slugs = array_diff($categoryFilters, $ids);
                         if (!empty($ids)) $w->orWhereIn('c.id', $ids);
                         if (!empty($slugs)) $w->orWhereIn('c.slug', $slugs);
                     });
            }
            if (!empty($tagFilters)) {
                $base->join('wk_product_tags as pt','pt.product_id','=','p.id')
                     ->where('pt.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                     ->join('wk_tags as t', function($j){ $j->on('t.id','=','pt.tag_id'); })
                     ->whereExists(function($sub) use ($tenant){ $sub->select(DB::raw(1))->from('wk_tags as tx')->whereColumn('tx.id','t.id')->where('tx.tenant_id',$tenant); })
                     ->where(function($w) use ($tagFilters){
                         $ids = array_filter($tagFilters, fn($v)=>ctype_digit((string)$v));
                         $slugs = array_diff($tagFilters, $ids);
                         if (!empty($ids)) $w->orWhereIn('t.id', $ids);
                         if (!empty($slugs)) $w->orWhereIn('t.slug', $slugs);
                     });
            }
            if (!empty($attributeFilters)) {
                foreach ($attributeFilters as $name => $values) {
                    if (!is_array($values) || empty($values)) continue;
                    $base->whereExists(function($sub) use ($name, $values, $tenant) {
                        $sub->select(DB::raw(1))
                            ->from('wk_product_attributes as pa')
                            ->whereColumn('pa.product_id', 'p.id')
                            ->where('pa.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                            ->where('pa.attribute_name', $name)
                            ->whereIn('pa.attribute_value', $values);
                    });
                }
            }
            // recount
            $total = (int) (clone $base)->count(DB::raw('distinct p.id'));
        }

        // Sorting
        // Personalization boosts (brand/category/price-affinity)
        $persona = null;
        if (!empty($userId)) {
            $persona = DB::table('wk_persona')->where('tenant_id',$tenant)->where('user_id',$userId)->first();
        }

        // ALWAYS: In-stock products FIRST, out-of-stock at END (applies to ALL sort options)
        $base->addSelect('p.in_stock')->orderBy('p.in_stock', 'DESC');

        switch ($sort) {
            case 'price_asc': $base->orderBy('p.price', 'asc'); break;
            case 'price_desc': $base->orderBy('p.price', 'desc'); break;
            case 'rating_desc': $base->orderBy('p.rating', 'desc'); break;
            case 'newest': $base->orderBy('p.created_at', 'desc'); break;
            default:
                // Add columns needed for ordering with DISTINCT (MySQL strict mode requirement)
                $base->addSelect('p.sku', 'p.brand', 'p.title');
                
                // PRIORITY 1: Exact matches FIRST (before relevance scoring, within stock groups)
                if (!empty($q)) {
                    $base->orderByRaw("
                        CASE 
                            WHEN LOWER(p.title) = LOWER(?) THEN 1
                            WHEN LOWER(p.sku) = LOWER(?) THEN 2
                            WHEN LOWER(p.title) LIKE LOWER(CONCAT(?, '%')) THEN 3
                            WHEN LOWER(p.title) LIKE LOWER(CONCAT('%', ?, '%')) THEN 4
                            WHEN LOWER(p.description) LIKE LOWER(CONCAT('%', ?, '%')) THEN 5
                            ELSE 6
                        END ASC
                    ", [$q, $q, $q, $q, $q]);
                }
                
                // PRIORITY 2: Then relevance and popularity
                if ($searchTerm !== null) {
                    $base->selectRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE) as relevance', [$searchTerm])
                         ->addSelect('p.popularity')
                         ->orderByDesc('relevance')
                         ->orderByDesc('p.popularity');
                } else {
                    $base->addSelect('p.popularity')->orderBy('p.popularity', 'desc');
                }
                break;
        }

        // Optional weight overrides for preview
        $weights = $request->input('weights');
        $wRelevance = isset($weights['relevance']) ? (float)$weights['relevance'] : 1.0;
        $wBrand = isset($weights['brand']) ? (float)$weights['brand'] : 0.3;
        $wCategory = isset($weights['category']) ? (float)$weights['category'] : 0.2;
        $wPrice = isset($weights['price']) ? (float)$weights['price'] : 0.1;

        if ($persona) {
            $data = json_decode($persona->bias_data ?? '[]', true) ?: [];
            $brandPref = array_keys(($data['brands'] ?? []));
            $catPref = array_keys(($data['categories'] ?? []));
            $targetPrice = isset($data['avg_price']) ? (float)$data['avg_price'] : null;
            if (!empty($brandPref)) {
                $inBindings = implode(',', array_fill(0, count($brandPref), '?'));
                $base->orderByRaw("(p.brand IN ($inBindings)) * ? DESC", array_merge($brandPref, [$wBrand]));
            }
            if (!empty($catPref)) {
                $inCat = implode(',', array_fill(0, count($catPref), '?'));
                $base->orderByRaw(
                    "EXISTS (SELECT 1 FROM wk_product_categories pc WHERE pc.tenant_id = ? AND pc.product_id = p.id AND pc.category_id IN ($inCat)) * ? DESC",
                    array_merge([$tenant], $catPref, [$wCategory])
                );
            }
            if ($targetPrice !== null) {
                $base->orderByRaw('(1.0 / NULLIF(ABS(p.price - ?),0)) * ? DESC', [$targetPrice, $wPrice]);
            }
        }

        $tStart = microtime(true);
        // Compute price range on the narrowed set (before pagination)
        $rangeMin = (float) (clone $base)->min('p.price');
        $rangeMax = (float) (clone $base)->max('p.price');
        // Add result columns without overriding previously selected fields
        // Include all columns that might be used in ORDER BY to satisfy MySQL DISTINCT requirement
        $base->addSelect('p.id','p.title','p.sku','p.brand','p.url','p.image','p.price','p.currency','p.rating', DB::raw('p.in_stock as inStock'),'p.html','p.created_at','p.popularity','p.description');
        $products = $base->distinct('p.id')
                         ->offset($offset)
                         ->limit($limit)
                         ->get();

        // Apply bans (remove) and pins (promote with position) for this query
        if ($q !== '') {
            $banIds = DB::table('wk_bans')->where('tenant_id',$tenant)->where('query',$q)->pluck('product_id')->all();
            if (!empty($banIds)) {
                $products = $products->reject(function($p) use ($banIds){ return in_array($p->id, $banIds); })->values();
            }
            $pins = DB::table('wk_pins')->where('tenant_id',$tenant)->where('query',$q)->orderBy('position')->get();
            if ($pins->count() > 0) {
                $byId = [];
                foreach ($products as $p) { $byId[$p->id] = $p; }
                $rest = $products->reject(function($p) use ($pins){ return in_array($p->id, $pins->pluck('product_id')->all()); })->values();
                $ordered = [];
                foreach ($pins as $pin) {
                    if (isset($byId[$pin->product_id])) $ordered[] = $byId[$pin->product_id];
                }
                $products = collect(array_values(array_merge($ordered, $rest->all())));
            }
        }

        // Facets with fast-path when not narrowed
        $isNarrowed = ($q !== '') || !empty($brandFilters) || !empty($categoryFilters) || !empty($tagFilters)
            || !empty($attributeFilters) || $priceMin !== null || $priceMax !== null
            || ($inStock !== null && $inStock !== '') || ($ratingMin !== null && $ratingMin !== '')
            || !empty($restrictIds);

        if (!$isNarrowed) {
            // When restrictIds present, compute precise facets instead of fast precomputed counts
            if (!empty($restrictIds)) {
                $facets = [
                    'brand' => $this->facetBrand($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $inStock, $ratingMin, $restrictIds, $onSale),
                    'category' => $this->facetCategory($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $inStock, $ratingMin, $restrictIds, $onSale),
                    'tag' => $this->facetTag($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $inStock, $ratingMin, $restrictIds, $onSale),
                    'availability' => $this->facetAvailability($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $ratingMin, $restrictIds, $onSale),
                    'rating' => $this->facetRatingBuckets($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $inStock, $restrictIds, $onSale),
                    'price' => $this->facetPriceBuckets($tenant, $q, $brandFilters, $categoryFilters, $inStock, $ratingMin, $restrictIds, $onSale),
                ];
            } else {
                $facets = [
                    'brand' => $this->facetBrandFast($tenant),
                    'category' => $this->facetCategoryFast($tenant),
                    'tag' => $this->facetTagFast($tenant),
                    'availability' => $this->facetAvailability($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $ratingMin),
                    'rating' => $this->facetRatingBuckets($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $inStock),
                    'price' => $this->facetPriceBuckets($tenant, $q, $brandFilters, $categoryFilters, $inStock, $ratingMin),
                ];
            }
        } else {
            $facets = [
                'brand' => $this->facetBrand($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $inStock, $ratingMin, $restrictIds, $onSale),
                'category' => $this->facetCategory($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $inStock, $ratingMin, $restrictIds, $onSale),
                'tag' => $this->facetTag($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $inStock, $ratingMin, $restrictIds, $onSale),
                'availability' => $this->facetAvailability($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $ratingMin, $restrictIds, $onSale),
                'rating' => $this->facetRatingBuckets($tenant, $q, $brandFilters, $categoryFilters, $priceMin, $priceMax, $inStock, $restrictIds, $onSale),
                'price' => $this->facetPriceBuckets($tenant, $q, $brandFilters, $categoryFilters, $inStock, $ratingMin, $restrictIds, $onSale),
            ];
        }

        // Zero-result resilience: provide popular products fallback
        $fallbackUsed = false;
        \Log::info('Search results check', [
            'query' => $q,
            'total' => $total,
            'tenant' => $tenant
        ]);
        
        if ($total === 0) {
            $fallbackUsed = true;
            \Log::info('Using fallback products for query', ['query' => $q]);
            
            // Build query for fallback (popular products)
            $fallbackQuery = DB::table('wk_index_products as p')
                ->where('p.tenant_id', $tenant)
                ->orderByDesc('p.popularity');
            
            // Get total count of fallback products
            $total = (int) (clone $fallbackQuery)->count();
            
            // Calculate price range from ALL fallback products (not just paginated)
            $rangeMin = (float) (clone $fallbackQuery)->min('p.price');
            $rangeMax = (float) (clone $fallbackQuery)->max('p.price');
            
            // Get paginated fallback products
            $products = $fallbackQuery
                ->offset($offset)
                ->limit($limit)
                ->get(['p.id','p.title','p.url','p.image','p.price','p.currency','p.rating','p.in_stock as inStock','p.html']);
            
            // Compute facets broadly without narrowing
            $facets = [
                'brand' => $this->facetBrand($tenant, '', [], [], null, null, null, null, $restrictIds),
                'category' => $this->facetCategory($tenant, '', [], [], null, null, null, null, $restrictIds),
                'tag' => $this->facetTag($tenant, '', [], [], null, null, null, null, $restrictIds),
                'availability' => $this->facetAvailability($tenant, '', [], [], null, null, null, $restrictIds),
                'rating' => $this->facetRatingBuckets($tenant, '', [], [], null, null, null, $restrictIds),
                'price' => $this->facetPriceBuckets($tenant, '', [], [], null, null, $restrictIds),
            ];
        }

        // Always compute facets from the actual returned product set for accurate filtering
        $returnedProductIds = $products->pluck('id')->toArray();
        if (!empty($returnedProductIds)) {
            $facets = [
                'brand' => $this->facetBrandFromProducts($tenant, $returnedProductIds),
                'category' => $this->facetCategoryFromProducts($tenant, $returnedProductIds),
                'tag' => $this->facetTagFromProducts($tenant, $returnedProductIds),
                'availability' => $this->facetAvailabilityFromProducts($tenant, $returnedProductIds),
                'rating' => $this->facetRatingFromProducts($tenant, $returnedProductIds),
                'price' => $this->facetPriceFromProducts($tenant, $returnedProductIds),
            ];
        }

        $elapsed = (int) round((microtime(true) - $tStart) * 1000);
        $currencyForRange = ($products->count() > 0) ? ($products[0]->currency ?? 'USD') : 'USD';
        
        \Log::info('Search response details', [
            'query' => $q,
            'total' => $total,
            'products_count' => $products->count(),
            'fallback_used' => $fallbackUsed,
            'first_product' => $products->count() > 0 ? $products[0]->title : null
        ]);
        
        // Build attribute facets for configured attributes on the narrowed set
        $attributeFacets = $this->buildAttributeFacets(
            $tenant,
            $attributesMeta,
            $brandFilters,
            $categoryFilters,
            $tagFilters,
            $priceMin,
            $priceMax,
            $inStock,
            $ratingMin,
            $restrictIds,
            $onSale,
            $searchTerm
        );
        $payload = [
            'products' => [
                'results' => $products,
                'total' => $total,
                'filters' => [
                    'brand' => $facets['brand'],
                    'category' => $facets['category'],
                    'tag' => $facets['tag'],
                    'attributes' => $attributeFacets,
                ],
                'page' => $page,
                'limit' => $limit,
            ],
            'metrics' => [ 'elapsed_ms' => $elapsed ],
            'facets_meta' => [
                'availability' => $facets['availability'],
                'rating' => $facets['rating'],
                'price' => $facets['price'],
                'price_range' => [
                    'min' => $rangeMin,
                    'max' => $rangeMax,
                    'currency' => $currencyForRange,
                ],
            ],
            'fallback_used' => $fallbackUsed
        ];

        // analytics: zero-results and perf
        try {
            if ($total === 0 && $q !== '') {
                DB::statement('INSERT INTO wk_search_analytics (tenant_id, `query`, `count`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `count` = `count` + 1, last_searched = CURRENT_TIMESTAMP', [$tenant, $q]);
            }
            DB::table('wk_analytics_hourly')->updateOrInsert(
                ['tenant_id'=>$tenant,'date'=>date('Y-m-d'),'hour'=>intval(date('G')),'event_type'=>'search_perf'],
                ['count'=>DB::raw('count+'.$elapsed), 'updated_at'=>now()]
            );
        } catch (\Throwable $e) {}

        if ($cacheTtl > 0) {
            cache()->put($cacheKey, $payload, $cacheTtl);
        }
        $etag = 'W/"'.md5(json_encode($payload)).'"';
        return response()->json($payload, 200, [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age='.$cacheTtl
        ]);
    }

    public function suggestions(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $query = $request->input('query', '');
        $limit = $request->input('limit', 5);
        
        if (empty($query)) {
            return response()->json(['suggestions' => []]);
        }
        
        $suggestions = [];
        $queryWords = array_filter(explode(' ', trim($query)));
        
        // If query has multiple words, try partial matches
        if (count($queryWords) > 1) {
            // Try each word individually to find partial matches
            foreach ($queryWords as $word) {
                if (strlen($word) < 3) continue; // Skip very short words
                
                $partialMatches = $this->getPartialMatches($tenant, $word, 2);
                $suggestions = array_merge($suggestions, $partialMatches);
            }
            
            // Try combinations of words (e.g., "nike shoes" -> try "nike" + "shoes" separately)
            $combinations = $this->generateWordCombinations($queryWords);
            foreach ($combinations as $combination) {
                $comboMatches = $this->getPartialMatches($tenant, $combination, 1);
                $suggestions = array_merge($suggestions, $comboMatches);
            }
        } else {
            // Single word - try character chunk matching first, then fuzzy
            $chunkMatches = $this->getCharacterChunkMatches($tenant, $query, 3);
            $suggestions = array_merge($suggestions, $chunkMatches);
            
            // If we don't have enough suggestions, try fuzzy matching
            if (count($suggestions) < 2) {
                $fuzzyMatches = $this->getFuzzyMatches($tenant, $query, 3);
                $suggestions = array_merge($suggestions, $fuzzyMatches);
            }
        }
        
        // Always include some popular searches as fallback
        $popularSearches = DB::table('wk_search_analytics')
            ->where('tenant_id', $tenant)
            ->orderBy('count', 'desc')
            ->limit(2)
            ->pluck('query')
            ->toArray();
        
        $suggestions = array_merge($suggestions, $popularSearches);
        
        // Remove duplicates and limit results
        $suggestions = array_unique($suggestions);
        $suggestions = array_slice($suggestions, 0, $limit);
        
        return response()->json(['suggestions' => $suggestions]);
    }
    
    private function getPartialMatches($tenant, $word, $limit)
    {
        $matches = [];
        
        // Search in product titles (most relevant)
        $productMatches = DB::table('wk_index_products')
            ->where('tenant_id', $tenant)
            ->where('title', 'like', '%' . $word . '%')
            ->orderBy('in_stock', 'desc')
            ->orderBy('purchase_count', 'desc')
            ->limit($limit)
            ->pluck('title')
            ->toArray();
        
        $matches = array_merge($matches, $productMatches);
        
        // Search in categories (need to join with wk_product_categories)
        $categoryMatches = DB::table('wk_index_products as p')
            ->join('wk_product_categories as pc', 'pc.product_id', '=', 'p.id')
            ->where('pc.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
            ->join('wk_categories as c', 'c.id', '=', 'pc.category_id')
            ->where('p.tenant_id', $tenant)
            ->where('c.tenant_id', $tenant)
            ->where('c.name', 'like', '%' . $word . '%')
            ->distinct()
            ->limit($limit)
            ->pluck('c.name')
            ->toArray();
        
        $matches = array_merge($matches, $categoryMatches);
        
        // Search in brands
        $brandMatches = DB::table('wk_index_products')
            ->where('tenant_id', $tenant)
            ->whereNotNull('brand')
            ->where('brand', 'like', '%' . $word . '%')
            ->orderBy('in_stock', 'desc')
            ->distinct()
            ->limit($limit)
            ->pluck('brand')
            ->toArray();
        
        $matches = array_merge($matches, $brandMatches);
        
        return array_unique($matches);
    }
    
    private function getFuzzyMatches($tenant, $query, $limit)
    {
        // Performance optimization: Use LIKE queries first, then fuzzy match
        $matches = [];
        
        // First try exact prefix matches (fastest)
        $prefixMatches = DB::table('wk_index_products')
            ->where('tenant_id', $tenant)
            ->where(function($q) use ($query) {
                $q->where('title', 'like', $query . '%')
                  ->orWhere('brand', 'like', $query . '%');
            })
            ->orderBy('in_stock', 'desc')
            ->limit($limit * 2) // Get more for better selection
            ->get()
            ->flatMap(function($item) {
                return array_filter([
                    $item->title,
                    $item->brand
                ]);
            })
            ->unique()
            ->toArray();
        
        // If we have enough prefix matches, return them
        if (count($prefixMatches) >= $limit) {
            return array_slice($prefixMatches, 0, $limit);
        }
        
        // Otherwise, do fuzzy matching on a smaller set
        $allTerms = DB::table('wk_index_products')
            ->where('tenant_id', $tenant)
            ->where(function($q) use ($query) {
                $q->where('title', 'like', '%' . $query . '%')
                  ->orWhere('brand', 'like', '%' . $query . '%');
            })
            ->orderBy('in_stock', 'desc')
            ->limit(50) // Limit to 50 items for performance
            ->select('title', 'brand')
            ->get()
            ->flatMap(function($item) {
                return array_filter([
                    $item->title,
                    $item->brand
                ]);
            })
            ->unique()
            ->toArray();
        
        // Simple fuzzy matching using similar_text
        $scoredMatches = [];
        foreach ($allTerms as $term) {
            similar_text(strtolower($query), strtolower($term), $percent);
            if ($percent > 60) { // 60% similarity threshold
                $scoredMatches[] = [
                    'term' => $term,
                    'score' => $percent
                ];
            }
        }
        
        // Sort by score and return top matches
        usort($scoredMatches, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        $fuzzyResults = array_slice(array_column($scoredMatches, 'term'), 0, $limit);
        
        // Combine prefix matches with fuzzy matches
        return array_unique(array_merge($prefixMatches, $fuzzyResults));
    }
    
    private function getCharacterChunkMatches($tenant, $query, $limit)
    {
        $matches = [];
        $queryLength = strlen($query);
        
        // Only do character chunking for words longer than 3 characters
        if ($queryLength < 4) {
            return $matches;
        }
        
        // Generate character chunks of 3 characters each
        $chunks = $this->generateCharacterChunks($query, 3);
        \Log::info("Character chunks for '{$query}': " . json_encode($chunks));
        
        foreach ($chunks as $chunk) {
            $chunkMatches = $this->getPartialMatches($tenant, $chunk, 2);
            $matches = array_merge($matches, $chunkMatches);
            
            // If we have enough matches, break early for performance
            if (count($matches) >= $limit * 2) {
                break;
            }
        }
        
        // Also try 2-character chunks if the word is long enough
        if ($queryLength >= 6) {
            $chunks2 = $this->generateCharacterChunks($query, 2);
            \Log::info("2-char chunks for '{$query}': " . json_encode($chunks2));
            foreach ($chunks2 as $chunk) {
                if (strlen($chunk) >= 2) {
                    $chunkMatches = $this->getPartialMatches($tenant, $chunk, 1);
                    $matches = array_merge($matches, $chunkMatches);
                }
            }
        }
        
        $uniqueMatches = array_unique($matches);
        \Log::info("Character chunk matches for '{$query}': " . json_encode($uniqueMatches));
        return $uniqueMatches;
    }
    
    private function generateCharacterChunks($word, $chunkSize)
    {
        $chunks = [];
        $length = strlen($word);
        
        // Generate overlapping chunks
        for ($i = 0; $i <= $length - $chunkSize; $i++) {
            $chunk = substr($word, $i, $chunkSize);
            if (strlen($chunk) == $chunkSize) {
                $chunks[] = $chunk;
            }
        }
        
        // Also try some variations for better matching
        // 1. Try without first character
        if ($length > $chunkSize + 1) {
            $chunks[] = substr($word, 1, $chunkSize);
        }
        
        // 2. Try without last character
        if ($length > $chunkSize + 1) {
            $chunks[] = substr($word, 0, $chunkSize);
        }
        
        // 3. Try middle chunk for longer words
        if ($length >= 6) {
            $start = intval($length / 2) - 1;
            $chunks[] = substr($word, $start, $chunkSize);
        }
        
        return array_unique($chunks);
    }
    
    private function generateWordCombinations($words)
    {
        $combinations = [];
        $count = count($words);
        
        // Generate 2-word combinations
        for ($i = 0; $i < $count - 1; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $combinations[] = $words[$i] . ' ' . $words[$j];
            }
        }
        
        return $combinations;
    }

    public function popular(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $limit = $request->input('limit', 20);
        
        \Log::info('Popular products request', ['tenant' => $tenant, 'limit' => $limit]);
        
        try {
            // First try to get popular products based on search analytics and order data
            $popularProducts = DB::table('wk_index_products as p')
                ->select([
                    'p.id', 'p.title', 'p.slug', 'p.price', 'p.price_old', 'p.currency',
                    'p.image', 'p.brand', 'p.rating', 'p.in_stock', 'p.url', 'p.html',
                    'p.popularity', 'p.view_count', 'p.purchase_count'
                ])
                ->where('p.tenant_id', $tenant)
                ->where('p.in_stock', true)
                ->orderByDesc('p.rating')
                ->orderByDesc('p.popularity')
                ->orderByDesc('p.purchase_count')
                ->limit($limit)
                ->get();

            \Log::info('Popular products query result', ['count' => $popularProducts->count(), 'tenant' => $tenant]);

            // If we don't have enough popular products, fallback to most sold products
            if ($popularProducts->count() < $limit) {
                $remainingLimit = $limit - $popularProducts->count();
                
                // Get most sold products (you can implement order tracking later)
                $mostSoldProducts = DB::table('wk_index_products as p')
                    ->select([
                        'p.id', 'p.title', 'p.slug', 'p.price', 'p.price_old', 'p.currency',
                        'p.image', 'p.brand', 'p.rating', 'p.in_stock', 'p.url', 'p.html',
                        'p.popularity', 'p.view_count', 'p.purchase_count'
                    ])
                    ->where('p.tenant_id', $tenant)
                    ->where('p.in_stock', true)
                    ->whereNotIn('p.id', $popularProducts->pluck('id')->toArray())
                    ->orderByDesc('p.purchase_count')
                    ->orderByDesc('p.rating')
                    ->orderBy('p.title')
                    ->limit($remainingLimit)
                    ->get();

                // Merge the results
                $popularProducts = $popularProducts->merge($mostSoldProducts);
            }

            $products = [];
            foreach ($popularProducts as $product) {
                $products[] = [
                    'id' => $product->id,
                    'title' => $product->title,
                    'slug' => $product->slug,
                    'price' => $product->price,
                    'sale_price' => $product->price_old,
                    'currency' => $product->currency,
                    'image' => $product->image,
                    'brand' => $product->brand,
                    'rating' => $product->rating,
                    'in_stock' => $product->in_stock,
                    'html' => $product->html,
                    'on_sale' => false, // TODO: calculate based on price vs price_old
                    'featured' => false, // TODO: add featured column or calculate
                    'permalink' => $product->url,
                    'description' => '', // TODO: add description or extract from html
                    'popularity' => $product->popularity,
                    'view_count' => $product->view_count,
                    'purchase_count' => $product->purchase_count
                ];
            }

            // Generate facets from the products
            $facets = $this->generateFacetsFromProducts($popularProducts, $tenant);
            
            return response()->json([
                'products' => $products,
                'totalResults' => count($products),
                'facets' => $facets
            ]);

        } catch (\Exception $e) {
            \Log::error('Popular products error: ' . $e->getMessage());
            return response()->json([
                'products' => [],
                'totalResults' => 0,
                'facets' => []
            ]);
        }
    }

    private function generateFacetsFromProducts($products, $tenant)
    {
        $brands = [];
        $categories = [];
        $tags = [];
        
        foreach ($products as $product) {
            // Collect brands (string key)
            if (!empty($product->brand)) {
                $brand = $product->brand;
                if (!isset($brands[$brand])) { $brands[$brand] = 0; }
                $brands[$brand]++;
            }
            
            // Collect categories by slug (stable id), keep latest name for display
            $productCategories = DB::table('wk_product_categories as pc')
                ->join('wk_categories as c', 'c.id', '=', 'pc.category_id')
                ->where('pc.product_id', $product->id)
                ->where('c.tenant_id', $tenant)
                ->select('c.name', 'c.slug')
                ->get();
            foreach ($productCategories as $cat) {
                $slug = $cat->slug ?: $cat->name;
                if (!isset($categories[$slug])) { $categories[$slug] = ['name' => $cat->name, 'count' => 0]; }
                $categories[$slug]['name'] = $cat->name;
                $categories[$slug]['count']++;
            }
            
            // Collect tags by slug (stable id)
            $productTags = DB::table('wk_product_tags as pt')
                ->join('wk_tags as t', 't.id', '=', 'pt.tag_id')
                ->where('pt.product_id', $product->id)
                ->where('t.tenant_id', $tenant)
                ->select('t.name', 't.slug')
                ->get();
            foreach ($productTags as $tag) {
                $slug = $tag->slug ?: $tag->name;
                if (!isset($tags[$slug])) { $tags[$slug] = ['name' => $tag->name, 'count' => 0]; }
                $tags[$slug]['name'] = $tag->name;
                $tags[$slug]['count']++;
            }
        }
        
        // Convert to the format expected by the frontend
        $facets = [];
        
        if (!empty($brands)) {
            $facets['brand'] = array_map(function($name, $count) {
                return ['name' => $name, 'id' => $name, 'count' => $count];
            }, array_keys($brands), array_values($brands));
        }
        
        if (!empty($categories)) {
            $facets['category'] = array_map(function($slug, $row) {
                return ['name' => $row['name'], 'id' => $slug, 'count' => $row['count']];
            }, array_keys($categories), array_values($categories));
        }
        
        if (!empty($tags)) {
            $facets['tag'] = array_map(function($slug, $row) {
                return ['name' => $row['name'], 'id' => $slug, 'count' => $row['count']];
            }, array_keys($tags), array_values($tags));
        }
        
        return $facets;
    }

    public function popularQueries(Request $request)
    {
        $tenant = $request->header('X-Tenant-Id');
        $limit = $request->input('limit', 10);
        
        \Log::info('Popular queries request', ['tenant' => $tenant, 'limit' => $limit]);
        
        try {
            // Get popular search queries from analytics
            $popularQueries = DB::table('wk_search_analytics')
                ->where('tenant_id', $tenant)
                ->whereNotNull('query')
                ->where('query', '!=', '')
                ->orderByDesc('count')
                ->limit($limit)
                ->pluck('query')
                ->toArray();

            \Log::info('Popular queries result', ['count' => count($popularQueries), 'queries' => $popularQueries, 'tenant' => $tenant]);

            return response()->json([
                'queries' => $popularQueries,
                'totalResults' => count($popularQueries)
            ]);

        } catch (\Exception $e) {
            \Log::error('Popular queries error: ' . $e->getMessage());
            return response()->json([
                'queries' => [],
                'totalResults' => 0
            ]);
        }
    }

    private function facetBrand($tenant, $q, $brands, $categories, $priceMin, $priceMax, $inStock, $ratingMin, $restrictIds = [], $onSale = null, $searchTerm = null)
    {
        $sql = DB::table('wk_index_products as p')->select('p.brand', DB::raw('COUNT(*) as c'))
            ->where('p.tenant_id', $tenant)
            ->whereNotNull('p.brand');
        if (!empty($restrictIds)) { $sql->whereIn('p.id', $restrictIds); }
        if (!empty($brands)) { $sql->whereIn('p.brand', $brands); }
        if ($onSale !== null && $onSale !== '') { $sql->whereNotNull('p.price_old')->whereColumn('p.price_old','>','p.price'); }
        if ($searchTerm) {
            $sql->whereRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE)', [$searchTerm]);
        } elseif ($q !== '') {
            $sql->where(function($w) use ($q){ $w->where('p.title','like','%'.$q.'%')->orWhere('p.brand','like','%'.$q.'%'); });
        }
        if (!empty($categories)) {
            $sql->join('wk_product_categories as pc','pc.product_id','=','p.id')
                ->where('pc.tenant_id',$tenant)  // CRITICAL: Multi-tenant isolation
                ->join('wk_categories as c','c.id','=','pc.category_id')
                ->where('c.tenant_id',$tenant)
                ->where(function($w) use ($categories){
                    // prefer slug matching for stability
                    $ids = array_filter($categories, fn($v)=>ctype_digit((string)$v));
                    $slugs = array_diff($categories, $ids);
                    if (!empty($slugs)) $w->orWhereIn('c.slug', $slugs);
                    if (!empty($ids)) $w->orWhereIn('c.id', $ids);
                });
        }
        if ($priceMin !== null) $sql->where('p.price','>=',(float)$priceMin);
        if ($priceMax !== null) $sql->where('p.price','<=',(float)$priceMax);
        if ($inStock !== null && $inStock !== '') $sql->where('p.in_stock',(int)$inStock);
        if ($ratingMin !== null && $ratingMin !== '') $sql->where('p.rating','>=',(float)$ratingMin);
        return $sql->groupBy('p.brand')->orderByDesc('c')->pluck('c','p.brand');
    }

    private function facetBrandFast($tenant)
    {
        return DB::table('wk_facet_counts')
            ->where('tenant_id',$tenant)
            ->where('facet_type','brand')
            ->orderByDesc('count')
            ->limit(20)
            ->get([
                DB::raw('facet_value as name'),
                DB::raw('count as c')
            ]);
    }

    private function facetCategory($tenant, $q, $brands, $categories, $priceMin, $priceMax, $inStock, $ratingMin, $restrictIds = [], $onSale = null, $searchTerm = null)
    {
        $sql = DB::table('wk_categories as c')->select('c.id','c.name','c.level', DB::raw('COUNT(distinct p.id) as c'))
            ->join('wk_product_categories as pc','pc.category_id','=','c.id')
            ->where('pc.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
            ->join('wk_index_products as p','p.id','=','pc.product_id')
            ->where('c.tenant_id',$tenant)
            ->where('p.tenant_id',$tenant);
        if (!empty($restrictIds)) { $sql->whereIn('p.id', $restrictIds); }
        if ($searchTerm) {
            $sql->whereRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE)', [$searchTerm]);
        } elseif ($q !== '') {
            $sql->where(function($w) use ($q){ $w->where('p.title','like','%'.$q.'%')->orWhere('p.brand','like','%'.$q.'%'); });
        }
        if (!empty($brands)) $sql->whereIn('p.brand', $brands);
        if ($onSale !== null && $onSale !== '') { $sql->whereNotNull('p.price_old')->whereColumn('p.price_old','>','p.price'); }
        if (!empty($categories)) {
            // Apply active category filters (slug preferred, fallback id)
            $sql->whereExists(function($sub) use ($categories, $tenant){
                $sub->select(DB::raw(1))
                    ->from('wk_product_categories as pcx')
                    ->where('pcx.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                    ->join('wk_categories as cx','cx.id','=','pcx.category_id')
                    ->where('cx.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
                    ->whereColumn('pcx.product_id','p.id')
                    ->where(function($w) use ($categories){
                        $ids = array_filter($categories, fn($v)=>ctype_digit((string)$v));
                        $slugs = array_diff($categories, $ids);
                        if (!empty($slugs)) $w->orWhereIn('cx.slug', $slugs);
                        if (!empty($ids)) $w->orWhereIn('cx.id', $ids);
                    });
            });
        }
        if ($priceMin !== null) $sql->where('p.price','>=',(float)$priceMin);
        if ($priceMax !== null) $sql->where('p.price','<=',(float)$priceMax);
        if ($inStock !== null && $inStock !== '') $sql->where('p.in_stock',(int)$inStock);
        if ($ratingMin !== null && $ratingMin !== '') $sql->where('p.rating','>=',(float)$ratingMin);
        return $sql->groupBy('c.id','c.name','c.level')->orderByDesc('c')->get();
    }

    private function facetCategoryFast($tenant)
    {
        return DB::table('wk_facet_counts')
            ->where('tenant_id',$tenant)
            ->where('facet_type','category')
            ->orderByDesc('count')
            ->limit(100)
            ->get([
                DB::raw('facet_value as name'),
                DB::raw('count as c')
            ]);
    }

    private function facetTag($tenant, $q, $brands, $categories, $priceMin, $priceMax, $inStock, $ratingMin, $restrictIds = [], $onSale = null, $searchTerm = null)
    {
        $sql = DB::table('wk_tags as t')->select('t.id','t.name', DB::raw('COUNT(distinct p.id) as c'))
            ->join('wk_product_tags as pt','pt.tag_id','=','t.id')
            ->where('pt.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
            ->join('wk_index_products as p','p.id','=','pt.product_id')
            ->where('t.tenant_id',$tenant)
            ->where('p.tenant_id',$tenant);
        if (!empty($restrictIds)) { $sql->whereIn('p.id', $restrictIds); }
        if ($onSale !== null && $onSale !== '') { $sql->whereNotNull('p.price_old')->whereColumn('p.price_old','>','p.price'); }
        if ($searchTerm) {
            $sql->whereRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE)', [$searchTerm]);
        } elseif ($q !== '') { $sql->where(function($w) use ($q){ $w->where('p.title','like','%'.$q.'%')->orWhere('p.brand','like','%'.$q.'%'); }); }
        if (!empty($brands)) $sql->whereIn('p.brand',$brands);
        if (!empty($categories)) {
            $sql->join('wk_product_categories as pc','pc.product_id','=','p.id')
                ->where('pc.tenant_id',$tenant)  // CRITICAL: Multi-tenant isolation
                ->join('wk_categories as c','c.id','=','pc.category_id')
                ->where('c.tenant_id',$tenant);
        }
        if ($priceMin !== null) $sql->where('p.price','>=',(float)$priceMin);
        if ($priceMax !== null) $sql->where('p.price','<=',(float)$priceMax);
        if ($inStock !== null && $inStock !== '') $sql->where('p.in_stock',(int)$inStock);
        if ($ratingMin !== null && $ratingMin !== '') $sql->where('p.rating','>=',(float)$ratingMin);
        return $sql->groupBy('t.id','t.name')->orderByDesc('c')->limit(20)->get();
    }

    private function facetTagFast($tenant)
    {
        return DB::table('wk_facet_counts')
            ->where('tenant_id',$tenant)
            ->where('facet_type','tag')
            ->orderByDesc('count')
            ->limit(20)
            ->get([
                DB::raw('facet_value as name'),
                DB::raw('count as c')
            ]);
    }

    private function facetAvailability($tenant, $q, $brands, $categories, $priceMin, $priceMax, $ratingMin, $restrictIds = [], $onSale = null, $searchTerm = null)
    {
        $sql = DB::table('wk_index_products as p')->select('p.in_stock', DB::raw('COUNT(*) as c'))->where('p.tenant_id',$tenant);
        if (!empty($restrictIds)) { $sql->whereIn('p.id', $restrictIds); }
        if ($onSale !== null && $onSale !== '') { $sql->whereNotNull('p.price_old')->whereColumn('p.price_old','>','p.price'); }
        if ($searchTerm) { $sql->whereRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE)', [$searchTerm]); }
        elseif ($q !== '') { $sql->where(function($w) use ($q){ $w->where('p.title','like','%'.$q.'%')->orWhere('p.brand','like','%'.$q.'%'); }); }
        if (!empty($brands)) $sql->whereIn('p.brand',$brands);
        if (!empty($categories)) {
            $sql->join('wk_product_categories as pc','pc.product_id','=','p.id')
                ->where('pc.tenant_id',$tenant)  // CRITICAL: Multi-tenant isolation
                ->join('wk_categories as c','c.id','=','pc.category_id')
                ->where('c.tenant_id',$tenant)
                ->where(function($w) use ($categories){
                    $ids = array_filter($categories, fn($v)=>ctype_digit((string)$v));
                    $slugs = array_diff($categories, $ids);
                    if (!empty($ids)) $w->orWhereIn('c.id', $ids);
                    if (!empty($slugs)) $w->orWhereIn('c.slug', $slugs);
                });
        }
        if ($priceMin !== null) $sql->where('p.price','>=',(float)$priceMin);
        if ($priceMax !== null) $sql->where('p.price','<=',(float)$priceMax);
        if ($ratingMin !== null && $ratingMin !== '') $sql->where('p.rating','>=',(float)$ratingMin);
        $raw = $sql->groupBy('p.in_stock')->pluck('c','p.in_stock');
        return ['in_stock'=>(int)($raw[1] ?? 0), 'out_of_stock'=>(int)($raw[0] ?? 0)];
    }

    private function facetRatingBuckets($tenant, $q, $brands, $categories, $priceMin, $priceMax, $inStock, $restrictIds = [], $onSale = null, $searchTerm = null)
    {
        $buckets = [5,4,3,2,1];
        $out = [];
        foreach ($buckets as $min) {
            $sql = DB::table('wk_index_products as p')->where('p.tenant_id',$tenant)->where('p.rating','>=',$min);
            if (!empty($restrictIds)) { $sql->whereIn('p.id', $restrictIds); }
            if ($onSale !== null && $onSale !== '') { $sql->whereNotNull('p.price_old')->whereColumn('p.price_old','>','p.price'); }
            if ($searchTerm) { $sql->whereRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE)', [$searchTerm]); }
            elseif ($q !== '') { $sql->where(function($w) use ($q){ $w->where('p.title','like','%'.$q.'%')->orWhere('p.brand','like','%'.$q.'%'); }); }
            if (!empty($brands)) $sql->whereIn('p.brand',$brands);
            if (!empty($categories)) {
                $sql->join('wk_product_categories as pc','pc.product_id','=','p.id')
                    ->where('pc.tenant_id',$tenant)  // CRITICAL: Multi-tenant isolation
                    ->join('wk_categories as c','c.id','=','pc.category_id')
                    ->where('c.tenant_id',$tenant);
            }
            if ($priceMin !== null) $sql->where('p.price','>=',(float)$priceMin);
            if ($priceMax !== null) $sql->where('p.price','<=',(float)$priceMax);
            if ($inStock !== null && $inStock !== '') $sql->where('p.in_stock',(int)$inStock);
            $out[(string)$min] = (int) $sql->count();
        }
        return $out;
    }

    private function facetPriceBuckets($tenant, $q, $brands, $categories, $inStock, $ratingMin, $restrictIds = [], $onSale = null, $searchTerm = null)
    {
        $ranges = [[0,25],[25,50],[50,100],[100,200],[200,null]];
        $out = [];
        foreach ($ranges as [$min,$max]) {
            $sql = DB::table('wk_index_products as p')->where('p.tenant_id',$tenant);
            if (!empty($restrictIds)) { $sql->whereIn('p.id', $restrictIds); }
            if ($onSale !== null && $onSale !== '') { $sql->whereNotNull('p.price_old')->whereColumn('p.price_old','>','p.price'); }
            if ($searchTerm) { $sql->whereRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE)', [$searchTerm]); }
            elseif ($q !== '') { $sql->where(function($w) use ($q){ $w->where('p.title','like','%'.$q.'%')->orWhere('p.brand','like','%'.$q.'%'); }); }
            if (!empty($brands)) $sql->whereIn('p.brand',$brands);
            if (!empty($categories)) {
                $sql->join('wk_product_categories as pc','pc.product_id','=','p.id')
                    ->where('pc.tenant_id',$tenant)  // CRITICAL: Multi-tenant isolation
                    ->join('wk_categories as c','c.id','=','pc.category_id')
                    ->where('c.tenant_id',$tenant);
            }
            if ($inStock !== null && $inStock !== '') $sql->where('p.in_stock',(int)$inStock);
            if ($ratingMin !== null && $ratingMin !== '') $sql->where('p.rating','>=',(float)$ratingMin);
            $sql->where('p.price','>=',$min);
            if ($max !== null) $sql->where('p.price','<',$max);
            $label = $max===null ? "$min+" : "$min-$max";
            $out[$label] = (int) $sql->count();
        }
        return $out;
    }

    private function expandWithSynonyms(string $tenant, string $q): string
    {
        $row = DB::table('wk_synonyms')->where('tenant_id',$tenant)->first();
        if (!$row) return $q;
        $data = json_decode($row->synonym_data ?? '[]', true);
        if (!is_array($data)) return $q;
        $terms = [$q];
        foreach ($data as $rule) {
            if (isset($rule['from']) && is_array($rule['from']) && isset($rule['to'])) {
                foreach ($rule['from'] as $from) {
                    if (mb_stripos($q, $from) !== false) { $terms[] = $rule['to']; }
                }
            }
        }
        $terms = array_values(array_unique(array_filter($terms)));
        return implode(' ', $terms);
    }

    private function buildAttributeFacets(
        string $tenant,
        array $attributesMeta,
        array $brandFilters,
        array $categoryFilters,
        array $tagFilters,
        $priceMin,
        $priceMax,
        $inStock,
        $ratingMin,
        array $restrictIds,
        $onSale,
        $searchTerm
    ) {
        if (empty($attributesMeta)) { return new \stdClass(); }
        $out = [];
        foreach ($attributesMeta as $attr) {
            $sql = DB::table('wk_product_attributes as pa')
                ->join('wk_index_products as p','p.id','=','pa.product_id')
                ->where('pa.tenant_id',$tenant)  // CRITICAL: Multi-tenant isolation
                ->where('p.tenant_id',$tenant)
                ->where('pa.attribute_name',$attr);
            if (!empty($restrictIds)) { $sql->whereIn('p.id', $restrictIds); }
            if ($onSale !== null && $onSale !== '') { $sql->whereNotNull('p.price_old')->whereColumn('p.price_old','>','p.price'); }
            if ($searchTerm) { $sql->whereRaw('MATCH(p.title, p.brand, p.sku, p.description) AGAINST (? IN BOOLEAN MODE)', [$searchTerm]); }
            if (!empty($brandFilters)) { $sql->whereIn('p.brand',$brandFilters); }
            if (!empty($categoryFilters)) {
                $sql->join('wk_product_categories as pc','pc.product_id','=','p.id')
                    ->where('pc.tenant_id',$tenant)  // CRITICAL: Multi-tenant isolation
                    ->join('wk_categories as c','c.id','=','pc.category_id')
                    ->where('c.tenant_id',$tenant)
                    ->where(function($w) use ($categoryFilters){
                        $ids = array_filter($categoryFilters, fn($v)=>ctype_digit((string)$v));
                        $slugs = array_diff($categoryFilters, $ids);
                        if (!empty($slugs)) $w->orWhereIn('c.slug', $slugs);
                        if (!empty($ids)) $w->orWhereIn('c.id', $ids);
                    });
            }
            if ($priceMin !== null) { $sql->where('p.price','>=',(float)$priceMin); }
            if ($priceMax !== null) { $sql->where('p.price','<=',(float)$priceMax); }
            if ($inStock !== null && $inStock !== '') { $sql->where('p.in_stock',(int)$inStock); }
            if ($ratingMin !== null && $ratingMin !== '') { $sql->where('p.rating','>=',(float)$ratingMin); }

            $values = $sql->select('pa.attribute_value as name', DB::raw('COUNT(distinct p.id) as c'))
                ->groupBy('pa.attribute_value')
                ->orderByDesc('c')
                ->limit(20)
                ->get();
            if ($values->count() > 0) {
                $out[$attr] = $values;
            }
        }
        return $out ?: new \stdClass();
    }

    /**
     * Compute facets from the actual returned product set for accurate filtering
     */
    private function facetBrandFromProducts($tenant, array $productIds)
    {
        return DB::table('wk_index_products as p')
            ->select('p.brand as name', DB::raw('COUNT(*) as c'))
            ->where('p.tenant_id', $tenant)
            ->whereIn('p.id', $productIds)
            ->whereNotNull('p.brand')
            ->where('p.brand', '!=', '')
            ->groupBy('p.brand')
            ->orderByDesc('c')
            ->get();
    }

    private function facetCategoryFromProducts($tenant, array $productIds)
    {
        return DB::table('wk_categories as c')
            ->select('c.id', 'c.name', 'c.level', DB::raw('COUNT(distinct p.id) as c'))
            ->join('wk_product_categories as pc', 'pc.category_id', '=', 'c.id')
            ->where('pc.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
            ->join('wk_index_products as p', 'p.id', '=', 'pc.product_id')
            ->where('c.tenant_id', $tenant)
            ->where('p.tenant_id', $tenant)
            ->whereIn('p.id', $productIds)
            ->groupBy('c.id', 'c.name', 'c.level')
            ->orderByDesc('c')
            ->get();
    }

    private function facetTagFromProducts($tenant, array $productIds)
    {
        return DB::table('wk_tags as t')
            ->select('t.id', 't.name', 't.slug', DB::raw('COUNT(distinct p.id) as c'))
            ->join('wk_product_tags as pt', 'pt.tag_id', '=', 't.id')
            ->where('pt.tenant_id', $tenant)  // CRITICAL: Multi-tenant isolation
            ->join('wk_index_products as p', 'p.id', '=', 'pt.product_id')
            ->where('t.tenant_id', $tenant)
            ->where('p.tenant_id', $tenant)
            ->whereIn('p.id', $productIds)
            ->groupBy('t.id', 't.name', 't.slug')
            ->orderByDesc('c')
            ->get();
    }

    private function facetAvailabilityFromProducts($tenant, array $productIds)
    {
        return DB::table('wk_index_products as p')
            ->select(DB::raw('CASE WHEN p.in_stock = 1 THEN "in_stock" ELSE "out_of_stock" END as name'), DB::raw('COUNT(*) as c'))
            ->where('p.tenant_id', $tenant)
            ->whereIn('p.id', $productIds)
            ->groupBy(DB::raw('CASE WHEN p.in_stock = 1 THEN "in_stock" ELSE "out_of_stock" END'))
            ->orderByDesc('c')
            ->get();
    }

    private function facetRatingFromProducts($tenant, array $productIds)
    {
        return DB::table('wk_index_products as p')
            ->select(DB::raw('CASE 
                WHEN p.rating >= 4.5 THEN "4.5+"
                WHEN p.rating >= 4.0 THEN "4.0-4.4"
                WHEN p.rating >= 3.5 THEN "3.5-3.9"
                WHEN p.rating >= 3.0 THEN "3.0-3.4"
                WHEN p.rating >= 2.5 THEN "2.5-2.9"
                WHEN p.rating >= 2.0 THEN "2.0-2.4"
                WHEN p.rating >= 1.5 THEN "1.5-1.9"
                WHEN p.rating >= 1.0 THEN "1.0-1.4"
                ELSE "0-0.9"
            END as name'), DB::raw('COUNT(*) as c'))
            ->where('p.tenant_id', $tenant)
            ->whereIn('p.id', $productIds)
            ->whereNotNull('p.rating')
            ->groupBy(DB::raw('CASE 
                WHEN p.rating >= 4.5 THEN "4.5+"
                WHEN p.rating >= 4.0 THEN "4.0-4.4"
                WHEN p.rating >= 3.5 THEN "3.5-3.9"
                WHEN p.rating >= 3.0 THEN "3.0-3.4"
                WHEN p.rating >= 2.5 THEN "2.5-2.9"
                WHEN p.rating >= 2.0 THEN "2.0-2.4"
                WHEN p.rating >= 1.5 THEN "1.5-1.9"
                WHEN p.rating >= 1.0 THEN "1.0-1.4"
                ELSE "0-0.9"
            END'))
            ->orderByDesc('c')
            ->get();
    }

    private function facetPriceFromProducts($tenant, array $productIds)
    {
        $products = DB::table('wk_index_products as p')
            ->select('p.price')
            ->where('p.tenant_id', $tenant)
            ->whereIn('p.id', $productIds)
            ->whereNotNull('p.price')
            ->get();

        if ($products->isEmpty()) {
            return collect();
        }

        $prices = $products->pluck('price')->filter()->sort()->values();
        $min = $prices->first();
        $max = $prices->last();
        
        if ($min === null || $max === null) {
            return collect();
        }

        $buckets = [];
        $range = $max - $min;
        $bucketSize = $range > 0 ? max(10, round($range / 10)) : 10;
        
        for ($i = 0; $i < 10; $i++) {
            $bucketMin = $min + ($i * $bucketSize);
            $bucketMax = $min + (($i + 1) * $bucketSize);
            $count = $prices->filter(function($price) use ($bucketMin, $bucketMax) {
                return $price >= $bucketMin && $price < $bucketMax;
            })->count();
            
            if ($count > 0) {
                $buckets[] = (object)[
                    'name' => '$' . number_format($bucketMin, 0) . ' - $' . number_format($bucketMax, 0),
                    'c' => $count
                ];
            }
        }
        
        
        return collect($buckets);
    }

    /**
     * Execute Classic Search Mode
     * Uses ClassicSearchService for strict, exact-match prioritization
     */
    private function executeClassicSearch(Request $request, string $tenant, $userId, string $q, int $page, int $limit, int $offset)
    {
        $tStart = microtime(true);
        
        // Get hide_out_of_stock from request parameter (WordPress sends this with every search)
        $hideOutOfStock = (bool)$request->input('hide_out_of_stock', false);
        
        // Check if description search is enabled
        $searchDescription = (bool)$request->input('search_description', false);
        
        // Get in_stock filter from request
        $inStockFilter = $request->input('in_stock');
        // Apply the setting: if hideOutOfStock is true and user hasn't explicitly filtered, force in_stock=1
        if ($hideOutOfStock && $inStockFilter === null) {
            $inStockFilter = 1;
        }
        
        // Prepare filters from request
        $filters = [
            'brand' => (array)($request->input('brand') ?? []),
            'category' => (array)($request->input('category') ?? []),
            'tag' => (array)($request->input('tag') ?? []),
            'attributes' => (array)($request->input('attributes') ?? []),
            'price_min' => $request->input('price_min'),
            'price_max' => $request->input('price_max'),
            'in_stock' => $inStockFilter,
            'on_sale' => $request->input('on_sale'),
            'rating_min' => $request->input('rating_min'),
        ];
        
        $sort = (string)($request->input('sort') ?? 'relevance');
        
        \Log::info('Classic search filters', [
            'query' => $q,
            'filters' => $filters,
            'search_description' => $searchDescription
        ]);
        
        // Use ClassicSearchService
        $classicService = new ClassicSearchService();
        $searchResult = $classicService->search($tenant, $q, $filters, $limit, $offset, $sort, $searchDescription);
        
        $products = $searchResult['products'];
        $total = $searchResult['total'];
        
        // Get ALL matching product IDs for accurate facet computation (not just paginated results)
        // This ensures facets show all available brands/categories from the full result set
        $allMatchingIds = [];
        if ($total > 0) {
            $allMatchingIds = $classicService->getMatchingIds($tenant, $q, $filters, $searchDescription);
        }
        
        // Apply bans and pins (same as advanced mode)
        if ($q !== '') {
            $banIds = DB::table('wk_bans')->where('tenant_id',$tenant)->where('query',$q)->pluck('product_id')->all();
            if (!empty($banIds)) {
                $products = $products->reject(function($p) use ($banIds){ return in_array($p->id, $banIds); })->values();
            }
            $pins = DB::table('wk_pins')->where('tenant_id',$tenant)->where('query',$q)->orderBy('position')->get();
            if ($pins->count() > 0) {
                $byId = [];
                foreach ($products as $p) { $byId[$p->id] = $p; }
                $rest = $products->reject(function($p) use ($pins){ return in_array($p->id, $pins->pluck('product_id')->all()); })->values();
                $ordered = [];
                foreach ($pins as $pin) {
                    if (isset($byId[$pin->product_id])) $ordered[] = $byId[$pin->product_id];
                }
                $products = collect(array_values(array_merge($ordered, $rest->all())));
            }
        }
        
        // Compute facets from ALL matching products (not just current page)
        // Use allMatchingIds if we have them, otherwise fall back to returned products
        $facetProductIds = !empty($allMatchingIds) ? $allMatchingIds : $products->pluck('id')->toArray();
        $facets = [];
        
        if (!empty($facetProductIds)) {
            $facets = [
                'brand' => $this->facetBrandFromProducts($tenant, $facetProductIds),
                'category' => $this->facetCategoryFromProducts($tenant, $facetProductIds),
                'tag' => $this->facetTagFromProducts($tenant, $facetProductIds),
                'availability' => $this->facetAvailabilityFromProducts($tenant, $facetProductIds),
                'rating' => $this->facetRatingFromProducts($tenant, $facetProductIds),
                'price' => $this->facetPriceFromProducts($tenant, $facetProductIds),
            ];
        }
        
        // Compute price range from ALL matching products, not just paginated results
        if (!empty($allMatchingIds)) {
            $rangeMin = (float) DB::table('wk_index_products')->whereIn('id', $allMatchingIds)->min('price');
            $rangeMax = (float) DB::table('wk_index_products')->whereIn('id', $allMatchingIds)->max('price');
        } else {
            // Fallback to paginated products if no matches
            $rangeMin = (float) $products->min('price');
            $rangeMax = (float) $products->max('price');
        }
        $currencyForRange = ($products->count() > 0) ? ($products[0]->currency ?? 'USD') : 'USD';
        
        // Build attribute facets (optional - only if configured)
        $attributesMeta = (array)($request->input('attributes_meta') ?? []);
        $attributeFacets = [];
        if (!empty($attributesMeta) && !empty($facetProductIds)) {
            foreach ($attributesMeta as $attr) {
                $sql = DB::table('wk_product_attributes as pa')
                    ->join('wk_index_products as p','p.id','=','pa.product_id')
                    ->where('p.tenant_id',$tenant)
                    ->where('pa.tenant_id',$tenant)
                    ->where('pa.attribute_name',$attr)
                    ->whereIn('p.id', $facetProductIds);
                
                $values = $sql->select('pa.attribute_value as name', DB::raw('COUNT(distinct p.id) as c'))
                    ->groupBy('pa.attribute_value')
                    ->orderByDesc('c')
                    ->limit(20)
                    ->get();
                if ($values->count() > 0) {
                    $attributeFacets[$attr] = $values;
                }
            }
        }
        
        $elapsed = (int) round((microtime(true) - $tStart) * 1000);
        
        \Log::info('Classic search executed', [
            'query' => $q,
            'total' => $total,
            'products_count' => $products->count(),
            'elapsed_ms' => $elapsed
        ]);
        
        $payload = [
            'mode' => 'classic',
            'products' => [
                'results' => $products,
                'total' => $total,
                'filters' => [
                    'brand' => $facets['brand'] ?? [],
                    'category' => $facets['category'] ?? [],
                    'tag' => $facets['tag'] ?? [],
                    'attributes' => $attributeFacets,
                ],
                'page' => $page,
                'limit' => $limit,
            ],
            'metrics' => [ 
                'elapsed_ms' => $elapsed,
                'search_algorithm' => 'classic_strict_matching'
            ],
            'facets_meta' => [
                'availability' => $facets['availability'] ?? [],
                'rating' => $facets['rating'] ?? [],
                'price' => $facets['price'] ?? [],
                'price_range' => [
                    'min' => $rangeMin,
                    'max' => $rangeMax,
                    'currency' => $currencyForRange,
                ],
            ],
            'fallback_used' => false,
            'query_info' => $searchResult['query_breakdown'] ?? []
        ];
        
        // Analytics tracking
        try {
            if ($total === 0 && $q !== '') {
                DB::statement('INSERT INTO wk_search_analytics (tenant_id, `query`, `count`) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE `count` = `count` + 1, last_searched = CURRENT_TIMESTAMP', [$tenant, $q]);
            }
            DB::table('wk_analytics_hourly')->updateOrInsert(
                ['tenant_id'=>$tenant,'date'=>date('Y-m-d'),'hour'=>intval(date('G')),'event_type'=>'search_perf_classic'],
                ['count'=>DB::raw('count+'.$elapsed), 'updated_at'=>now()]
            );
        } catch (\Throwable $e) {}
        
        // Cache the response
        $cacheTtl = (int) env('SEARCH_CACHE_TTL', 30);
        if ($cacheTtl > 0) {
            $cacheKey = 'search_classic:' . $tenant . ':' . md5(json_encode([
                'q'=>$q,'page'=>$page,'limit'=>$limit,'sort'=>$sort,'filters'=>$filters
            ]));
            cache()->put($cacheKey, $payload, $cacheTtl);
        }
        
        $etag = 'W/"'.md5(json_encode($payload)).'"';
        return response()->json($payload, 200, [
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age='.$cacheTtl,
            'X-Search-Mode' => 'classic'
        ]);
    }
    
    /**
     * Expand category filters to include all descendant categories
     * 
     * Since we now store only leaf categories in wk_product_categories,
     * when a user filters by a parent category, we need to find all
     * child/descendant categories to get the full result set.
     * 
     * Example: Filter by "Clothing" should return products in:
     * - Clothing > Men
     * - Clothing > Men > Shirts
     * - Clothing > Men > Shirts > Dress Shirts
     * - Clothing > Women
     * etc.
     * 
     * @param string $tenant Tenant ID
     * @param array $categoryFilters Array of category IDs or slugs
     * @return array Expanded array including all descendant category IDs
     */
    private function expandCategoryFilters(string $tenant, array $categoryFilters): array
    {
        if (empty($categoryFilters)) {
            return [];
        }
        
        // Separate IDs and slugs
        $ids = array_filter($categoryFilters, fn($v) => ctype_digit((string)$v));
        $slugs = array_diff($categoryFilters, $ids);
        
        // Convert slugs to IDs
        if (!empty($slugs)) {
            $slugIds = DB::table('wk_categories')
                ->where('tenant_id', $tenant)
                ->whereIn('slug', $slugs)
                ->pluck('id')
                ->toArray();
            $ids = array_merge($ids, $slugIds);
        }
        
        // Remove duplicates
        $ids = array_unique(array_map('intval', $ids));
        
        if (empty($ids)) {
            return [];
        }
        
        // Find all descendant categories recursively
        $allCategoryIds = $ids;
        $processedIds = [];
        
        while (!empty($ids)) {
            $newIds = [];
            
            foreach ($ids as $parentId) {
                if (in_array($parentId, $processedIds)) {
                    continue;
                }
                
                // Find children of this category
                $children = DB::table('wk_categories')
                    ->where('tenant_id', $tenant)
                    ->where('parent_id', $parentId)
                    ->pluck('id')
                    ->toArray();
                
                if (!empty($children)) {
                    $newIds = array_merge($newIds, $children);
                    $allCategoryIds = array_merge($allCategoryIds, $children);
                }
                
                $processedIds[] = $parentId;
            }
            
            // Process next level
            $ids = array_unique($newIds);
        }
        
        return array_unique($allCategoryIds);
    }

}



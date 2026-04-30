<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncPopularSearches extends Command
{
    protected $signature = 'popular-searches:sync {--tenant=}';
    protected $description = 'Sync popular searches to wk_search_analytics table';

    public function handle()
    {
        $this->info('Starting popular searches sync...');
        
        $tenantOpt = $this->option('tenant');
        $tenants = Tenant::when($tenantOpt, function($q) use ($tenantOpt) { 
            $q->where('tenant_id', $tenantOpt); 
        })->get();

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found to sync');
            return 0;
        }

        foreach ($tenants as $site) {
            try {
                $this->syncTenantPopularSearches($site);
            } catch (\Throwable $e) {
                $this->error("[{$site->tenant_id}] Popular searches sync error: " . $e->getMessage());
                Log::error("Popular searches sync failed for tenant {$site->tenant_id}: " . $e->getMessage());
            }
        }

        $this->info('Popular searches sync completed');
        return 0;
    }

    private function syncTenantPopularSearches($site)
    {
        $this->line("[{$site->tenant_id}] Syncing popular searches...");

        try {
            // Settings is automatically cast to array by Eloquent
            $settings = $site->settings ?? [];
            $popularSearchesUrl = $settings['popular_url'] ?? null;

            if (!$popularSearchesUrl) {
                $this->line("[{$site->tenant_id}] No popular_url configured; skipping");
                return;
            }

            $popularSearchesUrlWithCache = $this->addCacheBuster($popularSearchesUrl);
            $this->line("[{$site->tenant_id}] Fetching popular searches from: $popularSearchesUrlWithCache");

            $json = @file_get_contents($popularSearchesUrlWithCache);
            if ($json === false) {
                throw new \RuntimeException('Failed to download popular_searches.json');
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid JSON in popular_searches.json');
            }

            // Support both array format and object with queries key
            $queries = [];
            if (array_is_list($data)) {
                // Direct array format: [{"query": "...", "count": 7}, ...]
                $queries = $data;
            } elseif (isset($data['queries']) && is_array($data['queries'])) {
                // Object format: {"queries": [{"query": "...", "count": 7}, ...]}
                $queries = $data['queries'];
            } else {
                throw new \RuntimeException('Unsupported popular_searches.json format');
            }

            if (empty($queries)) {
                $this->line("[{$site->tenant_id}] No popular search queries found in JSON");
                return;
            }

            // Clear existing popular searches for this tenant
            DB::table('wk_search_analytics')->where('tenant_id', $site->tenant_id)->delete();

            // Insert new popular searches
            $inserted = 0;
            foreach ($queries as $index => $query) {
                if (is_string($query)) {
                    // Simple string format
                    DB::table('wk_search_analytics')->insert([
                        'tenant_id' => $site->tenant_id,
                        'query' => $query,
                        'count' => 100 - $index, // Higher count for higher rank
                        'last_searched' => now()->subDays(rand(0, 7))
                    ]);
                    $inserted++;
                } elseif (is_array($query) && isset($query['query'])) {
                    // Object format with query, count, and last_searched
                    DB::table('wk_search_analytics')->insert([
                        'tenant_id' => $site->tenant_id,
                        'query' => $query['query'],
                        'count' => $query['count'] ?? (100 - $index),
                        'last_searched' => isset($query['last_searched']) ? $query['last_searched'] : now()->subDays(rand(0, 7))
                    ]);
                    $inserted++;
                }
            }

            $this->line("[{$site->tenant_id}] Popular searches sync completed: $inserted queries imported from JSON");
            Log::info("Popular searches sync completed for tenant {$site->tenant_id}: $inserted queries from JSON");

        } catch (\Throwable $e) {
            $this->error("[{$site->tenant_id}] Popular searches sync failed: " . $e->getMessage());
            Log::error("Popular searches sync failed for tenant {$site->tenant_id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add cache buster parameter to URL to prevent caching
     */
    private function addCacheBuster(string $url): string
    {
        $separator = strpos($url, '?') !== false ? '&' : '?';
        return $url . $separator . 'time=' . time();
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSynonymSuggestions extends Command
{
    protected $signature = 'wk:suggest-synonyms {tenant_id} {--limit=1000}';
    protected $description = 'Generate synonym suggestions from logs and product corpus';

    public function handle()
    {
        $tenant = $this->argument('tenant_id');
        $limit = (int)$this->option('limit');

        // Candidates: zero-result queries and frequently corrected queries
        $zero = DB::table('wk_search_analytics')->where('tenant_id',$tenant)->orderByDesc('count')->limit($limit)->pluck('query')->all();

        // Token set from product titles
        $tokens = DB::table('wk_index_products')->where('tenant_id',$tenant)->limit(50000)->pluck('title')->all();
        $vocab = $this->buildVocab($tokens);

        $suggested = 0;
        foreach ($zero as $q) {
            $best = $this->nearestByEditDistance($q, $vocab);
            if ($best && $best['distance'] <= 2) {
                DB::table('wk_synonym_suggestions')->insert([
                    'tenant_id'=>$tenant,
                    'from_term'=>$q,
                    'to_term'=>$best['term'],
                    'score'=> max(0, 2 - $best['distance']) + $best['freq']/100,
                    'status'=>'suggested',
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);
                $suggested++;
            }
        }

        $this->info("Suggested $suggested synonyms.");
        return 0;
    }

    private function buildVocab(array $titles): array
    {
        $freq = [];
        foreach ($titles as $t) {
            foreach (preg_split('/\W+/u', mb_strtolower($t)) as $tok) {
                if (mb_strlen($tok) < 3) continue;
                $freq[$tok] = ($freq[$tok] ?? 0) + 1;
            }
        }
        arsort($freq);
        return $freq; // token => count
    }

    private function nearestByEditDistance(string $term, array $vocab): ?array
    {
        $term = mb_strtolower($term);
        $best = null; $bestD = PHP_INT_MAX;
        foreach ($vocab as $tok => $count) {
            $d = levenshtein($term, $tok);
            if ($d < $bestD) { $bestD = $d; $best = ['term'=>$tok,'freq'=>$count,'distance'=>$d]; }
            if ($bestD === 0) break;
        }
        return $best;
    }
}



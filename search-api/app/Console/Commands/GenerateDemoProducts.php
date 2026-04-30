<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateDemoProducts extends Command
{
    protected $signature = 'demo:seed-products {--tenant=} {--count=300}';
    protected $description = 'Generate demo products into wk_index_products for a tenant';

    public function handle()
    {
        $tenant = $this->option('tenant');
        if (!$tenant) {
            $this->error('Provide --tenant=TENANT_ID');
            return 1;
        }
        $count = (int) $this->option('count');
        $brands = ['Acme','Nimbus','Apex','Zenith','Bolt','Vista'];
        $titles = [
            "Men's Cotton Shirt","Women's Cotton Shirt","Premium T-Shirt","Hoodie Pullover",
            "Running Shoes","Trail Running Shoes","Baseball Cap","Sports Shorts",
            "Training Pants","Compression Shirt","Polo Shirt","Denim Shirt",
            "Flannel Shirt","Sleeveless Top","Long Sleeve Shirt","Graphic Tee"
        ];
        $images = [
            'https://via.placeholder.com/300x300?text=Product',
        ];

        $startId = (int) (DB::table('wk_index_products')->where('tenant_id',$tenant)->max('id') ?? 1000) + 1;
        $inserted = 0;
        for ($i = 0; $i < $count; $i++) {
            $id = $startId + $i;
            $title = $titles[$i % count($titles)];
            $brand = $brands[$i % count($brands)];
            $price = rand(1000, 15000) / 100.0; // 10.00 - 150.00
            $rating = rand(30, 50) / 10.0; // 3.0 - 5.0
            $inStock = (rand(0, 9) > 1) ? 1 : 0; // ~80% in stock
            DB::table('wk_index_products')->updateOrInsert(
                ['tenant_id'=>$tenant,'id'=>$id],
                [
                    'sku' => 'SKU-'.$id,
                    'title' => $title,
                    'slug' => 'demo-'.$id,
                    'url' => 'https://example.local/product/'.$id,
                    'brand' => $brand,
                    'price' => $price,
                    'price_old' => $price + rand(0, 3000)/100.0,
                    'currency' => 'USD',
                    'in_stock' => $inStock,
                    'rating' => $rating,
                    'image' => $images[0],
                    'html' => null,
                    'popularity' => rand(0, 1000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $inserted++;
        }
        $this->info("Inserted/updated $inserted products for tenant $tenant");
        return 0;
    }
}



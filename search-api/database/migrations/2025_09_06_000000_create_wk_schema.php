<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<SQL
CREATE TABLE IF NOT EXISTS `wk_sites` (
  `tenant_id` varchar(50) NOT NULL,
  `site_name` varchar(255) NOT NULL,
  `site_url` varchar(500) NOT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `api_key` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_feed_at` timestamp NULL DEFAULT NULL,
  `feed_frequency` enum('hourly','daily','weekly','monthly') DEFAULT 'hourly',
  `total_products` int(11) DEFAULT 0,
  `last_sync_at` timestamp NULL DEFAULT NULL,
  `settings` json DEFAULT NULL,
  `search_config` json DEFAULT NULL,
  PRIMARY KEY (`tenant_id`),
  UNIQUE KEY `idx_api_key` (`api_key`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_site_url` (`site_url`(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_index_products` (
  `id` bigint(20) NOT NULL,
  `tenant_id` varchar(50) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `price_old` decimal(10,2) DEFAULT 0.00,
  `currency` varchar(3) DEFAULT 'USD',
  `in_stock` tinyint(1) DEFAULT 1,
  `rating` decimal(3,2) DEFAULT 0.00,
  `image` varchar(500) DEFAULT NULL,
  `html` longtext DEFAULT NULL,
  `popularity` int(11) DEFAULT 0,
  `view_count` int(11) DEFAULT 0,
  `cart_count` int(11) DEFAULT 0,
  `purchase_count` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `id`),
  KEY `idx_tenant_brand` (`tenant_id`, `brand`),
  KEY `idx_tenant_price` (`tenant_id`, `price`),
  KEY `idx_tenant_stock` (`tenant_id`, `in_stock`),
  KEY `idx_tenant_popularity` (`tenant_id`, `popularity`),
  KEY `idx_tenant_rating` (`tenant_id`, `rating`),
  FULLTEXT KEY `ft_title_brand` (`title`, `brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_categories` (
  `id` bigint(20) NOT NULL,
  `tenant_id` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `url` varchar(500) DEFAULT NULL,
  `level` int(11) DEFAULT 0,
  `parent_id` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `id`),
  KEY `idx_tenant_slug` (`tenant_id`, `slug`),
  KEY `idx_tenant_parent` (`tenant_id`, `parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_tags` (
  `id` bigint(20) NOT NULL,
  `tenant_id` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `id`),
  KEY `idx_tenant_slug` (`tenant_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_product_categories` (
  `product_id` bigint(20) NOT NULL,
  `category_id` bigint(20) NOT NULL,
  PRIMARY KEY (`product_id`, `category_id`),
  KEY `idx_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_product_tags` (
  `product_id` bigint(20) NOT NULL,
  `tag_id` bigint(20) NOT NULL,
  PRIMARY KEY (`product_id`, `tag_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_product_attributes` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) NOT NULL,
  `attribute_name` varchar(100) NOT NULL,
  `attribute_value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_name_value` (`attribute_name`, `attribute_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(50) NOT NULL,
  `user_id` varchar(64) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `event_data` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_type` (`tenant_id`, `event_type`),
  KEY `idx_tenant_user` (`tenant_id`, `user_id`),
  KEY `idx_tenant_created` (`tenant_id`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_cooc_view` (
  `tenant_id` varchar(50) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `related_product_id` bigint(20) NOT NULL,
  `cooccurrence_score` decimal(8,4) DEFAULT 0.0000,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `product_id`, `related_product_id`),
  KEY `idx_related` (`tenant_id`, `related_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_cooc_buy` (
  `tenant_id` varchar(50) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `related_product_id` bigint(20) NOT NULL,
  `cooccurrence_score` decimal(8,4) DEFAULT 0.0000,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `product_id`, `related_product_id`),
  KEY `idx_related` (`tenant_id`, `related_product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_persona` (
  `tenant_id` varchar(50) NOT NULL,
  `user_id` varchar(64) NOT NULL,
  `bias_data` json DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `user_id`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_search_configs` (
  `tenant_id` varchar(50) NOT NULL,
  `config_key` varchar(50) NOT NULL,
  `config` json DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_synonyms` (
  `tenant_id` varchar(50) NOT NULL,
  `synonym_data` json DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_redirects` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(50) NOT NULL,
  `query` varchar(255) NOT NULL,
  `url` varchar(500) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_tenant_query` (`tenant_id`, `query`),
  KEY `idx_tenant_active` (`tenant_id`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_pins` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(50) NOT NULL,
  `query` varchar(255) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `position` int(11) DEFAULT 1,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_query` (`tenant_id`, `query`),
  KEY `idx_tenant_product` (`tenant_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_bans` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(50) NOT NULL,
  `query` varchar(255) NOT NULL,
  `product_id` bigint(20) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_query` (`tenant_id`, `query`),
  KEY `idx_tenant_product` (`tenant_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_strategies` (
  `tenant_id` varchar(50) NOT NULL,
  `strategy_data` json DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_tenant_settings` (
  `tenant_id` varchar(50) NOT NULL,
  `settings_data` json DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_content` (
  `id` bigint(20) NOT NULL,
  `tenant_id` varchar(50) NOT NULL,
  `type` varchar(50) DEFAULT 'page',
  `title` varchar(255) NOT NULL,
  `content` longtext DEFAULT NULL,
  `url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `id`),
  KEY `idx_tenant_type` (`tenant_id`, `type`),
  FULLTEXT KEY `ft_title_content` (`title`, `content`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_pages` (
  `tenant_id` varchar(50) NOT NULL,
  `page_key` varchar(100) NOT NULL,
  `config` json DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `page_key`),
  KEY `idx_tenant_active` (`tenant_id`, `active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_feed_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(50) NOT NULL,
  `run_type` varchar(20) NOT NULL,
  `is_full` tinyint(1) DEFAULT 0,
  `status` varchar(20) DEFAULT 'queued',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`, `status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_analytics_daily` (
  `tenant_id` varchar(50) NOT NULL,
  `date` date NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `count` int(11) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `date`, `event_type`),
  KEY `idx_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_analytics_hourly` (
  `tenant_id` varchar(50) NOT NULL,
  `date` date NOT NULL,
  `hour` tinyint(4) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `count` int(11) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `date`, `hour`, `event_type`),
  KEY `idx_date_hour` (`date`, `hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_search_analytics` (
  `tenant_id` varchar(50) NOT NULL,
  `query` varchar(255) NOT NULL,
  `count` int(11) DEFAULT 1,
  `last_searched` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `query`),
  KEY `idx_count` (`count`),
  KEY `idx_last_searched` (`last_searched`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wk_facet_counts` (
  `tenant_id` varchar(50) NOT NULL,
  `facet_type` varchar(50) NOT NULL,
  `facet_value` varchar(255) NOT NULL,
  `count` int(11) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tenant_id`, `facet_type`, `facet_value`),
  KEY `idx_count` (`count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<SQL
DROP TABLE IF EXISTS `wk_facet_counts`;
DROP TABLE IF EXISTS `wk_admin_users`;
DROP TABLE IF EXISTS `wk_search_analytics`;
DROP TABLE IF EXISTS `wk_analytics_hourly`;
DROP TABLE IF EXISTS `wk_analytics_daily`;
DROP TABLE IF EXISTS `wk_feed_runs`;
DROP TABLE IF EXISTS `wk_pages`;
DROP TABLE IF EXISTS `wk_content`;
DROP TABLE IF EXISTS `wk_tenant_settings`;
DROP TABLE IF EXISTS `wk_strategies`;
DROP TABLE IF EXISTS `wk_bans`;
DROP TABLE IF EXISTS `wk_pins`;
DROP TABLE IF EXISTS `wk_redirects`;
DROP TABLE IF EXISTS `wk_synonyms`;
DROP TABLE IF EXISTS `wk_search_configs`;
DROP TABLE IF EXISTS `wk_persona`;
DROP TABLE IF EXISTS `wk_cooc_buy`;
DROP TABLE IF EXISTS `wk_cooc_view`;
DROP TABLE IF EXISTS `wk_product_attributes`;
DROP TABLE IF EXISTS `wk_product_tags`;
DROP TABLE IF EXISTS `wk_product_categories`;
DROP TABLE IF EXISTS `wk_tags`;
DROP TABLE IF EXISTS `wk_categories`;
DROP TABLE IF EXISTS `wk_index_products`;
DROP TABLE IF EXISTS `wk_sites`;
SQL);
    }
};



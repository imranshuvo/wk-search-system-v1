<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::unprepared(<<<SQL
CREATE TABLE IF NOT EXISTS `wk_synonym_suggestions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(50) NOT NULL,
  `from_term` varchar(255) NOT NULL,
  `to_term` varchar(255) NOT NULL,
  `score` decimal(5,2) DEFAULT 0.00,
  `status` enum('suggested','approved','rejected') DEFAULT 'suggested',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        DB::unprepared("DROP TABLE IF EXISTS `wk_synonym_suggestions`");
    }
};



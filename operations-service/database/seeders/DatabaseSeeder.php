<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * [BD-0128] Clean Operations Service Database Seeder.
 * Demo outlets, demo registers, demo categories, and sample products removed
 * to ensure completely clean production VPS deployment.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Demo outlets, registers, categories, products, and inventory balances removed.
        // Catalog data is imported or created organically by organization users via POS/inventory.
    }
}

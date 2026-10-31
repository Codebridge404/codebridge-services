<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Outlet & Register First
        $existingOutlet = DB::table('outlets')->where('code', 'PP-01')->first();
        if ($existingOutlet) {
            $outletId = $existingOutlet->id;
        } else {
            $outletId = DB::table('outlets')->insertGetId([
                'name' => 'Phnom Penh Main Outlet',
                'code' => 'PP-01',
                'address' => 'Monivong Blvd, Phnom Penh',
                'phone' => '+855 23 123 456',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existingRegister = DB::table('registers')->where('code', 'REG-01')->first();
        if (!$existingRegister) {
            DB::table('registers')->insert([
                'outlet_id' => $outletId,
                'name' => 'Register #1',
                'code' => 'REG-01',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 2. Resolve Admin User from auth_db
        $adminUser = User::where('email', 'vibolsen2002@gmail.com')->first() ?? User::first();

        // 3. Categories
        $existingBev = DB::table('categories')->where('slug', 'beverages')->first();
        $beveragesId = $existingBev ? $existingBev->id : DB::table('categories')->insertGetId([
            'name' => 'Beverages',
            'slug' => 'beverages',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $existingSnk = DB::table('categories')->where('slug', 'snacks')->first();
        $snacksId = $existingSnk ? $existingSnk->id : DB::table('categories')->insertGetId([
            'name' => 'Snacks',
            'slug' => 'snacks',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Products
        if (!DB::table('products')->where('sku', 'BEV-AME-01')->exists()) {
            DB::table('products')->insert([
                'category_id' => $beveragesId,
                'name' => 'Iced Americano',
                'sku' => 'BEV-AME-01',
                'description' => 'Cold brew iced coffee',
                'cost_price' => 1.20,
                'selling_price' => 2.50,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!DB::table('products')->where('sku', 'BEV-LAT-01')->exists()) {
            DB::table('products')->insert([
                'category_id' => $beveragesId,
                'name' => 'Iced Latte',
                'sku' => 'BEV-LAT-01',
                'description' => 'Espresso with fresh milk',
                'cost_price' => 1.50,
                'selling_price' => 3.00,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!DB::table('products')->where('sku', 'SNK-CRO-01')->exists()) {
            DB::table('products')->insert([
                'category_id' => $snacksId,
                'name' => 'Butter Croissant',
                'sku' => 'SNK-CRO-01',
                'description' => 'Freshly baked croissant',
                'cost_price' => 0.80,
                'selling_price' => 2.00,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 5. Initial Inventory Balances
        $productIds = DB::table('products')->pluck('id')->toArray();
        foreach ($productIds as $productId) {
            $hasBal = DB::table('inventory_balances')->where('product_id', $productId)->exists();
            if (!$hasBal) {
                DB::table('inventory_balances')->insert([
                    'outlet_id' => $outletId,
                    'product_id' => $productId,
                    'on_hand' => 100,
                    'available' => 100,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('inventory_movements')->insert([
                    'outlet_id' => $outletId,
                    'product_id' => $productId,
                    'quantity_change' => 100,
                    'movement_type' => 'receive',
                    'created_by' => $adminUser ? $adminUser->id : 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}

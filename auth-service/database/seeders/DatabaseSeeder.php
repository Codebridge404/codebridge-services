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
        // 0. Seed Granular Permissions, System Roles, Role-Permission Mappings, and Platform Modules
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            RolePermissionSeeder::class,
            PlatformModuleSeeder::class,
        ]);
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

        if (\Illuminate\Support\Facades\Schema::hasTable('registers')) {
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
        }

        // 2. Seed Users across 3 Parent Roles (Super Admin, Organization, Employees)
        $roleMap = DB::table('roles')->pluck('id', 'slug')->toArray();

        $users = [
            [
                'email' => 'codebridge2026@gmail.com',
                'name' => 'CodeBridges',
                'role' => 'super_admin',
                'role_id' => $roleMap['super_admin'] ?? null,
                'password' => Hash::make('Code@2026'),
                'pin_code' => null,
            ],
            [
                'email' => 'admin@pos.com',
                'name' => 'Company Organization Admin',
                'role' => 'organization',
                'role_id' => $roleMap['organization'] ?? ($roleMap['admin'] ?? null),
                'password' => Hash::make('password'),
                'pin_code' => null,
            ],
            [
                'email' => 'cashier@pos.com',
                'name' => 'John Cashier',
                'role' => 'employee',
                'role_id' => $roleMap['cashier'] ?? null,
                'password' => Hash::make('password'),
                'pin_code' => '1234',
            ],
            [
                'email' => 'inventory@pos.com',
                'name' => 'Stock Clerk',
                'role' => 'employee',
                'role_id' => $roleMap['inventory_clerk'] ?? null,
                'password' => Hash::make('password'),
                'pin_code' => null,
            ],
            [
                'email' => 'accountant@pos.com',
                'name' => 'Finance Accountant',
                'role' => 'employee',
                'role_id' => $roleMap['accountant'] ?? null,
                'password' => Hash::make('password'),
                'pin_code' => null,
            ],
            [
                'email' => 'manager@pos.com',
                'name' => 'Store Operations Manager',
                'role' => 'employee',
                'role_id' => $roleMap['outlet_manager'] ?? null,
                'password' => Hash::make('password'),
                'pin_code' => null,
            ],
        ];

        $adminUser = null;
        foreach ($users as $userData) {
            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'role' => $userData['role'],
                    'role_id' => $userData['role_id'],
                    'outlet_id' => $outletId,
                    'pin_code' => $userData['pin_code'],
                    'password' => $userData['password'],
                ]
            );
            if ($userData['role'] === 'super_admin') {
                $adminUser = $user;
            }
        }

        // Seed Tenants & Memberships
        $this->call(TenantSeeder::class);

        // 3. Optional Retail Seeding (Only if legacy retail tables exist)
        if (\Illuminate\Support\Facades\Schema::hasTable('categories') && \Illuminate\Support\Facades\Schema::hasTable('products')) {
            $existingBev = DB::table('categories')->where('slug', 'beverages')->first();
        if ($existingBev) {
            $beveragesId = $existingBev->id;
        } else {
            $beveragesId = DB::table('categories')->insertGetId([
                'name' => 'Beverages',
                'slug' => 'beverages',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existingSnk = DB::table('categories')->where('slug', 'snacks')->first();
        if ($existingSnk) {
            $snacksId = $existingSnk->id;
        } else {
            $snacksId = DB::table('categories')->insertGetId([
                'name' => 'Snacks',
                'slug' => 'snacks',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
}

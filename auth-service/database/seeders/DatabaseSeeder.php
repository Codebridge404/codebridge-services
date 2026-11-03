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

        // 1. Seed ONLY the Platform Super Admin account
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
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'role' => $userData['role'],
                    'role_id' => $userData['role_id'],
                    'outlet_id' => null,
                    'pin_code' => $userData['pin_code'],
                    'password' => $userData['password'],
                ]
            );
        }

        // Demo tenants and demo meetings removed for clean production VPS deployment
    }
}

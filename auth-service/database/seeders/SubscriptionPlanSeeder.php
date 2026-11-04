<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Seed SaaS subscription plans and module bundles.
     */
    public function run(): void
    {
        $now = now();

        $plans = [
            [
                'name' => 'Free Forever Freemium',
                'slug' => 'free_freemium',
                'price' => 0.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features_json' => json_encode([
                    'max_tracked_subscriptions' => 5,
                    'storage_mb' => 25,
                    'renewal_calendar' => true,
                    'webcal_feed' => true,
                    'currencies' => ['USD', 'KHR'],
                ]),
                'max_outlets' => 1,
                'max_registers' => 1,
                'max_users' => 3,
                'is_active' => true,
            ],
            [
                'name' => '14-Day VIP Pro Trial',
                'slug' => 'pro_trial',
                'price' => 0.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features_json' => json_encode([
                    'max_tracked_subscriptions' => 5,
                    'storage_mb' => 50,
                    'renewal_calendar' => true,
                    'webcal_feed' => true,
                    'gl_sync_trial' => true,
                    'cost_splits_trial' => true,
                    'bakong_matching_trial' => true,
                ]),
                'max_outlets' => 1,
                'max_registers' => 1,
                'max_users' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'Pro / Business Runner',
                'slug' => 'business_runner',
                'price' => 29.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features_json' => json_encode([
                    'max_tracked_subscriptions' => 75,
                    'storage_mb' => 1024,
                    'renewal_calendar' => true,
                    'webcal_feed' => true,
                    'gl_sync' => true,
                    'department_cost_splits' => true,
                    'smart_optimization_hub' => true,
                    'contract_notice_windows' => true,
                    'bakong_khqr_matching' => true,
                ]),
                'max_outlets' => 5,
                'max_registers' => 10,
                'max_users' => 25,
                'is_active' => true,
            ],
            [
                'name' => 'Enterprise Corporate',
                'slug' => 'enterprise_org',
                'price' => 79.00,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'features_json' => json_encode([
                    'max_tracked_subscriptions' => -1,
                    'storage_mb' => 10240,
                    'renewal_calendar' => true,
                    'webcal_feed' => true,
                    'gl_sync' => true,
                    'department_cost_splits' => true,
                    'smart_optimization_hub' => true,
                    'contract_notice_windows' => true,
                    'bakong_khqr_matching' => true,
                    'hrm_offboarding_sync' => true,
                    'ai_right_sizing' => true,
                    'outbound_webhooks' => true,
                ]),
                'max_outlets' => 999,
                'max_registers' => 999,
                'max_users' => 9999,
                'is_active' => true,
            ],
        ];

        foreach ($plans as $planData) {
            $existing = DB::table('subscription_plans')->where('slug', $planData['slug'])->first();

            if (!$existing) {
                $planId = DB::table('subscription_plans')->insertGetId(array_merge($planData, [
                    'uuid' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } else {
                $planId = $existing->id;
                DB::table('subscription_plans')->where('id', $planId)->update(array_merge($planData, [
                    'updated_at' => $now,
                ]));
            }

            // Link all active platform modules to Business Runner and Enterprise plans
            if (in_array($planData['slug'], ['business_runner', 'enterprise_org']) && DB::getSchemaBuilder()->hasTable('subscription_plan_modules')) {
                $moduleIds = DB::table('platform_modules')->pluck('id');
                foreach ($moduleIds as $moduleId) {
                    DB::table('subscription_plan_modules')->updateOrInsert(
                        ['plan_id' => $planId, 'module_id' => $moduleId],
                        []
                    );
                }
            }
        }
    }
}


<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentProviderAccountSeeder extends Seeder
{
    /**
     * Seed platform-level payment provider collection accounts.
     */
    public function run(): void
    {
        $now = now();

        $accounts = [
            [
                'name' => 'NBC Bakong KHQR Collection (USD & KHR)',
                'provider' => 'bakong_khqr',
                'merchant_id' => 'codebridge_bakong_merchant',
                'environment' => 'sandbox',
                'api_base_url' => 'https://api-bakong.nbc.gov.kh/v1',
                'api_key_encrypted' => encrypt('bakong_sandbox_sec_key_2026'),
                'rsa_public_key' => "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0u1...\n-----END PUBLIC KEY-----",
                'rsa_private_key_encrypted' => encrypt("-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEA0u1...\n-----END RSA PRIVATE KEY-----"),
                'is_active' => true,
            ],
            [
                'name' => 'Stripe SaaS Subscription Billing',
                'provider' => 'stripe',
                'merchant_id' => 'acct_codebridge_prod',
                'environment' => 'sandbox',
                'api_base_url' => 'https://api.stripe.com/v1',
                'api_key_encrypted' => encrypt('sk_test_codebridge_stripe_key_2026'),
                'rsa_public_key' => null,
                'rsa_private_key_encrypted' => null,
                'is_active' => true,
            ],
            [
                'name' => 'ABA PayWay Merchant Gateway',
                'provider' => 'aba_payway',
                'merchant_id' => 'ec432109',
                'environment' => 'sandbox',
                'api_base_url' => 'https://checkout-sandbox.payway.com.kh/api/webreq',
                'api_key_encrypted' => encrypt('aba_payway_sandbox_key_2026'),
                'rsa_public_key' => null,
                'rsa_private_key_encrypted' => null,
                'is_active' => true,
            ],
        ];

        foreach ($accounts as $acc) {
            $existing = DB::table('payment_provider_accounts')
                ->where('provider', $acc['provider'])
                ->where('merchant_id', $acc['merchant_id'])
                ->where('environment', $acc['environment'])
                ->first();

            if (!$existing) {
                DB::table('payment_provider_accounts')->insert(array_merge($acc, [
                    'uuid' => (string) Str::uuid(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            } else {
                DB::table('payment_provider_accounts')->where('id', $existing->id)->update(array_merge($acc, [
                    'updated_at' => $now,
                ]));
            }
        }
    }
}


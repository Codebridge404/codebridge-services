<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('platform_modules')) {
            Schema::create('platform_modules', function (Blueprint $table) {
                $table->string('id', 64)->primary();
                $table->string('name', 128);
                $table->string('monogram', 8);
                $table->string('category', 64);
                $table->text('description');
                $table->string('icon', 64)->default('Store');
                $table->string('logo_url', 255)->nullable();
                $table->string('href', 255);
                $table->string('bg_pill', 128)->nullable();
                $table->string('card_bg', 255)->nullable();
                $table->boolean('is_core')->default(false);
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
            });

            // Seed initial platform modules
            $now = now();
            $seedModules = [
                [
                    'id'          => 'pos-management',
                    'name'        => 'POS Management',
                    'monogram'    => 'POS',
                    'category'    => 'Core Commerce & Operations',
                    'description' => 'Unified Cashier Terminal, Till Shifts & Float Auditing, Multi-Warehouse Stock, Kitchen Display (KDS) & Customer Display (CFD).',
                    'icon'        => 'Store',
                    'logo_url'    => '/module/pos.svg',
                    'href'        => '/pos/dashboard',
                    'bg_pill'     => 'bg-brand-subtle text-brand',
                    'card_bg'     => 'bg-blue-50/70 border-blue-200/90 hover:border-blue-400',
                    'is_core'     => true,
                    'is_active'   => true,
                    'sort_order'  => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'inventory-suite',
                    'name'        => 'Inventory & Stock Logistics',
                    'monogram'    => 'IV',
                    'category'    => 'Supply Chain & Warehousing',
                    'description' => 'Real-time multi-warehouse stock levels, transfers, safety buffers, supplier purchase orders, and stocktakes.',
                    'icon'        => 'Boxes',
                    'logo_url'    => '/module/invetory.svg',
                    'href'        => '/inventory',
                    'bg_pill'     => 'bg-emerald-50 text-emerald-700',
                    'card_bg'     => 'bg-emerald-50/70 border-emerald-200/90 hover:border-emerald-400',
                    'is_core'     => true,
                    'is_active'   => true,
                    'sort_order'  => 2,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'staff-hrm',
                    'name'        => 'HRM, Staff & Payroll',
                    'monogram'    => 'HR',
                    'category'    => 'Workforce Management',
                    'description' => 'Staff headcount directory, 4-digit POS register PIN quick-switch, timesheets, and payroll.',
                    'icon'        => 'Users',
                    'logo_url'    => '/module/hr.svg',
                    'href'        => '/hrm',
                    'bg_pill'     => 'bg-rose-50 text-rose-600',
                    'card_bg'     => 'bg-rose-50/70 border-rose-200/90 hover:border-rose-400',
                    'is_core'     => false,
                    'is_active'   => true,
                    'sort_order'  => 3,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'crm-loyalty',
                    'name'        => 'Customer CRM & Loyalty',
                    'monogram'    => 'CR',
                    'category'    => 'Customer Growth',
                    'description' => 'Customer contact directory, wholesale B2B contracts, VIP loyalty points, and purchase history.',
                    'icon'        => 'Briefcase',
                    'logo_url'    => '/module/management.svg',
                    'href'        => '/crm',
                    'bg_pill'     => 'bg-blue-50 text-blue-600',
                    'card_bg'     => 'bg-sky-50/70 border-sky-200/90 hover:border-sky-400',
                    'is_core'     => false,
                    'is_active'   => true,
                    'sort_order'  => 4,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'financial-management',
                    'name'        => 'Financial & Accounting',
                    'monogram'    => 'FN',
                    'category'    => 'Financial Operations & Ledgers',
                    'description' => 'General Ledger, Chart of Accounts (COA), Accounts Receivable (AR), Accounts Payable (AP), bank reconciliations, and P&L.',
                    'icon'        => 'DollarSign',
                    'logo_url'    => '/module/financial.svg',
                    'href'        => '/financial',
                    'bg_pill'     => 'bg-emerald-50 text-emerald-700',
                    'card_bg'     => 'bg-teal-50/70 border-teal-200/90 hover:border-teal-400',
                    'is_core'     => false,
                    'is_active'   => true,
                    'sort_order'  => 5,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'bill-subscription',
                    'name'        => 'Bill & SaaS Subscription',
                    'monogram'    => 'BS',
                    'category'    => 'Finance & Billing',
                    'description' => 'Automate recurring customer invoices, subscription quotas, and failed payment retry webhooks.',
                    'icon'        => 'Zap',
                    'logo_url'    => '/module/subscription.svg',
                    'href'        => '/super-admin/finance/billing',
                    'bg_pill'     => 'bg-slate-100 text-slate-900',
                    'card_bg'     => 'bg-purple-50/70 border-purple-200/90 hover:border-purple-400',
                    'is_core'     => false,
                    'is_active'   => true,
                    'sort_order'  => 6,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'meeting-management',
                    'name'        => 'Meeting Management (MMS)',
                    'monogram'    => 'MM',
                    'category'    => 'Corporate Facilities & Space Operations',
                    'description' => 'Corporate boardroom bookings, live attendee RFID check-ins, automated agenda dispatch, and minutes records.',
                    'icon'        => 'CalendarClock',
                    'logo_url'    => null,
                    'href'        => '/meetings',
                    'bg_pill'     => 'bg-indigo-50 text-indigo-700',
                    'card_bg'     => 'bg-indigo-50/70 border-indigo-200/90 hover:border-indigo-400',
                    'is_core'     => false,
                    'is_active'   => true,
                    'sort_order'  => 7,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'kds-kitchen',
                    'name'        => 'Kitchen Display (KDS)',
                    'monogram'    => 'KD',
                    'category'    => 'Kitchen & Fulfillment',
                    'description' => 'Live station order queue, ticket cook timers, bump bar interactions, and order expedition monitoring.',
                    'icon'        => 'UtensilsCrossed',
                    'logo_url'    => null,
                    'href'        => '/pos/kds',
                    'bg_pill'     => 'bg-orange-50 text-orange-700',
                    'card_bg'     => 'bg-orange-50/70 border-orange-200/90 hover:border-orange-400',
                    'is_core'     => false,
                    'is_active'   => true,
                    'sort_order'  => 8,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'cfd-display',
                    'name'        => 'Customer Display (CFD)',
                    'monogram'    => 'CF',
                    'category'    => 'Hardware & Terminal',
                    'description' => 'Customer-facing dynamic cart review, QR payment prompts, tipping interface, and brand promotion banner.',
                    'icon'        => 'Tv',
                    'logo_url'    => null,
                    'href'        => '/pos/cfd',
                    'bg_pill'     => 'bg-sky-50 text-sky-700',
                    'card_bg'     => 'bg-sky-50/70 border-sky-200/90 hover:border-sky-400',
                    'is_core'     => false,
                    'is_active'   => true,
                    'sort_order'  => 9,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
                [
                    'id'          => 'shop-storefront',
                    'name'        => 'Online Storefront',
                    'monogram'    => 'ST',
                    'category'    => 'Digital Commerce',
                    'description' => 'Omnichannel web catalog, live delivery tracker, click & collect dispatch, and direct gateway checkout.',
                    'icon'        => 'ShoppingBag',
                    'logo_url'    => null,
                    'href'        => '/shop',
                    'bg_pill'     => 'bg-pink-50 text-pink-700',
                    'card_bg'     => 'bg-pink-50/70 border-pink-200/90 hover:border-pink-400',
                    'is_core'     => false,
                    'is_active'   => true,
                    'sort_order'  => 10,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ],
            ];

            DB::table('platform_modules')->insert($seedModules);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_modules');
    }
};

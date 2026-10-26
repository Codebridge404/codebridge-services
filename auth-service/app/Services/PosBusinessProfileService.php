<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * [BD-0019] POS Business Operating Profile & Feature Toggles Service.
 *
 * Manages operating business profiles for retail mart, restaurant & F&B dining,
 * café/QSR, and boutique retail.
 */
class PosBusinessProfileService
{
    public const PROFILES = [
        'retail_mart' => [
            'id' => 'retail_mart',
            'title' => 'Retail Mart, Supermarket & Minimart',
            'short_title' => 'Retail Mart / Minimart',
            'subtitle' => 'High-speed barcode checkout, cash drawer kick, and dynamic KHQR customer display.',
            'badge' => 'High Throughput',
            'badge_color' => 'bg-amber-100 text-amber-800 border-amber-200',
            'icon' => 'ShoppingCart',
            'best_for' => 'Minimarts, Supermarkets, Grocery Stores, Convenience Stores & Wholesale Marts',
            'enabled_features' => [
                'Continuous barcode scanning (UPC / EAN / GS1)',
                'Cash drawer RJ11 automatic pulse kick',
                'Customer Facing Display (CFD) with live KHQR',
                'Electronic weighing scale & produce barcodes',
                'Fast cashier register ringing',
            ],
            'hidden_features' => [
                'Tables & Dining Floor Plans (Hidden from sidebar)',
                'Kitchen Display System (KDS tickets hidden)',
                'Course firing & split dining bills',
            ],
            'presets' => [
                'tables_enabled' => false,
                'kds_enabled' => false,
                'cfd_enabled' => true,
                'barcode_continuous_mode' => true,
            ],
        ],
        'restaurant_fnb' => [
            'id' => 'restaurant_fnb',
            'title' => 'Restaurant & F&B Dining',
            'short_title' => 'Restaurant & Bar',
            'subtitle' => 'Multi-zone floor plan, table occupancy tracker, KDS kitchen tickets, and bill splitting.',
            'badge' => 'Hospitality',
            'badge_color' => 'bg-rose-100 text-rose-800 border-rose-200',
            'icon' => 'UtensilsCrossed',
            'best_for' => 'Dine-in Restaurants, Bars, Lounges, Bistros & Food Courts',
            'enabled_features' => [
                'Visual floor plans (Main Dining, Patio, VIP)',
                'Live table occupancy (Vacant -> Dining -> Bill)',
                'Kitchen Display System (KDS) & Chit routing',
                'Course firing (Starters -> Mains -> Desserts)',
                'Split bill by seat, merge tables & tips',
            ],
            'hidden_features' => [
                'Rapid supermarket batch-scan mode',
            ],
            'presets' => [
                'tables_enabled' => true,
                'kds_enabled' => true,
                'cfd_enabled' => true,
                'barcode_continuous_mode' => false,
            ],
        ],
        'cafe_qsr' => [
            'id' => 'cafe_qsr',
            'title' => 'Café, Bakery & Quick-Service (QSR)',
            'short_title' => 'Café & Quick-Service',
            'subtitle' => 'Fast touch tiles with drink modifiers, token pickup buzzer numbers, and barista screen.',
            'badge' => 'Quick Service',
            'badge_color' => 'bg-orange-100 text-orange-800 border-orange-200',
            'icon' => 'Coffee',
            'best_for' => 'Coffee Shops, Bubble Tea, Bakeries, Takeaway Counters & Food Trucks',
            'enabled_features' => [
                'Large touchscreen SKU item tiles',
                'Drink & food modifiers (Less Ice, Oat Milk, Sweetness)',
                'Pickup Token / Buzzer number on receipt',
                'Barista & drink preparation KDS screen',
                'Customer Facing Screen (CFD) with KHQR',
            ],
            'hidden_features' => [
                'Multi-zone dining floor plan (Counter takeaway flow)',
            ],
            'presets' => [
                'tables_enabled' => false,
                'kds_enabled' => true,
                'cfd_enabled' => true,
                'barcode_continuous_mode' => false,
            ],
        ],
        'boutique_retail' => [
            'id' => 'boutique_retail',
            'title' => 'Boutique, Fashion & Specialty Retail',
            'short_title' => 'Boutique & Retail',
            'subtitle' => 'Product variant matrix (Size/Color), barcode tag lookups, and customer VIP loyalty points.',
            'badge' => 'Specialty Retail',
            'badge_color' => 'bg-purple-100 text-purple-800 border-purple-200',
            'icon' => 'Shirt',
            'best_for' => 'Apparel, Clothing, Shoe Stores, Cosmetics, Electronics & Gift Boutiques',
            'enabled_features' => [
                'Product Matrix Variant selector (Size / Color / Style)',
                'Barcode label & tag scanning',
                'Customer Loyalty lookup by phone & points redemption',
                'Store credit refunds & exchanges',
                'Thermal receipt branding',
            ],
            'hidden_features' => [
                'Tables & Dining Floor Plans (Hidden from sidebar)',
                'Kitchen Display System (KDS hidden)',
            ],
            'presets' => [
                'tables_enabled' => false,
                'kds_enabled' => false,
                'cfd_enabled' => true,
                'barcode_continuous_mode' => true,
            ],
        ],
    ];

    /**
     * Get all supported profiles.
     */
    public static function getAllProfiles(): array
    {
        return array_values(self::PROFILES);
    }

    /**
     * Get single profile definition with fallback to retail_mart.
     */
    public static function getProfile(?string $businessType): array
    {
        $type = $businessType && isset(self::PROFILES[$businessType]) ? $businessType : 'retail_mart';
        return self::PROFILES[$type];
    }

    /**
     * Check if a business type ID is valid.
     */
    public static function isValid(string $businessType): bool
    {
        return isset(self::PROFILES[$businessType]);
    }
}

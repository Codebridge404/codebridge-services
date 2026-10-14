<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $outletId = $request->query('outlet_id');

        // 1. Total Sales
        $salesQuery = DB::table('sales')->where('status', 'completed');
        if (!empty($outletId)) {
            $salesQuery->where('outlet_id', $outletId);
        }
        $totalSales = (float) $salesQuery->sum('grand_total');

        // 2. Total Sales Returns
        $totalSalesReturn = (float) DB::table('refunds')->sum('amount');

        // 3. Low Stock Count
        $lowStockQuery = DB::table('inventory_balances')->where('on_hand', '<=', 10);
        if (!empty($outletId)) {
            $lowStockQuery->where('outlet_id', $outletId);
        }
        $lowStockCount = $lowStockQuery->count();

        // 4. Total Customers & Orders Count
        $totalCustomers = DB::table('customers')->count();
        $totalOrders = DB::table('sales')->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'metrics' => [
                    'total_sales' => $totalSales,
                    'total_sales_change' => '+0%',
                    'total_sales_return' => $totalSalesReturn,
                    'total_sales_return_change' => '0%',
                    'total_purchase' => 0.00,
                    'total_purchase_change' => '0%',
                    'total_purchase_return' => 0.00,
                    'total_purchase_return_change' => '0%',
                    'profit' => max(0, $totalSales - $totalSalesReturn),
                    'profit_change' => '0%',
                    'invoice_due' => 0.00,
                    'invoice_due_change' => '0%',
                    'total_expenses' => 0.00,
                    'total_expenses_change' => '0%',
                    'total_payment_returns' => $totalSalesReturn,
                    'total_payment_returns_change' => '0%',
                ],
                'counts' => [
                    'low_stock' => $lowStockCount,
                    'suppliers' => DB::table('suppliers')->count(),
                    'customers' => $totalCustomers,
                    'orders' => $totalOrders,
                ]
            ]
        ]);
    }

    public function widgets(Request $request)
    {
        $outletId = $request->query('outlet_id');

        // 1. Top Selling Products from real sale lines
        $topSellingQuery = DB::table('sale_lines as sl')
            ->join('products as p', 'sl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->select(
                'p.id',
                'p.name',
                'p.sku',
                DB::raw("COALESCE(c.name, 'General') as category"),
                'p.selling_price as price',
                'p.image_url',
                DB::raw('SUM(sl.quantity) as sales_count'),
                DB::raw('SUM(sl.subtotal) as total_revenue'),
                DB::raw('(SELECT COALESCE(on_hand, 0) FROM inventory_balances WHERE product_id = p.id LIMIT 1) as stock_remaining')
            )
            ->groupBy('p.id', 'p.name', 'p.sku', 'c.name', 'p.selling_price', 'p.image_url')
            ->orderByDesc('sales_count')
            ->limit(5);

        $topSelling = $topSellingQuery->get();

        // 2. Low Stock Products
        $lowStockQuery = DB::table('inventory_balances as ib')
            ->join('products as p', 'ib.product_id', '=', 'p.id')
            ->select('p.id', 'p.name', 'p.sku', 'ib.on_hand as in_stock')
            ->where('ib.on_hand', '<=', DB::raw('COALESCE(p.min_reorder_point, 5)'))
            ->orderBy('ib.on_hand', 'asc')
            ->limit(5);

        if (!empty($outletId)) {
            $lowStockQuery->where('ib.outlet_id', $outletId);
        }

        $lowStockProducts = $lowStockQuery->get();

        // 3. Recent Sales / Transactions
        $recentSalesQuery = DB::table('sales as s')
            ->leftJoin('users as u', 's.user_id', '=', 'u.id')
            ->leftJoin('payments as p', 's.id', '=', 'p.sale_id')
            ->select(
                's.id',
                's.receipt_number',
                DB::raw("COALESCE(u.name, 'Walk-in Customer') as customer"),
                's.grand_total',
                's.status',
                DB::raw("COALESCE(p.tender_type, 'cash') as tender_type"),
                's.created_at'
            )
            ->orderByDesc('s.created_at')
            ->limit(10);

        if (!empty($outletId)) {
            $recentSalesQuery->where('s.outlet_id', $outletId);
        }

        $recentSales = $recentSalesQuery->get();

        // 4. Top Customers
        $topCustomers = DB::table('sales as s')
            ->leftJoin('users as u', 's.user_id', '=', 'u.id')
            ->select(
                DB::raw("COALESCE(u.name, 'Walk-in Customer') as name"),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(s.grand_total) as spent')
            )
            ->groupBy('u.name')
            ->orderByDesc('spent')
            ->limit(5)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'top_selling' => $topSelling,
                'low_stock' => $lowStockProducts,
                'recent_sales' => $recentSales,
                'top_customers' => $topCustomers,
            ]
        ]);
    }

    public function charts(Request $request)
    {
        $outletId = $request->query('outlet_id');
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $currentYear = now()->year;

        $salesPurchasesChart = [];
        $salesStatics = [];

        foreach ($months as $idx => $m) {
            $monthNum = $idx + 1;
            $monthSalesQuery = DB::table('sales')
                ->where('status', 'completed')
                ->whereYear('created_at', $currentYear)
                ->whereMonth('created_at', $monthNum);

            if (!empty($outletId)) {
                $monthSalesQuery->where('outlet_id', $outletId);
            }

            $monthSales = (float) $monthSalesQuery->sum('grand_total');

            $salesPurchasesChart[] = [
                'month' => $m,
                'purchases' => 0.00,
                'sales' => $monthSales,
            ];

            $salesStatics[] = [
                'month' => $m,
                'revenue' => $monthSales,
                'expense' => 0.00,
            ];
        }

        // Top categories from real sale_lines join categories
        $categoriesQuery = DB::table('sale_lines as sl')
            ->join('products as p', 'sl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->select(
                DB::raw("COALESCE(c.name, 'General') as name"),
                DB::raw('SUM(sl.subtotal) as sales')
            )
            ->groupBy('c.name')
            ->orderByDesc('sales')
            ->limit(5)
            ->get();

        $totalCatSales = (float) $categoriesQuery->sum('sales');
        $topCategories = $categoriesQuery->map(function ($cat) use ($totalCatSales) {
            return [
                'name' => $cat->name,
                'percentage' => $totalCatSales > 0 ? round(((float)$cat->sales / $totalCatSales) * 100, 1) : 0,
                'sales' => (float) $cat->sales,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => [
                'sales_purchases' => $salesPurchasesChart,
                'sales_statics' => $salesStatics,
                'top_categories' => $topCategories,
            ]
        ]);
    }
}

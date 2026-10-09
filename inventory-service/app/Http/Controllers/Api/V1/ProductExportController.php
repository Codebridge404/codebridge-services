<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductExportController extends Controller
{
    protected function getTenantId(Request $request): ?string
    {
        return $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? null;
    }

    /**
     * Stream CSV export of products catalog with live inventory balances.
     */
    public function export(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $outletId = $request->query('outlet_id', 1);
        $categoryId = $request->query('category_id');
        $brandId = $request->query('brand_id');
        $search = $request->query('q');
        $stockStatus = $request->query('stock_status');

        $query = DB::table('products as p')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('brands as b', 'p.brand_id', '=', 'b.id')
            ->leftJoin('inventory_balances as ib', function ($join) use ($outletId) {
                $join->on('p.id', '=', 'ib.product_id')
                    ->where('ib.outlet_id', '=', $outletId);
            })
            ->where('p.is_active', true);

        if (!empty($tenantId) && $tenantId !== 'all') {
            $hasTenantProducts = DB::table('products')->where('tenant_id', $tenantId)->exists();
            if ($hasTenantProducts) {
                $query->where('p.tenant_id', $tenantId);
            } else {
                $query->where(function ($q) use ($tenantId) {
                    $q->where('p.tenant_id', $tenantId)->orWhereNull('p.tenant_id');
                });
            }
        }

        if (!empty($categoryId) && $categoryId !== 'all') {
            $query->where('p.category_id', $categoryId);
        }

        if (!empty($brandId) && $brandId !== 'all') {
            $query->where('p.brand_id', $brandId);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('p.name', 'like', "%{$search}%")
                  ->orWhere('p.sku', 'like', "%{$search}%")
                  ->orWhere('p.barcode', 'like', "%{$search}%");
            });
        }

        if ($stockStatus === 'in_stock') {
            $query->where(DB::raw('COALESCE(ib.on_hand, 0)'), '>', 0);
        } elseif ($stockStatus === 'low_stock') {
            $query->whereRaw('COALESCE(p.min_reorder_point, 0) > 0 AND COALESCE(ib.on_hand, 0) <= p.min_reorder_point')
                  ->where(DB::raw('COALESCE(ib.on_hand, 0)'), '>', 0);
        } elseif ($stockStatus === 'out_of_stock') {
            $query->where(DB::raw('COALESCE(ib.on_hand, 0)'), '<=', 0);
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="products_catalog_export_' . date('Y-m-d') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $columns = [
            'Name',
            'SKU',
            'Barcode',
            'Category',
            'Brand',
            'Selling Price',
            'Cost Price',
            'Stock On Hand',
            'Min Buffer',
            'Expiry Date',
            'Status',
            'Description',
        ];

        return response()->stream(function () use ($query, $columns) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, $columns);

            $query->select(
                'p.name',
                'p.sku',
                'p.barcode',
                'c.name as category_name',
                'b.name as brand_name',
                'p.selling_price',
                'p.cost_price',
                DB::raw('COALESCE(ib.on_hand, 0) as stock_on_hand'),
                'p.min_reorder_point',
                'p.expiry_date',
                'p.is_active',
                'p.description'
            )
            ->orderBy('p.name', 'asc')
            ->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $p) {
                    fputcsv($handle, [
                        $p->name ?? '',
                        $p->sku ?? '',
                        $p->barcode ?? '',
                        $p->category_name ?? 'General',
                        $p->brand_name ?? '',
                        number_format((float)($p->selling_price ?? 0), 2, '.', ''),
                        number_format((float)($p->cost_price ?? 0), 2, '.', ''),
                        (int)($p->stock_on_hand ?? 0),
                        (int)($p->min_reorder_point ?? 0),
                        $p->expiry_date ? explode(' ', (string)$p->expiry_date)[0] : '',
                        $p->is_active ? 'Active' : 'Inactive',
                        $p->description ?? '',
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}

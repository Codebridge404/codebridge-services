<?php

namespace App\Http\Requests\Checkout;

use App\Traits\ResolvesTenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreCheckoutRequest extends FormRequest
{
    use ResolvesTenantContext;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $tenantId = $this->getTenantId($this, true);

        // 1. Resolve numeric outlet_id scoped to tenant
        $rawOutletId = $this->input('outlet_id') ?? $this->user()?->outlet_id ?? $this->header('X-Outlet-Id');
        $realOutlet = null;
        if (!empty($rawOutletId) && is_numeric($rawOutletId)) {
            $realOutlet = DB::table('outlets')->where('id', (int) $rawOutletId)->first();
            if ($realOutlet) {
                $this->authorizeTenantResource($realOutlet->tenant_id, $this, 'Outlet');
            }
        }
        if (!$realOutlet) {
            $realOutlet = DB::table('outlets')
                ->when($tenantId !== 'all', fn ($q) => $q->where('tenant_id', $tenantId))
                ->first();
        }
        $outletId = $realOutlet ? (int) $realOutlet->id : (is_numeric($rawOutletId) ? (int) $rawOutletId : 1);

        // 2. Resolve numeric register_id scoped to tenant
        $rawRegisterId = $this->input('register_id');
        $realRegister = null;
        if (!empty($rawRegisterId) && is_numeric($rawRegisterId)) {
            $realRegister = DB::table('registers')->where('id', (int) $rawRegisterId)->first();
            if ($realRegister) {
                $this->authorizeTenantResource($realRegister->tenant_id, $this, 'Register');
            }
        }
        if (!$realRegister) {
            $realRegister = DB::table('registers')
                ->when($tenantId !== 'all', fn ($q) => $q->where('tenant_id', $tenantId))
                ->where('outlet_id', $outletId)
                ->first()
                ?? DB::table('registers')
                    ->when($tenantId !== 'all', fn ($q) => $q->where('tenant_id', $tenantId))
                    ->first();
        }
        $registerId = $realRegister ? (int) $realRegister->id : (is_numeric($rawRegisterId) ? (int) $rawRegisterId : 1);

        // 3. Auto-resolve idempotency_key
        $idempotencyKey = $this->input('idempotency_key')
            ?? $this->header('X-Idempotency-Key')
            ?? ('sale-' . time() . '-' . Str::random(8));

        // 4. Normalize tax and discount
        $taxTotal = $this->input('tax_total') ?? $this->input('tax_amount') ?? 0;
        $discountTotal = $this->input('discount_total') ?? $this->input('discount_amount') ?? 0;

        // 5. Normalize items array
        $rawItems = $this->input('items', []);
        $normalizedItems = [];
        if (is_array($rawItems)) {
            foreach ($rawItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $pId = $item['product_id'] ?? $item['id'] ?? null;
                $qty = $item['quantity'] ?? $item['qty'] ?? 1;
                if ($pId !== null) {
                    $normalizedItems[] = [
                        'product_id' => $pId,
                        'variant_id' => $item['variant_id'] ?? null,
                        'quantity' => (float) $qty,
                    ];
                }
            }
        }

        $this->merge([
            'outlet_id' => $outletId,
            'register_id' => $registerId,
            'idempotency_key' => $idempotencyKey,
            'tax_total' => $taxTotal,
            'discount_total' => $discountTotal,
            'items' => $normalizedItems,
        ]);
    }

    public function rules(): array
    {
        return [
            'outlet_id' => ['required', 'integer', 'min:1'],
            'register_id' => ['required', 'integer', 'min:1'],
            'shift_id' => ['nullable', 'integer', 'min:1'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'table_id' => ['nullable', 'integer', 'min:1'],
            'table_name' => ['nullable', 'string', 'max:255'],
            'order_type' => ['nullable', 'string', 'in:dine_in,takeaway,delivery'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.variant_id' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:10000'],
            'tender_type' => ['required', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'in:USD,KHR'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}


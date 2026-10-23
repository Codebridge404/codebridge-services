<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [BD-0007] Customer CRM, Sales Pipeline & Deals Management Controller.
 *
 * @ticket BD-0007
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/CrmController.php
 * @table crm_deals, crm_leads, crm_activities
 * @entry-point GET /api/v1/crm/deals, POST /api/v1/crm/deals, GET /api/v1/crm/pipeline
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * 1. Kanban pipeline stage transitions (lead, proposal, negotiation, won, lost).
 * 2. Deal valuation, probability weighting, and expected close dates.
 * 3. Activity logging (calls, emails, meetings, notes) linked to deals.
 */
class CrmController extends Controller
{
    protected function getTenantId(Request $request): ?string
    {
        return $request->header('X-Tenant-Id')
            ?? $request->user()?->tenant_id
            ?? $request->query('tenant_id')
            ?? null;
    }

    // ==========================================
    // 1. Deals Pipeline
    // ==========================================

    public function deals(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $stage = $request->query('stage');
        $search = $request->query('q');

        $query = DB::table('deals');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        if (!empty($stage) && $stage !== 'all') {
            $query->where('stage', $stage);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%")
                  ->orWhere('owner_name', 'like', "%{$search}%");
            });
        }

        $deals = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $deals,
        ]);
    }

    public function storeDeal(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'value' => 'required|numeric|min:0',
            'stage' => 'nullable|string|in:lead,qualified,proposal,negotiation,won,lost',
            'probability' => 'nullable|integer|min:0|max:100',
            'owner_name' => 'nullable|string|max:255',
            'expected_close_date' => 'nullable|date',
        ]);

        $id = DB::table('deals')->insertGetId([
            'tenant_id' => $tenantId,
            'title' => $validated['title'],
            'company' => $validated['company'] ?? null,
            'value' => $validated['value'],
            'stage' => $validated['stage'] ?? 'lead',
            'probability' => $validated['probability'] ?? 50,
            'owner_name' => $validated['owner_name'] ?? 'Sales Rep',
            'expected_close_date' => $validated['expected_close_date'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $deal = DB::table('deals')->where('id', $id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Deal opportunity created successfully.',
            'data' => $deal,
        ], 201);
    }

    public function updateDeal(Request $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'company' => 'nullable|string|max:255',
            'value' => 'sometimes|numeric|min:0',
            'stage' => 'sometimes|string|in:lead,qualified,proposal,negotiation,won,lost',
            'probability' => 'sometimes|integer|min:0|max:100',
            'owner_name' => 'nullable|string|max:255',
        ]);

        $query = DB::table('deals')->where('id', $id);
        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $validated['updated_at'] = now();
        $query->update($validated);

        $deal = DB::table('deals')->where('id', $id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Deal updated successfully.',
            'data' => $deal,
        ]);
    }

    public function destroyDeal(Request $request, $id)
    {
        $tenantId = $this->getTenantId($request);
        $query = DB::table('deals')->where('id', $id);

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $query->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Deal opportunity removed.',
        ]);
    }

    // ==========================================
    // 2. Inbound Leads
    // ==========================================

    public function leads(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $search = $request->query('q');

        $query = DB::table('leads');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('company', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $leads = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $leads,
        ]);
    }

    public function storeLead(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'score' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        $id = DB::table('leads')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $validated['name'],
            'company' => $validated['company'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'score' => $validated['score'] ?? 'Warm (70)',
            'source' => $validated['source'] ?? 'Web Form',
            'status' => 'new',
            'notes' => $validated['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lead = DB::table('leads')->where('id', $id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Lead created successfully.',
            'data' => $lead,
        ], 201);
    }

    // ==========================================
    // 3. Activity Logs & Touchpoints
    // ==========================================

    public function activities(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $query = DB::table('crm_activities');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $query->where('tenant_id', $tenantId);
        }

        $activities = $query->orderBy('created_at', 'desc')->limit(50)->get();

        return response()->json([
            'status' => 'success',
            'data' => $activities,
        ]);
    }

    public function storeActivity(Request $request)
    {
        $tenantId = $this->getTenantId($request);
        $validated = $request->validate([
            'type' => 'required|string|in:call,email,meeting,note',
            'title' => 'required|string|max:255',
            'contact' => 'nullable|string|max:255',
            'summary' => 'required|string',
        ]);

        $id = DB::table('crm_activities')->insertGetId([
            'tenant_id' => $tenantId,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'contact' => $validated['contact'] ?? null,
            'summary' => $validated['summary'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activity = DB::table('crm_activities')->where('id', $id)->first();

        return response()->json([
            'status' => 'success',
            'message' => 'Touchpoint logged successfully.',
            'data' => $activity,
        ], 201);
    }

    // ==========================================
    // 4. Live CRM KPI Calculations
    // ==========================================

    public function kpis(Request $request)
    {
        $tenantId = $this->getTenantId($request);

        $dealsQuery = DB::table('deals');
        $leadsQuery = DB::table('leads');

        if (!empty($tenantId) && $tenantId !== 'all') {
            $dealsQuery->where('tenant_id', $tenantId);
            $leadsQuery->where('tenant_id', $tenantId);
        }

        $deals = $dealsQuery->get();
        $totalPipelineValue = (float) $deals->where('stage', '!=', 'lost')->sum('value');
        $activeDealsCount = $deals->whereNotIn('stage', ['won', 'lost'])->count();

        $startOfMonth = now()->startOfMonth();
        $dealsWonMtd = (float) $deals->where('stage', 'won')->where('updated_at', '>=', $startOfMonth)->sum('value');
        $wonCount = $deals->where('stage', 'won')->count();

        $totalCompletedDeals = $deals->whereIn('stage', ['won', 'lost'])->count();
        $winRate = $totalCompletedDeals > 0
            ? round(($wonCount / $totalCompletedDeals) * 100, 1)
            : ($deals->count() > 0 ? round(($wonCount / $deals->count()) * 100, 1) : 0);

        $leadsCount = $leadsQuery->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'pipeline_value' => $totalPipelineValue,
                'active_deals_count' => $activeDealsCount,
                'deals_won_mtd' => $dealsWonMtd,
                'won_deals_count' => $wonCount,
                'win_rate' => $winRate,
                'leads_count' => $leadsCount,
            ],
        ]);
    }
}

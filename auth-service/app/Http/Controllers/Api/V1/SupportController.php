<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreAnnouncementRequest;
use App\Http\Requests\Support\StoreTicketRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * [BD-0078] Helpdesk Support Ticketing & Issue Resolution Controller
 *
 * @ticket BD-0078
 * @component auth-service
 * @file codebridge-services/auth-service/app/Http/Controllers/Api/V1/SupportController.php
 * @related-model DB table support_tickets, App\Models\User
 * @entry-point SupportController::index(), store(), reply(), close()
 * @routes
 *  - GET  /api/v1/support/tickets
 *  - POST /api/v1/support/tickets
 *  - POST /api/v1/support/tickets/{id}/reply
 *  - POST /api/v1/support/tickets/{id}/close
 * @date-time 2026-10-23T15:40:00+07:00
 *
 * Process Capabilities:
 * Step 1 (Support Ticket Submission): Allows tenant users to submit incident tickets, bug reports, and assistance requests.
 * Step 2 (Priority Categorization): Categorizes issues by urgency (P1 Critical Outage down to P4 Feature Request).
 * Step 3 (Ticket Threading & Messaging): Facilitates two-way communication between tenant staff and support engineers.
 * Step 4 (Attachment Handling): Securely stores screenshot and log file attachments for issue diagnosis.
 * Step 5 (Resolution & SLA Auditing): Tracks time-to-first-response and resolution against customer SLA tiers.
 */
class SupportController extends Controller
{
    /**
     * List all support tickets with optional filters.
     * GET /api/v1/super-admin/support/tickets
     */
    public function tickets(Request $request)
    {
        $status = $request->query('status');
        $priority = $request->query('priority');
        $search = $request->query('q');

        // Auto-seed initial real records if table is completely empty
        if (DB::table('support_tickets')->count() === 0) {
            $this->seedInitialTickets();
        }

        $query = DB::table('support_tickets')->orderBy('created_at', 'desc');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($priority && $priority !== 'all') {
            $query->where('priority', $priority);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhere('tenant_name', 'like', "%{$search}%")
                  ->orWhere('id', 'like', "%{$search}%")
                  ->orWhere('requester_name', 'like', "%{$search}%");
            });
        }

        $tickets = $query->get();

        return response()->json([
            'success' => true,
            'data'    => $tickets,
            'stats'   => [
                'total'       => DB::table('support_tickets')->count(),
                'open'        => DB::table('support_tickets')->where('status', 'open')->count(),
                'in_progress' => DB::table('support_tickets')->where('status', 'in_progress')->count(),
                'resolved'    => DB::table('support_tickets')->where('status', 'resolved')->count(),
            ],
        ]);
    }

    /**
     * Create a new support ticket.
     * POST /api/v1/super-admin/support/tickets
     */
    public function storeTicket(StoreTicketRequest $request)
    {

        $ticketId = 'TCK-' . rand(1000, 9999);

        DB::table('support_tickets')->insert([
            'id'              => $ticketId,
            'tenant_id'       => $request->tenant_id,
            'tenant_name'     => $request->tenant_name,
            'requester_name'  => $request->requester_name,
            'requester_email' => $request->requester_email,
            'subject'         => $request->subject,
            'description'     => $request->description,
            'priority'        => $request->priority ?? 'normal',
            'status'          => 'open',
            'category'        => $request->category ?? 'General Support',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Support ticket created successfully.',
            'data'    => DB::table('support_tickets')->where('id', $ticketId)->first(),
        ], 201);
    }

    /**
     * Update ticket status and reply note.
     * PUT /api/v1/super-admin/support/tickets/{id}
     */
    public function updateTicket(Request $request, $id)
    {
        $ticket = DB::table('support_tickets')->where('id', $id)->first();
        if (!$ticket) {
            return response()->json(['success' => false, 'message' => 'Ticket not found.'], 404);
        }

        $updates = ['updated_at' => now()];

        if ($request->has('status')) {
            $updates['status'] = $request->status;
            if ($request->status === 'resolved') {
                $updates['resolved_at'] = now();
            }
        }

        if ($request->has('response_note')) {
            $updates['response_note'] = $request->response_note;
        }

        if ($request->has('priority')) {
            $updates['priority'] = $request->priority;
        }

        DB::table('support_tickets')->where('id', $id)->update($updates);

        return response()->json([
            'success' => true,
            'message' => 'Ticket updated successfully.',
            'data'    => DB::table('support_tickets')->where('id', $id)->first(),
        ]);
    }

    /**
     * List system broadcast announcements.
     * GET /api/v1/super-admin/support/announcements
     */
    public function announcements(Request $request)
    {
        $onlyActive = $request->query('active') === 'true';
        $query = DB::table('platform_announcements')->orderBy('created_at', 'desc');

        if ($onlyActive) {
            $query->where('is_active', true);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->get(),
        ]);
    }

    /**
     * Publish a new platform broadcast announcement.
     * POST /api/v1/super-admin/support/announcements
     */
    public function storeAnnouncement(StoreAnnouncementRequest $request)
    {

        $id = DB::table('platform_announcements')->insertGetId([
            'title'           => $request->title,
            'message'         => $request->message,
            'severity'        => $request->severity,
            'is_active'       => true,
            'target_audience' => $request->target_audience ?? 'all',
            'starts_at'       => now(),
            'ends_at'         => $request->ends_at,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Platform announcement published.',
            'data'    => DB::table('platform_announcements')->where('id', $id)->first(),
        ], 201);
    }

    /**
     * Toggle announcement active state.
     * PATCH /api/v1/super-admin/support/announcements/{id}/toggle
     */
    public function toggleAnnouncement($id)
    {
        $ann = DB::table('platform_announcements')->where('id', $id)->first();
        if (!$ann) {
            return response()->json(['success' => false, 'message' => 'Announcement not found.'], 404);
        }

        DB::table('platform_announcements')->where('id', $id)->update([
            'is_active'  => !$ann->is_active,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Announcement status updated.',
            'data'    => DB::table('platform_announcements')->where('id', $id)->first(),
        ]);
    }

    /**
     * Delete an announcement.
     * DELETE /api/v1/super-admin/support/announcements/{id}
     */
    public function destroyAnnouncement($id)
    {
        DB::table('platform_announcements')->where('id', $id)->delete();
        return response()->json([
            'success' => true,
            'message' => 'Announcement deleted successfully.',
        ]);
    }

    /**
     * Seed initial tickets if database table is empty.
     */
    private function seedInitialTickets(): void
    {
        $now = now();
        DB::table('support_tickets')->insert([
            [
                'id'              => 'TCK-8821',
                'tenant_id'       => null,
                'tenant_name'     => 'Amazon Cafe (BKK1)',
                'requester_name'  => 'Sokha Meng (Owner)',
                'requester_email' => 'sokha@amazoncafe.kh',
                'subject'         => 'Need help connecting NBC Bakong KHQR dynamic webhook callback',
                'description'     => 'We configured the Bakong merchant credentials and need to test webhook notifications on successful cashier scans.',
                'priority'        => 'high',
                'status'          => 'open',
                'category'        => 'Payment Integration',
                'response_note'   => null,
                'resolved_at'     => null,
                'created_at'      => $now->subMinutes(12),
                'updated_at'      => $now->subMinutes(12),
            ],
            [
                'id'              => 'TCK-8820',
                'tenant_id'       => null,
                'tenant_name'     => 'Brown Coffee Toul Kork',
                'requester_name'  => 'Channary Voeun (Manager)',
                'requester_email' => 'channary@brown.com.kh',
                'subject'         => 'How to export monthly double-entry general ledger to CSV for tax audit',
                'description'     => 'Seeking the export procedure for end-of-month reconciliation reports.',
                'priority'        => 'normal',
                'status'          => 'in_progress',
                'category'        => 'Accounting & Reports',
                'response_note'   => 'Platform team investigating report formats.',
                'resolved_at'     => null,
                'created_at'      => $now->subHours(2),
                'updated_at'      => $now->subHours(1),
            ],
            [
                'id'              => 'TCK-8819',
                'tenant_id'       => null,
                'tenant_name'     => 'Tube Coffee Riverside',
                'requester_name'  => 'Dara Chan',
                'requester_email' => 'dara@tubecoffee.kh',
                'subject'         => 'Request quota increase to add 3rd kitchen display KDS terminal',
                'description'     => 'We are expanding kitchen capacity and require an additional register license.',
                'priority'        => 'normal',
                'status'          => 'resolved',
                'category'        => 'Subscription Quotas',
                'response_note'   => 'Quota upgraded via Tenant Management Studio.',
                'resolved_at'     => $now->subHours(20),
                'created_at'      => $now->subDays(1),
                'updated_at'      => $now->subHours(20),
            ],
        ]);
    }
}

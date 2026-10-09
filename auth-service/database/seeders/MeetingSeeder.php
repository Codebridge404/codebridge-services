<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MeetingSeeder extends Seeder
{
    /**
     * Run the database seeds for Meeting Management System.
     */
    public function run(): void
    {
        $now = Carbon::now('Asia/Phnom_Penh');

        // 1. Seed Default Conference Rooms
        $rooms = [
            [
                'name' => 'Executive Boardroom A',
                'code' => 'CONF-301',
                'floor' => 'Floor 3',
                'capacity' => 20,
                'amenities' => '4K Dual Displays, Polycom Studio Video Bar, Smart Whiteboard, High-fidelity Mic Array',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Innovation Lab B',
                'code' => 'CONF-204',
                'floor' => 'Floor 2',
                'capacity' => 12,
                'amenities' => '75-inch Smart TV, Wireless Screen Share, Magnetic Whiteboard',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Agile Standup Pod C',
                'code' => 'POD-102',
                'floor' => 'Floor 1',
                'capacity' => 6,
                'amenities' => 'Standing Desk Table, 55-inch Monitor, Logitech MeetUp Webcam',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Client Briefing Suite D',
                'code' => 'CONF-401',
                'floor' => 'Floor 4',
                'capacity' => 14,
                'amenities' => 'Acoustic Soundproofing, 4K HDR Projector, Teleconference System, Mini Bar',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($rooms as $room) {
            DB::table('meeting_rooms')->updateOrInsert(
                ['code' => $room['code']],
                $room
            );
        }

        // Fetch Room IDs
        $roomA = DB::table('meeting_rooms')->where('code', 'CONF-301')->first();
        $roomB = DB::table('meeting_rooms')->where('code', 'CONF-204')->first();
        $roomC = DB::table('meeting_rooms')->where('code', 'POD-102')->first();

        // 2. Seed Sample Meetings for Today & Tomorrow
        $organizer = DB::table('users')->first();
        $organizerId = $organizer ? $organizer->id : 1;

        if ($roomA) {
            $m1Start = $now->copy()->setTime(9, 30);
            $m1End = $now->copy()->setTime(11, 0);

            $m1Id = DB::table('meetings')->insertGetId([
                'room_id' => $roomA->id,
                'organizer_id' => $organizerId,
                'title' => 'Executive Strategic Roadmap & Q4 Targets',
                'description' => 'Review quarterly corporate OKRs, cross-department resource allocations, and international expansion milestones.',
                'start_time' => $m1Start,
                'end_time' => $m1End,
                'status' => 'scheduled',
                'meeting_type' => 'review',
                'meeting_link' => 'https://meet.codebridges.io/exec-q4-roadmap',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('meeting_attendees')->insert([
                [
                    'meeting_id' => $m1Id,
                    'user_id' => $organizerId,
                    'name' => $organizer ? $organizer->name : 'Platform Admin',
                    'email' => $organizer ? $organizer->email : 'admin@codebridges.io',
                    'role' => 'organizer',
                    'rsvp_status' => 'accepted',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'meeting_id' => $m1Id,
                    'user_id' => null,
                    'name' => 'Sarah Connor (COO)',
                    'email' => 'sarah@codebridges.io',
                    'role' => 'attendee',
                    'rsvp_status' => 'accepted',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            DB::table('meeting_minutes')->insert([
                'meeting_id' => $m1Id,
                'recorded_by' => $organizerId,
                'content' => "## Executive Meeting Objectives\n\n1. Confirm Q4 budget and department quotas.\n2. Finalize deployment timeline for Meeting Management System.\n3. Align marketing launch across Southeast Asia.",
                'action_items' => json_encode([
                    ['task' => 'Deliver cloud infra cost projection by Friday', 'assignee' => 'DevOps Lead', 'status' => 'in_progress'],
                    ['task' => 'Review tenant onboarding legal disclaimer', 'assignee' => 'Legal Team', 'status' => 'pending'],
                ]),
                'status' => 'published',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($roomB) {
            $m2Start = $now->copy()->setTime(14, 0);
            $m2End = $now->copy()->setTime(15, 30);

            DB::table('meetings')->insert([
                'room_id' => $roomB->id,
                'organizer_id' => $organizerId,
                'title' => 'Design System 2.0 & Component Token Review',
                'description' => 'Fine-tuning responsive layouts, glassmorphism tokens, and micro-interactions for modern web clients.',
                'start_time' => $m2Start,
                'end_time' => $m2End,
                'status' => 'scheduled',
                'meeting_type' => 'internal',
                'meeting_link' => 'https://meet.codebridges.io/design-tokens-sync',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($roomC) {
            $m3Start = $now->copy()->setTime(16, 0);
            $m3End = $now->copy()->setTime(16, 30);

            DB::table('meetings')->insert([
                'room_id' => $roomC->id,
                'organizer_id' => $organizerId,
                'title' => 'Daily Engineering Standup',
                'description' => 'Fast blocker check-ins, sprint velocity tracking, and PR merge coordination.',
                'start_time' => $m3Start,
                'end_time' => $m3End,
                'status' => 'scheduled',
                'meeting_type' => 'internal',
                'meeting_link' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

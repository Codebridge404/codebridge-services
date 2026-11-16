# [Folder 2] Database Schema & Entity-Relationship Diagrams (ERD)

Please see the comprehensive database reference:
👉 **[02_mms_database_schema_and_erd.md](./02_mms_database_schema_and_erd.md)**

### Architecture Sections & 21 Tables Overview:

1. **MEETINGS & RESOURCE ALLOCATION (10 Tables)**:
   - `meetings` (Core Booking Engine)
   - `meeting_series` (Recurrence Engine)
   - `meeting_rooms` (Facility Fleet)
   - `meeting_room_displays` (Tablet Kiosk Hardware)
   - `meeting_attendees` (RSVP & Presence)
   - `meeting_attachments` (Document Repository with Duplicate Upload Prevention)
   - `meeting_minutes` (MoM & Decision Governance)
   - `meeting_action_items` (Post-Meeting Follow-ups)
   - `meeting_feedback` (Post-Session Star Ratings)
   - `meeting_catering_orders` (Catering & Refreshments)

2. **MEETINGS · ISSUES, STAFF & RESOURCES (5 Tables)**:
   - `meeting_materials` (AV & Logistics Equipment Inventory)
   - `meeting_material_allocations` (Usage & Return Tracking)
   - `meeting_staff` (Support Technician & Coordinator Profiles)
   - `meeting_staff_allocations` (Duty Shift Assignments)
   - `meeting_room_issues` (Facility Defect Tracker)

3. **MEETINGS · TELEGRAM & NOTIFICATIONS (6 Tables)**:
   - `meeting_telegram_configs` (Organization Bot Provider & Preferences)
   - `meeting_telegram_groups` (Multi-Group Connected Channels & Ownership Verification)
   - `meeting_telegram_routes` (Optional Granular Event-to-Group Routing)
   - `meeting_notification_templates` (Parameterized Reusable Alert Templates)
   - `meeting_notifications` (Durable Outbox Delivery Engine)
   - `meeting_system_settings` (Key-Value Preferences & Business Rule Constraints)

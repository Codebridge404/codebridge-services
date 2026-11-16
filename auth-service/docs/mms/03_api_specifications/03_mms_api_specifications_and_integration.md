# Volume 3: API Specifications & Open Group Integration
## CodeBridge Meeting Management System (MMS)

> **Document ID**: `MMS-ARCH-VOL-03`  
> **Status**: Approved for Production  
> **Target Audience**: Frontend Engineers, Third-Party Integrators, QA Engineers  
> **Base URL**: `http://localhost:8080/api/v1` (via API Gateway) or `/api/v1` (Internal)  
> **Specification Format**: RESTful JSON API  
> **Last Updated**: November 2026  

---

## 1. Global Request & Response Protocols

All MMS endpoints are routed through the central Nginx API Gateway and authenticated by `codebridge-auth-service`.

### Standard Headers
```http
Authorization: Bearer <JWT_ACCESS_TOKEN>
X-App-Key: <APPLICATION_CLIENT_KEY>
Content-Type: application/json
Accept: application/json
X-Request-ID: <UUID_CORRELATION_ID> (Optional, generated if omitted)
```

### Unified Response Envelope
Every endpoint returns a consistent JSON envelope:

```json
{
  "status": "success",
  "data": { ... },
  "message": "Operation completed successfully",
  "request_id": "b3f07a62-4b2a-4bc4-9d10-85f29910cf90"
}
```

### Standard Error Envelope
```json
{
  "status": "error",
  "code": "MEETING_COLLISION",
  "message": "The selected room is already booked between 14:00 and 15:00.",
  "errors": {
    "start_time": ["Time slot overlaps with 'Quarterly Strategy Review' (14:00 - 15:00)"]
  },
  "request_id": "b3f07a62-4b2a-4bc4-9d10-85f29910cf90"
}
```

---

## 2. API Catalog by Open Group Tags

The MMS API is organized into **9 Open Group Tags**:

```
MMS API Suite (38 Endpoints)
├── Tag 1: Meeting               (Core Scheduling, Lifecycle, Check-in, ICS, Attachments)
├── Tag 2: MeetingRoom           (Boardroom Fleet CRUD & Capacity)
├── Tag 3: MeetingActionItem     (Post-Meeting Tasks & RSVPs)
├── Tag 4: MeetingIssue          (Facility Defect Reporting)
├── Tag 5: MeetingLogistics      (AV Equipment & Specialist Staff)
├── Tag 6: MeetingMinutes        (MoM Notes & Formal Approvals)
├── Tag 7: MeetingNotification   (In-App Alerts & Read States)
├── Tag 8: MeetingSetting        (Turnover Buffers & System Rules)
└── Tag 9: MeetingTelegram       (Telegram Bot Alerts & Dynamic Templates)
```

---

### Tag 1: `Meeting` (Core Booking & Lifecycle)

#### 1.1 `GET /api/v1/meetings/stats`
Fetches executive summary metrics for today's meetings.
* **Roles**: `super_admin`, `organization`, `admin`, `staff`
* **Response `data`**:
  ```json
  {
    "today_meetings_count": 14,
    "active_meetings_count": 3,
    "pending_approvals_count": 2,
    "utilization_rate_percent": 68.5,
    "total_hours_booked_today": 26.5
  }
  ```

#### 1.2 `GET /api/v1/meetings`
Lists meeting bookings with multi-parameter filtering.
* **Query Parameters**:
  * `room_id` (integer, optional): Filter by room ID.
  * `status` (string, optional): `scheduled`, `in_progress`, `completed`, `cancelled`.
  * `start_date` (string `YYYY-MM-DD`, optional): Range filter start.
  * `end_date` (string `YYYY-MM-DD`, optional): Range filter end.
  * `search` (string, optional): Search by meeting title or description.

#### 1.3 `POST /api/v1/meetings`
Creates a single meeting reservation or initiates a recurring meeting series.
* **Request Body**:
  ```json
  {
    "room_id": 2,
    "title": "Board of Directors Strategy Session",
    "description": "Quarterly portfolio alignment and budget sign-off.",
    "start_time": "2026-11-20 10:00:00",
    "end_time": "2026-11-20 12:00:00",
    "buffer_minutes": 10,
    "requires_approval": false,
    "attendees": [
      {"name": "Sophea Chan", "email": "sophea@codebridge.kh", "role": "organizer"},
      {"name": "Dara Roth", "email": "dara@codebridge.kh", "role": "presenter"}
    ],
    "materials": [
      {"material_id": 1, "quantity": 1}
    ]
  }
  ```
* **Validation**: Validates room existence, verifies no overlapping bookings within `start_time` &minus; buffer and `end_time` + buffer.
* **Response Status**: `201 Created`

#### 1.4 `POST /api/v1/meetings/{id}/check-in`
Registers physical presence in the room (via Kiosk tablet or mobile portal).
* **Route**: `POST /api/v1/meetings/44/check-in`
* **Effect**: Sets `checked_in_at = now()`, `status = 'in_progress'`, cancels the ghost-meeting auto-release timer.

#### 1.5 `PATCH /api/v1/meetings/{id}/end-early`
Frees the conference room ahead of scheduled completion.
* **Route**: `PATCH /api/v1/meetings/44/end-early`
* **Effect**: Updates `end_time = now()`, changes status to `'completed'`, releases allocated materials, immediately marks room `available` for other teams.

#### 1.6 `GET /api/v1/meetings/{id}/ics`
Generates and downloads an RFC 5545-compliant `.ics` iCalendar file for Google Calendar, Apple Calendar, and Outlook sync.
* **Response Content-Type**: `text/calendar; charset=utf-8`

---

### Tag 2: `MeetingRoom` (Room Fleet Management)

#### 2.1 `GET /api/v1/meeting-rooms`
Lists all meeting rooms with live occupancy status and hardware amenities.
* **Response Sample**:
  ```json
  {
    "data": [
      {
        "id": 1,
        "uuid": "4fa9203e-826d-4720-9da2-a39626b916ce",
        "name": "Grand Mekong Boardroom",
        "code": "BD-401",
        "floor": "4th Floor, Executive Wing",
        "capacity": 24,
        "amenities": ["video_conferencing", "dual_displays", "digital_whiteboard", "crestron_audio"],
        "status": "available",
        "color": "#2563EB"
      }
    ]
  }
  ```

#### 2.2 `POST /api/v1/meeting-rooms`
Registers a new meeting room into the facility fleet.
* **Roles Required**: `super_admin`, `organization`, `admin`
* **Request Body**:
  ```json
  {
    "name": "Tonle Sap Huddle 1",
    "code": "HD-201",
    "floor": "2nd Floor",
    "capacity": 6,
    "amenities": ["tv_display", "hdmi_plug", "whiteboard"],
    "color": "#10B981"
  }
  ```

---

### Tag 3: `MeetingActionItem` (Post-Meeting Follow-ups)

#### 3.1 `GET /api/v1/meetings/{id}/actions`
Retrieves all action items and tasks generated during a meeting.

#### 3.2 `POST /api/v1/meetings/{id}/actions`
Assigns a follow-up action item to an attendee.
* **Request Body**:
  ```json
  {
    "assignee_id": 5,
    "title": "Submit Q4 Budget Forecast Spreadsheet",
    "description": "Incorporate feedback from Executive VP into column F.",
    "due_date": "2026-11-28",
    "priority": "high"
  }
  ```

#### 3.3 `PATCH /api/v1/meetings/actions/{id}/status`
Updates task progress.
* **Request Body**: `{"status": "in_progress"}` or `{"status": "completed"}`

---

### Tag 4: `MeetingIssue` (Facility Defect Reporting)

#### 4.1 `POST /api/v1/meeting-rooms/{roomId}/issues`
Submits a facility or equipment defect ticket.
* **Request Body**:
  ```json
  {
    "issue_type": "projector_failure",
    "priority": "urgent",
    "description": "Ceiling HDMI cable is frayed; projector flickering every 30 seconds."
  }
  ```
* **Effect**: Automatically alerts facilities team and flags the room status if priority is `critical`.

#### 4.2 `PATCH /api/v1/meetings/issues/{id}/status`
Marks defect resolution (`reported` &rarr; `investigating` &rarr; `in_progress` &rarr; `resolved`).

---

### Tag 5: `MeetingLogistics` (Equipment & Support Staff)

#### 5.1 `GET /api/v1/meetings/materials`
Lists inventory of shareable AV hardware assets and real-time available quantities.

#### 5.2 `POST /api/v1/meetings/materials`
Adds new AV hardware into the inventory (e.g., "Owl Labs 360 Camera", "Shure Wireless Mic Kit").

#### 5.3 `GET /api/v1/meetings/staff` & `POST /api/v1/meetings/staff`
Manages certified technical support staff (AV Technicians, Scribes, Simultaneous Translators).

---

### Tag 6: `MeetingMinutes` (MoM & Formal Sign-offs)

#### 6.1 `GET /api/v1/meetings/{id}/minutes`
Fetches full Minutes of Meeting (MoM) transcript, discussion notes, and formal resolutions.

#### 6.2 `POST /api/v1/meetings/{id}/minutes`
Drafts or updates MoM documentation.
* **Request Body**:
  ```json
  {
    "notes": "## Discussion Points\n1. Revenue goals met at 112%.\n2. Expansion to Siem Reap approved.",
    "decisions": [
      {"decision": "Approve $50,000 infrastructure expansion budget", "unanimous": true},
      {"decision": "Hire 2 additional senior Flutter engineers", "unanimous": true}
    ],
    "status": "submitted"
  }
  ```

---

### Tag 7: `MeetingNotification` (In-App Activity Stream)

#### 7.1 `GET /api/v1/meetings/notifications`
Fetches current user's unread meeting notifications.

#### 7.2 `PATCH /api/v1/meetings/notifications/{id}/read`
Marks single notification as read.

#### 7.3 `POST /api/v1/meetings/notifications/mark-all-read`
Dismisses all unread meeting alert badges for the authenticated user.

---

### Tag 8: `MeetingSetting` (Governance & System Rules)

#### 8.1 `GET /api/v1/meetings/system-settings`
Lists dynamic organizational configuration parameters.
* **Default Settings**:
  * `auto_release_unattended_minutes`: `15`
  * `default_turnover_buffer_minutes`: `5`
  * `max_advance_booking_days`: `90`
  * `allow_ad_hoc_kiosk_booking`: `true`

#### 8.2 `PUT /api/v1/meetings/system-settings/batch`
Updates organizational rules in batch.
* **Roles**: `super_admin`, `organization`, `admin`

---

### Tag 9: `MeetingTelegram` (Bot Alerts & Templates)

#### 9.1 `GET /api/v1/meetings/telegram/status`
Checks Telegram bot webhook connectivity, bot handle (`@MMS_Meeting_Alert_Bot`), and channel pairing state.

#### 9.2 `PUT /api/v1/meetings/telegram/config`
Updates Telegram Bot API Token and destination group chat ID.
* **Request Body**:
  ```json
  {
    "bot_token": "8123456789:AAH...example_bot_token",
    "chat_id": "-1002345678901",
    "is_active": true,
    "notify_on_create": true,
    "notify_on_cancel": true,
    "notify_reminder": true,
    "reminder_minutes_before": 15
  }
  ```

#### 9.3 `POST /api/v1/meetings/telegram/test`
Dispatches an immediate diagnostic test message to verify Telegram credentials and channel permissions.

#### 9.4 `GET /api/v1/meetings/telegram/templates` & `PUT /templates/{type}`
Customizes dynamic notification templates with variable substitution placeholders:
`{meeting_title}`, `{room_name}`, `{start_time}`, `{end_time}`, `{organizer_name}`.

---

## 3. Standard HTTP Status & Error Codes

| Status Code | Code String | Description / Cause |
| :--- | :--- | :--- |
| `200 OK` | `SUCCESS` | Request succeeded. |
| `201 Created` | `CREATED` | Meeting or resource successfully created. |
| `400 Bad Request` | `INVALID_INPUT` | Validation failure (e.g. `start_time` after `end_time`). |
| `401 Unauthorized` | `UNAUTHENTICATED` | Missing or expired JWT / invalid `X-App-Key`. |
| `403 Forbidden` | `ACCESS_DENIED` | User role lacks permission to manage room or setting. |
| `409 Conflict` | `MEETING_COLLISION` | Room is already reserved by another meeting in this time window. |
| `422 Unprocessable`| `VALIDATION_FAILED`| Required parameters missing or capacity exceeded. |
| `500 Server Error` | `INTERNAL_ERROR` | Database connection error or unexpected exception. |

---

## Next Steps & Cross-References
* For real-world collision prevention algorithms, auto-release timers, and Telegram bot delivery logic, see **[Volume 4: Workflows, Security & Notifications](../04_workflows_security_notifications/04_mms_workflows_security_and_notifications.md)**.

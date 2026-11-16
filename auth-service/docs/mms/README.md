# CodeBridge — Meeting Management System (MMS)
## Enterprise Architecture & Technical Specification Suite

> **System Domain**: Enterprise Boardroom Scheduling, Digital Door Tablet Displays (Kiosk Mode), AV Logistics Allocation, Post-Meeting Minutes (MoM), Action Items, and Automated Telegram Alerts  
> **Service Owner**: `codebridge-auth-service` (PHP 8.4-FPM / Laravel 12)  
> **API Gateway**: `codebridge-api-gateway` (Nginx, Port 8080)  
> **Database Engine**: MySQL 8.4 (`codebridge-auth-mysql`, Port 3307)  
> **Frontend Engine**: Next.js 16 (React 19, Port 3000)  
> **Version**: 1.0.0 (Production Release Edition)  

---

## Documentation Structure (Folders 1 to 4)

This architectural documentation suite is partitioned into 4 distinct technical volumes:

```
docs/mms/
├── README.md                                          # Master Navigation & Architecture Index (This Document)
│
├── 01_system_architecture/                            # [FOLDER 1] Architecture, Topology & Components
│   └── 01_mms_system_overview_and_topology.md         # High-level architecture, network topology, microservices
│
├── 02_database_schema_and_erd/                        # [FOLDER 2] Database Models & Entity Relationships
│   └── 02_mms_database_schema_and_erd.md              # 17 DB tables, Mermaid ERD, indexes, foreign keys, lifecycle states
│
├── 03_api_specifications/                             # [FOLDER 3] REST API & Open Group Specifications
│   └── 03_mms_api_specifications_and_integration.md   # 9 Open Group tags, 38 REST endpoints, payloads, response schemas
│
└── 04_workflows_security_notifications/               # [FOLDER 4] Business Workflows, Security & Telegram Bot
    └── 04_mms_workflows_security_and_notifications.md # Collision detection, ghost-meeting auto-release, MoM flow, Telegram bot
```

---

## Architectural Highlights

1. **Zero Double-Booking Guarantee**:
   Atomic server-side collision validation with configurable turnover sanitation buffers (e.g., 5-minute cleanup/reset window between consecutive meetings).

2. **Automated Ghost-Meeting Auto-Release**:
   Intelligent auto-release cron algorithm (`php artisan schedule:run`) automatically freeing reserved boardrooms if no participant checks in within 15 minutes of scheduled start.

3. **Digital Door Sign Tablet Kiosk Mode (`/meetings/room-display/[roomId]`)**:
   Dedicated low-latency digital signage for conference room tablets featuring live countdown timers, 1-tap ad-hoc reservations, and QR check-in codes.

4. **Bi-Directional Logistics & AV Asset Allocation**:
   Integrated scheduling of physical hardware (microphones, webcams, projectors, interactive whiteboards) and certified specialist staff (AV technicians, facilitators).

5. **Minutes of Meeting (MoM) & Action Item Distribution**:
   Full post-meeting governance workflow: agenda &rarr; minutes drafting &rarr; executive approval &rarr; action item assignment with due dates and real-time status tracking.

6. **Real-Time Telegram Bot Notification Engine (`@MMS_Meeting_Alert_Bot`)**:
   Dynamic templating engine with Mustache-style variable substitution (`{meeting_title}`, `{room_name}`, `{start_time}`, `{organizer}`) dispatching immediate event alerts.

7. **Strict Multi-Tenant Isolation & Audit Trail**:
   Every database query is tenant-scoped via `tenant_id`. Every state change is registered in `audit_logs` and every access anomaly is recorded in `security_events`.

---

## Quick Navigation

* 📖 **[Folder 1: System Overview & Architecture](./01_system_architecture/01_mms_system_overview_and_topology.md)**
* 🗄️ **[Folder 2: Database Schema & ERD](./02_database_schema_and_erd/02_mms_database_schema_and_erd.md)**
* 🔌 **[Folder 3: API Specifications & Integration](./03_api_specifications/03_mms_api_specifications_and_integration.md)**
* 🛡️ **[Folder 4: Workflows, Security & Notifications](./04_workflows_security_notifications/04_mms_workflows_security_and_notifications.md)**

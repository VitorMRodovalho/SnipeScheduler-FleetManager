# SnipeScheduler FleetManager

**v2.0.0** — A comprehensive fleet vehicle management system built on top of [Snipe-IT](https://snipeitapp.com/), designed for enterprise fleet operations with reservation scheduling, maintenance tracking, compliance management, and multi-entity fleet partitioning.

## Why This Project?

This project was born from a real need in **large-scale infrastructure programs**. The challenge was managing diverse assets on a single platform: not just IT equipment, but **fleet vehicles** used by construction management, PMO staff, and field personnel across multiple project areas.

### The Problem

* Snipe-IT excels at IT asset management but lacks reservation/scheduling capabilities
* Fleet vehicles require advance booking, check-out/check-in workflows, and inspection tracking
* Paper-based vehicle check-out processes were inefficient and lacked governance
* No single platform to manage both IT assets and fleet vehicles
* Multi-entity programs need fleet partitioning — each organization sees only its own vehicles

### The Solution

**SnipeScheduler FleetManager** extends Snipe-IT with a user-friendly frontend specifically designed for fleet operations:

* Uses Snipe-IT's API as the **single source of truth** for all asset data
* Adds reservation scheduling with approval workflows
* Implements configurable vehicle inspection checklists (quick/full/off modes)
* Provides maintenance tracking integrated with Snipe-IT custom fields
* Supports multi-entity fleet partitioning with auto-detection
* Maintains full audit trail and compliance reporting
* API resilience with exponential backoff retry on 429/5xx responses

## Features

### For Drivers

* **Book Vehicles** - Reserve vehicles in advance with pickup/return times
* **Mobile-Friendly** - Responsive design with QR code scanning
* **Configurable Vehicle Inspection** - Quick mode (4 high-level categories), Full mode (50-item detailed checklist with "All OK" quick-fill), or Off. Configurable by Fleet Admin
* **Photo Upload** - Optional camera-first photo capture during checkout and checkin for vehicle condition documentation
* **Digital Inspections** - Complete checkout/checkin forms on any device
* **Email & Teams Notifications** - Confirmation, reminders, and approvals via email or Microsoft Teams

### For Fleet Staff

* **Approval Workflow** - Review and approve/reject reservations
* **Vehicle Management** - Track all fleet vehicles and their status
* **Maintenance Tracking** - Flag issues, track maintenance history
* **Training Expiration Tracking** - Color-coded driver training status (green/yellow/red), date picker for completion dates, weekly expiration alerts via CRON
* **Reports** - Utilization, compliance, and usage reports with Chart.js visualizations

### For Administrators

* **Full Configuration** - OAuth authentication, SMTP, custom fields
* **Multi-Entity Fleet Management** - Company-based vehicle partitioning with auto-detect. When multiple companies exist in Snipe-IT, vehicles are filtered by the user's assigned company. Configurable modes: Auto-detect, Always On, Always Off
* **Corporate Theming** - Full CSS variable system with configurable primary color palette. Set your brand color in Settings and the entire UI adapts
* **Inspection Configuration** - Choose between Quick (4 categories), Full (50-item checklist), or Off inspection modes
* **Photo Upload Toggle** - Enable or disable optional photo capture during checkout/checkin
* **Notification Controls** - Configure per-event channels (Email/Teams/Both/Off)
* **CRON Health Monitoring** - Sync staleness detection with configurable alert thresholds. Security Dashboard shows last sync time, asset count, and health status
* **Startup Validation Script** - Run `php scripts/validate_snipeit.php` to verify all required groups, statuses, fields, and API connectivity
* **Announcements** - Display system-wide notices to users
* **Security Dashboard** - Monitor backup status, security checks, and CRON sync health
* **Booking Rules** - Set booking rules (min notice, max duration, blackouts, training requirements)
* **Activity Log** - Full audit trail of all system actions
* **API Resilience** - Exponential backoff retry on 429 (rate limit) and 5xx (server error) API responses

## Architecture

```
+-------------------------------------------------------------+
|                 SnipeScheduler FleetManager                  |
|  +-----------+  +-----------+  +---------------------+      |
|  |  Drivers  |  |Fleet Staff|  |    Fleet Admin      |      |
|  | (Group 2) |  | (Group 3) |  |    (Group 4)        |      |
|  +-----+-----+  +-----+-----+  +----------+----------+      |
|        |               |                   |                 |
|        +---------------+-------------------+                 |
|                        |                                     |
|        +---------------v-----------------+                   |
|        |    SnipeScheduler PHP App       |                   |
|        |  - Reservation Management       |                   |
|        |  - Inspection Forms             |                   |
|        |  - Approval Workflow            |                   |
|        |  - Email & Teams Notifications  |                   |
|        |  - Multi-Entity Filtering       |                   |
|        |  - Photo Storage                |                   |
|        +---------------+-----------------+                   |
|                        | API Calls (w/ retry)                |
+------------------------+------------------------------------+
                         |
+------------------------v------------------------------------+
|                      Snipe-IT                                |
|   - Asset Database (Source of Truth)                         |
|   - User Management & Groups                                |
|   - Company Assignments (Multi-Entity)                       |
|   - Custom Fields (Mileage, VIN, Maintenance)               |
|   - Status Labels (Available, Reserved, In Service)          |
|   - Locations (Pickup Points, Destinations)                  |
+--------------------------------------------------------------+
```

## Permission Model

Permissions are managed through **Snipe-IT Groups**. Notification recipients are resolved dynamically from group membership — no hardcoded email lists.

| Group ID | Group Name | Capabilities |
| --- | --- | --- |
| 1 | Admins | Full system access including Settings |
| 2 | Drivers | Book vehicles, view own reservations |
| 3 | Fleet Staff | Approve reservations, manage maintenance |
| 4 | Fleet Admin | All staff permissions + user management |

> **Multi-Entity Fleet:** When multi-entity mode is active, Drivers and Fleet Staff see only vehicles belonging to their assigned company. Fleet Admin and Super Admin always see all companies (full fleet visibility). Users with no company assigned see all vehicles (backward compatible).

Group IDs, status IDs, custom field names, and location parent IDs are all configurable in `config.php`.

## Screenshots

### Login

![Login](docs/screenshots/login.png)

*Secure authentication via Microsoft OAuth or Google Sign-In. Users are automatically assigned permissions based on their Snipe-IT group membership.*

---

### Home

![Home](docs/screenshots/index.png)

*Landing page with quick access to key actions and system overview.*

---

### Dashboard

![Dashboard](docs/screenshots/dashboard.png)

*Fleet overview with Chart.js visualizations, KPI summary cards (active reservations, pending approvals, fleet utilization), today's schedule, and overdue vehicle alerts. Users see their upcoming reservations at a glance.*

**Access:** All authenticated users

---

### Vehicle Catalogue

![Vehicle Catalogue](docs/screenshots/vehicle_catalogue.png)

*Browse all available fleet vehicles with real-time availability pulled from Snipe-IT. Filter by type, status, or availability window.*

**Access:** All authenticated users

---

### Book Vehicle

![Book Vehicle](docs/screenshots/vehicle_reserve.png)

*Reserve a vehicle by selecting pickup/return dates, times, and location. Include purpose notes for approval workflow.*

**Access:** All authenticated users

---

### My Reservations

![My Reservations](docs/screenshots/my_bookings.png)

*View and manage your own reservations. Cancel pending bookings, see approval status, and access checkout forms when ready. Pipeline tracker shows reservation progress from Booked through Returned.*

**Access:** All authenticated users

---

### Approval Queue

![Approval Queue](docs/screenshots/approval.png)

*Fleet Staff review pending reservation requests. Approve or reject with notes, view requester history and vehicle availability.*

**Access:** Fleet Staff and above

---

### All Reservations

![Reservations](docs/screenshots/reservations.png)

*Administrative view of all reservations across the fleet. Filter by status, date range, or vehicle.*

**Access:** Fleet Staff and above

---

### Maintenance Log

![Maintenance](docs/screenshots/maintenance.png)

*Track maintenance issues flagged during check-in. Log repairs, schedule service, and sync maintenance data back to Snipe-IT custom fields.*

**Access:** Fleet Staff and above

---

### Reports

![Reports](docs/screenshots/reports.png)

*Comprehensive reporting including utilization rates, compliance status, and usage history. Export to CSV for further analysis.*

**Access:** Fleet Staff and above

---

### Scan QR

![Scan QR](docs/screenshots/scan.png)

*Mobile-friendly QR code scanner for quick vehicle lookup. Scan vehicle QR codes to view details, check availability, or initiate checkout.*

**Access:** All authenticated users

---

### Vehicle Management

![Vehicles Admin](docs/screenshots/vehicles.png)

*Administrative vehicle management. View all fleet assets, their current status, company assignment (when multi-entity is active), and quick links to Snipe-IT for detailed editing.*

**Access:** Fleet Admin

---

### Create Vehicle

![Create Vehicle](docs/screenshots/vehicles_create.png)

*Add new fleet vehicles with automatic asset tag generation, VIN/plate duplicate checking, and Snipe-IT custom field mapping.*

**Access:** Fleet Admin

---

### User Management

![Users Admin](docs/screenshots/users.png)

*View users and their Snipe-IT group assignments. Training column with date picker shows color-coded expiration status (green = valid, yellow = expiring within 15 days, red = expired). Track VIP status for auto-approval workflows.*

**Access:** Fleet Admin

---

### Activity Log

![Activity Log](docs/screenshots/activity_log.png)

*Full audit trail of system actions: logins, reservations, approvals, checkouts, and administrative changes.*

**Access:** Fleet Admin

---

### Email Notifications

![Notifications](docs/screenshots/notifications.png)

*Configure email and Microsoft Teams notifications per event type. Set recipients, customize templates, and select channels (Email/Teams/Both/Off) per event.*

**Access:** Fleet Admin

---

### Announcements

![Announcements](docs/screenshots/announcements.png)

*Create and manage system-wide announcements. Schedule display windows, set urgency levels, and make notices dismissible. Auto-deactivates on new releases.*

**Access:** Fleet Admin

---

### Booking Rules

![Booking Rules](docs/screenshots/booking_rules.png)

*Configure reservation constraints: minimum notice periods, maximum duration, blackout windows, auto-approval rules, driver training requirements, and inspection mode selector (Quick / Full / Off).*

**Access:** Fleet Admin

---

### Security Dashboard

![Security](docs/screenshots/security.png)

*Monitor system security status, CRON sync health (Healthy/Stale/Never Run with last sync time and asset count), backup logs, config permissions, and security headers.*

**Access:** Super Admin only

---

### Settings

![Settings](docs/screenshots/settings.png)

*Full system configuration including authentication providers, SMTP settings, Teams integration, reservation controls, asset tag prefix, corporate theme color, and multi-entity fleet configuration (Auto-detect / Always On / Always Off with detected company count).*

**Access:** Super Admin only

## Installation

See [docs/INSTALLATION.md](docs/INSTALLATION.md) for detailed installation instructions.

### Quick Start

1. Install Snipe-IT (if not already running)
2. Clone this repository to `/var/www/snipescheduler`
3. Configure Apache with Alias to `/booking`
4. Copy `config/config.example.php` to `config/config.php`
5. Configure database, Snipe-IT API, and authentication
6. Run database migrations
7. Create user groups in Snipe-IT (Drivers, Fleet Staff, Fleet Admin)
8. Run `php scripts/validate_snipeit.php` to verify configuration
9. Set up CRON jobs (see table below)

## Snipe-IT Configuration Guide

### Required Snipe-IT Setup

#### 1. User Groups

Create these groups in Snipe-IT:

| Group Name | Group ID | Purpose |
| --- | --- | --- |
| Drivers | 2 | Basic vehicle booking access |
| Fleet Staff | 3 | Approval and maintenance management |
| Fleet Admin | 4 | Full fleet administration |

Group IDs are configurable in `config.php` under `snipeit_groups`.

#### 2. Status Labels

Create these status labels in Snipe-IT:

| Status Name | Type | Notes |
| --- | --- | --- |
| VEH-Available | Deployable | Default status for available vehicles |
| VEH-Reserved | Pending | Vehicle has upcoming reservation |
| VEH-In Service | Deployed | Vehicle currently checked out |
| VEH-Out of Service | Undeployable | Under maintenance |

Status IDs are configurable in `config.php` under `snipeit_statuses`.

#### 3. Custom Fields

Create a **Fleet Vehicle Fields** fieldset with these custom fields:

| Field Name | Element | Format | Purpose |
| --- | --- | --- | --- |
| VIN | Text | Regex (17 chars) | Vehicle identification |
| License Plate | Text | Regex | Plate number |
| Vehicle Year | Text | NUMERIC | Model year |
| Current Mileage | Text | Regex | Odometer reading (updated at checkout/checkin) |
| Last Oil Change (Miles) | Text | Regex | Maintenance tracking |
| Last Tire Rotation (Miles) | Text | Regex | Maintenance tracking |
| Insurance Expiry | Text | DATE | Compliance |
| Registration Expiry | Text | DATE | Compliance |
| Holman Account # | Text | ANY | Maintenance vendor |
| Last Maintenance Date | Text | DATE | Maintenance tracking |
| Last Maintenance Mileage | Text | Regex | Maintenance tracking |
| Maintenance Interval Miles | Text | Regex | Maintenance alerts |
| Maintenance Interval Days | Text | NUMERIC | Maintenance alerts |

Custom field names are configurable in `config.php` under `snipeit_fields`.

**Inspection Fields** (populated during checkout/checkin):

| Field Name | Element | Purpose |
| --- | --- | --- |
| Visual Inspection Complete? | Listbox | Driver must select Yes (never pre-filled) |
| Vehicle Condition Issues | Checkbox | Multi-select issue types |
| Exterior/Tire/Undercarriage/Interior Issues | Textarea | Free text descriptions |
| Checkout Time / Return Time | Text | HH:MM (auto-filled) |

#### 4. Companies (Multi-Entity Deployments)

For multi-entity deployments, create one Company per fleet entity in Snipe-IT. Assign users and vehicles to their respective companies. Enable **Full Multiple Companies Support** in Snipe-IT General Settings.

When SnipeScheduler detects multiple companies, it automatically activates fleet partitioning. This can be overridden in Settings (Auto-detect / Always On / Always Off).

#### 5. Categories, Manufacturers, Models & Locations

* Create a **Vehicles** category for fleet assets
* Create manufacturers (Ford, Chevrolet, etc.) and models
* Create **Pickup Points** and **Destinations** as locations

Location parent IDs are configurable in `config.php`.

## CRON Jobs

Set up the following scheduled tasks:

| Script | Schedule | Purpose |
| --- | --- | --- |
| `scripts/sync_checked_out_assets.php` | Every 5 minutes | Sync checked-out asset cache with Snipe-IT |
| `scripts/cron_sync_health.php` | Every 15 minutes | Monitor sync staleness, trigger alerts if stale |
| `scripts/cron_mark_missed.php` | Every 15 minutes | Auto-mark missed reservations past pickup window |
| `scripts/email_overdue_staff.php` | Every 30 minutes | Notify Fleet Staff of overdue vehicle returns |
| `scripts/email_overdue_users.php` | Every 30 minutes | Notify drivers of their overdue returns |
| `scripts/cron_training_expiry.php` | Weekly (Monday 8am) | Send training expiration warnings to Fleet Staff |

Example crontab:

```cron
*/5  * * * * php /var/www/snipescheduler/scripts/sync_checked_out_assets.php >> /var/log/snipescheduler/sync.log 2>&1
*/15 * * * * php /var/www/snipescheduler/scripts/cron_sync_health.php >> /var/log/snipescheduler/health.log 2>&1
*/15 * * * * php /var/www/snipescheduler/scripts/cron_mark_missed.php >> /var/log/snipescheduler/missed.log 2>&1
*/30 * * * * php /var/www/snipescheduler/scripts/email_overdue_staff.php >> /var/log/snipescheduler/overdue.log 2>&1
*/30 * * * * php /var/www/snipescheduler/scripts/email_overdue_users.php >> /var/log/snipescheduler/overdue.log 2>&1
0 8  * * 1  php /var/www/snipescheduler/scripts/cron_training_expiry.php >> /var/log/snipescheduler/training.log 2>&1
```

## API Integration

SnipeScheduler uses the Snipe-IT API exclusively — no direct database access to Snipe-IT. All API calls use exponential backoff retry on 429 (rate limit) and 5xx (server error) responses.

Key API operations:

* `GET /hardware` - List available vehicles
* `PATCH /hardware/{id}` - Update status, location, custom fields
* `GET /users` - Validate users and group membership
* `GET /companies` - List companies for multi-entity filtering
* `GET /statuslabels` - Get status options
* `GET /locations` - Get pickup/destination options

## Database Schema

See [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) for complete schema documentation.

SnipeScheduler maintains its own MySQL database for:

* Reservations and approval history
* Inspection responses
* Email queue and notifications
* Announcements and blackout slots
* Activity log
* System settings (multi-entity mode, CRON health, etc.)

## Security

See [SECURITY.md](SECURITY.md) for security checklist and hardening procedures.

### Additional Security & Operations Documentation

| Document | Description |
|----------|------------|
| [Security Audit Summary](docs/SECURITY_AUDIT.md) | Authentication, authorization, input protection, session security, known limitations |
| [Incident Response Plan](docs/INCIDENT_RESPONSE.md) | Detection, containment, notification timeline (72hr CCPA), credential rotation |
| [Disaster Recovery Runbook](docs/DISASTER_RECOVERY.md) | RTO/RPO targets, backup strategy, step-by-step restoration, validation checklist |

Key security features:

* Fleet group authorization gate — users must belong to an authorized Snipe-IT group
* CSRF protection on all forms
* XSS prevention with output encoding
* SQL injection prevention with PDO prepared statements
* Ownership validation on checkout/checkin operations
* Security headers (X-Frame-Options, CSP, etc.)
* Config file permissions (640)
* Automated daily backups
* API retry with exponential backoff
* CRON health monitoring with staleness alerts

## Credits

This project is a derivative work based on [SnipeScheduler](https://github.com/JSY-Ben/SnipeScheduler) by **Ben Pirozzolo**.

### Original Project

* **Author**: Ben Pirozzolo (JSY-Ben)
* **Repository**: https://github.com/JSY-Ben/SnipeScheduler
* **License**: MIT

### This Fork

* **Author**: Vitor Rodovalho
* **Repository**: https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager
* **Purpose**: Extended for enterprise fleet management with additional features

### Key Additions in FleetManager

* Multi-entity fleet partitioning (company-based vehicle filtering with auto-detect)
* Configurable vehicle inspection checklist (Quick / Full / Off modes, 50-item detailed checklist)
* Photo upload during checkout/checkin (optional, camera-first capture)
* Corporate theming with configurable primary color palette (CSS variable system)
* Driver training compliance tracking (date picker, color-coded expiration, weekly alerts)
* CRON health monitoring with sync staleness detection and alerting
* API resilience with exponential backoff retry on 429/5xx
* Startup validation script (`validate_snipeit.php`)
* Fleet-specific inspection forms (mileage + visual inspection enforcement)
* Maintenance tracking with Snipe-IT integration
* VIP auto-approval workflow
* Reservation pipeline tracker (Booked > Approved > Checked Out > Returned)
* Dynamic notification recipients from Snipe-IT groups
* Email + Microsoft Teams notifications (per-event channel control)
* Driver group merge (existing users get permissions appended, not blocked)
* Booking rules (blackouts, limits, min notice)
* Announcements system with auto-deactivation on new releases
* Security dashboard with backup monitoring
* Activity log with full audit trail
* QR code scanning for mobile vehicle lookup
* Mobile-optimized responsive interface
* Clean URLs (no .php extensions)

## License

This project is licensed under the GPL-3.0 License - see the [LICENSE](LICENSE) file for details.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

## Support

For issues and feature requests, please use the [GitHub Issues](https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager/issues) page.

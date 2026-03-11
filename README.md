# SnipeScheduler FleetManager

A comprehensive fleet vehicle management system built on top of [Snipe-IT](https://snipeitapp.com/), designed for enterprise fleet operations with reservation scheduling, maintenance tracking, and compliance management.

> **Current Version:** v1.4.3 · [Changelog](CHANGELOG.md) · [Releases](https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager/releases) · [User Guide](docs/USER_GUIDE.md)

## Why This Project?

This project was born from a real need in **large-scale infrastructure programs**. The challenge was managing diverse assets on a single platform: not just IT equipment, but **fleet vehicles** used by construction management, PMO staff, and field personnel across multiple project areas.

### The Problem

- Snipe-IT excels at IT asset management but lacks reservation/scheduling capabilities
- Fleet vehicles require advance booking, check-out/check-in workflows, and inspection tracking
- Paper-based vehicle check-out processes were inefficient and lacked governance
- No single platform to manage both IT assets and fleet vehicles

### The Solution

**SnipeScheduler FleetManager** extends Snipe-IT with a user-friendly frontend specifically designed for fleet operations:

- Uses Snipe-IT's API as the **single source of truth** for all asset data
- Adds reservation scheduling with approval workflows
- Implements digital vehicle inspection checklists
- Provides maintenance tracking integrated with Snipe-IT custom fields
- Maintains full audit trail and compliance reporting

## Features

### For Drivers

- **Book Vehicles** — Reserve vehicles in advance with pickup/return times
- **Mobile-Friendly** — Responsive design with QR code scanning
- **Digital Inspections** — Complete checkout/checkin forms on any device
- **Email and Teams Notifications** — Confirmation, reminders, and approvals via email and/or Microsoft Teams Adaptive Cards
- **Smart Booking Calendar** — Business day enforcement with holiday awareness; weekends, holidays, and blackouts grayed out
- **Driver Training Gate** — Training completion required before booking (configurable validity period with auto-expiration)

### For Fleet Staff

- **Approval Workflow** — Review and approve/reject reservations
- **Vehicle Management** — Track all fleet vehicles and their status
- **Maintenance Tracking** — Flag issues, track maintenance history
- **Reports** — Utilization, compliance, and usage reports
- **Training Management** — Toggle driver training status with date picker and expiration tracking

### For Administrators

- **Full Configuration** — Microsoft OAuth / Google OAuth / LDAP authentication, SMTP, custom fields
- **Notification Controls** — Configure recipients, delivery channel (Email / Teams / Both / Off) per event type
- **Microsoft Teams Integration** — Deliver Adaptive Cards to Teams channels via Power Automate webhooks
- **Announcements** — Display system-wide notices with scheduling and urgency levels
- **Security Dashboard** — Monitor backup status, CRON sync health, config permissions, and security headers
- **Booking Rules** — Configure business day buffer, working days, holiday calendar, and driver training requirements
- **Reservation Controls** — Set min notice, max duration, concurrent booking limits, and blackout periods
- **CRON Health Monitoring** — Real-time sync status with automatic alerting on CRON failures
- **Configurable Snipe-IT Mapping** — Group IDs, status labels, and custom field mappings in config (no hardcoding)
- **Startup Validation** — Script to verify all required Snipe-IT entities exist before deployment
- **API Resilience** — Exponential backoff retry on rate limiting (429) and server errors (5xx)
- **Release Management** — Built-in version management with semantic versioning and auto-announcements
- **Clean URLs** — Professional URL structure without .php extensions

## Architecture

```
+-------------------------------------------------------------+
|                   SnipeScheduler FleetManager                |
|  +-------------+  +-------------+  +---------------------+  |
|  |   Drivers   |  | Fleet Staff |  |   Fleet Admin       |  |
|  |  (Group 2)  |  |  (Group 3)  |  |   (Group 4)         |  |
|  +------+------+  +------+------+  +----------+----------+  |
|         |                |                     |             |
|         +----------------+---------------------+             |
|                          |                                   |
|         +----------------v----------------+                  |
|         |      SnipeScheduler PHP App     |                  |
|         |   - Reservation Management      |                  |
|         |   - Inspection Forms            |                  |
|         |   - Approval Workflow           |                  |
|         |   - Email & Teams Notifications |                  |
|         |   - Training Compliance         |                  |
|         |   - CRON Health Monitoring      |                  |
|         +----------------+----------------+                  |
|                          | API Calls (with retry/backoff)    |
+---------------------------+----------------------------------+
                           |
+---------------------------v----------------------------------+
|                      Snipe-IT                                |
|   - Asset Database (Source of Truth)                         |
|   - User Management & Groups                                |
|   - Custom Fields (Mileage, VIN, Maintenance)               |
|   - Status Labels (Available, Reserved, In Service)          |
|   - Locations (Pickup Points, Destinations)                  |
+--------------------------------------------------------------+
```

## Permission Model

Permissions are managed through **Snipe-IT Groups**. Group IDs are configurable in `config.php`:

| Config Key | Default ID | Group Name | Capabilities |
|------------|-----------|------------|--------------|
| `admins` | 1 | Admins | Full system access including Settings and Security |
| `drivers` | 2 | Drivers | Book vehicles, view own reservations |
| `fleet_staff` | 3 | Fleet Staff | Approve reservations, manage maintenance, training |
| `fleet_admin` | 4 | Fleet Admin | All staff permissions + user/vehicle/notification management |

Users must belong to at least one authorized group to access the system. Users in Snipe-IT without an authorized group assignment are denied login with a clear message directing them to contact Fleet Staff.

## Screenshots

### Login

![Login](docs/screenshots/login.png)

*SSO authentication via Microsoft OAuth (multi-tenant) or Google Sign-In. Users are automatically assigned permissions based on their Snipe-IT group membership. Users without an authorized fleet group are denied access with a descriptive message.*

**Access:** Public

---

### Dashboard

![Dashboard](docs/screenshots/dashboard.png)

*Today's vehicle schedule, quick statistics (total bookings, active checkouts, overdue vehicles), and system announcements. Users see their upcoming reservations at a glance.*

**Access:** All authenticated users

---

### Vehicle Catalogue

![Vehicle Catalogue](docs/screenshots/vehicle_catalogue.png)

*Browse all fleet vehicles with real-time availability pulled from Snipe-IT. Each vehicle shows status, mileage, and booking availability.*

**Access:** All authenticated users

---

### Book Vehicle

![Book Vehicle](docs/screenshots/vehicle_reserve.png)

*Three-step booking flow: select location, choose dates (business day calendar with weekends, holidays, and blackouts grayed out), then pick from available vehicles. Dynamic AJAX filtering enforces turnaround buffers and prevents double-booking. Drivers without completed training see a warning banner and cannot submit.*

**Access:** All authenticated users (training completion required when enabled)

---

### My Reservations

![My Reservations](docs/screenshots/my_bookings.png)

*View and manage your own reservations. Cancel pending bookings, see approval status, and access checkout/checkin forms.*

**Access:** All authenticated users

---

### Approval Queue

![Approval Queue](docs/screenshots/approval.png)

*Fleet Staff review pending reservation requests. Approve or reject with notes. VIP users bypass the queue with auto-approval.*

**Access:** Fleet Staff and above

---

### All Reservations

![All Reservations](docs/screenshots/reservations.png)

*Administrative view of all reservations across the fleet. Filter by status, date range, or vehicle.*

**Access:** Fleet Staff and above

---

### Maintenance Log

![Maintenance Log](docs/screenshots/maintenance.png)

*Track maintenance issues flagged during check-in. Log repairs, schedule service, and sync maintenance data back to Snipe-IT custom fields.*

**Access:** Fleet Staff and above

---

### Reports

![Reports](docs/screenshots/reports.png)

*Comprehensive reporting: utilization rates, compliance status (insurance/registration expiry), usage history, and mileage summaries. Export to CSV for external analysis.*

**Access:** Fleet Staff and above

---

### Vehicle Management

![Vehicle Management](docs/screenshots/vehicles.png)

*Administrative vehicle management with guided creation. Auto-generated asset tags, VIN/plate duplicate checking, and compliance field enforcement. All vehicles sync to Snipe-IT as requestable assets.*

**Access:** Fleet Admin

---

### Add Vehicle

![Add Vehicle](docs/screenshots/vehicles_create.png)

*Guided vehicle creation with governance controls. Vehicle Name auto-generated from Year, Manufacturer, Model, and License Plate. Confirmation summary before submitting to Snipe-IT.*

**Access:** Fleet Admin

---

### User Management

![User Management](docs/screenshots/users.png)

*View all users organized by Snipe-IT group (Drivers, Fleet Staff, Fleet Admin). Toggle VIP status for auto-approval workflows. Manage driver training completion with date picker, expiration tracking, and color-coded status indicators (green = valid, yellow = expiring within 15 days, red = expired). Confirmation dialogs on all actions with full audit trail.*

**Access:** Fleet Admin

---

### Notifications

![Notifications](docs/screenshots/notifications.png)

*Configure notification delivery per event type. Each event can be set to Email, Microsoft Teams, Both, or Off. Includes training expiry alerts, CRON health alerts, and all reservation lifecycle events. Message templates shared between email and Teams Adaptive Cards.*

**Access:** Fleet Admin

---

### Announcements

![Announcements](docs/screenshots/announcements.png)

*Create and manage system-wide announcements with scheduling, urgency levels, and dismissible toggle. Release announcements auto-generated on version updates and auto-deactivated on new releases.*

**Access:** Fleet Admin

---

### Booking Rules

![Booking Rules](docs/screenshots/booking_rules.png)

*Configure fleet scheduling rules: vehicle turnaround buffer, overdue redirect triggers, working day schedule, holiday calendar with pre-seeded federal holidays. Driver Training Requirements section with global enable/disable toggle and configurable validity period (6/12/24 months or no expiration). Disabling training preserves all records for re-enablement.*

**Access:** Fleet Admin

---

### Security Dashboard

![Security Dashboard](docs/screenshots/security.png)

*Monitor system health: CRON sync status (healthy/stale/never run with last sync time and asset count), backup recency and schedule, config file permissions, and security headers. Maintenance command reference for common admin tasks.*

**Access:** Super Admin only

---

### Settings

![Settings](docs/screenshots/settings.png)

*Full system configuration: authentication providers (Microsoft OAuth, Google OAuth), SMTP settings, Teams webhook URLs (masked with reveal toggle), and system preferences. Teams Integration card with inline setup instructions for Power Automate HTTP trigger flows.*

**Access:** Super Admin only

## User Guide

For detailed step-by-step procedures for each user role, see **[docs/USER_GUIDE.md](docs/USER_GUIDE.md)**:

- **Procedure A: Driver** — Booking, checkout, inspection, checkin, incident reporting
- **Procedure B: Fleet Staff** — Approval queue, maintenance, reports, training management
- **Procedure C: Fleet Admin / Super Admin** — Configuration, user management, notifications, security

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
9. Set up CRON jobs (sync, health check, email queue, training alerts)

## Snipe-IT Configuration

### Required Setup

All Snipe-IT entity IDs are **configurable** in `config.php` — no hardcoded values. Run `php scripts/validate_snipeit.php` after setup to verify everything is connected.

#### 1. User Groups

Create these groups in Snipe-IT (IDs are configured in `config.php['snipeit_groups']`):

| Group Name | Purpose |
|------------|---------|
| Drivers | Basic vehicle booking access |
| Fleet Staff | Approval and maintenance management |
| Fleet Admin | Full fleet administration |

#### 2. Status Labels

Create these status labels (IDs configured in `config.php['snipeit_statuses']`):

| Status Name | Type | Notes |
|-------------|------|-------|
| VEH-Available | Deployable | Default status for available vehicles |
| VEH-Reserved | Pending | Vehicle has approved reservation |
| VEH-In Service | Deployed | Vehicle currently checked out |
| VEH-Out of Service | Undeployable | Under maintenance |

#### 3. Custom Fields

Create a fieldset with these fields (column names configured in `config.php['snipeit_fields']`):

| Field Name | Element | Purpose |
|------------|---------|---------|
| VIN | Text | Vehicle identification (17-char validated) |
| License Plate | Text | Vehicle plate number |
| Current Mileage | Text | Odometer reading (updated at checkout/checkin) |
| Last Oil Change (Miles) | Text | Oil change tracking |
| Last Tire Rotation (Miles) | Text | Tire rotation tracking |
| Insurance Expiry | Text/Date | Compliance tracking |
| Registration Expiry | Text/Date | Compliance tracking |
| Visual Inspection Complete? | Listbox | Checkout confirmation |
| Checkout Time | Text | HH:MM format |
| Return Time | Text | HH:MM format |
| Last Maintenance Date | Text/Date | Service tracking |
| Last Maintenance Mileage | Text | Mileage at last service |

#### 4. Categories, Manufacturers, Models, Locations

- Create a **Vehicles** category for fleet assets
- Add manufacturers and models matching your fleet
- Create **Pickup Locations** and **Field Destinations** as location entries

### Configuration Validation

After setup, verify everything is connected:

```bash
php scripts/validate_snipeit.php
```

For strict mode (fails on errors — useful in CI/deploy):

```bash
php scripts/validate_snipeit.php --strict
```

## CRON Jobs

| Schedule | Script | Purpose |
|----------|--------|---------|
| Every 1 min | `sync_checked_out_assets.php` | Sync vehicle checkout data from Snipe-IT |
| Every 5 min | `cron_sync_health.php` | Monitor sync freshness, alert if stale |
| Every 5 min | `cron/process_email_queue.php` | Process outbound email queue |
| Every 15 min | `cron_mark_missed.php` | Mark uncollected reservations as missed |
| Every 15 min | `cron/scheduled_tasks.php` | Overdue checks, pickup reminders, redirects |
| Daily 8am | `email_overdue_staff.php` | Overdue vehicle alerts to staff |
| Daily 8am | `email_overdue_users.php` | Overdue return reminders to drivers |
| Weekly Mon 8am | `cron_training_expiry.php` | Training expiry digest + individual driver alerts |
| Daily 2am | `backup-snipescheduler.sh` | Full system backup (DB + files) |

## API Integration

SnipeScheduler uses the Snipe-IT API exclusively — no direct database access to Snipe-IT. API calls include retry with exponential backoff for rate limiting (HTTP 429) and server errors (5xx).

Key API operations:

- `GET /hardware` — List available vehicles
- `PATCH /hardware/{id}` — Update status, location, custom fields
- `GET /users` — Validate users and group membership
- `GET /statuslabels` — Get status options
- `GET /locations` — Get pickup/destination options
- `GET /groups` — Validate group configuration
- `GET /fields` — Validate custom field configuration

## Database Schema

See [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) for complete schema documentation.

SnipeScheduler maintains its own MySQL database for:

- Reservations and approval history
- Inspection responses
- Email queue and notification settings
- Announcements and blackout slots
- System settings (training config, sync health, booking rules)
- Activity log (audit trail)
- User training records

## Security

See [SECURITY.md](SECURITY.md) for security documentation.

Key security features:

- CSRF protection on all forms
- XSS prevention with output encoding
- SQL injection prevention with PDO prepared statements
- Security headers (X-Frame-Options, CSP, X-Content-Type-Options)
- Fleet group authorization gate (users must belong to an authorized Snipe-IT group)
- Config file permissions (640, owned by www-data)
- Automated daily backups with retention
- CRON sync health monitoring with alerting
- API retry with exponential backoff
- Built-in security scanner and configuration validator

## Credits

This project is a derivative work based on [SnipeScheduler](https://github.com/JSY-Ben/SnipeScheduler) by **Ben Pirozzolo**.

### Original Project

- **Author**: Ben Pirozzolo (JSY-Ben)
- **Repository**: https://github.com/JSY-Ben/SnipeScheduler
- **License**: MIT

### This Fork

- **Author**: Vitor Rodovalho
- **Repository**: https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager
- **Purpose**: Extended for enterprise fleet management

### Key Additions in FleetManager

- Fleet-specific inspection forms (checkout/checkin)
- Maintenance tracking with Snipe-IT custom field sync
- VIP auto-approval workflow with confirmation dialogs
- Driver training compliance gate with configurable expiration
- Weekly training expiry alerts (staff digest + individual driver notifications)
- Configurable Snipe-IT entity mapping (groups, statuses, custom fields)
- Startup validation script for deployment verification
- API retry with exponential backoff for resilience
- CRON sync health monitoring with alerting
- Reservation controls (blackouts, min notice, max duration)
- Email and Teams notification engine with per-event channel configuration
- Announcements system with scheduling and urgency levels
- Security dashboard with CRON health, backup status, and config monitoring
- Business day engine with federal holiday calendar
- Booking rules administration (turnaround buffer, working days, holidays, training)
- Overdue vehicle auto-redirect with alternate vehicle assignment
- Customizable email templates via admin notifications page
- Clean URLs, release management, and mobile-optimized interface

## License

This project is licensed under the GPL-3.0 License — see the [LICENSE](LICENSE) file for details.

The original [SnipeScheduler](https://github.com/JSY-Ben/SnipeScheduler) code by Ben Pirozzolo is licensed under the MIT License. All modifications and additions in this fork are licensed under GPL-3.0.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

## Support

For issues and feature requests, please use the [GitHub Issues](https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager/issues) page.

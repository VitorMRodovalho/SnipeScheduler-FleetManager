# SnipeScheduler FleetManager

A comprehensive fleet vehicle management system built on top of [Snipe-IT](https://snipeitapp.com/), designed for enterprise fleet operations with reservation scheduling, maintenance tracking, and compliance management.

## Why This Project?

This project was born from a real need in **large-scale infrastructure programs**. The challenge was managing diverse assets on a single platform: not just IT equipment, but **fleet vehicles** used by construction management, PMO staff, and field personnel across multiple project areas.

### The Problem

* Snipe-IT excels at IT asset management but lacks reservation/scheduling capabilities
* Fleet vehicles require advance booking, check-out/check-in workflows, and inspection tracking
* Paper-based vehicle check-out processes were inefficient and lacked governance
* No single platform to manage both IT assets and fleet vehicles

### The Solution

**SnipeScheduler FleetManager** extends Snipe-IT with a user-friendly frontend specifically designed for fleet operations:

* Uses Snipe-IT's API as the **single source of truth** for all asset data
* Adds reservation scheduling with approval workflows
* Implements digital vehicle inspection checklists
* Provides maintenance tracking integrated with Snipe-IT custom fields
* Maintains full audit trail and compliance reporting

## Features

### For Drivers

* **Book Vehicles** - Reserve vehicles in advance with pickup/return times
* **Mobile-Friendly** - Responsive design with QR code scanning
* **Digital Inspections** - Complete checkout/checkin forms on any device
* **Email & Teams Notifications** - Confirmation, reminders, and approvals via email or Microsoft Teams

### For Fleet Staff

* **Approval Workflow** - Review and approve/reject reservations
* **Vehicle Management** - Track all fleet vehicles and their status
* **Maintenance Tracking** - Flag issues, track maintenance history
* **Reports** - Utilization, compliance, and usage reports

### For Administrators

* **Full Configuration** - OAuth authentication, SMTP, custom fields
* **Notification Controls** - Configure per-event channels (Email/Teams/Both/Off)
* **Announcements** - Display system-wide notices to users
* **Security Dashboard** - Monitor backup status and security checks
* **Booking Rules** - Set booking rules (min notice, max duration, blackouts)
* **Activity Log** - Full audit trail of all system actions

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
|        +---------------+-----------------+                   |
|                        | API Calls                           |
+------------------------+------------------------------------+
                         |
+------------------------v------------------------------------+
|                      Snipe-IT                                |
|   - Asset Database (Source of Truth)                         |
|   - User Management & Groups                                |
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

*The main dashboard displays today's schedule, quick statistics, and overdue vehicle alerts. Users see their upcoming reservations at a glance.*

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

*Administrative vehicle management. View all fleet assets, their current status, and quick links to Snipe-IT for detailed editing.*

**Access:** Fleet Admin

---

### Create Vehicle

![Create Vehicle](docs/screenshots/vehicles_create.png)

*Add new fleet vehicles with automatic asset tag generation, VIN/plate duplicate checking, and Snipe-IT custom field mapping.*

**Access:** Fleet Admin

---

### User Management

![Users Admin](docs/screenshots/users.png)

*View users and their Snipe-IT group assignments. Add drivers by email — existing Snipe-IT users automatically get groups merged instead of duplicated. Track VIP status for auto-approval workflows.*

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

*Configure reservation constraints: minimum notice periods, maximum duration, blackout windows, and auto-approval rules.*

**Access:** Fleet Admin

---

### Security Dashboard

![Security](docs/screenshots/security.png)

*Monitor system security status, view backup logs, and CRON sync health. Real-time checks for config permissions, security headers, and backup recency.*

**Access:** Super Admin only

---

### Settings

![Settings](docs/screenshots/settings.png)

*Full system configuration including authentication providers, SMTP settings, Teams integration, reservation controls, and asset tag prefix.*

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

## Snipe-IT Configuration Guide

### Required Snipe-IT Setup

#### 1. User Groups

Create these groups in Snipe-IT:

| Group Name | Group ID | Purpose |
| --- | --- | --- |
| Drivers | 2 | Basic vehicle booking access |
| Fleet Staff | 3 | Approval and maintenance management |
| Fleet Admin | 4 | Full fleet administration |

#### 2. Status Labels

Create these status labels in Snipe-IT:

| Status Name | Type | Notes |
| --- | --- | --- |
| VEH-Available | Deployable | Default status for available vehicles |
| VEH-Reserved | Pending | Vehicle has upcoming reservation |
| VEH-In Service | Deployed | Vehicle currently checked out |
| VEH-Out of Service | Undeployable | Under maintenance |

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

**Inspection Fields** (populated during checkout/checkin):

| Field Name | Element | Purpose |
| --- | --- | --- |
| Visual Inspection Complete? | Listbox | Driver must select Yes (never pre-filled) |
| Vehicle Condition Issues | Checkbox | Multi-select issue types |
| Exterior/Tire/Undercarriage/Interior Issues | Textarea | Free text descriptions |
| Checkout Time / Return Time | Text | HH:MM (auto-filled) |

#### 4. Categories, Manufacturers, Models & Locations

* Create a **Vehicles** category for fleet assets
* Create manufacturers (Ford, Chevrolet, etc.) and models
* Create **Pickup Points** and **Destinations** as locations

## API Integration

SnipeScheduler uses the Snipe-IT API exclusively — no direct database access to Snipe-IT.

Key API operations:

* `GET /hardware` - List available vehicles
* `PATCH /hardware/{id}` - Update status, location, custom fields
* `GET /users` - Validate users and group membership
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

## Security

See [SECURITY.md](SECURITY.md) for security documentation.

Key security features:

* CSRF protection on all forms
* XSS prevention with output encoding
* SQL injection prevention with PDO
* Security headers (X-Frame-Options, CSP, etc.)
* Config file permissions (640)
* Automated daily backups

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

* Corporate UI/UX with navy palette (v1.5.0)
* Fleet-specific inspection forms (mileage + visual inspection enforcement)
* Maintenance tracking with Snipe-IT integration
* VIP auto-approval workflow
* Reservation pipeline tracker (Booked > Approved > Checked Out > Returned)
* Dynamic notification recipients from Snipe-IT groups
* Email + Microsoft Teams notifications (per-event channel control)
* Driver group merge (existing users get permissions appended, not blocked)
* Booking rules (blackouts, limits, min notice)
* Announcements system with auto-deactivation on new releases
* Security dashboard with CRON health monitoring
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

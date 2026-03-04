# SnipeScheduler FleetManager

A comprehensive fleet vehicle management system built on top of [Snipe-IT](https://snipeitapp.com/), designed for enterprise fleet operations with reservation scheduling, maintenance tracking, and compliance management.

> **Current Version:** v1.3.5 · [Changelog](CHANGELOG.md) · [Releases](https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager/releases)

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

- 📅 **Book Vehicles** — Reserve vehicles in advance with pickup/return times
- 📱 **Mobile-Friendly** — Responsive design with QR code scanning
- ✅ **Digital Inspections** — Complete checkout/checkin forms on any device
- 📧 **Email Notifications** — Confirmation, reminders, and approvals
- 📆 **Smart Booking Calendar** — Business day enforcement with holiday awareness; weekends, holidays, and blackouts grayed out

### For Fleet Staff

- 👥 **Approval Workflow** — Review and approve/reject reservations
- 🚗 **Vehicle Management** — Track all fleet vehicles and their status
- 🔧 **Maintenance Tracking** — Flag issues, track maintenance history
- 📊 **Reports** — Utilization, compliance, and usage reports

### For Administrators

- ⚙️ **Full Configuration** — LDAP/OAuth authentication, SMTP, custom fields
- 🔔 **Notification Controls** — Configure who receives which emails
- 📢 **Announcements** — Display system-wide notices with scheduling and urgency levels
- 🔒 **Security Dashboard** — Monitor backup status, config permissions, and security headers
- 📋 **Reservation Controls** — Set booking rules (min notice, max duration, blackouts)
- 🔗 **Clean URLs** — Professional URL structure without .php extensions
- 📦 **Release Management** — Built-in version management with semantic versioning
- 📆 **Booking Rules** — Configure business day buffer, working days, federal/custom holiday calendar
- 🔄 **Overdue Auto-Redirect** — Automatically reassigns next reservation when a vehicle is overdue
- ✉️ **Customizable Email Templates** — Override email subjects and bodies per notification event

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    SnipeScheduler FleetManager               │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────────┐  │
│  │   Drivers   │  │ Fleet Staff │  │   Fleet Admin       │  │
│  │  (Group 2)  │  │  (Group 3)  │  │   (Group 4)         │  │
│  └──────┬──────┘  └──────┬──────┘  └──────────┬──────────┘  │
│         │                │                     │             │
│         └────────────────┼─────────────────────┘             │
│                          │                                   │
│         ┌────────────────▼────────────────┐                  │
│         │      SnipeScheduler PHP App     │                  │
│         │   - Reservation Management      │                  │
│         │   - Inspection Forms            │                  │
│         │   - Approval Workflow           │                  │
│         │   - Email Notifications         │                  │
│         └────────────────┬────────────────┘                  │
│                          │ API Calls                         │
└──────────────────────────┼───────────────────────────────────┘
                           │
┌──────────────────────────▼───────────────────────────────────┐
│                      Snipe-IT                                │
│   - Asset Database (Source of Truth)                         │
│   - User Management & Groups                                │
│   - Custom Fields (Mileage, VIN, Maintenance)               │
│   - Status Labels (Available, Reserved, In Service)          │
│   - Locations (Pickup Points, Destinations)                  │
└──────────────────────────────────────────────────────────────┘
```

## Permission Model

Permissions are managed through **Snipe-IT Groups**:

| Group ID | Group Name | Capabilities |
|----------|------------|--------------|
| 1 | Admins | Full system access including Settings |
| 2 | Drivers | Book vehicles, view own reservations |
| 3 | Fleet Staff | Approve reservations, manage maintenance |
| 4 | Fleet Admin | All staff permissions + user management |

## Screenshots

### Home & Login

![Home & Login](docs/screenshots/login.png)

*The landing page provides an overview of the Fleet Management System, including key features and a quick-access login section. Users authenticate via Microsoft OAuth (multi-tenant) or Google Sign-In. Upon successful login, users are automatically assigned permissions based on their Snipe-IT group membership.*

**Access:** Public (unauthenticated)

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

*Three-step booking flow: select location, choose dates (with business day calendar — weekends, holidays, and blackouts are grayed out), then pick from vehicles available for your date window. The vehicle list updates dynamically via AJAX, enforcing turnaround buffers and preventing double-booking.*

**Access:** All authenticated users

---

### My Reservations

![My Reservations](docs/screenshots/my_bookings.png)

*View and manage your own reservations. Cancel pending bookings, see approval status, and access checkout forms when ready.*

**Access:** All authenticated users

---

### Approval Queue

![Approval Queue](docs/screenshots/approval.png)

*Fleet Staff review pending reservation requests. Approve or reject with notes, view requester history and vehicle availability.*

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

*Comprehensive reporting including utilization rates, compliance status, and usage history. Export to CSV for further analysis.*

**Access:** Fleet Staff and above

---

### Vehicle Management

![Vehicle Management](docs/screenshots/vehicles.png)

*Administrative vehicle management with guided vehicle creation. Vehicle Name and Asset Tag (FLEET-VEH-###) are auto-generated for consistency. The system enforces duplicate checks on VIN and License Plate, requires compliance fields (insurance, registration), and presents a confirmation summary before creating. All vehicles sync to Snipe-IT as requestable assets.*

**Access:** Fleet Admin

---

### Add Vehicle

![Add Vehicle](docs/screenshots/vehicles_create.png)

*Guided vehicle creation form with governance controls. Vehicle Name is auto-generated from Year, Manufacturer, Model, and License Plate. Asset Tags follow the FLEET-VEH-### format and are never reused. The system validates VIN (17-character) and License Plate for duplicates in real-time, enforces required compliance fields, and presents a confirmation summary modal before submitting to Snipe-IT.*

**Access:** Fleet Admin


---

### User Management

![User Management](docs/screenshots/users.png)

*View users and their Snipe-IT group assignments. Track VIP status for auto-approval workflows.*

**Access:** Fleet Admin

---

### Email Notifications

![Email Notifications](docs/screenshots/notifications.png)

*Configure email notifications per event type. Set recipients, customize templates, toggle SMTP settings, and view default templates.*

**Access:** Fleet Admin

---

### Announcements

![Announcements](docs/screenshots/announcements.png)

*Create and manage system-wide announcements. Schedule display windows, set urgency levels, and make notices dismissible. Includes release announcement templates for system updates.*

**Access:** Fleet Admin

---

### Booking Rules

![Booking Rules](docs/screenshots/booking_rules.png)

*Configure fleet scheduling rules: vehicle turnaround buffer (business days between consecutive reservations), overdue redirect triggers, working day schedule, and a complete holiday calendar with pre-seeded US federal holidays (2025-2030). Admins can toggle individual holidays and add custom dates.*

**Access:** Fleet Admin

---

### Security Dashboard

![Security Dashboard](docs/screenshots/security.png)

*Monitor system security status including config file permissions, security headers, and backup recency. View backup logs and access maintenance commands. Available exclusively to super administrators.*

**Access:** Super Admin only

---

### Settings

![Settings](docs/screenshots/settings.png)

*Full system configuration including authentication providers (Microsoft OAuth, Google OAuth), SMTP settings, reservation controls, blackout period management, and system preferences.*

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

Create these groups in Snipe-IT → Settings → Groups:

| Group Name | Group ID | Purpose |
|------------|----------|---------|
| Drivers | 2 | Basic vehicle booking access |
| Fleet Staff | 3 | Approval and maintenance management |
| Fleet Admin | 4 | Full fleet administration |

#### 2. Status Labels

Create these status labels in Snipe-IT → Settings → Status Labels:

| Status Name | Type | Notes |
|-------------|------|-------|
| VEH-Available | Deployable | Default status for available vehicles |
| VEH-Reserved | Pending | Vehicle has upcoming reservation |
| VEH-In Service | Deployed | Vehicle currently checked out |
| VEH-Out of Service | Undeployable | Under maintenance |

#### 3. Custom Fields

Create a **Fleet Vehicle Fields** fieldset with these custom fields:

| Field Name | Element | Format | Unique | Used In |
|------------|---------|--------|--------|---------|
| VIN | Text | `regex:/^[A-HJ-NPR-Z0-9]{17}$/` | Yes | Checkout, Emails |
| License Plate | Text | `regex:/^[A-Z0-9]{1,3}[- ]?[A-Z0-9]{1,4}$/` | Yes | Checkout, Emails |
| Vehicle Year | Text | NUMERIC | No | Catalogue |
| Current Mileage | Text | `regex:/^[0-9]{1,7}$/` | No | Checkout, Checkin |
| Last Oil Change (Miles) | Text | `regex:/^[0-9]{1,7}$/` | No | Maintenance |
| Last Tire Rotation (Miles) | Text | `regex:/^[0-9]{1,7}$/` | No | Maintenance |
| Insurance Expiry | Text | DATE | No | Compliance Report |
| Registration Expiry | Text | DATE | No | Compliance Report |
| Holman Account # | Text | ANY | No | Maintenance Vendor |
| Last Maintenance Date | Text | DATE | No | Maintenance Tracking |
| Last Maintenance Mileage | Text | `regex:/^[0-9]{1,7}$/` | No | Maintenance Tracking |
| Maintenance Interval Miles | Text | `regex:/^[0-9]{1,7}$/` | No | Maintenance Alerts |
| Maintenance Interval Days | Text | NUMERIC | No | Maintenance Alerts |

**Inspection Fields** (populated during checkout/checkin):

| Field Name | Element | Format | Purpose |
|------------|---------|--------|---------|
| Visual Inspection Complete? | Listbox | ANY | Confirmation checkbox |
| Vehicle Condition Issues | Checkbox | ANY | Multi-select issue types |
| Exterior Issues Description | Textarea | ANY | Free text for exterior damage |
| Tire Issues Description | Textarea | ANY | Free text for tire problems |
| Undercarriage Issues | Textarea | ANY | Free text for undercarriage |
| Interior Issues Description | Textarea | ANY | Free text for interior condition |
| Checkout Time | Text | `regex:/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/` | HH:MM format |
| Return Time | Text | `regex:/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/` | HH:MM format |
| Expected Return Time | Text | `regex:/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/` | HH:MM format |

#### 4. Categories

Create a **Vehicles** category (or similar) for fleet assets.

#### 5. Manufacturers & Models

Create manufacturers (Ford, Chevrolet, etc.) and models (F-150, Silverado, etc.) for your fleet.

#### 6. Locations

Create locations for:

- **Pickup Points** — Where vehicles are collected
- **Destinations** — Where vehicles will be used (field offices, job sites)

### Asset Creation Template

When creating a new fleet vehicle in Snipe-IT:

1. **Asset Tag**: Use consistent format (e.g., `VEH-001`)
2. **Model**: Select appropriate vehicle model
3. **Status**: Set to `VEH-Available`
4. **Category**: Select `Vehicles`
5. **Location**: Set default/home location
6. **Custom Fields**: Fill in VIN, mileage, license plate
7. **Requestable**: Enable for booking availability

## API Integration

SnipeScheduler uses the Snipe-IT API exclusively — no direct database access to Snipe-IT.

Key API operations:

- `GET /hardware` — List available vehicles
- `PATCH /hardware/{id}` — Update status, location, custom fields
- `GET /users` — Validate users and group membership
- `GET /statuslabels` — Get status options
- `GET /locations` — Get pickup/destination options

## Database Schema

See [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) for complete schema documentation.

SnipeScheduler maintains its own database for:

- Reservations and approval history
- Inspection responses
- Email queue and notifications
- Announcements and blackout slots

## Security

See [SECURITY.md](SECURITY.md) for security documentation.

Key security features:

- CSRF protection on all forms
- XSS prevention with output encoding
- SQL injection prevention with PDO
- Security headers (X-Frame-Options, CSP, etc.)
- Config file permissions (640)
- Automated daily backups
- Built-in security scanner and remediation tools

## Credits

This project is a derivative work based on [SnipeScheduler](https://github.com/JSY-Ben/SnipeScheduler) by **Ben Pirozzolo**.

### Original Project

- **Author**: Ben Pirozzolo (JSY-Ben)
- **Repository**: https://github.com/JSY-Ben/SnipeScheduler
- **License**: MIT

### This Fork

- **Author**: Vitor Rodovalho
- **Repository**: https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager
- **Purpose**: Extended for enterprise fleet management with additional features

### Key Additions in FleetManager

- Fleet-specific inspection forms (checkout/checkin)
- Maintenance tracking with Snipe-IT custom field sync
- VIP auto-approval workflow
- Reservation controls (blackouts, min notice, max duration)
- Email notification engine with configurable SMTP
- Announcements system with scheduling and urgency levels
- Security dashboard with real-time checks
- Clean URLs (no .php extensions)
- Built-in release management with semantic versioning
- Automated screenshot generation with anonymization
- Security scanner and remediation tools
- Mobile-optimized responsive interface
- Business day engine with federal holiday calendar
- Booking rules administration (turnaround buffer, working days, holidays)
- Date-aware vehicle availability with AJAX filtering
- Overdue vehicle auto-redirect with alternate vehicle assignment
- Customizable email templates via admin notifications page

## License

This project is licensed under the GPL-3.0 License — see the [LICENSE](LICENSE) file for details.

The original [SnipeScheduler](https://github.com/JSY-Ben/SnipeScheduler) code by Ben Pirozzolo is licensed under the MIT License. All modifications and additions in this fork are licensed under GPL-3.0.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

## Support

For issues and feature requests, please use the [GitHub Issues](https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager/issues) page.

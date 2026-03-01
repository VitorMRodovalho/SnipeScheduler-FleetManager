# SnipeScheduler FleetManager

A comprehensive fleet vehicle management system built on top of [Snipe-IT](https://snipeitapp.com/), designed for enterprise fleet operations with reservation scheduling, maintenance tracking, and compliance management.

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
- 📅 **Book Vehicles** - Reserve vehicles in advance with pickup/return times
- 📱 **Mobile-Friendly** - Responsive design with QR code scanning
- ✅ **Digital Inspections** - Complete checkout/checkin forms on any device
- 📧 **Email Notifications** - Confirmation, reminders, and approvals

### For Fleet Staff
- 👥 **Approval Workflow** - Review and approve/reject reservations
- 🚗 **Vehicle Management** - Track all fleet vehicles and their status
- 🔧 **Maintenance Tracking** - Flag issues, track maintenance history
- 📊 **Reports** - Utilization, compliance, and usage reports

### For Administrators
- ⚙️ **Full Configuration** - LDAP/OAuth authentication, SMTP, custom fields
- 🔔 **Notification Controls** - Configure who receives which emails
- 📢 **Announcements** - Display system-wide notices to users
- 🔒 **Security Dashboard** - Monitor backup status and security checks
- 📋 **Reservation Controls** - Set booking rules (min notice, max duration, blackouts)

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
│   - User Management & Groups                                 │
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

*Screenshots coming soon. See [docs/SCREENSHOTS.md](docs/SCREENSHOTS.md) for documentation guidelines.*

### Key Screens

| Screen | Access Level | Description |
|--------|--------------|-------------|
| Dashboard | All Users | Today's schedule, stats, overdue alerts |
| Vehicle Catalogue | All Users | Browse available vehicles |
| Book Vehicle | All Users | Reservation form with date/time |
| My Reservations | All Users | View and manage own bookings |
| Checkout/Checkin | Drivers | Digital inspection forms |
| Approval Queue | Staff+ | Review pending reservations |
| Maintenance | Staff+ | Track vehicle maintenance |
| Reports | Staff+ | Utilization, compliance, history |
| Notifications | Admin | Email configuration |
| Announcements | Admin | System notices |
| Security | Super Admin | Backup status, security checks |
| Settings | Super Admin | Full system configuration |

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
| Checkout Time | Text | `regex:/^(0[0-9]\|1[0-9]\|2[0-3]):[0-5][0-9]$/` | HH:MM format |
| Return Time | Text | `regex:/^(0[0-9]\|1[0-9]\|2[0-3]):[0-5][0-9]$/` | HH:MM format |
| Expected Return Time | Text | `regex:/^(0[0-9]\|1[0-9]\|2[0-3]):[0-5][0-9]$/` | HH:MM format |

#### 4. Categories
Create a **Vehicles** category (or similar) for fleet assets.

#### 5. Manufacturers & Models
Create manufacturers (Ford, Chevrolet, etc.) and models (F-150, Silverado, etc.) for your fleet.

#### 6. Locations
Create locations for:
- **Pickup Points** - Where vehicles are collected
- **Destinations** - Where vehicles will be used (field offices, job sites)

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

SnipeScheduler uses the Snipe-IT API exclusively - no direct database access to Snipe-IT.

Key API operations:
- `GET /hardware` - List available vehicles
- `PATCH /hardware/{id}` - Update status, location, custom fields
- `GET /users` - Validate users and group membership
- `GET /statuslabels` - Get status options
- `GET /locations` - Get pickup/destination options

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
- Fleet-specific inspection forms
- Maintenance tracking with Snipe-IT integration
- VIP auto-approval workflow
- Reservation controls (blackouts, limits)
- Email notification configuration
- Announcements system
- Security dashboard
- Mobile-optimized interface
- Clean URLs (no .php extensions)

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

## Support

For issues and feature requests, please use the [GitHub Issues](https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager/issues) page.

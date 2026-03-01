# SnipeScheduler FleetManager Database Schema

**Version:** v1.3.1  
**Last Updated:** March 1, 2026

## Tables Overview

| Table | Purpose | Records |
|-------|---------|---------|
| activity_log | User action tracking | Active |
| announcements | System-wide announcements | v1.3.0 |
| announcement_dismissals | Track dismissed announcements per user | v1.3.0 |
| approval_history | Reservation approval/rejection history | Active |
| blackout_slots | Blocked dates/times for reservations | v1.3.0 |
| checked_out_asset_cache | Cache for catalogue display | Active |
| email_notification_settings | Per-event email configuration | v1.3.0 |
| email_queue | Pending emails (when SMTP disabled) | Active |
| inspection_responses | Vehicle checkout/checkin inspections | Active |
| maintenance_log | Vehicle maintenance records | Active |
| maintenance_schedule | Scheduled maintenance tracking | Active |
| notification_log | Cron notification tracking | Active |
| reservation_items | Basket-based booking items | Legacy |
| reservations | Main reservation records | Active |
| schema_version | Database version tracking | System |
| system_settings | Global system settings | v1.3.0 |
| users | User tracking (VIP sync) | Active |

## Table Details

### reservations
Primary table for all vehicle reservations.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| user_id | INT | User ID |
| user_name | VARCHAR | User display name |
| user_email | VARCHAR | User email |
| asset_id | INT | Snipe-IT asset ID |
| asset_name_cache | VARCHAR | Cached asset name |
| pickup_location_id | INT | Snipe-IT location ID |
| destination_id | INT | Destination location ID |
| start_datetime | DATETIME | Reservation start |
| end_datetime | DATETIME | Reservation end |
| status | VARCHAR | pending/confirmed/completed/cancelled |
| approval_status | VARCHAR | pending_approval/approved/rejected/auto_approved |
| notes | TEXT | User notes/purpose |
| created_at | DATETIME | Record creation time |

### inspection_responses
Stores checkout/checkin inspection form responses.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| reservation_id | INT | FK to reservations |
| inspection_type | ENUM | checkout/checkin |
| field_name | VARCHAR | Custom field name |
| field_value | TEXT | Response value |
| created_at | DATETIME | Submission time |

### email_notification_settings
Controls who receives emails for each event type.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| event_key | VARCHAR | Event identifier |
| event_name | VARCHAR | Display name |
| enabled | BOOLEAN | Enable/disable |
| notify_requester | BOOLEAN | Send to requester |
| notify_staff | BOOLEAN | Send to staff |
| notify_admin | BOOLEAN | Send to admin |
| custom_emails | TEXT | Additional recipients |
| subject_template | VARCHAR | Custom subject |
| body_template | TEXT | Custom body |

### announcements
System-wide announcements displayed to users.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| title | VARCHAR | Announcement title |
| content | TEXT | Message body |
| type | ENUM | info/warning/success/danger |
| start_datetime | DATETIME | Display start |
| end_datetime | DATETIME | Display end |
| is_active | BOOLEAN | Enable/disable |
| show_once | BOOLEAN | Dismissible |
| created_by_name | VARCHAR | Creator name |
| created_by_email | VARCHAR | Creator email |

### blackout_slots
Block specific times from reservations.

| Column | Type | Description |
|--------|------|-------------|
| id | INT | Primary key |
| title | VARCHAR | Blackout title |
| start_datetime | DATETIME | Block start |
| end_datetime | DATETIME | Block end |
| asset_id | INT | NULL for all vehicles |
| reason | TEXT | Explanation |
| created_by_name | VARCHAR | Creator |

## Relationships
```
reservations 1--* inspection_responses
reservations 1--* approval_history
reservations 1--* notification_log
announcements 1--* announcement_dismissals
```

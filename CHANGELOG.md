# Changelog

## v1.4.1 (2026-03-10)

HOTFIX: Block login for users without authorized Fleet group. Users who exist in Snipe-IT but are not assigned to any authorized group (Admins, Drivers, Fleet Staff, Fleet Admin) are now correctly denied access.

## v1.4.0 (2026-03-04)

Microsoft Teams notification channel — deliver Adaptive Cards to Teams via Power Automate webhooks; unified NotificationService dispatcher; per-event channel selector (Email/Teams/Both/Off); Super Admin webhook URL management with masked fields in Settings; Fleet Admin status-only view on Notifications page; async queue delivery with cron processing

## v1.3.6 (2026-03-04)

Configurable asset tag prefix, URL cleanup & security hardening

## v1.3.5 (2026-03-04)

Future Availability, Business Day Engine & Booking Rules

## v1.3.4 (2026-03-03)

Vehicle creation governance: auto-generated Vehicle Name and Asset Tag (FLEET-VEH-###), VIN/plate duplicate checks, confirmation modal, required field enforcement

## v1.3.3 (2026-03-02)

### Custom Fields - Snipe-IT API Integration
- Fixed field property mapping: display_checkout, display_checkin, display_audit (was using wrong API property names)
- Fixed element type mapping: type instead of element
- Checkout/checkin forms now dynamically filter fields based on Snipe-IT field settings
- Replaced hardcoded field exclusion lists with API-driven inclusion lists
- Auto-filled fields (Checkout Time, Return Time, Expected Return Time) properly excluded from forms

### Business Rules & Validation
- Current Mileage: mandatory field with real-time validation on checkout and checkin
- Mileage cannot be less than previously recorded value
- Mileage plausibility check on checkin: max 80 mph average over trip duration
- Mileage sanity check on checkout: max 5,000 mile increase
- Visual Inspection: must be marked "Yes" to proceed
- "I confirm" checkbox disabled until Visual Inspection = Yes
- Submit button disabled until all validations pass
- Frontend (JS) and backend (PHP) dual validation for security

### Vehicle Compliance Status
- New compliance card on checkout and checkin pages
- Shows Insurance, Registration, and Maintenance status with visual indicators
- Color-coded: green (ok), yellow (warning), red (expired/overdue), gray (unknown)
- Read-only display for driver transparency and liability documentation

### My Reservations Redesign
- Reservations ordered by ID (newest first)
- Visual pipeline: Booked → Approved → Checked Out → Returned
- Color-coded stage progression with active stage highlighted
- Scheduled vs Actual times displayed side-by-side
- OVERDUE badge and red border for overdue vehicles
- Delete button hidden for completed/confirmed reservations
- Status badges with contextual colors

### Reports Improvements
- Default date filter changed to "All Time" (was current month only)
- Quick filter presets: All Time, This Month, Last Month, Last 90 Days, YTD, Last Year
- Fixed duplicate maintenance query that was overwriting API data with local DB results
- Maintenance by Type and Maintenance Costs now display correctly

### Announcement System Fixes
- Fixed "Show Release Announcements" toggle (POST handler was missing)
- Added system_settings integration for toggle persistence
- Fixed HTML rendering in announcement list (system vs user content)
- Defined $showReleaseAnnouncements variable before template use

### Email Notifications
- Removed emojis from all email subjects for professional appearance
- Added [ACTION REQUIRED] prefix for maintenance alerts
- Added [OVERDUE] prefix for overdue vehicle alerts
- Added email queue processor to cron (every 5 minutes)

### Security & Backup
- Fixed backup directory permissions for security dashboard visibility
- Backups running daily at 2:00 AM (verified 6 backups present)


## v1.3.2 (2026-03-02)

### Email System
- SMTP fully operational (port 587 enabled)
- Processed 34 pending emails successfully
- Added `cron/process_email_queue.php` for queue processing
- Fixed `email_service.php` to send directly (removed queue bypass)
- Cron configured: every 5 minutes

### UI/UX Improvements
- Reduced nav font-size (0.92rem → 0.82rem) to prevent line wrapping
- Reduced nav padding and gap for compact display
- Updated CSS cache bust to v=1.3.2

### Screenshots & Documentation
- 15 anonymized screenshots for README
- Automated screenshot script (`scripts/take-screenshots.js`)
- Anonymization for names, emails, locations, VINs
- Footer credits preserved in screenshots

### Release Management
- New `scripts/release.php` for version management
- Supports major/minor/patch versioning
- Auto-updates version.txt, CHANGELOG.md, CSS cache
- Creates system announcement for releases
- Toggle in Admin → Announcements for release notifications
- Database: added `is_system`, `system_type` columns to announcements

---

## v1.3.1 (2026-03-01)

### Security & Infrastructure
- Clean URLs implemented - `.php` extensions hidden from browser
- All internal links and redirects updated for clean URLs
- Fixed PHP timezone duplicate configuration
- Disabled Apache MultiViews to prevent URL conflicts

### Admin Features
- New Security Dashboard (Super Admin only)
  - Real-time security status checks
  - Backup log viewer
  - Maintenance commands reference
  - Restore procedure documentation

### UI/UX Improvements
- Fixed checkbox/radio button visibility issues
- Improved vehicle card selection feedback
- Cache busting on all CSS files (v=1.3.1)

### Code Cleanup
- Archived legacy files (snipeit_db.php, book_submit.php, cancel_reservation.php)
- Created comprehensive database schema documentation
- Updated schema version tracking

### Documentation
- Complete README rewrite with project context
- Snipe-IT configuration guide
- Database schema documentation
- Improved GitHub release workflow

---

## v1.3.0 (2026-02-28)

### New Features

#### Reservation Controls
- Minimum notice period (hours)
- Maximum reservation duration (hours)
- Maximum concurrent reservations per user
- Staff bypass option
- Blackout Slots management (block dates/times)

#### Email Notifications Admin
- Enable/disable per event type
- Configure recipients: Requester, Staff, Admin, Custom
- Custom subject/body templates
- SMTP master toggle
- Email queue stats & test emails
- 8 notification events supported

#### Announcements System
- Timed announcements (start/end dates)
- 4 styles: Info, Success, Warning, Danger
- Dismissible per user
- Modal display on dashboard

### Admin Navigation
- New tabs: Notifications, Announcements
- Integrated with existing permission structure

---

## v1.2.2 (2026-02-28)

### Performance Improvements
- Added API response caching (2-10 min TTL)
- Added CURL timeouts to prevent hanging requests
- Email queuing to avoid SMTP timeouts

### Bug Fixes
- Modal z-index blocking issue resolved
- Approval email notification corrected
- CSRF tokens added to delete forms
- EST timezone display fixed

### Mobile Optimization
- Touch-friendly buttons (44px minimum)
- QR scanner camera support
- Full-screen modals on mobile

### Security
- Drivers cannot delete checked-out reservations

---

## v1.2.1 (2026-02-27)

- Initial GitHub Actions release automation
- Dynamic footer version from version.txt
- Comprehensive INSTALLATION.md guide

---

## v1.2.0 (2026-02-26)

- Fleet dashboard with status cards
- Vehicle inspection forms (checkout/checkin)
- Maintenance logging with Snipe-IT sync
- Comprehensive reports (5 report types)
- QR code scanning for quick actions
- Mobile CSS optimization

---

## v1.1.0 (2026-02-25)

- Snipe-IT API integration
- User management via Snipe-IT groups
- VIP auto-approval workflow
- Email notification system
- Activity logging

---

## v1.0.0 (2026-02-19)

- Initial release based on SnipeScheduler
- Basic reservation system
- Microsoft OAuth authentication
- Location-based booking

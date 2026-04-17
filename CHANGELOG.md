# Changelog

## [2.1.1] - 2026-04-17

### Security
- image_proxy: now requires authentication (previously only loaded bootstrap, not auth.php)
- image_proxy: disabled `CURLOPT_FOLLOWLOCATION` so a 3xx redirect cannot bypass the Snipe-IT host validation (SSRF hardening)
- image_proxy: content-type is now verified as `image/*` before streaming, and `X-Content-Type-Options: nosniff` is set
- Email SMTP envelope addresses are now validated and rejected if they contain CR/LF or fail `FILTER_VALIDATE_EMAIL` (prevents SMTP header injection)
- Email EHLO hostname is now configurable (`smtp.ehlo_host`); no more hardcoded `reserveit.local`
- `create_snipeit_user()` temp password now generated via `random_bytes()` with URL-safe base64 (was `rand()`-based, ~10k possibilities)
- `basket_add` now enforces CSRF check
- `basket_remove` now requires POST + CSRF check (was a GET endpoint that could be triggered by `<img>`/link prefetch)
- Google OAuth login now resolves Snipe-IT permissions like the Microsoft flow; previously `$snipePerms`, `$snipeitId`, `$isVip` were undefined in that branch

### Fixed
- `update_asset_status` / `update_asset_location` now use the shared `snipeit_request()` wrapper, honoring retry, SSL verification, and cache invalidation (previously raw curl with no safety nets)
- `get_maintenances` / `get_asset_maintenances` now hit the correct endpoint `GET /maintenances?asset_id=...` (previous `GET /hardware/{id}/maintenances` returned 404 on Snipe-IT)
- `create_maintenance` now sends the `title` field that Snipe-IT expects (was sending `name`; maintenance records created without titles)
- `snipeit_request()` retry now parses the `Retry-After` HTTP header (was hardcoded to 2 seconds regardless of server response)
- `sync_checked_out_assets.php` uses `DELETE FROM` instead of `TRUNCATE`, keeping the transaction atomic (TRUNCATE implicitly commits in MySQL)
- `bootstrap.php` timezone is now taken from `app.timezone` config (was hardcoded `America/New_York`)
- Location parent IDs for pickup/destination lookups are now read from `snipeit_location_parents.{pickup,destination}` (were hardcoded `9` and `10`)

### Added
- `snipeit_invalidate_cache()` helper for write operations
- `snipeit_generate_temp_password()` helper for cryptographically strong placeholder passwords

## [2.1.0] - 2026-03-20

### Added
- BL-008: Vehicle Assignment System (Phase 1 + Phase 2)
  - vehicle_assignments table with per-vehicle driver assignment (primary + secondary)
  - Assignment enforcement modes: Off / Soft Warning / Enforced (system_settings toggle)
  - Catalogue filtering: enforced mode shows only assigned + pool vehicles for drivers
  - Staff/Admin see all vehicles with assignment badges
  - Cross-company validation prevents assigning drivers from different companies
  - Offboarding and CCPA deletion cascade to vehicle_assignments
  - DSAR export includes assignment data
  - Backend safety net: reservation validator rejects unauthorized bookings
  - Approval queue shows warning when vehicle was reassigned post-submission
  - Redirect engine constrained to pool vehicles in enforced/soft mode
  - QR quick checkout validates assignment in enforced mode
  - Book on Behalf: soft warning for cross-assignment, staff override preserved
- Vehicle assignment badges on catalogue and booking pages
  - Label + primary name with tag icon
  - Primary name with person icon for unlabeled assignments
  - +N count badge for vehicles with multiple authorized drivers
  - Gray Pool badge for unassigned vehicles
- Vehicle Assignment modal with vehicle-level label editing
- fleet_tag_config table: per-company asset tag prefix with auto-increment
- Asset tags migrated from license plates to unit numbers
- Add Vehicle form: auto-generates tags from fleet_tag_config
- 34 drivers onboarded via Snipe-IT API (30 new + 4 updated)
- 16 vehicles registered with full custom field data (VIN, plate, year, mileage, insurance, maintenance intervals)
- 17 vehicle assignments loaded with primary drivers and labels
- Training validity period: 36-month option added to Booking Rules
- Help page: badge guide, assignment management, asset tag format sections
- User Guide: A11, B13, C18, C19 sections for assignment workflow
- Announcement popup moved from dashboard-only to layout footer (shows on first page after login)

### Fixed
- Company badge double-encoding (Snipe-IT returns pre-encoded HTML entities)
- Settings form POST action pointing to activity_log instead of settings
- Settings form nested form tag breaking save button
- Vehicle assignments API auth using wrong session variable pattern
- Training validity whitelist missing 36-month option
- Color picker placeholder #YOURHEX replaced with valid hex
- vehicle_catalogue.php missing assignment filtering for drivers

### Changed
- Asset tag pattern: replaced (configurable per-company via fleet_tag_config)
- Vehicle name format: [Year] [Manufacturer] [Model] (removed plate from name)
- Quick Reference sidebar updated with new naming convention

---

## v1.5.1 (2026-03-12)

Epic 2-3: Fleet Health Dashboard, Reports CXO overhaul, driver typeahead, behalf booking fix

## v1.5.0 (2026-03-11)

Corporate UI rebrand, dynamic group-based notifications, driver sync merge, checkout/checkin UX improvements, modal z-index fix, CSP security headers

## v1.4.3 (2026-03-10)

BL-008: CRON sync health check with alerting and Security dashboard monitoring. BL-009: API retry with exponential backoff for rate limiting and server errors. BL-011 complete: Driver training gate with configurable validity, date picker, expiration alerts, and Booking Rules admin. VIP toggle and CSRF fixes across Users page.

## v1.4.2 (2026-03-10)

Externalize hardcoded Snipe-IT IDs and field mappings to config. Group IDs, status label IDs, and custom field column names are now configurable. Added startup validation script (scripts/validate_snipeit.php).

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

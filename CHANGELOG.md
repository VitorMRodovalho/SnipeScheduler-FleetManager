# Changelog

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

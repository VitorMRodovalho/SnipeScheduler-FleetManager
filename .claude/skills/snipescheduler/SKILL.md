---
name: snipescheduler-fleetmanager
description: >
  Use this skill for ALL work on the SnipeScheduler FleetManager project — a PHP fleet vehicle
  reservation and management system built on top of Snipe-IT. Activate whenever touching: PHP files,
  MySQL queries, Snipe-IT API calls, reservation logic, checkout/checkin flows, inspection checklists,
  maintenance tracking, approval workflows, notification/email code, CRON jobs, dashboard/reports,
  user management, training validity, vehicle assignments, or deployment/release tasks. Also use when
  editing config, running migrations, managing file permissions, or preparing git commits. This skill
  prevents the recurring bugs caused by domain-specific gotchas (user ID mapping, API pagination caps,
  config architecture, multi-entity security, status label names). If you're working in
  /var/www/snipescheduler or on any file in this repo, READ THIS SKILL FIRST.
---

# SnipeScheduler FleetManager — Project Skill

## 1. Architecture Overview

- **Stack:** PHP 8.x (no framework), MySQL 8, Apache2, Ubuntu 24.04 on AWS EC2
- **Base system:** Snipe-IT v8.4.0 (asset management) — we extend it, never modify its core
- **Auth:** Microsoft OAuth SSO (multi-tenant: `organizations`)
- **URL:** `inventory.amtrakfdt.com/booking`
- **Repo:** `github.com/VitorMRodovalho/SnipeScheduler-FleetManager`
- **Current version:** v2.0.0 (check `version.txt` for truth)

### Directory Layout
```
/var/www/snipescheduler/
├── config/
│   ├── config.php          # GITIGNORED — instance-specific Snipe-IT IDs, API keys
│   ├── config.example.php  # Committed — uses placeholders
│   ├── cache/              # API response cache (JSON files)
│   └── security_terms.json # GITIGNORED — org-specific terms
├── public/                 # Web-accessible PHP pages
│   ├── api/                # AJAX endpoints (search_drivers.php, etc.)
│   ├── assets/             # CSS, JS, images
│   ├── install/            # Installer + schema.sql
│   └── uploads/            # User-uploaded photos (inspections)
├── src/
│   ├── bootstrap.php       # Session, auth, DB init — loaded by every page
│   ├── layout.php          # Shared HTML shell (nav, footer, CSP headers)
│   ├── snipeit_client.php  # ALL Snipe-IT API calls (~2600 lines)
│   ├── email_service.php   # SMTP email + queue
│   ├── teams_service.php   # Microsoft Teams webhooks via Power Automate
│   └── booking_helpers.php # Reservation logic, vehicle redirect, status helpers
├── scripts/
│   ├── release.php         # Version bump + changelog + screenshot prompt
│   ├── validate_snipeit.php # Startup entity validation
│   └── take-screenshots.js # Puppeteer screenshot capture
├── docs/                   # Markdown documentation
├── version.txt             # Single source of truth for version number
└── CHANGELOG.md
```

## 2. Critical ID Mappings (Cheat Sheet)

### User ID — THE #1 SOURCE OF BUGS
```
OAuth login → CRC32 hash of email → stored as users.user_id (e.g., "3924312588")
Snipe-IT API → sequential integer → stored as users.snipeit_user_id (e.g., 42)

RULE: Session uses user_id (OAuth). Snipe-IT API uses snipeit_user_id.
      Reservations store user_id (OAuth) in the user_id column.
      NEVER mix them. If booking on behalf, store the TARGET user's user_id, not the staff's.
```

### Snipe-IT Group IDs
```
1 = Super Admin (platform administrators)
2 = Drivers (can reserve vehicles, do inspections)
3 = Fleet Staff (view/approve reservations, manage day-to-day)
4 = Fleet Admin (full fleet control, reports, settings)
```

### Status Labels (exact Snipe-IT names)
```
"VEH-Available"      → Vehicle ready for reservation
"VEH-Out of Service" → Maintenance, accident, or demobilized
```
NEVER create new status labels without checking Snipe-IT first.

### Config Architecture (3-layer)
```
Layer 1: config/config.php (GITIGNORED)
  → Snipe-IT API URL, API key, asset model IDs, location parent IDs
  → Instance-specific, machine-specific values

Layer 2: system_settings table (MySQL)
  → Runtime config: training_validity_months, inspection_mode, photo_upload_enabled
  → Changed via Settings UI, canonical for runtime behavior

Layer 3: config/config.example.php (COMMITTED)
  → Placeholder values for documentation/new installs
  → NEVER put real IDs or URLs here
```

## 3. Database Gotchas

### Key Tables
- `reservations` — Core booking data. `user_id` is OAuth ID (string), NOT Snipe-IT ID
- `users` — Local user cache. Has both `user_id` (OAuth) and `snipeit_user_id`
- `inspection_results` — 50-item checklist results, linked to reservation_id
- `inspection_photos` — Photo uploads linked to inspection_results
- `system_settings` — Key-value runtime config
- `activity_log` — Audit trail for all actions
- `checklist_items` — Configurable inspection checklist (admin-managed)
- `email_queue` — Outbound email queue, processed by CRON every 2 min

### Multi-Entity Security
```
Company filtering is MANDATORY on all queries that list data for staff/admin.
The approval queue, staff reservations, and notification recipients MUST filter
by the logged-in user's company_id.

Fleet Admin and Super Admin see cross-entity data.
Fleet Staff sees only their own entity's data.
Drivers see only their own reservations.
```

### SQL Patterns
```php
// CORRECT — positional parameters only (PDO)
$stmt = $pdo->prepare("SELECT * FROM reservations WHERE user_id = ? AND status = ?");
$stmt->execute([$userId, $status]);

// WRONG — mixing named and positional causes PDO crash
$stmt = $pdo->prepare("SELECT * FROM reservations WHERE user_id = :uid AND status = ?");
```

## 4. Snipe-IT API Rules

### Pagination — CRITICAL
```
Default API limit = 100 items. If you call get_requestable_assets(100), you get
AT MOST 100 results, silently dropping the rest.

ALWAYS use fetch_all_paginated() for any list that could exceed 100 items:
- Assets (vehicles)
- Users
- Maintenances
- Locations

The function is in snipeit_client.php. If it doesn't exist for your endpoint, add it.
```

### API Field Names vs Display Names
```
Snipe-IT API returns:     What you might expect:
  asset.status_label.name   asset.status
  asset.model.name          asset.model
  asset.rtd_location.name   asset.location
  asset.assigned_to.name    asset.user

Custom fields are nested under asset.custom_fields.<field_name>.value
```

### Maintenance API
```
POST /maintenances requires field "title" (not "name")
GET /hardware/{id}/maintenances does NOT exist — use GET /maintenances?asset_id={id}
```

### Cache Invalidation
```
After any write operation (checkout, checkin, status change, location update):
  rm -f config/cache/*.json
Or in PHP: array_map('unlink', glob(CONFIG_DIR . '/cache/*.json'));
```

## 5. CSRF Protection Pattern

```php
// In form:
<?= csrf_field() ?>

// In handler (top of POST block):
csrf_check();  // Exits with 403 if token mismatch

// EVERY form POST must have both. No exceptions.
```

## 6. Notification Architecture

```
Email: SMTP via email_service.php → email_queue table → CRON processes every 2 min
Teams: Power Automate webhooks via teams_service.php → public channels only

Recipients are resolved from Snipe-IT group membership, filtered by company_id.
Fleet Admin + Super Admin always receive cross-entity notifications.
```

## 7. File Permissions — MUST FOLLOW

```bash
# Web files (Apache needs to read/write)
sudo chown -R www-data:www-data public/ src/ scripts/ docs/ uploads/

# Git directory (your user needs to push)
sudo chown -R vitor:www-data .git/

# Config file (restricted)
sudo chmod 640 config/config.php
sudo chown www-data:www-data config/config.php

# After any git pull:
sudo chown -R www-data:www-data public/ src/ scripts/ docs/ uploads/
```

## 8. Editing Rules — PREVENTS PRODUCTION OUTAGES

### ALWAYS before committing:
```bash
# Lint check EVERY changed PHP file
find public/ src/ -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"

# If ANY file fails lint → DO NOT COMMIT
```

### PHP editing approach (ranked by reliability):
1. **Full file replacement** via SCP → `sudo cp` (most reliable)
2. **Python script with marker-based replacement** (read → find marker → replace → write)
3. **nano with explicit line numbers** for small targeted edits
4. **str_replace** for single-line changes

### NEVER USE for multi-line PHP edits:
- `sed` with multi-line patterns (breaks on PHP `$` variables)
- Heredoc approaches for complex insertions
- Inline Python with unescaped PHP `$` variables

### If sed/heredoc fails due to `$` escaping:
Write the Python script to a file first via `tee` with a **quoted** delimiter:
```bash
tee /tmp/fix_script.py << 'PYEOF'
# Python code here — raw strings r"""...""" for PHP content
PYEOF
python3 /tmp/fix_script.py
```

## 9. Security Rules

### Code commits:
- **NO org-specific names** in committed files (Amtrak, AECOM, BPTR, FDT)
- Acceptable ONLY in gitignored files: `config/config.php`, `docs/PROJECT_CONTEXT.md`, `config/security_terms.json`
- Footer/developer attribution is intentionally left visible
- **NO `.bak` files** committed ever
- **NO debug output** (console.log, var_dump, print_r) in production code

### Session security:
- `session.cookie_httponly = true`
- `session.cookie_secure = true`
- `session.cookie_samesite = Strict`
- CSP headers set in `layout.php`

## 10. Release Workflow

```bash
# Ensure www-data owns version.txt and CHANGELOG.md
sudo chown www-data:www-data version.txt CHANGELOG.md

# Run release script
sudo -u www-data php scripts/release.php patch|minor|major "Release notes here"

# Follow the git commands it outputs
# minor = bumps middle version (2.x.0)
# patch = incremental (2.0.x)
```

### Dual remote push:
```bash
git push origin main
# 'origin' has two push URLs: GitHub + AWS CodeCommit
# Both receive the push simultaneously
```

## 11. CRON Jobs (12 active)
```
*/2  * * * *  — Process email queue
*/5  * * * *  — Mark missed reservations
*/15 * * * *  — Sync Snipe-IT user cache
0    6 * * *  — Training expiry notifications
0    7 * * 1  — Weekly fleet summary email
0    1 * * *  — Clean expired sessions
0    2 * * *  — Backup database
0    3 * * *  — Clean API cache
0    0 * * *  — Snipe-IT backup:run
30   0 * * *  — Snipe-IT backup:clean
0    4 * * 1  — Insurance/registration expiry check
*/10 * * * *  — Scheduled task processor
```

## 12. Common Pitfalls (Historical Bug Registry)

| Bug | Root Cause | Fix |
|-----|-----------|-----|
| Behalf bookings invisible to driver | Stored snipeit_user_id instead of OAuth user_id | Always use `$_SESSION['user_id']` of target user |
| Dashboard counts wrong for >100 vehicles | `get_requestable_assets(100)` caps results | Use `fetch_all_paginated()` |
| `&amp;` shows in company badge | Snipe-IT returns pre-encoded HTML; `htmlspecialchars()` double-encodes | Use `htmlspecialchars_decode()` first or raw output |
| PDO crash on dashboard | Mixed named + positional params | Use ALL positional (`?`) |
| Approval queue shows other entity's data | No company_id filter on query | Add `WHERE company_id = ?` |
| Rejection never saved | Wrong SQL column names + missing `execute()` | Fix column names, add execute |
| Maintenance API 404 | Called `/hardware/{id}/maintenances` (doesn't exist) | Use `/maintenances?asset_id={id}` |
| reservation crash | `company_abbr` VARCHAR(10) too short | ALTER TABLE to VARCHAR(50) |
| Checkout checkboxes pre-populated | Snipe-IT custom field values loaded into form | Use `$forceEmpty` flag |

## 13. Current Backlog (as of v2.0.0)

- [ ] `&amp;` double-encoding fix in layout.php company badge
- [ ] Vehicle onboarding: 16 vehicles (FDT-01 to FDT-16) + 35 drivers
- [ ] BL-008: Restrictive vehicle assignment (Pete confirmed: hard lock, not preference)
- [ ] Training dates loading for 30 drivers (expiration dates received, back-calculate issuance)
- [ ] Mileage in/out fields at checkout/checkin (Pete confirmed: no fuel log needed)

## 14. Testing Checklist (Before Any Deploy)

```bash
# 1. PHP lint — ALL files
find public/ src/ -name "*.php" -exec php -l {} \; | grep -v "No syntax errors"

# 2. Validate Snipe-IT connection
php scripts/validate_snipeit.php

# 3. Check file permissions
ls -la config/config.php  # Should be 640, www-data:www-data
ls -la version.txt        # Should be www-data:www-data

# 4. Test in browser
# - Login via SSO
# - Dashboard loads with charts
# - Reserve a vehicle → approve → checkout → checkin
# - Check approval queue filters by company
# - Verify CRON: sudo tail -20 /var/log/syslog | grep CRON

# 5. Clear cache after deploy
rm -f config/cache/*.json
```

# SnipeScheduler FleetManager - Project Context

## Overview

**Repository:** https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager
**Production URL:** https://inventory.amtrakfdt.com/booking/
**Current Version:** v1.3.3
**Server:** AWS EC2 Ubuntu 24.04

## Architecture
```
SnipeScheduler (PHP) ←→ Snipe-IT API (Asset Management)
         ↓
    MySQL Database (snipescheduler)
         ↓
    Apache2 + mod_rewrite (Clean URLs)
```

## Key Directories
```
/var/www/snipescheduler/
├── config/config.php      # Main config (640 permissions, www-data owner)
├── public/                # Web-accessible files
├── src/                   # Core PHP classes
├── cron/                  # Scheduled tasks
├── scripts/               # Utility scripts
├── docs/                  # Documentation
└── archive/               # Legacy files
```

## Permission Model (Snipe-IT Groups)

| Group ID | Name | Capabilities |
|----------|------|--------------|
| 1 | Admins | Full system access (Super Admin) |
| 2 | Drivers | Book vehicles, view own reservations |
| 3 | Fleet Staff | Approve reservations, manage maintenance |
| 4 | Fleet Admin | All staff permissions + user management |

## Database Tables (17 active)

- `reservations` - Main bookings
- `approval_history` - Approval tracking
- `inspection_responses` - Checkout/checkin forms
- `maintenance_log` - Vehicle maintenance
- `email_queue` - Pending emails
- `announcements` - System notices
- `announcement_dismissals` - User dismissals
- `system_settings` - Global config
- `activity_log` - User actions

## Key Configuration

### OAuth (Multi-tenant)
```php
'microsoft_oauth' => [
    'tenant' => 'organizations',  // Multi-tenant support
    // AECOM works, Amtrak requires admin consent
]
```

### SMTP
```php
'smtp' => [
    'host' => 'smtp-mail.outlook.com',
    'port' => 587,
    'from_email' => 'comms@amtrakfdt.com',
    'from_name' => 'Fleet Management System',
]
```

### App Settings
```php
'app' => [
    'base_url' => 'https://inventory.amtrakfdt.com/booking',
    'timezone' => 'America/New_York',
]
```

---

## Common Commands

### Release Management
```bash
# View current version
sudo -u www-data php /var/www/snipescheduler/scripts/release.php --current

# Create patch release (bug fixes): 1.3.2 → 1.3.3
sudo -u www-data php /var/www/snipescheduler/scripts/release.php patch "Description"

# Create minor release (features): 1.3.3 → 1.4.0
sudo -u www-data php /var/www/snipescheduler/scripts/release.php minor "Description"

# Create major release (breaking): 1.4.0 → 2.0.0
sudo -u www-data php /var/www/snipescheduler/scripts/release.php major "Description"
```

### Git Workflow
```bash
cd /var/www/snipescheduler
git add -A
git status
git commit -m "Description"
git push origin main

# Create tag
git tag -a v1.3.3 -m "Release v1.3.3: Description"
git push origin v1.3.3
```

### Email Queue
```bash
# Process pending emails
sudo -u www-data php /var/www/snipescheduler/cron/process_email_queue.php

# Check queue status
sudo mysql -u root snipescheduler -e "SELECT status, COUNT(*) FROM email_queue GROUP BY status;"
```

### Security Scan
```bash
# Run security scanner
python3 /var/www/snipescheduler/scripts/security_scan.py

# Run remediation
python3 /var/www/snipescheduler/scripts/security_remediate.py
```

### Screenshots
```bash
cd /var/www/snipescheduler/scripts
node take-screenshots.js --session=YOUR_PHPSESSID
```

### PHP Syntax Check
```bash
php -l /var/www/snipescheduler/path/to/file.php
```

### Apache
```bash
sudo systemctl reload apache2
sudo tail -f /var/log/apache2/error.log
```

### Permissions Fix
```bash
# Standard permissions
sudo chown -R vitor:www-data /var/www/snipescheduler/
sudo chmod -R 775 /var/www/snipescheduler/

# Config (secure)
sudo chmod 640 /var/www/snipescheduler/config/config.php
sudo chown www-data:www-data /var/www/snipescheduler/config/config.php

# Git directory (for vitor user)
sudo chown -R vitor:vitor /var/www/snipescheduler/.git
```

---

## Active Cron Jobs
```bash
# Email queue processor (every 5 min)
*/5 * * * * /usr/bin/php /var/www/snipescheduler/cron/process_email_queue.php >> /var/log/snipescheduler-email.log 2>&1

# Daily backup (2:00 AM)
0 2 * * * /usr/local/bin/backup-snipescheduler.sh
```

---

## Current Issues / Pending

### 1. Announcement System Bugs (RESOLVED v1.3.3)
- ~~Toggle "Show Release Announcements" not activating~~ Fixed
- ~~HTML tags showing in announcement list view~~ Fixed
- Release announcement template updated

### 2. Amtrak OAuth
- Requires IT admin consent
- Ticket submitted to Amtrak Service Desk
- Client ID: 00eb743d-d5ad-42ab-bc3b-b63f755cbd22

### 3. Security Scan Findings
- 272 total findings (59 high, 65 medium, 148 low)
- Most are intentional (config emails, Snipe-IT URLs)
- Low severity: password/secret field names (OK)

---

## Version History

| Version | Date | Key Changes |
|---------|------|-------------|
| v1.3.3 | 2026-03-02 | SMTP working, Screenshots, Release script, Security scanner |
| v1.3.1 | 2026-03-01 | Clean URLs, Security Dashboard, UI improvements |
| v1.3.0 | 2026-02-28 | Reservation controls, Email admin, Announcements |
| v1.2.2 | 2026-02-28 | API caching, Mobile optimization, Bug fixes |

---

## Best Practices

### Code Changes
1. Always run `php -l` after editing PHP files
2. Test locally before committing
3. Use `sudo -u www-data` when running PHP scripts that need config access

### Git
1. Don't commit `node_modules/` or `vendor/`
2. Check `git status` before committing to avoid mode-only changes
3. Use `git config core.fileMode false` if mode changes appear

### Security
1. Never hardcode credentials in PHP files
2. Use config.php for all environment-specific values
3. Run security_scan.py before releases
4. Keep config.php at 640 permissions

### Releases
1. Update CHANGELOG.md with detailed notes
2. Update version.txt
3. Update CSS cache bust (style.css?v=X.X.X)
4. Create system announcement for users
5. Tag release in Git

---

## File Reference

### Key Files
- `/var/www/snipescheduler/version.txt` - Current version
- `/var/www/snipescheduler/CHANGELOG.md` - Release history
- `/var/www/snipescheduler/config/config.php` - Main configuration
- `/var/www/snipescheduler/src/email_service.php` - Email notifications
- `/var/www/snipescheduler/src/announcements.php` - Announcement system
- `/var/www/snipescheduler/public/announcements.php` - Announcement admin UI

### Scripts
- `scripts/release.php` - Version management
- `scripts/security_scan.py` - Find sensitive data
- `scripts/security_remediate.py` - Fix sensitive data
- `scripts/take-screenshots.js` - Automated screenshots

---

## Next Session Tasks

1. **Fix Announcement System:**
   - Toggle "Show Release Announcements" not working
   - HTML showing in admin list view (different from modal)
   - Update release template to include GitHub changelog link

2. **Test Email Flow:**
   - Create new reservation
   - Verify emails are sent (not just queued)

3. **Consider:**
   - Add scheduled_tasks.php to cron (reminders, overdue alerts)
   - Verify backup cron is running

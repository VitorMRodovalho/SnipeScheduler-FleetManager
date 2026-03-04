# Developer & Maintenance Guide

A comprehensive guide for software engineers maintaining **SnipeScheduler FleetManager**. Covers anonymization policies, screenshot generation, documentation standards, release workflow, and dual Git repository management.

---

## Table of Contents

1. [Anonymization Policy](#anonymization-policy)
2. [Screenshot Generation](#screenshot-generation)
3. [Documentation Structure](#documentation-structure)
4. [Release Workflow](#release-workflow)
5. [Dual Git Repository Management](#dual-git-repository-management)
6. [Security Scanning](#security-scanning)
7. [File Permissions](#file-permissions)
8. [Quick Reference](#quick-reference)

---

## Anonymization Policy

This project is deployed for a specific client organization but the source code is published to public repositories. All organization-identifying information must be removed from any file that is committed to version control, with specific exceptions documented below.

### What Must Be Anonymized

| Category | Examples | Replacement |
|----------|----------|-------------|
| Company names | Client org names, partner names | Generic terms (e.g., "Organization") |
| Project identifiers | Internal project codes or infrastructure names | Remove entirely |
| Domain names | `inventory.clientdomain.com` | Read from `config.php` or use `your-snipeit-domain.com` |
| Email addresses | `user@clientdomain.com` | `user@example.com` |
| Asset tag prefixes | `PROJ-VEH-###` | Read from `system_settings` DB table via `get_asset_tag_prefix()` |
| Location names | Client-specific office or site names | Generic terms (e.g., "Main Office") |

### What Is Allowed to Remain

| Context | Reason |
|---------|--------|
| `config/config.php` | In `.gitignore`, never committed |
| `docs/PROJECT_CONTEXT.md` | Local-only (in `.gitignore`), used for session context |
| `config/security_terms.json` | Local-only (in `.gitignore`), used by scanner |
| `scripts/take-screenshots.js` | Contains anonymization replacement rules that map real terms to generic ones |
| `README.md` Credits section | Author name and GitHub URL are standard for open source |
| `LICENSE` | Author attribution is legally required |
| `scripts/release.php` | References the public GitHub repository URL |
| Footer credits in `layout.php` | Developer attribution is intentionally visible in the UI |

### How Anonymization Works

**Configuration-driven approach (preferred):**

Sensitive values are stored in `config/config.php` (gitignored) or the `system_settings` database table. PHP code reads these at runtime rather than hardcoding values.

Example — Asset tag prefix:
```php
// DO NOT hardcode: $prefix = 'PROJ-VEH-';
// DO use the helper:
$prefix = get_asset_tag_prefix();  // Reads from system_settings table
```

Example — Snipe-IT base URL:
```php
// DO NOT hardcode: href="https://specific-domain.com/hardware/123"
// DO use config:
href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/hardware/123"
```

### Footer Credits — Intentional Exception

The application footer displays developer attribution. This is **intentionally not anonymized** in screenshots because:

1. It serves as developer credit visible to end users
2. It matches what users actually see in production
3. The author name is already public in README.md, LICENSE, and Git history

The `take-screenshots.js` anonymization rules deliberately skip the footer credit line.

---

## Screenshot Generation

Screenshots are auto-generated using Puppeteer with built-in anonymization.

### Prerequisites
```bash
cd /var/www/snipescheduler/scripts
npm install  # Installs puppeteer
```

### Running the Screenshot Script

1. Get a fresh PHP session cookie from your browser (DevTools > Application > Cookies > `PHPSESSID`)
2. Run:
```bash
cd /var/www/snipescheduler/scripts
node take-screenshots.js --session=YOUR_PHPSESSID
```

**Important:** Sessions expire. If screenshots show the login page, obtain a new `PHPSESSID`.

### How Screenshot Anonymization Works

The script defines a `PAGES` array of routes and an `ANONYMIZE_RULES` array of find/replace pairs. Before each screenshot the page DOM is walked and all matching text is replaced.

### Adding New Pages

Add to the `PAGES` array in `take-screenshots.js`:
```javascript
{ name: 'booking_rules', path: '/booking_rules', auth: true },
```

Then add a corresponding section in `README.md`.

### Adding Anonymization Rules

Add to the `ANONYMIZE_RULES` array:
```javascript
['Specific Term', 'Generic Replacement'],
```

---

## Documentation Structure
```
docs/
├── screenshots/          # Auto-generated (committed)
├── DATABASE_SCHEMA.md    # Complete schema (committed)
├── DEVELOPER_GUIDE.md    # This file (committed)
├── INSTALLATION.md       # Setup instructions (committed)
├── PROJECT_CONTEXT.md    # Architecture notes (LOCAL ONLY)
└── SCREENSHOTS.md        # Screenshot index (committed)

config/
├── config.php            # Production config (LOCAL ONLY)
├── config.example.php    # Template (committed)
└── security_terms.json   # Scanner terms (LOCAL ONLY)
```

### Files That Must NEVER Be Committed

| File | Contains |
|------|----------|
| `config/config.php` | Database credentials, API keys, OAuth secrets, SMTP credentials |
| `config/security_terms.json` | Organization-specific terms for the security scanner |
| `docs/PROJECT_CONTEXT.md` | Internal architecture notes, server commands, pending tasks |

### Before Committing Documentation

Always run the security scanner:
```bash
python3 scripts/security_scan.py
```

Fix or whitelist any high severity findings before committing.

---

## Release Workflow

### Versioning Rules

| Change Type | Command | Example |
|-------------|---------|---------|
| Bug fixes | `patch` | 1.3.5 > 1.3.6 |
| New features | `minor` | 1.3.6 > 1.4.0 |
| Breaking changes | `major` | 1.4.0 > 2.0.0 |

### Step-by-Step Release Process

**1. Commit all changes:**
```bash
cd /var/www/snipescheduler
sudo chown -R vitor:www-data .
sudo chown -R vitor:vitor .git
git add -A
git commit -m "Description of changes"
```

**2. Run the release script:**
```bash
sudo chown www-data:www-data version.txt CHANGELOG.md
sudo -u www-data php scripts/release.php patch "Brief description"
```

This auto-updates: `version.txt`, CSS cache-bust strings, `CHANGELOG.md`, and creates a 7-day release announcement.

**3. Commit and push the release:**
```bash
sudo chown -R vitor:www-data .
sudo chown -R vitor:vitor .git
git add -A
git commit -m "Release vX.Y.Z: Description"
git pushall
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
git push codecommit vX.Y.Z
```

**4. Update screenshots if UI changed:**
```bash
cd scripts
node take-screenshots.js --session=FRESH_PHPSESSID
cd /var/www/snipescheduler
git add -A
git commit -m "Update screenshots for vX.Y.Z"
git pushall
```

---

## Dual Git Repository Management

### Remotes

| Remote | URL | Purpose |
|--------|-----|---------|
| `origin` | GitHub (personal) | Public repository |
| `codecommit` | AWS CodeCommit | Corporate repository |

### Push Alias
```bash
git pushall   # Pushes to both origin and codecommit
```

### Setting Up on a New Machine
```bash
git remote add origin https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager.git
git remote add codecommit https://git-codecommit.us-east-1.amazonaws.com/v1/repos/SnipeScheduler-FleetManager
git config credential.helper store
git config alias.pushall '!git push origin main && git push codecommit main'
```

### CodeCommit Authentication

1. AWS Console > IAM > Users > Security Credentials
2. Generate HTTPS Git credentials for CodeCommit
3. IAM user must have `AWSCodeCommitPowerUser` policy
4. If password contains `/` or `+`, URL-encode them (`%2F`, `%2B`)

### Pushing Tags
```bash
git tag -a v1.3.6 -m "Release v1.3.6"
git push origin v1.3.6
git push codecommit v1.3.6
```

---

## Security Scanning

### Running the Scanner
```bash
python3 scripts/security_scan.py    # Target: 0 high severity
```

### Whitelisting Legitimate Terms

Edit `ALLOWED_CONTEXTS` in `scripts/security_scan.py`:
```python
ALLOWED_CONTEXTS = {
    "config.php": ["your-domain.com"],
    "README.md": ["rodovalho"],
    # Add entries as needed
}
```

---

## File Permissions

### Standard Pattern
```bash
sudo chown -R vitor:www-data /var/www/snipescheduler/
sudo chmod -R 775 /var/www/snipescheduler/
sudo chmod 640 /var/www/snipescheduler/config/config.php
sudo chown www-data:www-data /var/www/snipescheduler/config/config.php
sudo chown -R vitor:vitor /var/www/snipescheduler/.git
```

### Before Release Script
```bash
sudo chown www-data:www-data version.txt CHANGELOG.md
```

---

## Quick Reference

| Task | Command |
|------|---------|
| Push to both repos | `git pushall` |
| Security scan | `python3 scripts/security_scan.py` |
| Screenshots | `node scripts/take-screenshots.js --session=PHPSESSID` |
| Release patch | `sudo -u www-data php scripts/release.php patch "Notes"` |
| Run cron tasks | `sudo -u www-data php cron/scheduled_tasks.php` |
| Process emails | `sudo -u www-data php cron/process_email_queue.php` |

### Key Settings Locations

| Setting | Location |
|---------|----------|
| Asset tag prefix | `system_settings` table, key `asset_tag_prefix` |
| Business day buffer | `system_settings` table, key `business_day_buffer` |
| Snipe-IT URL | `config/config.php` > `$config['snipeit']['base_url']` |
| App URL | `config/config.php` > `$config['app']['base_url']` |
| SMTP | `config/config.php` > `$config['smtp']` |

### Cron Jobs

| Schedule | Script | Purpose |
|----------|--------|---------|
| Every 5 min | `cron/process_email_queue.php` | Send queued emails |
| Every 15 min | `cron/scheduled_tasks.php` | Reminders, overdue, missed, compliance, redirect |

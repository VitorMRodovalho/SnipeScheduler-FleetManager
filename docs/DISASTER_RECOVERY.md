# Fleet Management System — Disaster Recovery Runbook

**Version:** 1.0
**Last Updated:** 2026-03-13

---

## 1. Recovery Objectives

| Metric | Target | Justification |
|--------|--------|---------------|
| **RTO** (Recovery Time Objective) | 4 hours | Fleet operations can fall back to manual key checkout for up to half a business day |
| **RPO** (Recovery Point Objective) | 24 hours | Daily backups mean at most one day of reservation data could be lost. Snipe-IT remains the source of truth for asset data. |

---

## 2. Backup Strategy

### Automated Daily Backup

- **Schedule:** Daily at 2:00 AM via `/usr/local/bin/backup-snipescheduler.sh`
- **Contents:**
  - MySQL database dump (`snipescheduler.sql`)
  - Application configuration (`config/config.php`)
  - Uploaded inspection photos (`uploads/inspections/`)
  - Version file (`version.txt`)
- **Storage:** `/var/backups/snipescheduler/` with 30-day retention
- **Naming convention:** `backup_YYYY-MM-DD_HHMMSS.tar.gz`

### Backup Verification

- **Security Dashboard** (`/booking/security`) monitors backup recency
- Alert triggers if no backup found within 48 hours
- Backup script exit code logged to `/var/log/snipescheduler/backup.log`

### Example Backup Script

```bash
#!/bin/bash
# /usr/local/bin/backup-snipescheduler.sh
set -euo pipefail

TIMESTAMP=$(date +%Y-%m-%d_%H%M%S)
BACKUP_DIR="/var/backups/snipescheduler"
APP_DIR="/var/www/snipescheduler"
BACKUP_FILE="${BACKUP_DIR}/backup_${TIMESTAMP}.tar.gz"

mkdir -p "${BACKUP_DIR}"

# Database dump
mysqldump --single-transaction snipescheduler > "/tmp/snipescheduler_${TIMESTAMP}.sql"

# Create archive
tar czf "${BACKUP_FILE}" \
  -C /tmp "snipescheduler_${TIMESTAMP}.sql" \
  -C "${APP_DIR}" config/config.php \
  -C "${APP_DIR}" uploads/inspections/ \
  -C "${APP_DIR}" version.txt

# Cleanup temp file
rm -f "/tmp/snipescheduler_${TIMESTAMP}.sql"

# Retain only 30 days of backups
find "${BACKUP_DIR}" -name "backup_*.tar.gz" -mtime +30 -delete

echo "[$(date)] Backup completed: ${BACKUP_FILE}"
```

### CRON Entry

```cron
0 2 * * * /usr/local/bin/backup-snipescheduler.sh >> /var/log/snipescheduler/backup.log 2>&1
```

---

## 3. Restoration Procedure

### Prerequisites

- SSH access to the application server
- MySQL root or admin credentials
- Backup archive file (`.tar.gz`)

### Step-by-Step Restoration

**Step 1: Identify the latest backup**

```bash
ls -la /var/backups/snipescheduler/
# Look for the most recent backup_YYYY-MM-DD_HHMMSS.tar.gz file
```

**Step 2: Extract the backup**

```bash
mkdir /tmp/restore && cd /tmp/restore
tar xzf /var/backups/snipescheduler/backup_YYYY-MM-DD_HHMMSS.tar.gz
```

**Step 3: Restore the database**

```bash
# Drop and recreate (or restore over existing)
mysql -u root -p snipescheduler < /tmp/restore/snipescheduler_YYYY-MM-DD_HHMMSS.sql
```

**Step 4: Restore configuration**

```bash
cp /tmp/restore/config/config.php /var/www/snipescheduler/config/config.php
chmod 640 /var/www/snipescheduler/config/config.php
```

**Step 5: Restore uploaded files**

```bash
cp -r /tmp/restore/uploads/inspections/ /var/www/snipescheduler/uploads/inspections/
```

**Step 6: Fix file permissions**

```bash
chown -R www-data:www-data /var/www/snipescheduler/
chmod 640 /var/www/snipescheduler/config/config.php
chmod 750 /var/www/snipescheduler/uploads/inspections/
```

**Step 7: Validate system integrity**

```bash
php /var/www/snipescheduler/scripts/validate_snipeit.php --strict
```

Expected output: All checks pass (groups, statuses, custom fields, API connectivity).

**Step 8: Test critical workflows**

1. Log in via each enabled authentication provider
2. Verify dashboard loads with current data
3. Create a test reservation
4. Verify CRON jobs are running: check Security Dashboard > CRON Sync Health
5. Verify notifications are queuing: check Admin > Notifications > Queue stats

**Step 9: Cleanup**

```bash
rm -rf /tmp/restore
```

---

## 4. Snipe-IT Dependency

The Fleet Management System depends on Snipe-IT for asset data, user authentication (group membership), and vehicle status management.

| Scenario | Impact | Recovery |
|----------|--------|----------|
| **Snipe-IT is also down** | Cannot authenticate new users, cannot checkout/checkin vehicles, cannot fetch vehicle data | Restore Snipe-IT first (separate backup/recovery procedure), then restore SnipeScheduler |
| **Snipe-IT is up, SnipeScheduler is down** | Existing reservations and inspection data unavailable, but Snipe-IT can still manage assets directly | Restore SnipeScheduler from backup; asset data will sync automatically via CRON within 5 minutes |
| **Database lost, no backup** | All reservations, inspection records, activity logs, and system settings lost | Run `public/install/schema.sql` to recreate empty tables. Re-run migrations from `migrations/` directory. Snipe-IT asset data remains intact. Historical booking data is permanently lost. |

---

## 5. Communication Plan

### During Outage

1. **Notify Fleet Operations Manager** — Phone call within 30 minutes of confirmed outage
2. **Email fleet staff** — Brief message: system is down, manual key checkout procedures in effect, estimated restoration time
3. **Post announcement** (once system is restored) — Admin > Announcements > Create with urgency "Warning": "System restored after maintenance. Please verify your upcoming reservations."

### Manual Fallback During Extended Outage

If restoration exceeds the 4-hour RTO:
- Fleet office reverts to manual paper-based key checkout log
- Staff tracks vehicle assignments in a shared spreadsheet
- All manual checkouts must be entered retroactively once the system is restored

---

## 6. Recovery Validation Checklist

After restoration, verify each item before declaring the system operational:

- [ ] Application loads without errors (`/booking/login`)
- [ ] Login works via Microsoft OAuth / Google OAuth / LDAP
- [ ] Dashboard shows correct fleet status counts
- [ ] CRON Sync Health shows "Healthy" on Security Dashboard
- [ ] Backup monitoring shows recent backup on Security Dashboard
- [ ] A test reservation can be created, approved, and cancelled
- [ ] Notifications are queuing (check Admin > Notifications > Queue)
- [ ] Activity log is recording events (check Admin > Activity Log)
- [ ] Multi-entity filtering is active (if applicable)
- [ ] All CRON jobs are scheduled: `crontab -l | grep snipescheduler`

---

## 7. Infrastructure Requirements

### Disk Encryption

Data at rest is **not** encrypted at the application level. Disk-level encryption **MUST** be enabled on all servers hosting this system:

| Platform | Method | Verification |
|----------|--------|-------------|
| **AWS EC2** | Enable EBS encryption on all volumes (gp3, io2, etc.) | AWS Console > EC2 > Volumes > Encrypted column |
| **Bare metal / VM** | LUKS/dm-crypt on Linux, BitLocker on Windows | `lsblk` — look for `crypt` type in the output |
| **Database server** | Same as above — the MySQL data directory volume must be encrypted | Verify the volume containing `/var/lib/mysql` |
| **Backup storage** | Encrypt backup archives with GPG before storage, or use an encrypted volume | `gpg --encrypt backup.tar.gz` or verify volume encryption |

This is an infrastructure requirement, not an application feature. The application does not implement its own encryption layer for data at rest.

---

## Document History

| Date | Version | Change |
|------|---------|--------|
| 2026-03-13 | 1.0 | Initial disaster recovery runbook |

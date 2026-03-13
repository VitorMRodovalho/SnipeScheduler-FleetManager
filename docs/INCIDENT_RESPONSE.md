# Fleet Management System — Incident Response Plan

**Version:** 1.0
**Last Updated:** 2026-03-13
**Classification:** Internal — Fleet Operations

---

## 1. Purpose & Scope

This plan defines the procedures for detecting, responding to, and recovering from security incidents affecting the Fleet Management System (SnipeScheduler FleetManager). It covers:

- Data breaches involving employee personal data (names, emails, mileage, training records)
- Unauthorized access to the application or its database
- System outages affecting fleet operations
- Data corruption or loss
- Snipe-IT API compromise or credential exposure

This plan does **not** cover Snipe-IT infrastructure incidents (managed separately) unless the Fleet Management System is directly affected.

---

## 2. Incident Classification

| Severity | Definition | Examples | Response Time |
|----------|-----------|----------|---------------|
| **Critical** | Data breach, unauthorized access to personal data, credential compromise | Leaked API tokens, unauthorized DB access, session hijacking | Immediate (within 1 hour) |
| **High** | System outage, data corruption, authentication failure | Database down, login broken, CRON failures causing missed bookings | Within 2 hours |
| **Medium** | Performance degradation, partial feature failure | Slow API responses, notification delivery delays, report errors | Within 8 hours |
| **Low** | Cosmetic issues, non-blocking bugs | UI rendering issues, incorrect badge colors, minor display errors | Next business day |

---

## 3. Detection Sources

Incidents may be detected through:

- **CRON health alerts** — Sync staleness detected by `cron_sync_health.php` (15-minute interval)
- **Security Dashboard** — Backup verification, config permission checks, CRON status
- **Apache error logs** — `/var/log/apache2/error.log` and application-specific logs in `/var/log/snipescheduler/`
- **Snipe-IT API errors** — Logged by `snipeit_client.php` with retry attempt details
- **Activity log anomalies** — Unusual login patterns, bulk data exports, permission escalation attempts visible in Admin > Activity Log
- **User reports** — Fleet Staff or drivers reporting unexpected behavior
- **Automated backup monitoring** — Security Dashboard flags missing or stale backups (>48 hours)

---

## 4. Response Team

| Role | Responsibility |
|------|---------------|
| **System Administrator** | Technical investigation, containment, credential rotation, system restoration |
| **Fleet Operations Manager** | Operational impact assessment, user communication, workflow continuity |
| **IT Security Lead** | Breach scope assessment, regulatory notification decisions, forensic oversight |

Contact information: **[To be filled by system administrator before deployment]**

---

## 5. Response Procedure

### Phase 1: Immediate Response (0-1 hour)

1. **Identify scope** — Determine what systems, data, and users are affected
   - Check Activity Log for unauthorized actions: Admin > Activity Log, filter by event type and date range
   - Review Apache access logs: `tail -500 /var/log/apache2/access.log | grep -i "POST\|DELETE\|PUT"`
   - Check for active sessions: review `sessions` table for unexpected entries
2. **Contain the threat**
   - Disable compromised user accounts in Snipe-IT (Users > Edit > Activated: No)
   - If credential compromise is suspected, immediately rotate all credentials (see Section 6)
   - If the application is actively being exploited, take it offline: `sudo systemctl stop apache2`
3. **Preserve evidence**
   - Copy current logs before rotation: `cp -r /var/log/snipescheduler/ /var/backups/incident_$(date +%Y%m%d)/`
   - Export Activity Log: Admin > Activity Log > Export CSV (or `mysqldump snipescheduler activity_log`)
   - Screenshot Security Dashboard status
   - Do **not** modify or truncate log files until investigation is complete

### Phase 2: Assessment & Remediation (1-24 hours)

1. **Assess impact**
   - Identify all affected user records (personal data: names, emails, mileage, training dates)
   - Determine if data was exfiltrated, modified, or only accessed
   - Check `my_data_export` activity log entries for unauthorized data exports
   - Review reservation and inspection data for unauthorized modifications
2. **Notify stakeholders**
   - Inform Fleet Operations Manager of operational impact
   - Inform IT Security Lead of breach scope
   - Post an announcement in SnipeScheduler (Admin > Announcements) with urgency level "Critical" to alert users if needed
3. **Begin remediation**
   - Patch the vulnerability that allowed the incident
   - Rotate all credentials (see Section 6)
   - Force all user sessions to re-authenticate (truncate sessions or restart Apache)
   - Validate system integrity: `php scripts/validate_snipeit.php --strict`

### Phase 3: Notification (within 72 hours)

For data breaches affecting personal data:

- **CCPA/State Privacy Laws:** If the breach involves personal information of residents of states with privacy notification laws, notify affected individuals within 72 hours
- **Notification content:** What happened, what data was involved, what steps are being taken, what affected individuals can do (e.g., download their data via "My Data" export, monitor for misuse)
- **State Attorney General:** File notification with relevant state authorities as required by applicable law
- **Internal stakeholders:** Provide written incident summary to program leadership

### Phase 4: Recovery

1. **Restore from backup if needed** — See [DISASTER_RECOVERY.md](DISASTER_RECOVERY.md)
2. **Validate system integrity**
   - Run `php scripts/validate_snipeit.php --strict`
   - Verify all CRON jobs are running: check Security Dashboard > CRON Sync Health
   - Test critical workflows: login, reservation, checkout, checkin, approval
   - Verify backup schedule is active
3. **Resume normal operations**
   - Re-enable Apache if taken offline: `sudo systemctl start apache2`
   - Remove "Critical" announcement once resolved
   - Notify Fleet Operations Manager that system is operational

### Phase 5: Post-Incident Review (within 7 days)

1. **Root cause analysis** — Document the vulnerability, how it was exploited, and why existing controls did not prevent it
2. **Update security measures** — Implement additional controls to prevent recurrence
3. **Document lessons learned** — Update this IRP, SECURITY.md, and relevant operational procedures
4. **Review access controls** — Audit Snipe-IT group memberships and admin access
5. **Archive incident report** — Store final incident report with timeline, impact assessment, remediation steps, and preventive measures

---

## 6. Credential Rotation Procedure

Perform these steps whenever credential compromise is suspected or confirmed:

| Credential | How to Rotate |
|-----------|---------------|
| **Snipe-IT API Token** | Snipe-IT Admin > Personal API Key > Generate New Token. Update `config.php` key `snipeit.api_token`. |
| **OAuth Client Secret** (Microsoft) | Azure Portal > App Registrations > Client Secrets > New Secret. Update `config.php` key `microsoft_oauth.client_secret`. |
| **OAuth Client Secret** (Google) | Google Cloud Console > Credentials > OAuth 2.0 > Reset Secret. Update `config.php` key `google_oauth.client_secret`. |
| **Database Password** | MySQL: `ALTER USER 'snipescheduler'@'localhost' IDENTIFIED BY 'new_password';`. Update `config.php` key `database.password`. |
| **LDAP Bind Password** | Reset in Active Directory. Update `config.php` key `ldap.bind_password`. |
| **Session Invalidation** | Restart Apache: `sudo systemctl restart apache2` (destroys all active PHP sessions). |

After rotating credentials:
1. Verify application starts correctly
2. Run `php scripts/validate_snipeit.php` to confirm API connectivity
3. Test login via each enabled authentication provider
4. Verify CRON jobs execute without errors

---

## 7. Contact Information

| Role | Name | Email | Phone |
|------|------|-------|-------|
| System Administrator | [TBD] | [TBD] | [TBD] |
| Fleet Operations Manager | [TBD] | [TBD] | [TBD] |
| IT Security Lead | [TBD] | [TBD] | [TBD] |
| Snipe-IT Administrator | [TBD] | [TBD] | [TBD] |

**Fill in this table before production deployment.**

---

## Document History

| Date | Version | Change |
|------|---------|--------|
| 2026-03-13 | 1.0 | Initial incident response plan |

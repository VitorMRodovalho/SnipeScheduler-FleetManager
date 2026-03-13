# Fleet Management System — Security Audit Summary

**Version:** 1.0
**Last Updated:** 2026-03-13
**System:** SnipeScheduler FleetManager v2.1.0

---

## 1. Authentication

The system supports three authentication providers, selectable via configuration:

- **Microsoft OAuth 2.0** — Authorization Code flow via Azure AD / Entra ID
- **Google OAuth 2.0** — Authorization Code flow via Google Workspace
- **LDAP** — Direct bind authentication against Active Directory

All providers use the OAuth 2.0 Authorization Code flow (not Implicit) with PKCE-equivalent state parameter validation using `hash_equals()` for timing-safe comparison.

**Multi-Factor Authentication (MFA):**

Multi-factor authentication must be enforced at the identity provider level. The fleet management application authenticates via SSO and inherits the MFA enforcement configured at the IdP. The application does not provide its own MFA layer.

- **Microsoft OAuth:** Enable Conditional Access policies in Azure AD / Entra ID requiring MFA for all users accessing this application. Configure under Azure Portal > Security > Conditional Access > New Policy. Target the SnipeScheduler app registration.
- **Google OAuth:** Enable 2-Step Verification in Google Workspace admin console. Enforce for the organizational unit containing fleet users under Admin Console > Security > 2-Step Verification > Enforcement.
- **LDAP:** Implement MFA at the network or VPN level before LDAP access is available, or migrate to OAuth with IdP-level MFA enforcement.

**Session regeneration:** Session IDs are regenerated after successful authentication (`session_regenerate_id(true)`) on all three login paths to prevent session fixation attacks.

**Group revalidation:** User permissions are re-checked against Snipe-IT every 2 minutes during active sessions. If a user's group membership changes or they are deactivated, access is revoked within 2 minutes without requiring re-login.

---

## 2. Authorization

Role-based access control enforced through 4 Snipe-IT groups:

| Group | ID | Access Level |
|-------|----|-------------|
| Admins (Super Admin) | 1 | Full system access including Settings, Security Dashboard |
| Drivers | 2 | Book vehicles, view own reservations only |
| Fleet Staff | 3 | Approve reservations, manage maintenance, view all reservations |
| Fleet Admin | 4 | All staff permissions + user management, notifications, booking rules |

Authorization checks:
- Every page load verifies `$_SESSION['user']` exists and contains valid group flags
- Staff/admin pages check `$isStaff` or `$isAdmin` before rendering
- Multi-entity company filtering restricts data visibility: Drivers and Staff see only their company's vehicles; Admin sees all
- Group membership is re-validated from Snipe-IT API every 2 minutes (configurable)
- Session destroyed immediately if user loses fleet access

---

## 3. Input Protection

### CSRF (Cross-Site Request Forgery)
- Token generation: `bin2hex(random_bytes(32))` — cryptographically secure 256-bit tokens
- Validation: `hash_equals()` for timing-attack-safe comparison
- Enforcement: `csrf_check()` called on every POST request via `src/csrf.php`
- Token embedded in all forms via `csrf_field()` helper

### XSS (Cross-Site Scripting)
- All user-supplied output escaped via `h()` helper function
- `h()` wraps `htmlspecialchars()` with `ENT_QUOTES` flag
- Applied consistently across all templates and PHP output
- Content Security Policy header configured in `.htaccess`

### SQL Injection
- All database queries use PDO prepared statements with parameterized bindings
- No string concatenation of user input into SQL queries
- PDO configured with `ERRMODE_EXCEPTION` for explicit error handling
- Database connection initialized in `src/db.php` with secure defaults

---

## 4. Data Protection

### Transport Security
- HTTPS enforced for all client-server communication
- Security headers configured in `public/.htaccess`:
  - `X-Frame-Options: SAMEORIGIN` — prevents clickjacking
  - `X-Content-Type-Options: nosniff` — prevents MIME type sniffing
  - `X-XSS-Protection: 1; mode=block` — legacy XSS filter
  - `Content-Security-Policy` — restricts resource loading sources
- Snipe-IT API calls verify SSL certificates: `CURLOPT_SSL_VERIFYPEER = true`, `CURLOPT_SSL_VERIFYHOST = 2`
- SMTP uses TLS encryption for email delivery

### File Security
- Config file permissions enforced at 640 (owner read/write, group read, no world access)
- Security Dashboard (`public/security.php`) validates config permissions on every load
- `.gitignore` excludes `config.php`, `.env`, and credential files from version control
- Uploaded inspection photos stored with UUID filenames to prevent path traversal

### EXIF Metadata Stripping
- All uploaded images (JPEG, PNG) are re-saved through PHP GD library regardless of file size
- This strips all EXIF metadata including GPS coordinates, device information, and timestamps
- Implemented in `src/inspection_photos.php` via `strip_and_resize_photo()`
- MIME type validated using `finfo()` (not relying on browser-supplied Content-Type)
- File size limit: 10MB per upload, 5 photos per inspection event

---

## 5. Session Security

| Feature | Implementation |
|---------|---------------|
| Idle timeout | Configurable (default 30 minutes). Setting cached in session for 5 minutes to minimize DB queries. |
| Session regeneration | `session_regenerate_id(true)` called after successful authentication on all 3 login paths |
| Group revalidation | Snipe-IT group membership checked every 2 minutes during active sessions |
| Forced logout | Session destroyed immediately if user loses fleet access or is deactivated |
| Timeout configuration | Admin-configurable via Settings page: 15, 30, 60, 120 minutes, or no timeout |

---

## 6. API Security

Communication with Snipe-IT API:

| Feature | Implementation |
|---------|---------------|
| Authentication | Bearer token in `Authorization` header over HTTPS |
| Token storage | `config.php` (file permissions 640, excluded from version control) |
| SSL verification | `CURLOPT_SSL_VERIFYPEER = true`, `CURLOPT_SSL_VERIFYHOST = 2` |
| Retry logic | 3 attempts with exponential backoff (2s, 4s delays) on 429 and 5xx responses |
| Response caching | GET requests cached for 60 seconds to reduce API load and improve resilience |
| Error handling | All API calls wrapped in try/catch with error logging |
| Rate limiting | Automatic retry on HTTP 429 with backoff |

---

## 7. Monitoring & Logging

| Component | Implementation |
|-----------|---------------|
| Activity log | Every significant action logged with actor, subject, timestamp, IP address, and metadata (JSON) |
| CRON health | `cron_sync_health.php` runs every 15 minutes, alerts on sync staleness |
| Backup monitoring | Security Dashboard checks backup recency (alerts if >48 hours stale) |
| API errors | All Snipe-IT API failures logged with HTTP status, retry count, and error message |
| Login tracking | Every login attempt logged with provider, email, IP address, and outcome |
| Data exports | All "My Data" exports logged to activity log with user ID and timestamp |

---

## 8. Data Retention & Privacy

| Feature | Implementation |
|---------|---------------|
| Auto-purge | Weekly CRON job (`cron_data_retention.php`) purges expired data |
| Activity logs | Configurable retention: 90 / 180 / 365 / 730 days (default: 365) |
| Inspection photos | Configurable retention: 1 / 2 / 3 years or Never (default: 2 years) |
| Email queue | Configurable retention: 7 / 14 / 30 / 60 days (default: 30) |
| Data export | Self-service personal data export via "My Data" page (CCPA compliance) |
| Privacy notice | Public privacy notice at `/booking/privacy` (no authentication required) |
| Concurrent protection | Data retention CRON uses `flock()` to prevent concurrent execution |

---

## 9. Known Limitations

| Limitation | Risk | Mitigation |
|-----------|------|------------|
| No application-level MFA | Medium — relies on IdP enforcement | Enforce MFA at Azure AD / Google Workspace level (see Section 1) |
| No malware scanning on uploads | Medium — crafted images could pass MIME validation | ClamAV hourly CRON scan available (`scripts/cron_scan_uploads.php`). Install ClamAV and configure the CRON job. Infected files are quarantined and admin is notified. See Security Dashboard for status |
| No application-level data-at-rest encryption | Medium — all database and file data stored in plaintext on disk | **Disk-level encryption MUST be enabled.** AWS EC2: enable EBS encryption on all volumes. Bare metal: use LUKS/dm-crypt. Verify with `lsblk` or AWS console. This is an infrastructure requirement, not an application feature. |
| No automated CCPA deletion | Low — deletion requires manual admin intervention | Self-service export available; deletion requests handled by Fleet Admin |
| LDAP password transmitted to server | Low — encrypted via HTTPS | Consider migrating LDAP users to OAuth for improved security posture |

---

## 10. Recommended Infrastructure Hardening

These measures should be implemented at the server/infrastructure level:

1. **Disk encryption (MANDATORY)** — Data at rest is not encrypted at the application level. Disk-level encryption **MUST** be enabled on the server hosting this system. For AWS EC2: enable EBS encryption on all volumes. For bare metal: use LUKS/dm-crypt. For all deployments: verify encryption status with `lsblk` (look for `crypt` type) or the AWS console (EBS > Volumes > Encrypted column). This is an infrastructure requirement, not an application feature
2. **Firewall** — Configure `ufw` to allow only ports 80, 443, and SSH. Block direct database access from external networks
3. **Fail2ban** — Install and configure for Apache to auto-block IPs with repeated failed login attempts
4. **OS updates** — Schedule regular security updates: `sudo unattended-upgrades` or equivalent
5. **Database access** — Restrict MySQL to localhost only (`bind-address = 127.0.0.1`). Use a dedicated database user with minimal privileges
6. **Log rotation** — Configure logrotate for `/var/log/snipescheduler/` to prevent disk exhaustion
7. **Backup encryption** — Encrypt backup archives before storage: `gpg --encrypt backup.tar.gz`
8. **Network segmentation** — Place the application server and database in a private network segment. Expose only the web server via reverse proxy

---

## Document History

| Date | Version | Change |
|------|---------|--------|
| 2026-03-13 | 1.0 | Initial security audit summary |

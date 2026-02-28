# SnipeScheduler Security Checklist

## Regular Maintenance (Monthly)
- [ ] Check PHP version: `php -v`
- [ ] Update composer packages: `composer update`
- [ ] Review Apache error logs: `sudo tail -100 /var/log/apache2/error.log`
- [ ] Check for failed login attempts in activity_log
- [ ] Review user access and deactivate unused accounts

## Server Hardening
- [ ] Keep Ubuntu updated: `sudo apt update && sudo apt upgrade`
- [ ] Enable automatic security updates: `sudo apt install unattended-upgrades`
- [ ] Ensure firewall is enabled: `sudo ufw status`
- [ ] SSL certificate is valid (auto-renewed via Let's Encrypt)

## Backup Procedures
- [ ] Database backup: `mysqldump snipescheduler > backup.sql`
- [ ] Config backup: `cp config/config.php config/config.php.bak`

## If Credentials Are Compromised
1. Rotate Snipe-IT API token immediately
2. Rotate Microsoft OAuth client secret
3. Change database password
4. Regenerate all user sessions

## Security Files Created
- /var/www/snipescheduler/.htaccess (root protection)
- /var/www/snipescheduler/public/.htaccess (security headers)
- /var/www/snipescheduler/config/.htaccess (deny all)
- /var/www/snipescheduler/src/.htaccess (deny all)
- /var/www/snipescheduler/vendor/.htaccess (deny all)
- /var/www/snipescheduler/cron/.htaccess (deny all)
- /var/www/snipescheduler/src/csrf.php (CSRF protection)

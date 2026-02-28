# Installation Guide

## Prerequisites

- Ubuntu 22.04/24.04 LTS
- PHP 8.1+ with extensions: pdo, pdo_mysql, curl, json, mbstring
- MySQL 8.0+ or MariaDB 10.6+
- Apache 2.4+ with mod_rewrite, mod_headers
- Snipe-IT v6.x or v7.x installed and configured
- Composer

## Step 1: Clone Repository
```bash
cd /var/www
git clone https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager.git snipescheduler
cd snipescheduler
```

## Step 2: Install Dependencies
```bash
composer install --no-dev
```

## Step 3: Configure Application
```bash
cp config/config.example.php config/config.php
nano config/config.php
```

Configure:
- Database credentials (db_booking section)
- Snipe-IT API URL and token
- Microsoft OAuth credentials (optional)
- LDAP settings (optional)
- SMTP settings for notifications

## Step 4: Create Database
```bash
mysql -u root -p
```
```sql
CREATE DATABASE snipescheduler CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'snipescheduler'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON snipescheduler.* TO 'snipescheduler'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## Step 5: Set Permissions
```bash
sudo chown -R www-data:www-data /var/www/snipescheduler
sudo chmod 640 /var/www/snipescheduler/config/config.php
```

## Step 6: Configure Apache
```bash
sudo nano /etc/apache2/sites-available/snipescheduler.conf
```
```apache
Alias /booking /var/www/snipescheduler/public

<Directory /var/www/snipescheduler/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Enable site and modules:
```bash
sudo a2ensite snipescheduler
sudo a2enmod rewrite headers
sudo systemctl reload apache2
```

## Step 7: Set Up Cron Jobs
```bash
sudo crontab -e
```

Add:
```cron
# Send notifications every 5 minutes
*/5 * * * * /usr/bin/php /var/www/snipescheduler/cron/send_notifications.php

# Daily backup at 2 AM
0 2 * * * /usr/local/bin/backup-snipescheduler.sh
```

## Step 8: Configure Snipe-IT

Follow the Snipe-IT Configuration section in the main README.md.

## Step 9: Test Installation

Visit: `https://your-domain.com/booking/`

## Troubleshooting

### Permission Errors
```bash
sudo chown -R www-data:www-data /var/www/snipescheduler
```

### API Connection Issues
- Verify Snipe-IT API token has correct permissions
- Check API URL ends without trailing slash

### Login Issues
- Ensure user exists in Snipe-IT with email address
- Check user is in appropriate group (Drivers, Fleet Staff, or Fleet Admin)

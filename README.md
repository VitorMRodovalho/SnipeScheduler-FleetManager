# SnipeScheduler Fleet Manager

A comprehensive fleet vehicle management system that extends [Snipe-IT](https://snipeitapp.com/) asset management with vehicle reservations, checkout/checkin workflows, maintenance tracking, and compliance reporting.

## Credits & Attribution

This project is a derivative work based on:
- **Original Project**: [SnipeScheduler](https://github.com/JSY-Ben/SnipeScheduler) by [Ben Pirozzolo (JSY-Ben)](https://github.com/JSY-Ben)
- **Asset Management**: [Snipe-IT](https://github.com/snipe/snipe-it) by [Snipe](https://snipeitapp.com/)

## What's New in Fleet Manager Edition

This edition significantly extends the original SnipeScheduler with:

### Core Features
- Vehicle reservation system with approval workflows
- VIP auto-approval for designated users
- Checkout/Checkin with pre/post vehicle inspection forms
- QR code scanning for quick actions
- Maintenance tracking integrated with Snipe-IT API
- 5 comprehensive reports (Summary, Usage, Utilization, Maintenance, Compliance)
- CSV export for all reports

### Administration
- Vehicle management (create vehicles, models, manufacturers via Snipe-IT API)
- User management with Snipe-IT group-based access control
- VIP status toggle for auto-approval
- Activity logging and audit trail
- Dynamic custom field mapping (portable across Snipe-IT instances)

### Security
- CSRF protection on all forms
- XSS prevention
- SQL injection prevention (PDO prepared statements)
- Security headers (X-Frame-Options, CSP, etc.)
- Directory protection (.git, config, src, vendor)
- File permission hardening

### Operations
- Automated daily backups (database + config)
- Automated security updates
- Comprehensive logging

## Requirements

- PHP 8.1+
- MySQL 8.0+ or MariaDB 10.6+
- Apache 2.4+ with mod_rewrite, mod_headers
- Snipe-IT v6.x or v7.x
- Composer

## Snipe-IT Configuration

### 1. Create Category
- Name: `Fleet Vehicles`
- Type: Asset

### 2. Create Custom Fieldset: "Fleet Vehicle Fields"

| Field Name | Type | Required |
|------------|------|----------|
| VIN | Text (17 chars) | No |
| License Plate | Text | No |
| Vehicle Year | Numeric | No |
| Current Mileage | Numeric | No |
| Insurance Expiry | Date | No |
| Registration Expiry | Date | No |
| Last Maintenance Date | Date | No |
| Last Maintenance Mileage | Numeric | No |
| Last Oil Change (Miles) | Numeric | No |
| Last Tire Rotation (Miles) | Numeric | No |
| Maintenance Interval Miles | Numeric | Default: 7500 |
| Maintenance Interval Days | Numeric | Default: 180 |

### 3. Create Status Labels

| Name | Type | Color |
|------|------|-------|
| VEH-Available | Deployable | Green |
| VEH-Reserved | Pending | Yellow |
| VEH-In Service | Deployed | Blue |
| VEH-Out of Service | Undeployable | Red |

### 4. Create User Groups

| Group | Purpose |
|-------|---------|
| Drivers | Basic - can make reservations |
| Fleet Staff | Can manage maintenance, view reports |
| Fleet Admin | Full fleet management access |

### 5. Create Supplier
- Name: Your maintenance provider (e.g., "Service Station")

## Installation

See [docs/INSTALLATION.md](docs/INSTALLATION.md) for detailed instructions.

### Quick Start
```bash
# Clone
git clone https://github.com/VitorMRodovalho/SnipeScheduler-FleetManager.git
cd SnipeScheduler-FleetManager

# Install dependencies
composer install --no-dev

# Configure
cp config/config.example.php config/config.php
nano config/config.php

# Set permissions
sudo chown -R www-data:www-data .
sudo chmod 640 config/config.php
```

## Security

See [SECURITY.md](SECURITY.md) for security checklist and best practices.

## Backup & Maintenance

Automated backup script included:
```bash
# Manual backup
sudo /usr/local/bin/backup-snipescheduler.sh

# Full maintenance
sudo /usr/local/bin/maintenance-snipescheduler.sh
```

## Screenshots

*Coming soon*

## License

This project maintains the same license as the original SnipeScheduler project.

## Contributing

Contributions are welcome! Please open an issue first to discuss proposed changes.

## Acknowledgments

Special thanks to:
- [Ben Pirozzolo (JSY-Ben)](https://github.com/JSY-Ben) for the original SnipeScheduler
- [Snipe-IT Team](https://snipeitapp.com/) for the excellent asset management platform

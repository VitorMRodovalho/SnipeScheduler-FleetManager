# Snipe-IT Compatibility Guide

## Version Compatibility

| Snipe-IT Version | Status | Notes |
|-----------------|--------|-------|
| v7.x – v8.4.x | **Tested** | Full support; v8.4.0 is the primary development/test target |
| v6.x | **Supported** | API v1 endpoints are compatible; some UI features may differ |
| v5.x and below | **Unsupported** | API changes may cause failures |

**Minimum requirement:** Snipe-IT v6.0+ with API v1 enabled.

## API Endpoints Used

The application calls the following Snipe-IT REST API v1 endpoints:

### Assets (Hardware)
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/hardware` | List assets (requestable, by status, by category) |
| GET | `/api/v1/hardware/{id}` | Get single asset with custom fields |
| POST | `/api/v1/hardware` | Create new vehicle |
| PATCH | `/api/v1/hardware/{id}` | Update asset custom fields, status, location |
| POST | `/api/v1/hardware/{id}/checkout` | Check out asset to user |
| POST | `/api/v1/hardware/{id}/checkin` | Check in asset |

### Users
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/users` | List/search users |
| GET | `/api/v1/users/{id}` | Get single user |
| POST | `/api/v1/users` | Create user |
| PATCH | `/api/v1/users/{id}` | Update user (activate/deactivate, groups) |

### Models & Categories
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/models` | List asset models |
| GET | `/api/v1/models/{id}` | Get model details + fieldset |
| POST | `/api/v1/models` | Create model |
| GET | `/api/v1/categories` | List categories |

### Supporting Resources
| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/fields` | Custom field definitions |
| GET | `/api/v1/fieldsets/{id}/fields` | Fields in a fieldset |
| GET | `/api/v1/locations` | Pickup locations and destinations |
| GET | `/api/v1/companies` | Company list (multi-entity) |
| GET | `/api/v1/groups` | Permission groups (role mapping) |
| GET | `/api/v1/statuslabels` | Status labels |
| GET | `/api/v1/manufacturers` | Manufacturer list |
| POST | `/api/v1/manufacturers` | Create manufacturer |
| GET | `/api/v1/suppliers` | Supplier list (maintenance) |
| GET | `/api/v1/maintenances` | Maintenance records |
| POST | `/api/v1/maintenances` | Create maintenance record |

## Before Upgrading Snipe-IT

1. **Check Snipe-IT release notes** for API breaking changes (especially
   `/api/v1/hardware` response format and checkout/checkin endpoints)
2. **Run validation on staging first:**
   ```bash
   php scripts/validate_snipeit.php --strict
   ```
3. **Test critical workflows** after upgrade:
   - Vehicle checkout and checkin
   - User authentication and permission group resolution
   - Company filtering (multi-entity)
   - Custom field read/write on assets
   - Maintenance record creation
4. **Known concerns:**
   - Multiple Companies Support behavior may vary across Snipe-IT versions —
     verify company assignment filtering after upgrade
   - Custom field column names (`_snipeit_*`) must match between Snipe-IT and
     the `config.php` field mapping
   - Status label IDs are numeric; if Snipe-IT recreates them, update config

## If a Snipe-IT Upgrade Breaks the System

1. **Rollback Snipe-IT** to the previous version immediately
2. Run `php scripts/validate_snipeit.php --strict` to identify what broke
3. Check the Snipe-IT GitHub issues for known API regressions
4. Report the issue at the SnipeScheduler FleetManager repository with:
   - Previous Snipe-IT version
   - New Snipe-IT version
   - `validate_snipeit.php` output
   - Error messages from the PHP error log

## Configuration Requirements in Snipe-IT

See the **Snipe-IT Configuration Guide** section in `README.md` for:
- Required user groups (Drivers, Fleet Staff, Fleet Admin)
- Required status labels (VEH-Available, VEH-Reserved, VEH-In-Service, etc.)
- Required custom fields and fieldset
- Company setup for multi-entity fleet

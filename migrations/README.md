# Database Migrations

## Fresh Installs

For fresh installations, use **`public/install/schema.sql`** which contains the
complete database schema with all tables, indexes, and seed data. The web
installer (`/install`) runs this automatically.

## Upgrading from Previous Versions

These migration files are for upgrading existing installations from earlier
versions. Apply them in order after upgrading the application files:

| File | From → To | Purpose |
|------|-----------|---------|
| `v1_3_5_future_availability.sql` | v1.3.x → v1.3.5 | Holidays table, business day config, reservation redirect, status enum expansion |
| `v2_1_0_checklist_admin.sql` | v2.0.x → v2.1.0 | Inspection checklist profiles, categories, items, assignments + seed data |
| `v2_1_0_company_columns.sql` | v2.0.x → v2.1.0 | Company badge columns on reservations |
| `v2_1_0_data_compliance.sql` | v2.0.x → v2.1.0 | Data retention system settings |
| `v2_1_0_dsar_tracking.sql` | v2.0.x → v2.1.0 | DSAR (data_requests) table |
| `v2_1_0_missed_reservations.sql` | v2.0.x → v2.1.0 | Missed resolution + key handover columns, notification events |
| `v2_1_0_notification_events.sql` | v2.0.x → v2.1.0 | Training expiry + force check-in notification events |

### Running Migrations

```bash
mysql -u <user> -p <database> < migrations/v1_3_5_future_availability.sql
mysql -u <user> -p <database> < migrations/v2_1_0_checklist_admin.sql
# ... repeat for each applicable migration
```

The upgrade UI at `/install/upgrade` can also apply pending migrations
automatically.

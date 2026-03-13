# SnipeScheduler FleetManager — User Guide

Step-by-step procedures for each user role. All procedures assume the system is deployed and the user has valid SSO credentials and an authorized Snipe-IT group assignment.

---

## System Requirements

| Component | Minimum Version | Notes |
|-----------|----------------|-------|
| PHP | 8.1+ | With cURL, PDO, LDAP, GD extensions |
| MySQL | 5.7+ | Or MariaDB 10.3+ |
| Apache | 2.4+ | With mod_rewrite enabled |
| Snipe-IT | v6+ | With API access and valid API token |
| GD Library | PHP GD extension | Required for photo resize during upload |
| CRON | System crontab | Required for sync, health checks, and alerts |

---

## Procedure A: Driver (Group 2)

Drivers can reserve vehicles, view their own reservations, complete checkout/checkin inspections, and cancel their own pending bookings.

**Prerequisites:** Active SSO credentials, assigned to Drivers group in Snipe-IT, driver safety training completed (if training enforcement is enabled).

### A1. Sign In

Open the booking portal URL in your browser. Click "Sign in with Microsoft" (or Google, if configured). Authenticate using your corporate credentials. The system assigns permissions automatically based on your Snipe-IT group membership. No separate registration is required.

### A2. Dashboard

After login, the Dashboard displays today's vehicle schedule, your upcoming reservations, overdue return alerts, and system announcements. KPI summary cards show active reservations, pending approvals, and fleet utilization. Check for announcements regarding blackout dates or maintenance windows.

### A3. Browse Vehicle Catalogue

Navigate to **Vehicle Catalogue**. View all fleet vehicles with real-time availability. Each vehicle card shows year, make, model, VIN (last 4), current status (Available / Reserved / In Service / Out of Service), and mileage. Use the date filter to narrow by availability window.

In multi-entity deployments, you will only see vehicles belonging to your assigned company. Each vehicle card displays a colored company badge next to the vehicle name — the badge color and abbreviation are configured by your Fleet Admin in Snipe-IT.

### A4. Reserve a Vehicle

Click **Book Vehicle**. Complete the reservation form:

1. Select **Pick-Up Location** and **Destination**
2. Select **Pick-Up Date and Time** and **Return Date and Time** (business days only — weekends, holidays, and blackouts are grayed out)
3. Choose from vehicles available for your date window — in multi-entity mode, vehicle selection cards show a colored company badge for easy identification
4. Add **Purpose Notes** (optional)
5. Click **Submit**

Your reservation enters "Pending Approval" status. You will receive an email/Teams notification when it is approved or rejected.

> **Training Gate:** If driver training enforcement is enabled and your training is not completed or has expired, the reservation form will display a warning banner explaining that your training must be current before booking. The submit button will be disabled until your training status is resolved. Contact Fleet Staff to update your training record.

### A5. Track Your Reservations

Navigate to **My Reservations**. View all your bookings with status:

| Status | Meaning |
|--------|---------|
| Pending | Awaiting Fleet Staff approval |
| Approved | Confirmed, ready for checkout |
| Rejected | With reason provided |
| Checked Out | Vehicle in your possession |
| Completed | Returned successfully |
| Missed | Not checked out within window |
| Cancelled | By you or staff |

You can cancel any Pending or Approved reservation from this page.

### A6. Check Out the Vehicle

On your reservation date, go to **My Reservations** and click **Check Out**. The checkout form includes:

- Driver Name (pre-filled)
- Vehicle (pre-filled)
- Check-Out Date and Time (auto-populated)
- Odometer reading (required)
- Pick-Up Location and Destination

**Vehicle Inspection** — the inspection section depends on the mode configured by your Fleet Admin:

- **Quick Mode (default):** Four high-level category checks — Exterior, Tires/Undercarriage, Interior, and Lights/Signals. Each has a checkbox and optional description field. Use "All OK" to quickly mark all categories as satisfactory.
- **Full Mode:** A detailed 50-item checklist covering every inspectable component (body panels, glass, mirrors, tire tread, fluid levels, controls, emergency equipment, etc.). Each item is individually checked. "All OK" buttons are available per section to speed up a clean inspection.
- **Off:** No inspection form is shown. Only mileage is required.

**Photo Upload** (if enabled): Optionally capture photos of the vehicle's condition using your device camera. The camera opens in capture mode by default on mobile devices. Photos are resized automatically and stored with the inspection record.

Click **Check Out** to set the vehicle status to In Service.

### A7. Perform Walk-Around Inspection

Before departing, perform the physical walk-around inspection. Verify:

- Proof of insurance and registration in glove compartment
- Tire condition and inflation
- Interior controls and mirrors
- All lights and signals
- Brakes and steering
- Fuel level
- Emergency safety kit (first aid, fire extinguisher, safety triangle, reflective vest)

Report any deficiency that affects safe operation to Fleet Staff before departing. Do not operate a vehicle with safety deficiencies.

### A8. Return and Check In

When returning the vehicle, go to **My Reservations** and click **Check In**. Complete:

- Check-In Date and Time (auto-populated)
- Updated odometer reading (required — must be greater than or equal to checkout reading)
- Vehicle inspection (same mode as checkout — Quick, Full, or Off)
- Report any new condition issues discovered during use

**Photo Upload** (if enabled): Capture return-condition photos. Fleet Staff can compare checkout and checkin photos to identify new damage.

Click **Check In**. The vehicle returns to Available status. Flagged issues are routed to Fleet Staff for maintenance review.

### A9. Report Incidents

If involved in a vehicle incident:

1. Call emergency services if necessary
2. Contact your safety team immediately regardless of severity
3. Do not admit liability
4. Use the check-in form to document condition changes
5. Refer to the Accident Instruction Sheet in the vehicle binder

---

## Procedure B: Fleet Staff (Group 3)

Fleet Staff have all Driver capabilities plus: reservation approval/rejection, all-reservations view, maintenance log management, report generation, and driver training management.

**Prerequisites:** Active SSO credentials, assigned to Fleet Staff group in Snipe-IT.

### B1. Dashboard

Sign in with your corporate credentials. The Dashboard shows today's schedule across all vehicles, pending approval count, overdue returns, and recent activity. Chart.js visualizations display fleet utilization trends. The Staff badge appears next to your name.

### B2. Process Approval Queue

Navigate to **Approvals**. Each pending reservation shows requester name, requested vehicle, dates/times, destination, and purpose notes. Click **Approve** or **Reject**. If rejecting, provide a reason (required). The requester receives a notification with your decision. VIP users (if configured) bypass the queue with auto-approval.

### B3. Monitor All Reservations

Navigate to **Reservations** (Staff view). Filter by status, date range, or specific vehicle. This is your operational command view. Identify overdue returns (highlighted), check vehicle utilization, and verify checkout/checkin compliance.

### B4. Manage Checked Out Vehicles

Monitor checked-out reservations for overdue returns. The system flags vehicles past their expected return time. Contact the driver directly for overdue vehicles. You can force-checkin a vehicle if the driver is unavailable.

### B5. Manage Maintenance

Navigate to **Maintenance**. View issues flagged during check-in inspections. Each entry shows vehicle, reporter, issue category, description, and date reported. Log maintenance actions taken. Update vehicle status to "Out of Service" in Snipe-IT when repair is needed. Return to "Available" after maintenance is complete. Maintenance data syncs back to Snipe-IT custom fields.

### B6. Generate Reports

Navigate to **Reports**. Available reports:

- **Summary** — KPI cards with active reservations, completion rate, total miles, fleet pulse
- **Vehicle Utilization** — Fleet average utilization, completion rate, idle vehicle callout
- **Compliance Status** — Insurance expiry, registration expiry, maintenance due. Worst-first sort with action links
- **Reservation History** — Filterable by driver, vehicle, date range, status with footer totals
- **Maintenance Costs** — Maintenance type breakdown with footer totals and type filter
- **Driver Analytics** — Per-driver summary cards with user filter and row highlighting
- **Mileage Summary** — Mileage per driver, per vehicle

Export any report to CSV for external analysis.

### B7. Manage Driver Training

In the **Users** page (Admin tab), the Drivers table shows a **Training** column:

- **Green mortarboard** — Training completed and valid (with expiry date displayed)
- **Yellow mortarboard** — Training expiring within 15 days (date shown in yellow)
- **Red mortarboard** — Training expired or not completed (date shown in red, or "Not Set")

To set training: Click the mortarboard icon. A date picker appears allowing you to select the training completion date (can be a historical date for retroactive recording). Confirm to save. The system automatically calculates the expiration date based on the configured validity period (6, 12, or 24 months).

To clear training: Click the green mortarboard and confirm. Training dates are preserved when cleared — only the completion flag is toggled. Re-enabling restores the previous date.

**Weekly Alerts:** The `cron_training_expiry.php` CRON job runs weekly and sends a summary notification to Fleet Staff listing all drivers with training expiring within 30 days or already expired.

### B8. Handle Incidents

When a driver reports a vehicle incident: document it in the maintenance log, update vehicle status if needed, and coordinate with the safety team. For traffic violations, follow your organization's progressive discipline process.

### B9. Monitor CRON Health

Navigate to the **Security Dashboard** (Admin tab). The CRON Sync Health card shows:

- **Status:** Healthy (synced recently), Stale (sync overdue), or Never Run
- **Last Sync:** Timestamp of most recent sync
- **Asset Count:** Number of assets in the checked-out cache
- **Health Check Frequency:** How often the health check runs
- **Alert Threshold:** How many minutes of staleness triggers an alert

If the sync status shows "Stale," verify that the CRON jobs are running on the server. Check `/var/log/snipescheduler/` for error logs.

### Maintenance Intervals Reference

Fleet Staff should monitor these thresholds and schedule service accordingly:

| Service | Frequency | Alert Threshold |
|---------|-----------|-----------------|
| Oil Change | Every 7,500 miles | 250 miles before due |
| Tire Rotation | Every 7,500 miles | 250 miles before due |
| Multi-Point Inspection | Per service interval | 250 miles before due |
| Engine Air Filter | Every 20,000 miles | 250 miles before due |
| Cabin Air Filter | Every 20,000 miles | 250 miles before due |
| Spark Plugs | Every 60,000 miles | 250 miles before due |
| Timing Belt/Chain | Every 100,000 miles | 250 miles before due |
| Transmission Fluid | Every 150,000 miles | 250 miles before due |
| Engine Coolant | Every 200,000 miles | 250 miles before due |

---

## Procedure C: Fleet Admin / Super Admin (Group 4 / Group 1)

Fleet Admin has all Staff capabilities plus: user management, vehicle management, notification configuration, announcements, booking rules, and training settings. Super Admin adds: Settings page, Security Dashboard, and Teams webhook management.

**Prerequisites:** Active SSO credentials, assigned to Fleet Admin (Group 4) or Admins (Group 1) in Snipe-IT.

### C1. Admin Access

Sign in with your credentials. The Admin badge appears next to your name. You have access to all Staff functions plus the Admin tab with sub-tabs: Vehicles, Users, Activity Log, Notifications, Announcements, Booking Rules, Security, and Settings.

### C2. Manage Users

Navigate to **Users** (Admin tab). View all registered users organized by Snipe-IT group (Drivers, Fleet Staff, Fleet Admin, Inactive). Features:

- **VIP Toggle** — Click the star icon to grant/revoke VIP status (auto-approve reservations). Confirmation dialog before each action.
- **Training Toggle** — Click the mortarboard icon to manage training. Date picker for historical training dates. Color-coded expiration status (green/yellow/red).
- **Deactivate/Activate** — Manage user access. Confirmation required.
- **Snipe-IT Link** — Quick link to user profile in Snipe-IT for group changes.

Users are auto-provisioned on first SSO login. Assign them to the correct Snipe-IT group via the Snipe-IT admin interface.

### C3. Configure Vehicle Fleet

Navigate to **Vehicles** (Admin tab). View all fleet assets from Snipe-IT. When multi-entity fleet mode is active, a Company column shows each vehicle's company assignment. Use **Add Vehicle** for guided creation with auto-generated asset tags, VIN/plate validation, and compliance field enforcement. All vehicles sync to Snipe-IT as requestable assets.

### C4. Configure Notifications

Navigate to **Notifications**. Configure per-event notification channels: for each event type (Reservation Created, Approved, Rejected, Checkout, Checkin, Cancelled, Overdue, Maintenance Alert, Training Expiring), select the delivery channel: Email Only, Teams Only, Both, or Off.

### C5. Manage Booking Rules

Navigate to **Booking Rules**. Configure:

- **Business Days** — Select working days (Mon-Fri default)
- **Holiday Calendar** — Federal holidays pre-seeded (2025-2030), plus custom holidays
- **Vehicle Turnaround Buffer** — Business days between consecutive reservations
- **Driver Training Requirements** — Enable/disable training enforcement globally. Set validity period (6/12/24 months or no expiration). When disabled, all training records are preserved — re-enabling restores enforcement with existing data intact
- **Inspection Mode** — Select the vehicle inspection checklist mode:
  - **Quick** (default) — 4 high-level category checks (Exterior, Tires, Interior, Lights)
  - **Full** — Detailed 50-item checklist with "All OK" quick-fill buttons per section
  - **Off** — No inspection form, only mileage required
- **Photo Upload** — Enable or disable optional photo capture during checkout/checkin. When enabled, drivers can photograph vehicle condition using their device camera

### C6. Publish Announcements

Navigate to **Announcements**. Create system-wide notices with title, message body, urgency level (Info, Warning, Critical), display dates, and dismissible toggle. Release announcements are auto-generated on version updates and auto-deactivated on new releases. Users created after an announcement will not see it.

### C7. Monitor Security (Super Admin)

Navigate to **Security Dashboard**. Monitor:

- **CRON Sync Health** — Status (Healthy/Stale/Never Run), last sync time, asset count, health check frequency, alert threshold
- **Backup Status** — Schedule, recent backups, storage usage
- **Config Permissions** — config.php should be 640, owned by www-data
- **Security Headers** — X-Frame-Options, CSP, X-Content-Type-Options
- **Maintenance Commands** — Reference for common admin tasks

### C8. Manage Teams Integration (Super Admin)

In **Settings**, scroll to Microsoft Teams Integration. Enter webhook URLs for Fleet Operations and Admin Alerts channels. URLs are stored masked with eye-reveal toggles. Use the Test button to verify delivery. Teams notifications are delivered via Power Automate HTTP trigger webhooks to public Team channels.

### C9. Deployment Validation

After any configuration change or deployment, run:

```bash
php scripts/validate_snipeit.php
```

This verifies all required groups, status labels, and custom fields exist in the connected Snipe-IT instance. Use `--strict` flag for CI/deploy pipelines (exits with code 1 on failure).

### C10. Configure Multi-Entity Fleet (Super Admin)

Multi-entity fleet partitioning allows different user groups to see only vehicles belonging to their assigned company.

**When to use companies:** Enable multi-entity when your fleet serves multiple organizations, departments, or projects that need separate vehicle pools. If all users share the same fleet, leave this disabled.

**Setup in Snipe-IT:**

1. Go to **Settings → Companies** in Snipe-IT
2. Create one Company per fleet entity
3. For each company, set:
   - **Name:** Full entity name (e.g., "Engineering Division")
   - **Notes:** Short abbreviation for badges (e.g., "ENG") — max 5 characters recommended
   - **Tag Color:** Hex color for the badge background (e.g., "#00537e")
4. Assign each user to their company in Snipe-IT user profile (People > Edit User > Company)
5. Assign each vehicle to its company in Snipe-IT asset profile (Assets > Edit Asset > Company)
6. Enable **Full Multiple Companies Support** in Snipe-IT Admin > Settings > General

**Setup in SnipeScheduler:**

1. Navigate to **Settings** (Super Admin only). Scroll to the **Multi-Entity Fleet** card
2. Select the filtering mode:
   - **Auto-detect** (default) — Automatically enables filtering when Snipe-IT has more than one company. Shows detected count ("2 companies found — filtering active")
   - **Always On** — Forces filtering even with a single company (useful for testing)
   - **Always Off** — Disables filtering regardless of company count
3. Click **Save settings**

**How filtering works:**

- Drivers and Fleet Staff see only vehicles belonging to their assigned company
- Fleet Admin and Super Admin always see the full fleet across all companies
- Users with no company assigned in Snipe-IT see all vehicles (backward compatible — no one is locked out)
- Company badges appear on all vehicle references across the system: catalogue, reservations, dashboard, reports, maintenance, and email/Teams notifications
- A company badge appears next to the user's name in the top bar showing their company assignment
- The Vehicles admin page shows a Company column when multi-entity is active

**Admin toggle in Settings:**

| Mode | Behavior |
|------|----------|
| Auto-detect (default) | Enables when 2+ companies exist in Snipe-IT |
| Always On | Forces filtering even with 1 company (useful for testing) |
| Always Off | Disables all company filtering |

**Important:** Badge text (abbreviation) and color are controlled entirely from Snipe-IT Companies settings. No code changes are needed to customize badge appearance.

### C11. Customize Theme (Super Admin)

In **Settings**, locate the App Preferences section. Use the **Primary Color** picker to set your organization's brand color. The entire UI adapts automatically — navigation, buttons, badges, and accent colors are all derived from the primary color using CSS custom properties. Changes take effect immediately after saving.

### C12. Employee Offboarding

When an employee leaves the organization, follow these steps to ensure proper access removal and data handling:

1. **Check for active reservations:** Navigate to Admin > Reservations, filter by the employee's name. Cancel any pending or approved reservations to release the vehicles back to the available pool.

2. **Force-checkin if needed:** If the employee has a vehicle currently checked out, use the **Force Check-In** feature on the Checked Out Assets page. This returns the vehicle to Available status in Snipe-IT and logs the action.

3. **Verify key return:** Confirm all physical vehicle keys have been returned to the fleet office. Check the Dashboard for any "Key Out" indicators associated with the departing employee.

4. **Deactivate in Snipe-IT:** Go to Snipe-IT > People > find the employee > Edit > set **Activated** to **No**. This immediately prevents login to both Snipe-IT and SnipeScheduler. The system re-validates group membership every 2 minutes, so active sessions will be terminated within that window.

5. **Optional data export:** Before deactivation, you may export the employee's data for HR records. Either log in as the employee (if still active) and use **My Reservations > Download My Data**, or access the data via the Activity Log and Reports pages using admin access.

6. **Data retention:** The employee's historical data (reservations, activity logs, inspection photos) will be automatically purged per the configured retention policy (Admin > Booking Rules > Data Retention). No manual cleanup is required. Default retention: activity logs 365 days, inspection photos 730 days.

---

## Security Requirements

### Multi-Factor Authentication (MFA)

Multi-factor authentication must be enforced at the identity provider level. The fleet management application authenticates via SSO and inherits the MFA enforcement configured at the IdP. The application does not provide its own MFA layer.

- **Microsoft OAuth:** Enable Conditional Access policies in Azure AD / Entra ID requiring MFA for all users accessing this application. Configure under Azure Portal > Security > Conditional Access > New Policy.
- **Google OAuth:** Enable 2-Step Verification in Google Workspace admin console. Enforce for the organizational unit containing fleet users under Admin Console > Security > 2-Step Verification.
- **LDAP:** Implement MFA at the network or VPN level before LDAP access is available, or migrate to OAuth with IdP-level MFA enforcement.

For full security documentation, see [SECURITY_AUDIT.md](SECURITY_AUDIT.md).

---

## Critical Reminders for All Users

- Fleet vehicles are for official business use only
- Smoking (including e-cigarettes) is prohibited in all fleet vehicles
- Portable electronic device use while driving is prohibited
- GPS units may only be used if dashboard-mounted and configured before driving
- All vehicle incidents must be reported immediately to the safety team regardless of severity
- Do not admit liability at an accident scene
- Emergency safety kit must be verified present before departure
- When creating vehicles or users, assign them to the correct company if multi-entity mode is active
- Unassigned vehicles (no company) are visible to all users regardless of company filtering
- Company assignment changes in Snipe-IT take effect within 2 minutes (auth revalidation cycle)

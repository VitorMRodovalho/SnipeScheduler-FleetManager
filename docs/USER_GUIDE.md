# SnipeScheduler FleetManager — User Guide

Step-by-step procedures for each user role. All procedures assume the system is deployed and the user has valid SSO credentials and an authorized Snipe-IT group assignment.

---

## Procedure A: Driver (Group 2)

Drivers can reserve vehicles, view their own reservations, complete checkout/checkin inspections, and cancel their own pending bookings.

**Prerequisites:** Active SSO credentials, assigned to Drivers group in Snipe-IT, driver safety training completed (if training enforcement is enabled).

### A1. Sign In

Open the booking portal URL in your browser. Click "Sign in with Microsoft" (or Google, if configured). Authenticate using your corporate credentials. The system assigns permissions automatically based on your Snipe-IT group membership. No separate registration is required.

### A2. Dashboard

After login, the Dashboard displays today's vehicle schedule, your upcoming reservations, overdue return alerts, and system announcements. Check for announcements regarding blackout dates or maintenance windows.

### A3. Browse Vehicle Catalogue

Navigate to **Vehicle Catalogue**. View all fleet vehicles with real-time availability. Each vehicle card shows year, make, model, VIN (last 4), current status (Available / Reserved / In Service / Out of Service), and mileage. Use the date filter to narrow by availability window.

### A4. Reserve a Vehicle

Click **Book Vehicle**. Complete the reservation form:

1. Select **Pick-Up Location** and **Destination**
2. Select **Pick-Up Date and Time** and **Return Date and Time** (business days only — weekends, holidays, and blackouts are grayed out)
3. Choose from vehicles available for your date window
4. Add **Purpose Notes** (optional)
5. Click **Submit**

Your reservation enters "Pending Approval" status. You will receive an email/Teams notification when it is approved or rejected.

> **Training Gate:** If driver training enforcement is enabled and your training is not completed or has expired, you will see a warning banner and the submit button will be disabled. Contact Fleet Staff for training verification.

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

On your reservation date, go to **My Reservations** and click **Check Out**. Complete the digital inspection form:

- Driver Name (pre-filled)
- Vehicle (pre-filled)
- Check-Out Date and Time (auto-populated)
- Odometer reading (required)
- Pick-Up Location and Destination
- Visual Inspection Complete (Yes/No)
- Report any condition issues by category (Exterior, Tires, Undercarriage, Interior)
- Description for each flagged category

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
- Report any new condition issues discovered during use

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

Sign in with your corporate credentials. The Dashboard shows today's schedule across all vehicles, pending approval count, overdue returns, and recent activity. The Staff badge appears next to your name.

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

- **Vehicle Utilization** — Usage rates by vehicle and time period
- **Compliance Status** — Insurance expiry, registration expiry, maintenance due
- **Reservation History** — Filterable by driver, vehicle, date range, status
- **Mileage Summary** — Mileage per driver, per vehicle

Export any report to CSV for external analysis.

### B7. Manage Driver Training

In the **Users** page (Admin tab), the Drivers table shows a **Training** column:

- **Green mortarboard** — Training completed and valid (with expiry date)
- **Yellow mortarboard** — Training expiring within 15 days
- **Red mortarboard** — Training expired or not completed

To set training: Click the mortarboard icon, enter the training completion date (can be historical), and confirm. To clear training: Click the green mortarboard and confirm. Training dates are preserved when cleared — only the completion flag is toggled.

### B8. Handle Incidents

When a driver reports a vehicle incident: document it in the maintenance log, update vehicle status if needed, and coordinate with the safety team. For traffic violations, follow your organization's progressive discipline process.

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
- **Training Toggle** — Click the mortarboard icon to manage training. Date picker for historical training dates. Color-coded expiration status.
- **Deactivate/Activate** — Manage user access. Confirmation required.
- **Snipe-IT Link** — Quick link to user profile in Snipe-IT for group changes.

Users are auto-provisioned on first SSO login. Assign them to the correct Snipe-IT group via the Snipe-IT admin interface.

### C3. Configure Vehicle Fleet

Navigate to **Vehicles** (Admin tab). View all fleet assets from Snipe-IT. Use **Add Vehicle** for guided creation with auto-generated asset tags, VIN/plate validation, and compliance field enforcement. All vehicles sync to Snipe-IT as requestable assets.

### C4. Configure Notifications

Navigate to **Notifications**. Configure per-event notification channels: for each event type (Reservation Created, Approved, Rejected, Checkout, Checkin, Cancelled, Overdue, Maintenance Alert, Training Expiring), select the delivery channel: Email Only, Teams Only, Both, or Off.

### C5. Manage Booking Rules

Navigate to **Booking Rules**. Configure:

- **Business Days** — Select working days (Mon-Fri default)
- **Holiday Calendar** — Federal holidays pre-seeded (2025-2030), plus custom holidays
- **Vehicle Turnaround Buffer** — Business days between consecutive reservations
- **Driver Training Requirements** — Enable/disable training enforcement globally. Set validity period (6/12/24 months or no expiration). When disabled, all training records are preserved — re-enabling restores enforcement with existing data intact.

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

---

## Critical Reminders for All Users

- Fleet vehicles are for official business use only
- Smoking (including e-cigarettes) is prohibited in all fleet vehicles
- Portable electronic device use while driving is prohibited
- GPS units may only be used if dashboard-mounted and configured before driving
- All vehicle incidents must be reported immediately to the safety team regardless of severity
- Do not admit liability at an accident scene
- Emergency safety kit must be verified present before departure

# Changelog

## v1.3.0 (2026-02-28)

### 🚀 New Features

#### Reservation Controls
- **Minimum Notice Period**: Require advance booking (hours)
- **Maximum Duration**: Limit reservation length (hours)
- **Maximum Concurrent**: Limit active reservations per user
- **Staff Bypass**: Allow staff/admin to override rules
- **Blackout Slots**: Block specific dates/times for all or specific vehicles
- New admin page: Blackout Slots Management

#### Email Notifications Admin
- **Per-Event Configuration**: Enable/disable each notification type
- **Recipient Control**: Choose who receives each notification (Requester, Staff, Admin, Custom emails)
- **Custom Templates**: Edit subject and body for each notification
- **SMTP Toggle**: Enable/disable SMTP sending globally
- **Email Queue Stats**: Monitor pending emails
- **Test Email**: Send test emails to verify configuration
- 8 notification events: Submitted, Approved, Rejected, Checked Out, Checked In, Maintenance, Pickup Reminder, Overdue

#### Announcements System
- **Timed Announcements**: Set start/end dates for display
- **Multiple Styles**: Info (blue), Success (green), Warning (yellow), Danger (red)
- **Dismissible**: Users can dismiss one-time announcements
- **Dashboard Integration**: Announcements appear as modals on login
- New admin page: Announcements Management

### 🔧 Improvements
- Added Notifications and Announcements tabs to admin navigation
- Reservation validator integrated into booking flow
- Dynamic notification recipient resolution

---

## v1.2.2 (2026-02-28)

### 🚀 Performance Improvements
- Added API response caching (2-10 min TTL)
- Added CURL timeouts to prevent hanging requests
- Email queuing to avoid SMTP timeouts

### 🐛 Bug Fixes
- Modal z-index blocking issue resolved
- Approval email notification corrected
- CSRF tokens added to delete forms
- EST timezone display fixed

### 📱 Mobile Optimization
- Touch-friendly buttons (44px minimum)
- QR scanner camera support
- Full-screen modals on mobile

### 🔒 Security
- Drivers cannot delete checked-out reservations

---

## v1.2.1 (2026-02-27)
- Initial GitHub Actions release automation
- Dynamic footer version from version.txt

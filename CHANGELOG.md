# Changelog

## v1.2.2 (2026-02-28)

### 🚀 Performance Improvements
- Added API response caching for frequently called endpoints:
  - `get_locations()`: 5 minute cache
  - `get_requestable_assets()`: 2 minute cache  
  - `get_categories()`: 10 minute cache
  - `get_status_labels()`: 10 minute cache
- Added CURL timeouts (30s request, 10s connect) to prevent hanging requests
- Email queuing: Skip SMTP attempts to avoid 30+ second timeouts (queue for later processing)

### 🐛 Bug Fixes
- **Modal Fix**: Resolved modal z-index issue where approval/reject dialogs were blocked by grey overlay
- **Email Fix**: Corrected approval flow calling wrong email method (was sending rejection email on approval)
- **CSRF Fix**: Added missing CSRF tokens to delete reservation forms
- **Timezone Fix**: Use PHP date() instead of MySQL NOW() for correct EST timestamps

### 📱 Mobile Optimization
- Added comprehensive mobile.css with touch-friendly improvements:
  - Minimum 44x44px touch targets for all buttons
  - Full-screen modals on mobile devices
  - iOS zoom prevention (16px font on inputs)
  - Horizontal scroll for tables on small screens
- Updated Content-Security-Policy to allow html5-qrcode library from unpkg.com
- Updated Permissions-Policy to allow camera access for QR scanner

### 🔒 Security & Business Rules
- Drivers cannot delete reservations that have been checked out or completed
- Only Fleet Staff/Admin can delete confirmed reservations

### 🛠️ Technical
- Modals moved outside table structure (invalid HTML fix)
- JavaScript moves modals to body to escape stacking context
- PHPMailer timeout reduced to prevent page hangs

---

## v1.2.1 (2026-02-27)
- Initial GitHub Actions release automation
- Dynamic footer version from version.txt


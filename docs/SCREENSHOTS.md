# Screenshots Documentation

This document explains how to capture and prepare screenshots for the README.

## Screenshot Guidelines

### 1. Data Anonymization

Before capturing screenshots, ensure sensitive data is masked:

**Must be anonymized:**
- Last names → Use initials or "User"
- Email addresses → Use generic (e.g., user@example.com)
- VIN numbers → Use placeholder (e.g., 1HGBH41JXMN######)
- License plates → Blur or use placeholder
- Phone numbers → Use (555) 555-####
- Specific addresses → Use generic descriptions

### 2. Required Screenshots

| Screen | File Name | Access Level | Key Elements to Show |
|--------|-----------|--------------|---------------------|
| Login | login.png | Public | OAuth buttons, branding |
| Dashboard | dashboard.png | Driver | Stats cards, today's schedule |
| Vehicle Catalogue | catalogue.png | Driver | Vehicle cards, availability |
| Book Vehicle | book_vehicle.png | Driver | Date/time pickers, form |
| My Reservations | my_bookings.png | Driver | Reservation list, status badges |
| Checkout Form | checkout.png | Driver | Inspection checklist |
| Checkin Form | checkin.png | Driver | Mileage, condition form |
| Approval Queue | approvals.png | Staff | Pending list, approve/reject |
| Reservations | reservations.png | Staff | All reservations view |
| Maintenance | maintenance.png | Staff | Maintenance log |
| Reports | reports.png | Staff | Report options, charts |
| Vehicles Admin | vehicles.png | Admin | Vehicle management |
| Users Admin | users.png | Admin | User list, groups |
| Notifications | notifications.png | Admin | Email settings |
| Announcements | announcements.png | Admin | Announcement list |
| Security | security.png | Super Admin | Security checks, backup log |
| Settings | settings.png | Super Admin | Configuration panels |

### 3. Capture Tools

**Browser Extensions:**
- [GoFullPage](https://chrome.google.com/webstore/detail/gofullpage) - Full page screenshots
- [Awesome Screenshot](https://chrome.google.com/webstore/detail/awesome-screenshot) - Annotate and blur

**Desktop Tools:**
- macOS: Cmd+Shift+4 (selection) or Cmd+Shift+3 (full screen)
- Windows: Win+Shift+S (Snipping Tool)
- Linux: gnome-screenshot or flameshot

**Automated (CLI):**
```bash
# Using puppeteer (requires Node.js)
npx puppeteer screenshot https://inventory.amtrakfdt.com/booking/dashboard --output dashboard.png

# Using wkhtmltoimage
wkhtmltoimage https://inventory.amtrakfdt.com/booking/dashboard dashboard.png
```

### 4. Image Specifications

- **Format:** PNG (preferred) or WebP
- **Width:** 1200px recommended
- **Quality:** High, but optimize file size
- **Borders:** Optional subtle shadow/border

### 5. Adding to README
```markdown
### Dashboard
![Dashboard](docs/screenshots/dashboard.png)
*The main dashboard shows today's schedule, overdue vehicles, and quick statistics.*
*Access: All authenticated users*
```

### 6. Hosting Options

1. **GitHub Repository** (recommended)
   - Store in `docs/screenshots/`
   - Reference with relative paths

2. **GitHub Issues/Wiki**
   - Upload via drag-and-drop
   - Copy markdown URL

3. **External CDN**
   - Imgur, Cloudinary, etc.
   - Use direct image URLs

## Automation Script

For batch processing with anonymization:
```bash
#!/bin/bash
# anonymize-screenshot.sh

# Use ImageMagick to blur sensitive areas
# Define regions: x,y,width,height

convert input.png \
    -region 100x20+500+200 -blur 0x8 \
    -region 150x20+300+400 -blur 0x8 \
    output.png
```

## Checklist Before Publishing

- [ ] All sensitive data anonymized
- [ ] Images optimized (< 500KB each)
- [ ] Consistent browser/viewport size
- [ ] Light theme used (better visibility)
- [ ] No personal bookmarks/extensions visible
- [ ] Browser in incognito or clean profile

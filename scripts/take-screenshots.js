/**
 * Automated Screenshot Generator for SnipeScheduler FleetManager
 * 
 * Usage: 
 *   node take-screenshots.js --session=<session_id>
 * 
 * Get session_id from browser cookies after logging in manually
 */

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'https://inventory.amtrakfdt.com/booking';
const SCREENSHOT_DIR = path.join(__dirname, '../docs/screenshots');

// Pages to screenshot with access levels
const PAGES = [
    { name: 'login', path: '/login', auth: false, description: 'Login page' },
    { name: 'dashboard', path: '/dashboard', auth: true, description: 'Main dashboard' },
    { name: 'vehicle_catalogue', path: '/vehicle_catalogue', auth: true, description: 'Vehicle catalogue' },
    { name: 'vehicle_reserve', path: '/vehicle_reserve', auth: true, description: 'Book vehicle form' },
    { name: 'my_bookings', path: '/my_bookings', auth: true, description: 'My reservations' },
    { name: 'approval', path: '/approval', auth: true, staff: true, description: 'Approval queue' },
    { name: 'reservations', path: '/reservations', auth: true, staff: true, description: 'All reservations' },
    { name: 'maintenance', path: '/maintenance', auth: true, staff: true, description: 'Maintenance log' },
    { name: 'reports', path: '/reports', auth: true, staff: true, description: 'Reports' },
    { name: 'vehicles', path: '/vehicles', auth: true, admin: true, description: 'Vehicle management' },
    { name: 'users', path: '/users', auth: true, admin: true, description: 'User management' },
    { name: 'notifications', path: '/notifications', auth: true, admin: true, description: 'Email notifications' },
    { name: 'announcements', path: '/announcements', auth: true, admin: true, description: 'Announcements' },
    { name: 'security', path: '/security', auth: true, superadmin: true, description: 'Security dashboard' },
    { name: 'settings', path: '/settings', auth: true, superadmin: true, description: 'System settings' },
];

// CSS to inject for anonymization
const ANONYMIZE_CSS = `
    /* Hide/blur sensitive data */
    .user-email, 
    [data-email],
    td:contains('@'),
    .email-column {
        filter: blur(4px) !important;
    }
    
    /* Blur last names (keep first name) */
    .user-name::after {
        content: '' !important;
    }
    
    /* Blur VIN numbers */
    [data-field="vin"],
    td[data-vin] {
        filter: blur(4px) !important;
    }
    
    /* Blur license plates */
    [data-field="license"],
    .license-plate {
        filter: blur(4px) !important;
    }
    
    /* Hide specific backup log content */
    .backup-log pre {
        filter: blur(2px) !important;
    }
`;

// JavaScript to run on page for anonymization
const ANONYMIZE_JS = `
    // Anonymize emails
    document.querySelectorAll('*').forEach(el => {
        if (el.children.length === 0 && el.textContent) {
            // Email pattern
            el.textContent = el.textContent.replace(
                /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g, 
                'user@example.com'
            );
            // VIN pattern (17 chars)
            el.textContent = el.textContent.replace(
                /\\b[A-HJ-NPR-Z0-9]{17}\\b/g, 
                '1HGBH41JXMN######'
            );
            // Phone numbers
            el.textContent = el.textContent.replace(
                /\\b\\d{3}[-.]?\\d{3}[-.]?\\d{4}\\b/g, 
                '(555) 555-####'
            );
        }
    });
    
    // Anonymize last names (keep first name, replace last with initial)
    document.querySelectorAll('.top-bar-user strong, .user-name').forEach(el => {
        const parts = el.textContent.trim().split(' ');
        if (parts.length >= 2) {
            el.textContent = parts[0] + ' ' + parts[1][0] + '.';
        }
    });
`;

async function takeScreenshots(sessionId) {
    console.log('Starting screenshot capture...');
    
    // Ensure screenshot directory exists
    if (!fs.existsSync(SCREENSHOT_DIR)) {
        fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    }
    
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    const page = await browser.newPage();
    
    // Set viewport
    await page.setViewport({ width: 1400, height: 900 });
    
    // Set session cookie if provided
    if (sessionId) {
        await page.setCookie({
            name: 'PHPSESSID',
            value: sessionId,
            domain: 'inventory.amtrakfdt.com',
            path: '/',
            httpOnly: true,
            secure: true
        });
        console.log('Session cookie set');
    }
    
    // Add anonymization CSS
    await page.evaluateOnNewDocument((css) => {
        const style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);
    }, ANONYMIZE_CSS);
    
    for (const pageInfo of PAGES) {
        try {
            const url = BASE_URL + pageInfo.path;
            console.log(`Capturing: ${pageInfo.name} (${url})`);
            
            await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
            
            // Wait for page to fully render
            await page.waitForTimeout(1000);
            
            // Run anonymization script
            if (pageInfo.auth) {
                await page.evaluate(ANONYMIZE_JS);
            }
            
            // Wait a bit for changes to apply
            await page.waitForTimeout(500);
            
            // Take screenshot
            const filename = `${pageInfo.name}.png`;
            const filepath = path.join(SCREENSHOT_DIR, filename);
            
            await page.screenshot({ 
                path: filepath, 
                fullPage: false // Just viewport, not full page
            });
            
            console.log(`  ✓ Saved: ${filename}`);
            
        } catch (error) {
            console.error(`  ✗ Error capturing ${pageInfo.name}: ${error.message}`);
        }
    }
    
    await browser.close();
    console.log('\nScreenshot capture complete!');
    console.log(`Screenshots saved to: ${SCREENSHOT_DIR}`);
}

// Parse command line arguments
const args = process.argv.slice(2);
let sessionId = null;

for (const arg of args) {
    if (arg.startsWith('--session=')) {
        sessionId = arg.split('=')[1];
    }
}

if (!sessionId) {
    console.log(`
Usage: node take-screenshots.js --session=<PHPSESSID>

To get your session ID:
1. Log in to the application in your browser
2. Open Developer Tools (F12)
3. Go to Application > Cookies
4. Find PHPSESSID and copy its value
5. Run this script with that value

Example:
  node take-screenshots.js --session=abc123def456
`);
    process.exit(1);
}

takeScreenshots(sessionId);

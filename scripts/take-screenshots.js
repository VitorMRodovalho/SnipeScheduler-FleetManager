/**
 * Automated Screenshot Generator for SnipeScheduler FleetManager
 * v3 - Wider viewport, centered capture, improved anonymization
 */

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'https://your-instance.example.com/booking';
const SCREENSHOT_DIR = path.join(__dirname, '../docs/screenshots');

const PAGES = [
    { name: 'login', path: '/login', auth: false },
    { name: 'index', path: '/index', auth: true },
    { name: 'dashboard', path: '/dashboard', auth: true },
    { name: 'vehicle_catalogue', path: '/vehicle_catalogue', auth: true },
    { name: 'vehicle_reserve', path: '/vehicle_reserve', auth: true },
    { name: 'my_bookings', path: '/my_bookings', auth: true },
    { name: 'approval', path: '/approval', auth: true },
    { name: 'reservations', path: '/reservations', auth: true },
    { name: 'maintenance', path: '/maintenance', auth: true },
    { name: 'reports', path: '/reports', auth: true },
    { name: 'scan', path: '/scan', auth: true },
    { name: 'activity_log', path: '/activity_log', auth: true },
    { name: 'vehicles', path: '/vehicles', auth: true },
    { name: 'vehicles_create', path: '/vehicles?tab=create', auth: true },
    { name: 'users', path: '/users', auth: true },
    { name: 'notifications', path: '/notifications', auth: true },
    { name: 'announcements', path: '/announcements', auth: true },
    { name: 'security', path: '/security', auth: true },
    { name: 'booking_rules', path: '/booking_rules', auth: true },
    { name: 'settings', path: '/settings', auth: true },
];

// Names to anonymize - ADD YOUR REAL NAMES HERE
const NAMES_TO_REPLACE = [
    // Admin/Staff
    ['Vitor Rodovalho', 'John D.'],
    ['Vitor Maia Rodovalho', 'John D.'],
    ['Vitor Maiarodovalho', 'John D.'],
    ['Rodovalho, Vitor', 'Doe, John'],
    ['Maia Rodovalho, Vitor', 'Doe, John'],
    ['Rupp, Aaron', 'Smith, Admin'],
    ['Aaron Rupp', 'Admin Smith'],
    ['Dones, Yvette', 'Jones, Staff'],
    ['Yvette Dones', 'Staff Jones'],
    // Drivers (Last, First format + First Last)
    ['McFadden, Shawn', 'Driver A.'],
    ['Shawn McFadden', 'Driver A.'],
    ['Dokka, Charan', 'Driver B.'],
    ['Charan Dokka', 'Driver B.'],
    ['Johnson, Clifton', 'Driver C.'],
    ['Clifton Johnson', 'Driver C.'],
    ['Albright, Wes', 'Driver D.'],
    ['Wes Albright', 'Driver D.'],
    ['Salcedo Rueda, Edwin', 'Driver E.'],
    ['Edwin Salcedo Rueda', 'Driver E.'],
    ['Spong, J. Bradley', 'Driver F.'],
    ['Brad Spong', 'Driver F.'],
    ['Wray, Dale', 'Driver G.'],
    ['Wray, Pete', 'Driver G.'],
    ['Pete Wray', 'Driver G.'],
    ['Matthews, Jim', 'Driver H.'],
    ['Jim Matthews', 'Driver H.'],
    ['Dago Beek', 'Driver I.'],
    ['Carey, Damian', 'Driver J.'],
    ['Damian Carey', 'Driver J.'],
    ['Keppel, Mike', 'Driver K.'],
    ['Mike Keppel', 'Driver K.'],
    ['Sterbutzel, Matt', 'Driver L.'],
    ['Matt Sterbutzel', 'Driver L.'],
    ['Walck, Tim', 'Driver M.'],
    ['Tim Walck', 'Driver M.'],
    ['Berg, Steve', 'Driver N.'],
    ['Steve Berg', 'Driver N.'],
    ['Orozco, Claudia', 'Driver O.'],
    ['Claudia Orozco', 'Driver O.'],
    ['Milligan, Gary', 'Driver P.'],
    ['Gary Milligan', 'Driver P.'],
    ['Waddell, Keith', 'Driver Q.'],
    ['Keith Waddell', 'Driver Q.'],
    ['Varghese, Koshy', 'Driver R.'],
    ['Koshy Varghese', 'Driver R.'],
    ['Cummings, Shawn', 'Driver S.'],
    ['Shawn Cummings', 'Driver S.'],
    ['Lange, Brian', 'Driver T.'],
    ['Brian Lange', 'Driver T.'],
    ['Smith, Heather', 'Driver U.'],
    ['Heather Smith', 'Driver U.'],
    ['Gil, Aurelien', 'Driver V.'],
    ['Aurelien Gil', 'Driver V.'],
    ['Shreeve, Rob', 'Driver W.'],
    ['Rob Shreeve', 'Driver W.'],
    ['Smith, Ralph', 'Driver X.'],
    ['Ralph Smith', 'Driver X.'],
    ['Motwani, Rohit', 'Driver Y.'],
    ['Rohit Motwani', 'Driver Y.'],
    ['Ureta, Nathan', 'Driver Z.'],
    ['Nathan Ureta', 'Driver Z.'],
    ['Smith, Ellison', 'Driver AA.'],
    ['Ellison Smith', 'Driver AA.'],
    ['Kertis, Joe', 'Driver AB.'],
    ['Joe Kertis', 'Driver AB.'],
    ['Beabes, Shane', 'Driver AC.'],
    ['Shane Beabes', 'Driver AC.'],
    ['Mouradi, Adam', 'Driver AD.'],
    ['Adam Mouradi', 'Driver AD.'],
    ['Niknia, Homayoon', 'Driver AE.'],
    ['Talebi, Kaveh', 'Driver AF.'],
    ['Ofwono, Poly', 'Driver AG.'],
    ['Poland, Evan', 'Driver AH.'],
    // Catch-all for emails
    ['maiarodovalho', 'doe'],
    ['Rodovalho', 'Doe'],
    ['Vitor', 'John'],
];

// Location/address patterns to anonymize
const LOCATIONS_TO_REPLACE = [
    ['your-instance.example.com', 'yoursite.com'],
    ['example.com', 'yoursite.com'],
    ['@amtrak.com', '@email.com'],
    ['BPTR', 'FLEET'],
    ['B&P Office', 'Main Office'],
    ['B&P', 'Company'],
    ['Main office - primary vehicle pickup location', 'Main Office - Vehicle Pickup'],
    ['North Vent Facility', 'North Facility'],
    ['Area 4d:', 'Zone A:'],
    ['Amtrak B&P Tunnel Replacement (Delivery Partners)', 'Transit Fleet Co'],
    ['Amtrak B\\x26P Tunnel Replacement (Delivery Partners)', 'Transit Fleet Co'],
    ['Advance DP (BPTR)', 'Fleet Division'],
    ['Advance DP', 'Fleet Division'],
    ['Amtrak', 'Transit Co'],
    ['AMTRAK', 'TRANSIT CO'],
    ['FDT', 'Fleet'],
];

async function anonymizePage(page) {
    await page.evaluate((names, locations) => {
        // Check if element is inside footer (should NOT be anonymized)
        function isInFooter(node) {
            let current = node;
            while (current) {
                if (current.tagName === 'FOOTER' || 
                    (current.classList && current.classList.contains('footer')) ||
                    (current.classList && current.classList.contains('site-footer'))) {
                    return true;
                }
                current = current.parentElement;
            }
            return false;
        }

        function walkTextNodes(node, callback) {
            if (node.nodeType === Node.TEXT_NODE) {
                callback(node);
            } else {
                for (let child of node.childNodes) {
                    walkTextNodes(child, callback);
                }
            }
        }

        // Combine all replacements
        const allReplacements = [...names, ...locations];

        // Anonymize text content (skip footer)
        walkTextNodes(document.body, (textNode) => {
            // Skip if in footer
            if (isInFooter(textNode)) return;
            
            let text = textNode.textContent;
            
            for (const [find, replace] of allReplacements) {
                const regex = new RegExp(find.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                text = text.replace(regex, replace);
            }
            
            // Email pattern
            text = text.replace(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g, 'user@example.com');
            
            // VIN pattern
            text = text.replace(/\b[A-HJ-NPR-Z0-9]{17}\b/g, '1HGBH41JXMN######');
            
            if (text !== textNode.textContent) {
                textNode.textContent = text;
            }
        });

        // Also handle innerHTML for elements that might have nested content (skip footer)
        document.querySelectorAll('td, th, span, div, p, strong, a, label').forEach(el => {
            // Skip if in footer
            if (isInFooter(el)) return;
            
            if (el.children.length === 0 || el.tagName === 'A') {
                let text = el.innerHTML;
                for (const [find, replace] of allReplacements) {
                    const regex = new RegExp(find.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                    text = text.replace(regex, replace);
                }
                text = text.replace(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g, 'user@example.com');
                if (text !== el.innerHTML) {
                    el.innerHTML = text;
                }
            }
        });

        // Input values (these won't be in footer anyway)
        document.querySelectorAll('input, textarea').forEach(el => {
            let val = el.value;
            for (const [find, replace] of allReplacements) {
                const regex = new RegExp(find.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                val = val.replace(regex, replace);
            }
            val = val.replace(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g, 'user@example.com');
            if (val !== el.value) el.value = val;
        });

    }, NAMES_TO_REPLACE, LOCATIONS_TO_REPLACE);
}

async function takeScreenshots(sessionId) {
    console.log('Starting screenshot capture v3 (wider viewport, footer preserved)...\n');
    
    if (!fs.existsSync(SCREENSHOT_DIR)) {
        fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    }
    
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1920,1080']
    });
    
    const page = await browser.newPage();
    
    // Wide viewport to prevent menu wrapping - 1920px width
    await page.setViewport({ 
        width: 1920, 
        height: 1080,
        deviceScaleFactor: 1
    });
    
    // Set session cookie
    if (sessionId) {
        await page.setCookie({
            name: 'PHPSESSID',
            value: sessionId,
            domain: 'your-instance.example.com',
            path: '/',
            httpOnly: true,
            secure: true
        });
        console.log('Session cookie set\n');
    }
    
    for (const pageInfo of PAGES) {
        try {
            // For non-auth pages (login), clear session to show real SSO screen
            if (!pageInfo.auth && sessionId) {
                await page.deleteCookie({ name: "PHPSESSID", domain: "your-instance.example.com" });
            } else if (pageInfo.auth && sessionId) {
                await page.setCookie({ name: "PHPSESSID", value: sessionId, domain: "your-instance.example.com", path: "/", httpOnly: true, secure: true });
            }
            const url = BASE_URL + pageInfo.path;
            console.log(`Capturing: ${pageInfo.name}`);
            
            await page.goto(url, { waitUntil: 'networkidle2', timeout: 30000 });
            
            // Wait for page to fully render
            await new Promise(r => setTimeout(r, 1500));
            
            // Run anonymization on ALL pages (including login) - footer is excluded
            await anonymizePage(page);
            await new Promise(r => setTimeout(r, 500));
            
            // Take full viewport screenshot (no clipping - capture full width)
            const filename = `${pageInfo.name}.png`;
            const filepath = path.join(SCREENSHOT_DIR, filename);
            
            await page.screenshot({ 
                path: filepath, 
                fullPage: false  // Just viewport
            });
            
            console.log(`  ✓ Saved: ${filename}`);
            
        } catch (error) {
            console.error(`  ✗ Error: ${error.message}`);
        }
    }
    
    await browser.close();
    console.log('\n✅ Screenshot capture complete!');
    console.log(`Screenshots saved to: ${SCREENSHOT_DIR}`);
}

// Parse arguments
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

Example:
  node take-screenshots.js --session=abc123def456
`);
    process.exit(1);
}

takeScreenshots(sessionId);

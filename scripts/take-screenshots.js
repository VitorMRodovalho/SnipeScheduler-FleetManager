/**
 * Automated Screenshot Generator for SnipeScheduler FleetManager
 * v3 - Wider viewport, centered capture, improved anonymization
 */

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'https://inventory.amtrakfdt.com/booking';
const SCREENSHOT_DIR = path.join(__dirname, '../docs/screenshots');

const PAGES = [
    { name: 'login', path: '/login', auth: false },
    { name: 'dashboard', path: '/dashboard', auth: true },
    { name: 'vehicle_catalogue', path: '/vehicle_catalogue', auth: true },
    { name: 'vehicle_reserve', path: '/vehicle_reserve', auth: true },
    { name: 'my_bookings', path: '/my_bookings', auth: true },
    { name: 'approval', path: '/approval', auth: true },
    { name: 'reservations', path: '/reservations', auth: true },
    { name: 'maintenance', path: '/maintenance', auth: true },
    { name: 'reports', path: '/reports', auth: true },
    { name: 'vehicles', path: '/vehicles', auth: true },
    { name: 'users', path: '/users', auth: true },
    { name: 'notifications', path: '/notifications', auth: true },
    { name: 'announcements', path: '/announcements', auth: true },
    { name: 'security', path: '/security', auth: true },
    { name: 'settings', path: '/settings', auth: true },
];

// Names to anonymize - ADD YOUR REAL NAMES HERE
const NAMES_TO_REPLACE = [
    ['Vitor Rodovalho', 'John D.'],
    ['Vitor Maiarodovalho', 'John D.'],
    ['vitor.maiarodovalho@aecom.com', 'user@example.com'],
    ['vitor.rodovalho@aecom.com', 'user@example.com'],
    ['Rodovalho', 'Doe'],
    ['Vitor', 'John'],
    ['maiarodovalho', 'doe'],
];

// Location/address patterns to anonymize
const LOCATIONS_TO_REPLACE = [
    ['inventory.amtrakfdt.com', 'yoursite.com'],
    ['amtrakfdt.com', 'yoursite.com'],
    ['@amtrak.com', '@email.com'],
    ['BPTR', 'FLEET'],
    ['B&P Office', 'Main Office'],
    ['B&P', 'Company'],
    ['Main office - primary vehicle pickup location', 'Main Office - Vehicle Pickup'],
    ['North Vent Facility', 'North Facility'],
    ['Area 4d:', 'Zone A:'],
    ['Amtrak', 'Transit Co'],
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
            domain: 'inventory.amtrakfdt.com',
            path: '/',
            httpOnly: true,
            secure: true
        });
        console.log('Session cookie set\n');
    }
    
    for (const pageInfo of PAGES) {
        try {
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

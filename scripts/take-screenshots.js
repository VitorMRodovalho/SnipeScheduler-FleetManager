/**
 * Automated Screenshot Generator for SnipeScheduler FleetManager
 * v2 - Improved anonymization and 67% zoom
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

// Anonymization patterns
const ANONYMIZE_PATTERNS = [
    // Email patterns - replace with generic
    { pattern: /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g, replacement: 'user@example.com' },
    // VIN (17 alphanumeric, no I, O, Q)
    { pattern: /\b[A-HJ-NPR-Z0-9]{17}\b/g, replacement: '1HGBH41JXMN######' },
    // Phone numbers
    { pattern: /\b\d{3}[-.\s]?\d{3}[-.\s]?\d{4}\b/g, replacement: '(555) 555-0000' },
    // License plates (various formats)
    { pattern: /\b[A-Z0-9]{1,3}[-\s]?[A-Z0-9]{1,4}\b/g, replacement: 'ABC-1234' },
];

// Names to anonymize (add your actual names here)
const NAMES_TO_REPLACE = [
    // Format: [fullName, replacement]
    ['Vitor Rodovalho', 'John D.'],
    ['Rodovalho', 'Doe'],
    ['Vitor', 'John'],
    // Add more names as needed
];

async function anonymizePage(page) {
    await page.evaluate((patterns, names) => {
        // Function to walk all text nodes
        function walkTextNodes(node, callback) {
            if (node.nodeType === Node.TEXT_NODE) {
                callback(node);
            } else {
                for (let child of node.childNodes) {
                    walkTextNodes(child, callback);
                }
            }
        }

        // Anonymize text content
        walkTextNodes(document.body, (textNode) => {
            let text = textNode.textContent;
            
            // Replace names first (longer names first to avoid partial matches)
            for (const [name, replacement] of names) {
                const regex = new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                text = text.replace(regex, replacement);
            }
            
            // Replace patterns
            for (const p of patterns) {
                const regex = new RegExp(p.pattern, p.flags || 'g');
                text = text.replace(regex, p.replacement);
            }
            
            if (text !== textNode.textContent) {
                textNode.textContent = text;
            }
        });

        // Also anonymize input values
        document.querySelectorAll('input, textarea').forEach(el => {
            let val = el.value;
            for (const [name, replacement] of names) {
                const regex = new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                val = val.replace(regex, replacement);
            }
            for (const p of patterns) {
                const regex = new RegExp(p.pattern, p.flags || 'g');
                val = val.replace(regex, p.replacement);
            }
            if (val !== el.value) {
                el.value = val;
            }
        });

        // Anonymize table cells specifically
        document.querySelectorAll('td, th').forEach(cell => {
            let html = cell.innerHTML;
            for (const [name, replacement] of names) {
                const regex = new RegExp(name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
                html = html.replace(regex, replacement);
            }
            for (const p of patterns) {
                const regex = new RegExp(p.pattern, p.flags || 'g');
                html = html.replace(regex, p.replacement);
            }
            if (html !== cell.innerHTML) {
                cell.innerHTML = html;
            }
        });

    }, ANONYMIZE_PATTERNS.map(p => ({
        pattern: p.pattern.source,
        flags: p.pattern.flags,
        replacement: p.replacement
    })), NAMES_TO_REPLACE);
}

async function takeScreenshots(sessionId, extraNames = []) {
    console.log('Starting screenshot capture (v2 - 67% zoom, improved anonymization)...\n');
    
    // Add extra names to anonymize
    const allNames = [...NAMES_TO_REPLACE, ...extraNames];
    
    if (!fs.existsSync(SCREENSHOT_DIR)) {
        fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    }
    
    const browser = await puppeteer.launch({
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox']
    });
    
    const page = await browser.newPage();
    
    // Set larger viewport for 67% zoom effect (captures more content)
    // 1400x900 at 100% = 1750x1125 at 67%
    await page.setViewport({ 
        width: 1750, 
        height: 1125,
        deviceScaleFactor: 1
    });
    
    // Set CSS zoom to 67%
    await page.evaluateOnNewDocument(() => {
        const style = document.createElement('style');
        style.textContent = `
            html { 
                zoom: 0.67 !important; 
            }
        `;
        document.head.appendChild(style);
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
            
            // Run anonymization
            if (pageInfo.auth) {
                await anonymizePage(page);
                // Wait for DOM changes
                await new Promise(r => setTimeout(r, 500));
            }
            
            // Take screenshot
            const filename = `${pageInfo.name}.png`;
            const filepath = path.join(SCREENSHOT_DIR, filename);
            
            await page.screenshot({ 
                path: filepath, 
                fullPage: false,
                clip: {
                    x: 0,
                    y: 0,
                    width: 1400,  // Output at standard width
                    height: 900
                }
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
let extraNames = [];

for (const arg of args) {
    if (arg.startsWith('--session=')) {
        sessionId = arg.split('=')[1];
    }
    if (arg.startsWith('--names=')) {
        // Format: --names="FirstName LastName:Replacement,AnotherName:Rep2"
        const namesStr = arg.split('=')[1];
        namesStr.split(',').forEach(pair => {
            const [name, rep] = pair.split(':');
            if (name && rep) {
                extraNames.push([name.trim(), rep.trim()]);
            }
        });
    }
}

if (!sessionId) {
    console.log(`
Usage: node take-screenshots.js --session=<PHPSESSID> [--names="Name1:Rep1,Name2:Rep2"]

Options:
  --session=XXX     Your PHP session ID (required)
  --names="..."     Additional names to anonymize (optional)
                    Format: "Full Name:Replacement,Another:Rep2"

Example:
  node take-screenshots.js --session=abc123 --names="John Smith:User A,Jane Doe:User B"
`);
    process.exit(1);
}

takeScreenshots(sessionId, extraNames);

#!/usr/bin/env python3
"""
Security Remediation Script for SnipeScheduler FleetManager
Fixes sensitive data found by security_scan.py
"""

import re
import os

PROJECT_ROOT = "/var/www/snipescheduler"

# Remediation rules: file -> [(pattern, replacement, description)]
REMEDIATIONS = {
    # Email Service - Make baseUrl configurable
    "src/email_service.php": [
        (
            r"\$baseUrl = 'https://inventory\.amtrakfdt\.com/booking';",
            "$baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');",
            "Make baseUrl configurable"
        ),
        (
            r"\$baseUrl = 'https://inventory\.amtrakfdt\.com';",
            "$baseUrl = rtrim($this->config['app']['base_url'] ?? '', '/');",
            "Make baseUrl configurable"
        ),
        (
            r"Frederick Douglass Tunnel Project - Fleet Vehicle Management System",
            "Fleet Vehicle Management System",
            "Remove project name from email template"
        ),
        (
            r"FDT Fleet Management",
            "Fleet Management",
            "Remove FDT acronym"
        ),
    ],
    
    # Snipe-IT Client - Remove comments
    "src/snipeit_client.php": [
        (
            r"Amtrak B&P",
            "the program",
            "Remove company/project from comments"
        ),
    ],
    
    # Vehicles - Remove hardcoded filters
    "public/vehicles.php": [
        (
            r"stripos\(\$co\['name'\], 'Amtrak'\) !== false",
            "false /* Default company filter */",
            "Remove Amtrak filter"
        ),
        (
            r"stripos\(\$loc\['name'\], 'B&P'\) !== false",
            "false /* Default location filter */",
            "Remove B&P location filter"
        ),
    ],
    
    # Scan - Replace placeholder
    "public/scan.php": [
        (
            r'placeholder="Asset Tag \(e\.g\., BPTR-VEH-001\)"',
            'placeholder="Asset Tag (e.g., VEH-001)"',
            "Generic asset tag placeholder"
        ),
        (
            r"// Expected format: https://inventory\.amtrakfdt\.com/hardware/300",
            "// Expected format: https://yoursite.com/hardware/300",
            "Generic URL in comment"
        ),
    ],
    
    # Quick - Replace placeholder
    "public/quick.php": [
        (
            r'placeholder="e\.g\., BPTR-VEH-001"',
            'placeholder="e.g., VEH-001"',
            "Generic asset tag placeholder"
        ),
    ],
    
    # Users - Replace email placeholder
    "public/users.php": [
        (
            r'placeholder="user@aecom\.com"',
            'placeholder="user@example.com"',
            "Generic email placeholder"
        ),
    ],
    
    # Screenshots doc - Replace example URLs
    "docs/SCREENSHOTS.md": [
        (
            r"https://inventory\.amtrakfdt\.com",
            "https://your-domain.com",
            "Generic URL in documentation"
        ),
    ],
    
    # Index - Remove FDT
    "public/index.php": [
        (
            r"FDT Fleet",
            "Fleet",
            "Remove FDT acronym"
        ),
    ],
}

# Files to check for hardcoded Snipe-IT URLs and make configurable
SNIPEIT_URL_FILES = [
    "public/maintenance.php",
    "public/vehicles.php", 
    "public/users.php",
]


def apply_remediations():
    """Apply all remediation rules"""
    changes_made = []
    
    for rel_path, rules in REMEDIATIONS.items():
        filepath = os.path.join(PROJECT_ROOT, rel_path)
        if not os.path.exists(filepath):
            print(f"⚠️  File not found: {rel_path}")
            continue
        
        with open(filepath, 'r', encoding='utf-8') as f:
            content = f.read()
        
        original = content
        for pattern, replacement, description in rules:
            new_content = re.sub(pattern, replacement, content)
            if new_content != content:
                print(f"✓ {rel_path}: {description}")
                changes_made.append((rel_path, description))
                content = new_content
        
        if content != original:
            with open(filepath, 'w', encoding='utf-8') as f:
                f.write(content)
    
    return changes_made


def add_base_url_to_config():
    """Add base_url to config if not present"""
    config_path = os.path.join(PROJECT_ROOT, "config/config.php")
    
    with open(config_path, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # Check if base_url already exists in app section
    if "'base_url'" in content:
        print("✓ base_url already in config")
        return False
    
    # Find the 'app' => [ section and add base_url
    pattern = r"('app'\s*=>\s*\[)"
    replacement = r"\1\n        'base_url' => 'https://inventory.amtrakfdt.com/booking',"
    
    new_content = re.sub(pattern, replacement, content)
    
    if new_content != content:
        with open(config_path, 'w', encoding='utf-8') as f:
            f.write(new_content)
        print("✓ Added base_url to config")
        return True
    
    return False


def create_snipeit_url_helper():
    """Info about making Snipe-IT URLs configurable"""
    print("\n" + "=" * 60)
    print("MANUAL REVIEW REQUIRED")
    print("=" * 60)
    print("""
The following files have hardcoded Snipe-IT URLs that link to
the Snipe-IT admin interface. These are INTENTIONAL as they
provide direct links to manage assets in Snipe-IT:

  - public/maintenance.php (link to create/view maintenances)
  - public/vehicles.php (link to hardware details)
  - public/users.php (link to user profiles)

RECOMMENDATION: 
These URLs are OK to keep hardcoded as they're admin shortcuts
to the Snipe-IT instance. The domain (inventory.amtrakfdt.com)
is the actual Snipe-IT server and won't change.

If you want to make them configurable, add to config.php:
  'snipeit' => [
      'url' => 'https://inventory.amtrakfdt.com',
      ...
  ]

And replace hardcoded URLs with:
  <?= rtrim(\$config['snipeit']['url'], '/') ?>/hardware/<?= \$id ?>
""")


def main():
    print("=" * 60)
    print("SECURITY REMEDIATION - SnipeScheduler FleetManager")
    print("=" * 60)
    print()
    
    # Apply remediations
    print("Applying remediations...\n")
    changes = apply_remediations()
    
    # Add base_url to config
    print()
    add_base_url_to_config()
    
    # Summary
    print("\n" + "=" * 60)
    print(f"SUMMARY: {len(changes)} changes applied")
    print("=" * 60)
    
    # Show what still needs manual review
    create_snipeit_url_helper()
    
    print("\n" + "=" * 60)
    print("ITEMS REQUIRING MANUAL REVIEW")
    print("=" * 60)
    print("""
1. config/config.php:
   - Line 129: 'from_name' => 'BPTR Asset Management...'
   - CHANGE TO: 'from_name' => 'Fleet Management System'
   - NOTE: Email addresses are OK (they're real contacts)

2. scripts/take-screenshots.js:
   - Contains sensitive terms INTENTIONALLY for anonymization
   - NO ACTION NEEDED (it's the anonymization list)

3. README.md:
   - GitHub URLs contain username - OK (it's the repo owner)
   - NO ACTION NEEDED

4. Low Severity 'password'/'secret' findings:
   - These are mostly form field names and config keys
   - VERIFY no actual passwords are hardcoded

5. 'holman' references:
   - This is a maintenance vendor name in form fields
   - DECIDE: Keep or replace with generic 'Service Provider'
""")


if __name__ == "__main__":
    main()

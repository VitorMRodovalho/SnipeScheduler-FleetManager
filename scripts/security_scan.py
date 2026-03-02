#!/usr/bin/env python3
"""
Security Scanner for SnipeScheduler FleetManager
Scans for sensitive data in code, comments, documentation, and metadata.
"""

import os
import re
import json
from pathlib import Path
from datetime import datetime
from collections import defaultdict

# Configuration
PROJECT_ROOT = "/var/www/snipescheduler"
REPORT_FILE = "/tmp/security_scan_report.json"

# Sensitive terms to search for (case-insensitive)
SENSITIVE_TERMS = {
    # Company names
    "aecom": {"severity": "high", "category": "company", "action": "Remove or replace with generic term"},
    "amtrak": {"severity": "high", "category": "company", "action": "Remove or replace with generic term"},
    
    # Project names
    "b&p": {"severity": "high", "category": "project", "action": "Remove or replace with generic term"},
    "bptr": {"severity": "high", "category": "project", "action": "Remove or replace with generic term"},
    "fdt": {"severity": "medium", "category": "project", "action": "Remove or replace with generic term"},
    "frederick douglass": {"severity": "high", "category": "project", "action": "Remove completely"},
    "tunnel replacement": {"severity": "high", "category": "project", "action": "Remove or replace"},
    "b&p tunnel": {"severity": "high", "category": "project", "action": "Remove or replace"},
    
    # AI assistants (shouldn't be in production code)
    "claude.ai": {"severity": "medium", "category": "ai", "action": "Remove AI references"},
    "claude": {"severity": "low", "category": "ai", "action": "Review if AI-related"},
    "gemini": {"severity": "low", "category": "ai", "action": "Review if AI-related"},
    "chatgpt": {"severity": "low", "category": "ai", "action": "Review if AI-related"},
    "openai": {"severity": "low", "category": "ai", "action": "Review if AI-related"},
    "anthropic": {"severity": "low", "category": "ai", "action": "Review if AI-related"},
    
    # Personal identifiers
    "rodovalho": {"severity": "high", "category": "personal", "action": "Remove personal name from non-credit contexts"},
    "maiarodovalho": {"severity": "high", "category": "personal", "action": "Remove email username"},
    
    # Infrastructure
    "inventory.amtrakfdt.com": {"severity": "medium", "category": "infrastructure", "action": "OK in config, remove from docs"},
    "amtrakfdt.com": {"severity": "medium", "category": "infrastructure", "action": "Review context"},
    
    # Credentials patterns
    "password": {"severity": "low", "category": "security", "action": "Ensure not hardcoded"},
    "api_key": {"severity": "medium", "category": "security", "action": "Ensure not hardcoded"},
    "secret": {"severity": "low", "category": "security", "action": "Ensure not hardcoded"},
    
    # Other project-specific
    "holman": {"severity": "low", "category": "vendor", "action": "Review if should be generic"},
}

# File extensions to scan
SCAN_EXTENSIONS = {
    '.php', '.js', '.css', '.html', '.htm', '.json', '.xml', '.yaml', '.yml',
    '.md', '.txt', '.sql', '.sh', '.bash', '.py', '.ini', '.conf', '.htaccess',
    '.env', '.example', '.sample', '.bak', '.log'
}

# Directories to skip
SKIP_DIRS = {
    '.git', 'node_modules', 'vendor', '__pycache__', '.idea', '.vscode',
    'cache', 'tmp', 'logs'
}

# Files to skip
SKIP_FILES = {
    'security_scan.py',  # This script
    'package-lock.json',
    'composer.lock',
}

# Context patterns for smarter detection
ALLOWED_CONTEXTS = {
    # These are OK in specific files
    "config.php": ["amtrakfdt.com", "amtrak"],  # OK in config
    "footer": ["rodovalho"],  # OK in credits
    "layout.php": ["rodovalho"],  # OK in footer credits
    "CREDITS": ["rodovalho", "aecom"],
    "LICENSE": ["rodovalho"],
}


class SecurityScanner:
    def __init__(self, root_path):
        self.root_path = Path(root_path)
        self.findings = []
        self.stats = defaultdict(int)
        
    def should_skip_file(self, filepath):
        """Check if file should be skipped"""
        path = Path(filepath)
        
        # Skip by directory
        for part in path.parts:
            if part in SKIP_DIRS:
                return True
        
        # Skip by filename
        if path.name in SKIP_FILES:
            return True
            
        # Skip by extension
        if path.suffix.lower() not in SCAN_EXTENSIONS and path.name not in ['.htaccess', '.gitignore', '.env']:
            return True
            
        # Skip binary files
        if path.suffix.lower() in {'.png', '.jpg', '.jpeg', '.gif', '.ico', '.pdf', '.zip', '.tar', '.gz'}:
            return True
            
        return False
    
    def is_allowed_context(self, filepath, term):
        """Check if term is allowed in this specific file context"""
        filename = Path(filepath).name.lower()
        rel_path = str(filepath).lower()
        
        for allowed_file, allowed_terms in ALLOWED_CONTEXTS.items():
            if allowed_file.lower() in rel_path or allowed_file.lower() in filename:
                if term.lower() in [t.lower() for t in allowed_terms]:
                    return True
        return False
    
    def scan_file(self, filepath):
        """Scan a single file for sensitive terms"""
        try:
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as f:
                content = f.read()
                lines = content.split('\n')
        except Exception as e:
            return
        
        rel_path = str(filepath).replace(str(self.root_path), '').lstrip('/')
        
        for term, info in SENSITIVE_TERMS.items():
            # Case-insensitive search
            pattern = re.compile(re.escape(term), re.IGNORECASE)
            
            for line_num, line in enumerate(lines, 1):
                matches = pattern.findall(line)
                if matches:
                    # Check if allowed in this context
                    if self.is_allowed_context(filepath, term):
                        continue
                    
                    # Skip if it's just a variable name like $claude or function claude()
                    if info["category"] == "ai" and term.lower() == "claude":
                        # Check if it's likely a code reference vs actual AI mention
                        if re.search(r'[\$\-_]claude|claude[\(\[]', line, re.IGNORECASE):
                            continue
                    
                    # Determine context (comment, string, code)
                    context = self._determine_context(line, filepath)
                    
                    self.findings.append({
                        "file": rel_path,
                        "line": line_num,
                        "term": term,
                        "matched": matches[0],
                        "context": context,
                        "line_content": line.strip()[:150],
                        "severity": info["severity"],
                        "category": info["category"],
                        "action": info["action"],
                    })
                    self.stats[info["severity"]] += 1
                    self.stats[f"cat_{info['category']}"] += 1
    
    def _determine_context(self, line, filepath):
        """Determine if the match is in a comment, string, or code"""
        ext = Path(filepath).suffix.lower()
        line_stripped = line.strip()
        
        # PHP/JS comments
        if line_stripped.startswith('//') or line_stripped.startswith('#'):
            return "comment"
        if line_stripped.startswith('/*') or line_stripped.startswith('*'):
            return "comment"
        
        # HTML comments
        if '<!--' in line:
            return "comment"
        
        # Markdown
        if ext == '.md':
            return "documentation"
        
        # String literals
        if re.search(r'["\'][^"\']*$', line):
            return "string"
        
        return "code"
    
    def scan_all(self):
        """Scan all files in the project"""
        print(f"Scanning {self.root_path}...\n")
        
        file_count = 0
        for filepath in self.root_path.rglob('*'):
            if filepath.is_file() and not self.should_skip_file(filepath):
                self.scan_file(filepath)
                file_count += 1
        
        self.stats["files_scanned"] = file_count
        return self.findings
    
    def generate_report(self):
        """Generate a detailed report"""
        report = {
            "scan_date": datetime.now().isoformat(),
            "project_root": str(self.root_path),
            "statistics": dict(self.stats),
            "findings_by_severity": {
                "high": [],
                "medium": [],
                "low": [],
            },
            "findings_by_file": defaultdict(list),
            "summary": {},
        }
        
        for finding in self.findings:
            report["findings_by_severity"][finding["severity"]].append(finding)
            report["findings_by_file"][finding["file"]].append(finding)
        
        report["findings_by_file"] = dict(report["findings_by_file"])
        report["summary"] = {
            "total_findings": len(self.findings),
            "high_severity": len(report["findings_by_severity"]["high"]),
            "medium_severity": len(report["findings_by_severity"]["medium"]),
            "low_severity": len(report["findings_by_severity"]["low"]),
            "files_with_issues": len(report["findings_by_file"]),
        }
        
        return report
    
    def print_report(self, report):
        """Print a formatted report to console"""
        print("=" * 70)
        print("SECURITY SCAN REPORT - SnipeScheduler FleetManager")
        print("=" * 70)
        print(f"Scan Date: {report['scan_date']}")
        print(f"Files Scanned: {self.stats.get('files_scanned', 0)}")
        print()
        
        print("SUMMARY")
        print("-" * 40)
        print(f"  Total Findings:    {report['summary']['total_findings']}")
        print(f"  🔴 High Severity:  {report['summary']['high_severity']}")
        print(f"  🟡 Medium Severity: {report['summary']['medium_severity']}")
        print(f"  🟢 Low Severity:   {report['summary']['low_severity']}")
        print(f"  Files with Issues: {report['summary']['files_with_issues']}")
        print()
        
        # High severity findings
        if report["findings_by_severity"]["high"]:
            print("🔴 HIGH SEVERITY FINDINGS")
            print("-" * 40)
            for f in report["findings_by_severity"]["high"]:
                print(f"  File: {f['file']}:{f['line']}")
                print(f"  Term: '{f['matched']}' ({f['category']})")
                print(f"  Context: {f['context']}")
                print(f"  Line: {f['line_content'][:80]}...")
                print(f"  Action: {f['action']}")
                print()
        
        # Medium severity findings
        if report["findings_by_severity"]["medium"]:
            print("🟡 MEDIUM SEVERITY FINDINGS")
            print("-" * 40)
            for f in report["findings_by_severity"]["medium"]:
                print(f"  File: {f['file']}:{f['line']}")
                print(f"  Term: '{f['matched']}' ({f['category']})")
                print(f"  Action: {f['action']}")
                print()
        
        # Low severity - just count by term
        if report["findings_by_severity"]["low"]:
            print("🟢 LOW SEVERITY (Summary)")
            print("-" * 40)
            low_by_term = defaultdict(int)
            for f in report["findings_by_severity"]["low"]:
                low_by_term[f['term']] += 1
            for term, count in sorted(low_by_term.items(), key=lambda x: -x[1]):
                print(f"  '{term}': {count} occurrences")
            print()
        
        # Files with most issues
        print("FILES WITH MOST ISSUES")
        print("-" * 40)
        sorted_files = sorted(report["findings_by_file"].items(), key=lambda x: -len(x[1]))[:10]
        for filepath, findings in sorted_files:
            high = len([f for f in findings if f['severity'] == 'high'])
            med = len([f for f in findings if f['severity'] == 'medium'])
            low = len([f for f in findings if f['severity'] == 'low'])
            print(f"  {filepath}: {len(findings)} findings (H:{high} M:{med} L:{low})")
        print()
        
        print("RECOMMENDED ACTIONS")
        print("-" * 40)
        print("  1. Review HIGH severity items immediately")
        print("  2. Replace company/project names with generic terms")
        print("  3. Ensure no credentials are hardcoded")
        print("  4. Remove AI assistant references from production code")
        print("  5. Keep personal names only in LICENSE/CREDITS sections")
        print()
        
        return report


def main():
    scanner = SecurityScanner(PROJECT_ROOT)
    scanner.scan_all()
    report = scanner.generate_report()
    scanner.print_report(report)
    
    # Save JSON report
    with open(REPORT_FILE, 'w') as f:
        json.dump(report, f, indent=2, default=str)
    print(f"Full report saved to: {REPORT_FILE}")


if __name__ == "__main__":
    main()

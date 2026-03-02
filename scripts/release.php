<?php
/**
 * Release Management Script
 * 
 * Usage:
 *   php release.php patch "Bug fixes and small improvements"
 *   php release.php minor "New feature: Security Dashboard"
 *   php release.php major "Breaking changes - Complete rewrite"
 *   php release.php --current   # Show current version
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';

$versionFile = __DIR__ . '/../version.txt';
$changelogFile = __DIR__ . '/../CHANGELOG.md';

// Get current version
$currentVersion = trim(file_get_contents($versionFile));
echo "Current version: v{$currentVersion}\n\n";

// Parse arguments
$args = array_slice($argv, 1);

if (empty($args) || $args[0] === '--current') {
    exit(0);
}

$releaseType = strtolower($args[0]);
$releaseNotes = $args[1] ?? '';

if (!in_array($releaseType, ['major', 'minor', 'patch'])) {
    echo "Usage: php release.php [major|minor|patch] \"Release notes\"\n";
    echo "  major - Breaking changes (X.0.0)\n";
    echo "  minor - New features (1.X.0)\n";
    echo "  patch - Bug fixes (1.0.X)\n";
    exit(1);
}

if (empty($releaseNotes)) {
    echo "Error: Release notes required\n";
    exit(1);
}

// Calculate new version
$parts = explode('.', $currentVersion);
$major = (int)$parts[0];
$minor = (int)($parts[1] ?? 0);
$patch = (int)($parts[2] ?? 0);

switch ($releaseType) {
    case 'major':
        $major++;
        $minor = 0;
        $patch = 0;
        break;
    case 'minor':
        $minor++;
        $patch = 0;
        break;
    case 'patch':
        $patch++;
        break;
}

$newVersion = "{$major}.{$minor}.{$patch}";
echo "New version: v{$newVersion}\n";
echo "Release notes: {$releaseNotes}\n\n";

// Confirm
echo "Proceed? [y/N]: ";
$confirm = trim(fgets(STDIN));
if (strtolower($confirm) !== 'y') {
    echo "Aborted.\n";
    exit(0);
}

// 1. Update version.txt
file_put_contents($versionFile, $newVersion);
echo "✓ Updated version.txt\n";

// 2. Update CSS cache bust in all PHP files
$cssVersion = "v={$newVersion}";
$phpFiles = glob(__DIR__ . '/../public/*.php');
foreach ($phpFiles as $file) {
    $content = file_get_contents($file);
    $content = preg_replace('/style\.css\?v=[0-9.]+/', "style.css?{$cssVersion}", $content);
    file_put_contents($file, $content);
}
// Also update layout.php
$layoutFile = __DIR__ . '/../src/layout.php';
if (file_exists($layoutFile)) {
    $content = file_get_contents($layoutFile);
    $content = preg_replace('/style\.css\?v=[0-9.]+/', "style.css?{$cssVersion}", $content);
    file_put_contents($layoutFile, $content);
}
echo "✓ Updated CSS cache bust to {$cssVersion}\n";

// 3. Update CHANGELOG.md
$date = date('Y-m-d');
$changelogEntry = "\n## v{$newVersion} ({$date})\n\n{$releaseNotes}\n";
$changelog = file_get_contents($changelogFile);
$changelog = preg_replace('/(# Changelog\n)/', "$1{$changelogEntry}", $changelog);
file_put_contents($changelogFile, $changelog);
echo "✓ Updated CHANGELOG.md\n";

// 4. Check if show_release_announcements is enabled
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'show_release_announcements'");
$stmt->execute();
$showAnnouncements = $stmt->fetchColumn() !== '0';

if ($showAnnouncements) {
    // 5. Create system announcement
    $title = "🚀 New Release: v{$newVersion}";
    $content = "<p><strong>Version {$newVersion}</strong> is now available!</p>\n<p>{$releaseNotes}</p>\n<p><em>See the full changelog in the admin area for more details.</em></p>";
    
    $stmt = $pdo->prepare("
        INSERT INTO announcements 
        (title, content, type, start_datetime, end_datetime, is_active, show_once, is_system, system_type, created_by_name, created_by_email)
        VALUES (?, ?, 'info', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 1, 1, 1, 'release', 'System', 'system@fleet.local')
    ");
    $stmt->execute([$title, $content]);
    echo "✓ Created release announcement (7 days)\n";
} else {
    echo "✓ Release announcements disabled - skipped\n";
}

// 6. Git commands
echo "\n=== Git Commands ===\n";
echo "Run these commands to complete the release:\n\n";
echo "  git add -A\n";
echo "  git commit -m \"Release v{$newVersion}: {$releaseNotes}\"\n";
echo "  git push origin main\n";
echo "  git tag -a v{$newVersion} -m \"Release v{$newVersion}\"\n";
echo "  git push origin v{$newVersion}\n";

echo "\n✅ Release v{$newVersion} prepared!\n";

<?php
/**
 * Announcements System
 * Display timed announcements to users
 */

/**
 * Get active announcements for a user
 */
function get_active_announcements(string $userEmail, PDO $pdo): array
{
    $now = date('Y-m-d H:i:s');
    
    $sql = "
        SELECT a.* 
        FROM announcements a
        LEFT JOIN announcement_dismissals d 
            ON a.id = d.announcement_id AND d.user_email = ?
        WHERE a.is_active = 1
        AND a.start_datetime <= ?
        AND a.end_datetime >= ?
        AND (a.show_once = 0 OR d.id IS NULL)
        ORDER BY a.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userEmail, $now, $now]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Dismiss an announcement for a user
 */
function dismiss_announcement(int $announcementId, string $userEmail, PDO $pdo): bool
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO announcement_dismissals (announcement_id, user_email)
        VALUES (?, ?)
    ");
    return $stmt->execute([$announcementId, $userEmail]);
}

/**
 * Get all announcements for admin
 */
function get_all_announcements(PDO $pdo, bool $includeExpired = true): array
{
    $sql = "SELECT * FROM announcements";
    if (!$includeExpired) {
        $sql .= " WHERE end_datetime >= NOW()";
    }
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create an announcement
 */
function create_announcement(
    string $title,
    string $content,
    string $type,
    string $startDatetime,
    string $endDatetime,
    bool $showOnce,
    string $createdByName,
    string $createdByEmail,
    PDO $pdo
): int {
    $stmt = $pdo->prepare("
        INSERT INTO announcements 
        (title, content, type, start_datetime, end_datetime, show_once, created_by_name, created_by_email)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $title, $content, $type, $startDatetime, $endDatetime, 
        $showOnce ? 1 : 0, $createdByName, $createdByEmail
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Update an announcement
 */
function update_announcement(
    int $id,
    string $title,
    string $content,
    string $type,
    string $startDatetime,
    string $endDatetime,
    bool $showOnce,
    bool $isActive,
    PDO $pdo
): bool {
    $stmt = $pdo->prepare("
        UPDATE announcements SET
            title = ?, content = ?, type = ?, 
            start_datetime = ?, end_datetime = ?,
            show_once = ?, is_active = ?
        WHERE id = ?
    ");
    return $stmt->execute([
        $title, $content, $type, $startDatetime, $endDatetime,
        $showOnce ? 1 : 0, $isActive ? 1 : 0, $id
    ]);
}

/**
 * Delete an announcement
 */
function delete_announcement(int $id, PDO $pdo): bool
{
    $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
    return $stmt->execute([$id]);
}

/**
 * Get announcement by ID
 */
function get_announcement(int $id, PDO $pdo): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Render announcements modal HTML
 * Include this in pages where announcements should appear
 */
function render_announcements_modal(string $userEmail, PDO $pdo): string
{
    $announcements = get_active_announcements($userEmail, $pdo);
    
    if (empty($announcements)) {
        return '';
    }
    
    $typeIcons = [
        'info' => 'bi-info-circle-fill',
        'warning' => 'bi-exclamation-triangle-fill',
        'success' => 'bi-check-circle-fill',
        'danger' => 'bi-x-octagon-fill',
    ];
    
    $typeColors = [
        'info' => 'primary',
        'warning' => 'warning',
        'success' => 'success',
        'danger' => 'danger',
    ];
    
    $html = '';
    
    foreach ($announcements as $index => $a) {
        $icon = $typeIcons[$a['type']] ?? 'bi-info-circle-fill';
        $color = $typeColors[$a['type']] ?? 'primary';
        $modalId = 'announcementModal' . $a['id'];
        $showOnLoad = $index === 0 ? 'true' : 'false';
        
        $html .= '
        <div class="modal fade" id="' . $modalId . '" tabindex="-1" data-show-on-load="' . $showOnLoad . '">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-' . $color . '">
                    <div class="modal-header bg-' . $color . ' text-white">
                        <h5 class="modal-title">
                            <i class="bi ' . $icon . ' me-2"></i>' . htmlspecialchars($a['title']) . '
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        ' . nl2br(htmlspecialchars($a['content'])) . '
                    </div>
                    <div class="modal-footer">
                        <small class="text-muted me-auto">
                            Valid until ' . date('M j, Y', strtotime($a['end_datetime'])) . '
                        </small>
                        <form method="post" action="dismiss_announcement.php" class="d-inline">
                            <input type="hidden" name="announcement_id" value="' . $a['id'] . '">
                            <input type="hidden" name="redirect" value="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                Don\'t show again
                            </button>
                        </form>
                        <button type="button" class="btn btn-' . $color . '" data-bs-dismiss="modal">
                            Got it
                        </button>
                    </div>
                </div>
            </div>
        </div>';
    }
    
    // Add script to show first announcement on page load
    $html .= '
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var firstModal = document.querySelector(".modal[data-show-on-load=\'true\']");
        if (firstModal) {
            var modal = new bootstrap.Modal(firstModal);
            modal.show();
        }
    });
    </script>';
    
    return $html;
}

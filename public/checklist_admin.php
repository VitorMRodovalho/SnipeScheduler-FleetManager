<?php
/**
 * Checklist Management — Admin UI
 * Manage inspection checklist profiles, categories, items, and assignments.
 *
 * @since v2.2.0
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/inspection_checklist.php';
require_once SRC_PATH . '/snipeit_client.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}

$active = 'activity_log'; // Admin nav group
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    header('Location: dashboard');
    exit;
}

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$userEmail = $currentUser['email'] ?? '';
$success = '';
$error = '';

// Auto-create tables if not exist
if (!checklist_tables_exist($pdo)) {
    try {
        $sql = file_get_contents(__DIR__ . '/../migrations/v2_1_0_checklist_admin.sql');
        $pdo->exec($sql);
    } catch (\Throwable $e) {
        $error = 'Failed to initialize checklist tables: ' . $e->getMessage();
    }
}

$tab = $_GET['tab'] ?? 'profiles';
$editProfileId = (int)($_GET['profile_id'] ?? 0);

// ── POST Handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_profile') {
        $name = trim($_POST['profile_name'] ?? '');
        $desc = trim($_POST['profile_description'] ?? '');
        if (empty($name)) {
            $error = 'Profile name is required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO checklist_profiles (name, description, created_by) VALUES (?, ?, ?)");
                $stmt->execute([$name, $desc, $userEmail]);
                $success = "Profile '{$name}' created.";
                activity_log_event('checklist_profile_created', "Created checklist profile: {$name}", ['metadata' => ['profile_name' => $name]]);
            } catch (\Throwable $e) {
                $error = stripos($e->getMessage(), 'Duplicate') !== false ? 'A profile with that name already exists.' : $e->getMessage();
            }
        }

    } elseif ($action === 'set_default') {
        $profileId = (int)$_POST['profile_id'];
        $pdo->exec("UPDATE checklist_profiles SET is_default = 0");
        $pdo->prepare("UPDATE checklist_profiles SET is_default = 1 WHERE id = ?")->execute([$profileId]);
        $success = 'Default profile updated.';
        activity_log_event('checklist_default_changed', "Set default checklist profile ID: {$profileId}");

    } elseif ($action === 'toggle_profile_active') {
        $profileId = (int)$_POST['profile_id'];
        $pdo->prepare("UPDATE checklist_profiles SET is_active = NOT is_active WHERE id = ?")->execute([$profileId]);
        $success = 'Profile status updated.';

    } elseif ($action === 'delete_profile') {
        $profileId = (int)$_POST['profile_id'];
        // Check if assigned
        $assignCount = $pdo->prepare("SELECT COUNT(*) FROM checklist_profile_assignments WHERE profile_id = ?");
        $assignCount->execute([$profileId]);
        if ((int)$assignCount->fetchColumn() > 0) {
            $error = 'Cannot delete a profile that has vehicle assignments. Remove assignments first.';
        } else {
            $pdo->prepare("DELETE FROM checklist_profiles WHERE id = ? AND is_default = 0")->execute([$profileId]);
            $success = 'Profile deleted.';
            activity_log_event('checklist_profile_deleted', "Deleted checklist profile ID: {$profileId}");
        }

    } elseif ($action === 'duplicate_profile') {
        $sourceId = (int)$_POST['profile_id'];
        $newName = trim($_POST['new_name'] ?? '');
        if (empty($newName)) {
            $error = 'New profile name is required.';
        } else {
            try {
                $pdo->beginTransaction();
                $srcProfile = $pdo->prepare("SELECT * FROM checklist_profiles WHERE id = ?");
                $srcProfile->execute([$sourceId]);
                $src = $srcProfile->fetch(PDO::FETCH_ASSOC);
                if (!$src) throw new \Exception('Source profile not found.');

                $pdo->prepare("INSERT INTO checklist_profiles (name, description, is_default, is_active, created_by) VALUES (?, ?, 0, 1, ?)")
                    ->execute([$newName, $src['description'] . ' (copy)', $userEmail]);
                $newProfileId = (int)$pdo->lastInsertId();

                $cats = $pdo->prepare("SELECT * FROM checklist_categories WHERE profile_id = ?");
                $cats->execute([$sourceId]);
                while ($cat = $cats->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->prepare("INSERT INTO checklist_categories (profile_id, name, sort_order, is_active) VALUES (?, ?, ?, ?)")
                        ->execute([$newProfileId, $cat['name'], $cat['sort_order'], $cat['is_active']]);
                    $newCatId = (int)$pdo->lastInsertId();

                    $items = $pdo->prepare("SELECT * FROM checklist_items WHERE category_id = ?");
                    $items->execute([$cat['id']]);
                    while ($item = $items->fetch(PDO::FETCH_ASSOC)) {
                        $pdo->prepare("INSERT INTO checklist_items (category_id, label, sort_order, is_active, is_safety_critical, applies_to) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$newCatId, $item['label'], $item['sort_order'], $item['is_active'], $item['is_safety_critical'], $item['applies_to']]);
                    }
                }
                $pdo->commit();
                $success = "Profile duplicated as '{$newName}'.";
                activity_log_event('checklist_profile_duplicated', "Duplicated checklist profile from ID {$sourceId} to '{$newName}'");
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $error = 'Duplication failed: ' . $e->getMessage();
            }
        }

    } elseif ($action === 'add_category') {
        $profileId = (int)$_POST['profile_id'];
        $catName = trim($_POST['category_name'] ?? '');
        if (empty($catName)) { $error = 'Category name is required.'; }
        else {
            $maxOrder = $pdo->prepare("SELECT MAX(sort_order) FROM checklist_categories WHERE profile_id = ?");
            $maxOrder->execute([$profileId]);
            $next = ((int)$maxOrder->fetchColumn()) + 1;
            $pdo->prepare("INSERT INTO checklist_categories (profile_id, name, sort_order) VALUES (?, ?, ?)")->execute([$profileId, $catName, $next]);
            $success = "Category '{$catName}' added.";
            $editProfileId = $profileId;
            $tab = 'edit';
        }

    } elseif ($action === 'delete_category') {
        $catId = (int)$_POST['category_id'];
        $pdo->prepare("DELETE FROM checklist_categories WHERE id = ?")->execute([$catId]);
        $success = 'Category deleted.';
        $editProfileId = (int)$_POST['profile_id'];
        $tab = 'edit';

    } elseif ($action === 'add_item') {
        $catId = (int)$_POST['category_id'];
        $label = trim($_POST['item_label'] ?? '');
        $safety = !empty($_POST['is_safety_critical']) ? 1 : 0;
        $appliesTo = $_POST['applies_to'] ?? 'both';
        if (empty($label)) { $error = 'Item label is required.'; }
        else {
            $maxOrder = $pdo->prepare("SELECT MAX(sort_order) FROM checklist_items WHERE category_id = ?");
            $maxOrder->execute([$catId]);
            $next = ((int)$maxOrder->fetchColumn()) + 1;
            $pdo->prepare("INSERT INTO checklist_items (category_id, label, sort_order, is_safety_critical, applies_to) VALUES (?, ?, ?, ?, ?)")
                ->execute([$catId, $label, $next, $safety, $appliesTo]);
            $success = 'Item added.';
            $editProfileId = (int)$_POST['profile_id'];
            $tab = 'edit';
        }

    } elseif ($action === 'update_item') {
        $itemId = (int)$_POST['item_id'];
        $label = trim($_POST['item_label'] ?? '');
        $safety = !empty($_POST['is_safety_critical']) ? 1 : 0;
        $appliesTo = $_POST['applies_to'] ?? 'both';
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        if (!empty($label)) {
            $pdo->prepare("UPDATE checklist_items SET label = ?, is_safety_critical = ?, applies_to = ?, is_active = ? WHERE id = ?")
                ->execute([$label, $safety, $appliesTo, $isActive, $itemId]);
            $success = 'Item updated.';
        }
        $editProfileId = (int)$_POST['profile_id'];
        $tab = 'edit';

    } elseif ($action === 'delete_item') {
        $itemId = (int)$_POST['item_id'];
        $pdo->prepare("DELETE FROM checklist_items WHERE id = ?")->execute([$itemId]);
        $success = 'Item deleted.';
        $editProfileId = (int)$_POST['profile_id'];
        $tab = 'edit';

    } elseif ($action === 'move_category') {
        $catId = (int)$_POST['category_id'];
        $direction = $_POST['direction'] ?? 'up';
        $profileId = (int)$_POST['profile_id'];
        $cats = $pdo->prepare("SELECT id, sort_order FROM checklist_categories WHERE profile_id = ? ORDER BY sort_order ASC");
        $cats->execute([$profileId]);
        $allCats = $cats->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allCats as $i => $c) {
            if ((int)$c['id'] === $catId) {
                $swapIdx = $direction === 'up' ? $i - 1 : $i + 1;
                if (isset($allCats[$swapIdx])) {
                    $pdo->prepare("UPDATE checklist_categories SET sort_order = ? WHERE id = ?")->execute([$allCats[$swapIdx]['sort_order'], $catId]);
                    $pdo->prepare("UPDATE checklist_categories SET sort_order = ? WHERE id = ?")->execute([$c['sort_order'], $allCats[$swapIdx]['id']]);
                }
                break;
            }
        }
        $editProfileId = $profileId;
        $tab = 'edit';

    } elseif ($action === 'save_assignments') {
        // Bulk assignment update
        $assignments = $_POST['assignments'] ?? [];
        $pdo->exec("DELETE FROM checklist_profile_assignments WHERE snipeit_model_id IS NOT NULL");
        foreach ($assignments as $modelId => $profileId) {
            $profileId = (int)$profileId;
            $modelId = (int)$modelId;
            if ($profileId > 0) {
                $pdo->prepare("INSERT INTO checklist_profile_assignments (profile_id, snipeit_model_id) VALUES (?, ?)")
                    ->execute([$profileId, $modelId]);
            }
        }
        $success = 'Assignments saved.';
        activity_log_event('checklist_assignments_updated', 'Updated checklist profile assignments');
        $tab = 'assignments';
    }
}

// ── Load data ──
$profiles = checklist_tables_exist($pdo) ? get_all_checklist_profiles($pdo) : [];
$editProfile = null;
if ($editProfileId > 0 && $tab === 'edit') {
    $editProfile = load_checklist_profile($pdo, $editProfileId);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checklist Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.5.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1><i class="bi bi-list-check me-2"></i>Checklist Management</h1>
            <p class="text-muted">Manage inspection checklist profiles, categories, items, and vehicle assignments</p>
        </div>
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= h($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link <?= $tab === 'profiles' ? 'active' : '' ?>" href="checklist_admin?tab=profiles">Profiles</a></li>
            <?php if ($editProfileId > 0): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === 'edit' ? 'active' : '' ?>" href="checklist_admin?tab=edit&profile_id=<?= $editProfileId ?>">Edit Profile</a></li>
            <?php endif; ?>
            <li class="nav-item"><a class="nav-link <?= $tab === 'assignments' ? 'active' : '' ?>" href="checklist_admin?tab=assignments">Assignments</a></li>
            <li class="nav-item"><a class="nav-link <?= $tab === 'analytics' ? 'active' : '' ?>" href="checklist_admin?tab=analytics">Analytics</a></li>
        </ul>

<?php if ($tab === 'profiles'): ?>
        <!-- ═══ PROFILES TAB ═══ -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-collection me-2"></i>Checklist Profiles</h5>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createProfileModal"><i class="bi bi-plus-circle me-1"></i>Create Profile</button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($profiles)): ?>
                    <p class="text-muted text-center py-4">No profiles found. Create one to get started.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th class="text-center">Categories</th>
                                    <th class="text-center">Items</th>
                                    <th class="text-center">Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($profiles as $p): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($p['name']) ?></strong>
                                        <?php if ($p['is_default']): ?><span class="badge bg-primary ms-1">Default</span><?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= h(mb_strimwidth($p['description'] ?? '', 0, 80, '...')) ?></td>
                                    <td class="text-center"><?= (int)$p['category_count'] ?></td>
                                    <td class="text-center"><?= (int)$p['item_count'] ?></td>
                                    <td class="text-center">
                                        <?php if ($p['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="checklist_admin?tab=edit&profile_id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <?php if (!$p['is_default']): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="set_default">
                                            <input type="hidden" name="profile_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Set as Default"><i class="bi bi-star"></i></button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="post" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_profile_active">
                                            <input type="hidden" name="profile_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-<?= $p['is_active'] ? 'warning' : 'success' ?>" title="<?= $p['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                <i class="bi bi-<?= $p['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i>
                                            </button>
                                        </form>
                                        <button class="btn btn-sm btn-outline-info" title="Duplicate" data-bs-toggle="modal" data-bs-target="#duplicateModal" onclick="document.getElementById('dupSourceId').value=<?= $p['id'] ?>;document.getElementById('dupNewName').value='<?= h($p['name']) ?> (Copy)';">
                                            <i class="bi bi-copy"></i>
                                        </button>
                                        <?php if (!$p['is_default']): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this profile? All categories and items will be removed.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_profile">
                                            <input type="hidden" name="profile_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            <strong>Safety-critical items</strong> show a warning to drivers during checkout. The driver can acknowledge and proceed but staff will be notified.
        </div>

        <!-- Create Profile Modal -->
        <div class="modal fade" id="createProfileModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_profile">
                        <div class="modal-header"><h5 class="modal-title">Create Checklist Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-3"><label class="form-label">Profile Name <span class="text-danger">*</span></label><input type="text" name="profile_name" class="form-control" required></div>
                            <div class="mb-3"><label class="form-label">Description</label><textarea name="profile_description" class="form-control" rows="2"></textarea></div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Create</button></div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Duplicate Modal -->
        <div class="modal fade" id="duplicateModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="duplicate_profile">
                        <input type="hidden" name="profile_id" id="dupSourceId">
                        <div class="modal-header"><h5 class="modal-title">Duplicate Profile</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="mb-3"><label class="form-label">New Profile Name <span class="text-danger">*</span></label><input type="text" name="new_name" id="dupNewName" class="form-control" required></div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-info">Duplicate</button></div>
                    </form>
                </div>
            </div>
        </div>

<?php elseif ($tab === 'edit' && $editProfile): ?>
        <!-- ═══ EDIT PROFILE TAB ═══ -->
        <div class="mb-3">
            <h4><?= h($editProfile['profile_name']) ?></h4>
            <a href="checklist_admin?tab=profiles" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Profiles</a>
        </div>

        <?php foreach ($editProfile['categories'] as $catIdx => $cat): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="<?= CHECKLIST_CATEGORY_ICONS[$cat['name']] ?? 'bi-check-circle' ?> me-2"></i><?= h($cat['name']) ?> <span class="badge bg-secondary"><?= count($cat['items']) ?> items</span></h6>
                <div>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="move_category">
                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                        <input type="hidden" name="profile_id" value="<?= $editProfileId ?>">
                        <input type="hidden" name="direction" value="up">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Move Up" <?= $catIdx === 0 ? 'disabled' : '' ?>><i class="bi bi-arrow-up"></i></button>
                    </form>
                    <form method="post" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="move_category">
                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                        <input type="hidden" name="profile_id" value="<?= $editProfileId ?>">
                        <input type="hidden" name="direction" value="down">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Move Down" <?= $catIdx === count($editProfile['categories']) - 1 ? 'disabled' : '' ?>><i class="bi bi-arrow-down"></i></button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this category and all its items?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_category">
                        <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                        <input type="hidden" name="profile_id" value="<?= $editProfileId ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Category"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Label</th>
                            <th class="text-center" style="width:100px;">Safety</th>
                            <th class="text-center" style="width:120px;">Applies To</th>
                            <th class="text-center" style="width:80px;">Active</th>
                            <th style="width:100px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cat['items'] as $item): ?>
                        <tr>
                            <td><?= h($item['label']) ?><?php if ($item['is_safety_critical']): ?> <span class="text-danger" title="Safety Critical"><i class="bi bi-shield-fill-exclamation"></i></span><?php endif; ?></td>
                            <td class="text-center"><?= $item['is_safety_critical'] ? '<span class="badge bg-danger">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
                            <td class="text-center"><span class="badge bg-<?= $item['applies_to'] === 'both' ? 'primary' : ($item['applies_to'] === 'checkout' ? 'success' : 'info') ?>"><?= ucfirst($item['applies_to']) ?></span></td>
                            <td class="text-center"><?= !isset($item['is_active']) || $item['is_active'] !== false ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editItemModal_<?= $item['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this item?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_item">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <input type="hidden" name="profile_id" value="<?= $editProfileId ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <!-- Edit Item Modal -->
                        <div class="modal fade" id="editItemModal_<?= $item['id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_item">
                                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                        <input type="hidden" name="profile_id" value="<?= $editProfileId ?>">
                                        <div class="modal-header"><h5 class="modal-title">Edit Item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body">
                                            <div class="mb-3"><label class="form-label">Label</label><input type="text" name="item_label" class="form-control" value="<?= h($item['label']) ?>" required></div>
                                            <div class="mb-3 form-check form-switch">
                                                <input type="checkbox" class="form-check-input" name="is_safety_critical" value="1" id="safety_<?= $item['id'] ?>" <?= $item['is_safety_critical'] ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="safety_<?= $item['id'] ?>"><i class="bi bi-shield-fill-exclamation text-danger me-1"></i>Safety Critical</label>
                                            </div>
                                            <div class="mb-3"><label class="form-label">Applies To</label>
                                                <select name="applies_to" class="form-select">
                                                    <option value="both" <?= $item['applies_to'] === 'both' ? 'selected' : '' ?>>Both</option>
                                                    <option value="checkout" <?= $item['applies_to'] === 'checkout' ? 'selected' : '' ?>>Checkout Only</option>
                                                    <option value="checkin" <?= $item['applies_to'] === 'checkin' ? 'selected' : '' ?>>Checkin Only</option>
                                                </select>
                                            </div>
                                            <div class="mb-3 form-check form-switch">
                                                <input type="checkbox" class="form-check-input" name="is_active" value="1" id="active_<?= $item['id'] ?>" checked>
                                                <label class="form-check-label" for="active_<?= $item['id'] ?>">Active</label>
                                            </div>
                                        </div>
                                        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <!-- Add Item Row -->
                        <tr class="table-light">
                            <td colspan="5">
                                <form method="post" class="row g-2 align-items-end">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add_item">
                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    <input type="hidden" name="profile_id" value="<?= $editProfileId ?>">
                                    <div class="col-md-5"><input type="text" name="item_label" class="form-control form-control-sm" placeholder="New item label..." required></div>
                                    <div class="col-md-2">
                                        <select name="applies_to" class="form-select form-select-sm">
                                            <option value="both">Both</option>
                                            <option value="checkout">Checkout</option>
                                            <option value="checkin">Checkin</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="form-check form-check-inline">
                                            <input type="checkbox" class="form-check-input" name="is_safety_critical" value="1" id="newSafety_<?= $cat['id'] ?>">
                                            <label class="form-check-label small" for="newSafety_<?= $cat['id'] ?>"><i class="bi bi-shield-fill-exclamation text-danger"></i> Safety</label>
                                        </div>
                                    </div>
                                    <div class="col-md-3"><button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-plus me-1"></i>Add Item</button></div>
                                </form>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Add Category -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_category">
                    <input type="hidden" name="profile_id" value="<?= $editProfileId ?>">
                    <div class="col-md-8"><label class="form-label">New Category</label><input type="text" name="category_name" class="form-control" placeholder="Category name..." required></div>
                    <div class="col-md-4"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-circle me-1"></i>Add Category</button></div>
                </form>
            </div>
        </div>

<?php elseif ($tab === 'edit' && !$editProfile): ?>
        <div class="alert alert-warning">Profile not found. <a href="checklist_admin?tab=profiles">Back to Profiles</a></div>

<?php elseif ($tab === 'assignments'): ?>
        <!-- ═══ ASSIGNMENTS TAB ═══ -->
        <?php
        $models = get_models(200);
        $existingAssignments = [];
        if (checklist_tables_exist($pdo)) {
            $aStmt = $pdo->query("SELECT snipeit_model_id, profile_id FROM checklist_profile_assignments WHERE snipeit_model_id IS NOT NULL");
            while ($a = $aStmt->fetch(PDO::FETCH_ASSOC)) {
                $existingAssignments[(int)$a['snipeit_model_id']] = (int)$a['profile_id'];
            }
        }
        ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Vehicle Model Assignments</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">Assign a checklist profile to each vehicle model. Models without an assignment use the default profile.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_assignments">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Model</th>
                                    <th>Manufacturer</th>
                                    <th>Checklist Profile</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($models as $model): ?>
                                <tr>
                                    <td><strong><?= h($model['name'] ?? 'Unknown') ?></strong></td>
                                    <td><?= h($model['manufacturer']['name'] ?? '-') ?></td>
                                    <td>
                                        <select name="assignments[<?= $model['id'] ?>]" class="form-select form-select-sm">
                                            <option value="0">-- Use Default --</option>
                                            <?php foreach ($profiles as $p): ?>
                                                <?php if ($p['is_active']): ?>
                                                <option value="<?= $p['id'] ?>" <?= ($existingAssignments[(int)$model['id']] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?><?= $p['is_default'] ? ' (Default)' : '' ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($models)): ?>
                                <tr><td colspan="3" class="text-muted text-center py-4">No vehicle models found in Snipe-IT.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (!empty($models)): ?>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Assignments</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

<?php elseif ($tab === 'analytics'): ?>
        <!-- ═══ ANALYTICS TAB ═══ -->
        <?php
        // Top Failed Items (Last 30 Days)
        $topFailed = [];
        $safetyCounts = ['30' => 0, '90' => 0, '365' => 0];
        $profileUsage = [];
        try {
            $inspStmt = $pdo->prepare("SELECT response_data FROM inspection_responses WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $inspStmt->execute();
            $failCounts = [];
            while ($row = $inspStmt->fetch(PDO::FETCH_ASSOC)) {
                $data = json_decode($row['response_data'] ?? '{}', true) ?: [];
                foreach ($data as $key => $val) {
                    if (strpos($key, 'insp_') === 0 && $val === 'no') {
                        $failCounts[$key] = ($failCounts[$key] ?? 0) + 1;
                    }
                }
            }
            arsort($failCounts);
            $topFailed = array_slice($failCounts, 0, 15, true);

            // Safety-critical failures
            foreach (['30', '90', '365'] as $days) {
                $scStmt = $pdo->prepare("SELECT response_data FROM inspection_responses WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)");
                $scStmt->execute();
                while ($row = $scStmt->fetch(PDO::FETCH_ASSOC)) {
                    $data = json_decode($row['response_data'] ?? '{}', true) ?: [];
                    if (!empty($data['_safety_critical_failures'])) {
                        $safetyCounts[$days]++;
                    }
                }
            }

            // Inspections by Profile
            $profStmt = $pdo->query("SELECT response_data FROM inspection_responses WHERE created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
            while ($row = $profStmt->fetch(PDO::FETCH_ASSOC)) {
                $data = json_decode($row['response_data'] ?? '{}', true) ?: [];
                $pName = $data['_profile_name'] ?? 'Legacy (hardcoded)';
                $profileUsage[$pName] = ($profileUsage[$pName] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            // Tables may not have data yet
        }
        ?>

        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header bg-dark text-white"><h6 class="mb-0"><i class="bi bi-exclamation-circle me-2"></i>Top Failed Items (Last 30 Days)</h6></div>
                    <div class="card-body p-0">
                        <?php if (empty($topFailed)): ?>
                            <p class="text-muted text-center py-4">No failures recorded in the last 30 days.</p>
                        <?php else: ?>
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Item Key</th><th class="text-end">Failures</th></tr></thead>
                                <tbody>
                                    <?php foreach ($topFailed as $key => $count): ?>
                                    <tr><td><code><?= h($key) ?></code></td><td class="text-end"><span class="badge bg-danger"><?= $count ?></span></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card mb-4">
                    <div class="card-header bg-danger text-white"><h6 class="mb-0"><i class="bi bi-shield-fill-exclamation me-2"></i>Safety-Critical Failures</h6></div>
                    <div class="card-body">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Period</th><th class="text-end">Inspections with Failures</th></tr></thead>
                            <tbody>
                                <tr><td>Last 30 days</td><td class="text-end"><span class="badge bg-<?= $safetyCounts['30'] > 0 ? 'danger' : 'success' ?>"><?= $safetyCounts['30'] ?></span></td></tr>
                                <tr><td>Last 90 days</td><td class="text-end"><span class="badge bg-<?= $safetyCounts['90'] > 0 ? 'danger' : 'success' ?>"><?= $safetyCounts['90'] ?></span></td></tr>
                                <tr><td>Last 365 days</td><td class="text-end"><span class="badge bg-<?= $safetyCounts['365'] > 0 ? 'danger' : 'success' ?>"><?= $safetyCounts['365'] ?></span></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Inspections by Profile (Last 90 Days)</h6></div>
                    <div class="card-body p-0">
                        <?php if (empty($profileUsage)): ?>
                            <p class="text-muted text-center py-4">No inspection data.</p>
                        <?php else: ?>
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Profile</th><th class="text-end">Count</th></tr></thead>
                                <tbody>
                                    <?php arsort($profileUsage); foreach ($profileUsage as $pName => $cnt): ?>
                                    <tr><td><?= h($pName) ?></td><td class="text-end"><?= $cnt ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

<?php endif; ?>

    </div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php layout_footer(); ?>
</body>
</html>

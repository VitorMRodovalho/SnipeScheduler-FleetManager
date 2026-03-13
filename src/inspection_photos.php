<?php
/**
 * BL-007: Inspection Photo Upload
 * Handles photo capture, storage, retrieval, and display for vehicle inspections.
 *
 * @since v2.1.0
 */

/**
 * Check if photo upload is enabled in system settings.
 */
function is_photo_upload_enabled($pdo): bool
{
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'photo_upload_enabled' LIMIT 1");
    $stmt->execute();
    $val = $stmt->fetchColumn();
    return $val !== '0'; // default enabled
}

/**
 * Get the base uploads directory for inspections.
 */
function get_inspection_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/inspections';
}

/**
 * Save uploaded inspection photos.
 *
 * @param PDO    $pdo
 * @param int    $reservationId
 * @param string $type          'checkout' or 'checkin'
 * @param string $uploaderEmail
 * @param array  $files         $_FILES['inspection_photos'] array
 * @return array ['saved' => int, 'errors' => string[]]
 */
function save_inspection_photos(PDO $pdo, int $reservationId, string $type, string $uploaderEmail, array $files): array
{
    $result = ['saved' => 0, 'errors' => []];
    $allowedMimes = ['image/jpeg', 'image/png', 'image/heic', 'image/heif'];
    $maxFileSize = 10 * 1024 * 1024; // 10MB
    $maxFiles = 5;

    // Normalize $_FILES structure
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;
    if (!is_array($files['name'])) {
        // Single file upload — normalize to array structure
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
            $files[$k] = [$files[$k]];
        }
        $fileCount = 1;
    }

    if ($fileCount > $maxFiles) {
        $result['errors'][] = "Maximum {$maxFiles} photos allowed. Only the first {$maxFiles} will be processed.";
        $fileCount = $maxFiles;
    }

    $uploadDir = get_inspection_upload_dir() . '/' . $reservationId . '/' . $type;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0750, true);
    }

    for ($i = 0; $i < $fileCount; $i++) {
        $tmpName = $files['tmp_name'][$i] ?? '';
        $error   = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        $size    = $files['size'][$i] ?? 0;
        $origName= $files['name'][$i] ?? '';

        if ($error === UPLOAD_ERR_NO_FILE || empty($tmpName)) {
            continue; // Skip empty slots
        }

        if ($error !== UPLOAD_ERR_OK) {
            $result['errors'][] = "Upload error for '{$origName}': code {$error}";
            continue;
        }

        if ($size > $maxFileSize) {
            $result['errors'][] = "'{$origName}' exceeds 10MB limit.";
            continue;
        }

        // Validate MIME via finfo (not trusting browser-reported type)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);
        if (!in_array($mimeType, $allowedMimes, true)) {
            $result['errors'][] = "'{$origName}' has unsupported type ({$mimeType}). Only JPEG, PNG, and HEIC are allowed.";
            continue;
        }

        // Generate unique filename
        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/heic', 'image/heif' => 'heic',
            default => 'jpg',
        };
        $filename = time() . '_' . md5($origName . $i . mt_rand()) . '.' . $ext;
        $destPath = $uploadDir . '/' . $filename;

        // Move and resize
        if (!move_uploaded_file($tmpName, $destPath)) {
            $result['errors'][] = "Failed to save '{$origName}'.";
            continue;
        }

        // Resize if larger than 1920px width (JPEG/PNG only)
        if (in_array($mimeType, ['image/jpeg', 'image/png']) && function_exists('imagecreatefromjpeg')) {
            resize_inspection_photo($destPath, $mimeType, 1920);
        }

        // Get final file size after resize
        $finalSize = filesize($destPath);

        // Insert DB record
        $stmt = $pdo->prepare("
            INSERT INTO inspection_photos
                (reservation_id, inspection_type, filename, original_name, file_size, mime_type, uploaded_by_email)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$reservationId, $type, $filename, $origName, $finalSize, $mimeType, $uploaderEmail]);
        $result['saved']++;
    }

    return $result;
}

/**
 * Resize image to max width using GD.
 */
function resize_inspection_photo(string $path, string $mimeType, int $maxWidth): void
{
    $info = @getimagesize($path);
    if (!$info || $info[0] <= $maxWidth) return;

    $srcW = $info[0];
    $srcH = $info[1];
    $ratio = $maxWidth / $srcW;
    $newW = $maxWidth;
    $newH = (int)round($srcH * $ratio);

    $src = ($mimeType === 'image/png') ? imagecreatefrompng($path) : imagecreatefromjpeg($path);
    if (!$src) return;

    $dst = imagecreatetruecolor($newW, $newH);
    if ($mimeType === 'image/png') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

    if ($mimeType === 'image/png') {
        imagepng($dst, $path, 8);
    } else {
        imagejpeg($dst, $path, 85);
    }

    imagedestroy($src);
    imagedestroy($dst);
}

/**
 * Get inspection photos for a reservation.
 */
function get_inspection_photos(PDO $pdo, int $reservationId, ?string $type = null): array
{
    if ($type) {
        $stmt = $pdo->prepare("SELECT * FROM inspection_photos WHERE reservation_id = ? AND inspection_type = ? ORDER BY created_at ASC");
        $stmt->execute([$reservationId, $type]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM inspection_photos WHERE reservation_id = ? ORDER BY inspection_type, created_at ASC");
        $stmt->execute([$reservationId]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Render the photo upload UI.
 */
function render_photo_upload(string $type, bool $enabled = true): string
{
    if (!$enabled) return '';

    $inputId = 'inspection_photos_' . $type;

    $html = '<div class="card mb-4">';
    $html .= '<div class="card-header bg-light">';
    $html .= '<h6 class="mb-0"><i class="bi bi-camera me-2"></i>Photos (Optional)</h6>';
    $html .= '</div>';
    $html .= '<div class="card-body">';
    $html .= '<p class="text-muted small mb-3">Attach photos of the vehicle condition. Tap to capture with your camera or drag-and-drop from your device.</p>';

    // Camera capture button (primary, large, mobile-first)
    $html .= '<div class="mb-3">';
    $html .= '<label for="' . $inputId . '" class="btn btn-primary photo-capture-btn w-100 w-md-auto">';
    $html .= '<i class="bi bi-camera-fill me-2"></i>Take Photo or Choose File';
    $html .= '</label>';
    $html .= '<input type="file" id="' . $inputId . '" name="inspection_photos[]" multiple ';
    $html .= 'accept="image/jpeg,image/png,image/heic" capture="environment" class="d-none" data-max-files="5">';
    $html .= '</div>';

    // Drag-and-drop zone
    $html .= '<div class="photo-dropzone" id="dropzone_' . $type . '">';
    $html .= '<i class="bi bi-cloud-arrow-up fs-3 text-muted"></i>';
    $html .= '<p class="text-muted small mb-0">or drag &amp; drop photos here</p>';
    $html .= '<p class="text-muted small mb-0">JPEG, PNG, HEIC — max 10MB each, up to 5 photos</p>';
    $html .= '</div>';

    // Thumbnail preview strip
    $html .= '<div class="photo-thumbnail-strip mt-3" id="previews_' . $type . '"></div>';

    $html .= '</div></div>';
    return $html;
}

/**
 * Render JavaScript for photo upload interactivity.
 */
function render_photo_upload_js(): string
{
    return <<<'JS'
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="inspection_photos[]"]').forEach(function(input) {
        const type = input.id.replace('inspection_photos_', '');
        const previewStrip = document.getElementById('previews_' + type);
        const dropzone = document.getElementById('dropzone_' + type);
        const maxFiles = parseInt(input.dataset.maxFiles) || 5;
        let filesList = new DataTransfer();

        function updateInput() {
            input.files = filesList.files;
        }

        function addFiles(newFiles) {
            for (let i = 0; i < newFiles.length; i++) {
                if (filesList.files.length >= maxFiles) {
                    alert('Maximum ' + maxFiles + ' photos allowed.');
                    break;
                }
                const f = newFiles[i];
                if (!f.type.match(/image\/(jpeg|png|heic|heif)/)) {
                    alert(f.name + ': unsupported file type.');
                    continue;
                }
                if (f.size > 10 * 1024 * 1024) {
                    alert(f.name + ': file too large (max 10MB).');
                    continue;
                }
                filesList.items.add(f);
                addPreview(f, filesList.files.length - 1);
            }
            updateInput();
        }

        function addPreview(file, index) {
            const wrap = document.createElement('div');
            wrap.className = 'photo-thumbnail';
            wrap.dataset.index = index;

            const reader = new FileReader();
            reader.onload = function(e) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.alt = file.name;
                wrap.appendChild(img);
            };
            reader.readAsDataURL(file);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'photo-remove-btn';
            removeBtn.innerHTML = '&times;';
            removeBtn.addEventListener('click', function() {
                removeFile(wrap);
            });
            wrap.appendChild(removeBtn);

            const nameLabel = document.createElement('small');
            nameLabel.className = 'photo-name text-truncate d-block';
            nameLabel.textContent = file.name;
            wrap.appendChild(nameLabel);

            previewStrip.appendChild(wrap);
        }

        function removeFile(thumbEl) {
            // Rebuild DataTransfer without this index
            const oldFiles = filesList.files;
            const newDt = new DataTransfer();
            const removeIdx = parseInt(thumbEl.dataset.index);
            for (let i = 0; i < oldFiles.length; i++) {
                if (i !== removeIdx) newDt.items.add(oldFiles[i]);
            }
            filesList = newDt;
            updateInput();
            // Rebuild previews
            previewStrip.innerHTML = '';
            for (let i = 0; i < filesList.files.length; i++) {
                addPreview(filesList.files[i], i);
            }
        }

        // File input change
        input.addEventListener('change', function() {
            addFiles(this.files);
            // Reset input so same file can be re-selected
            this.value = '';
        });

        // Drag-and-drop
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(function(evt) {
                dropzone.addEventListener(evt, function(e) {
                    e.preventDefault();
                    dropzone.classList.add('photo-dropzone-active');
                });
            });
            ['dragleave', 'drop'].forEach(function(evt) {
                dropzone.addEventListener(evt, function(e) {
                    e.preventDefault();
                    dropzone.classList.remove('photo-dropzone-active');
                });
            });
            dropzone.addEventListener('drop', function(e) {
                addFiles(e.dataTransfer.files);
            });
            // Click dropzone to open file picker
            dropzone.addEventListener('click', function() {
                input.click();
            });
        }
    });
});
</script>
JS;
}

/**
 * Render a photo gallery for already-saved photos.
 */
function render_photo_gallery(array $photos, int $reservationId): string
{
    if (empty($photos)) return '';

    $html = '<div class="photo-gallery">';
    foreach ($photos as $p) {
        $url = 'api/serve_photo?reservation_id=' . $reservationId
             . '&type=' . urlencode($p['inspection_type'])
             . '&file=' . urlencode($p['filename']);
        $html .= '<div class="photo-gallery-item">';
        $html .= '<a href="' . h($url) . '" target="_blank" title="' . h($p['original_name']) . '">';
        $html .= '<img src="' . h($url) . '" alt="' . h($p['original_name']) . '" loading="lazy">';
        $html .= '</a>';
        $html .= '<small class="d-block text-muted text-truncate">' . h($p['original_name']) . '</small>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Serve an inspection photo file securely.
 */
function serve_inspection_photo(PDO $pdo, int $reservationId, string $type, string $filename): void
{
    // Prevent directory traversal
    $filename = basename($filename);
    $type = in_array($type, ['checkout', 'checkin'], true) ? $type : 'checkout';

    // Verify photo exists in DB
    $stmt = $pdo->prepare("SELECT * FROM inspection_photos WHERE reservation_id = ? AND inspection_type = ? AND filename = ? LIMIT 1");
    $stmt->execute([$reservationId, $type, $filename]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$photo) {
        http_response_code(404);
        echo 'Photo not found.';
        return;
    }

    $filePath = get_inspection_upload_dir() . '/' . $reservationId . '/' . $type . '/' . $filename;
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'File not found on disk.';
        return;
    }

    header('Content-Type: ' . $photo['mime_type']);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: private, max-age=86400');
    header('Content-Disposition: inline; filename="' . $photo['original_name'] . '"');
    readfile($filePath);
    exit;
}

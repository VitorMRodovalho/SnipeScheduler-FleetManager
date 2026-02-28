<?php
// src/activity_log.php
// Lightweight activity log writer for auditing key actions.

require_once __DIR__ . '/db.php';

if (!function_exists('activity_log_event')) {
    function activity_log_event(string $eventType, string $message, array $context = []): void
    {
        global $pdo;

        if (!isset($pdo)) {
            return;
        }

        $actor = $context['actor'] ?? ($_SESSION['user'] ?? []);

        $actorId = '';
        if (is_array($actor)) {
            $actorId = (string)($actor['id'] ?? ($actor['user_id'] ?? ''));
        }

        $actorName = '';
        if (is_array($actor)) {
            $actorName = trim(($actor['first_name'] ?? '') . ' ' . ($actor['last_name'] ?? ''));
            if ($actorName === '') {
                $actorName = (string)($actor['display_name'] ?? ($actor['name'] ?? ''));
            }
        }

        $actorEmail = is_array($actor) ? (string)($actor['email'] ?? '') : '';
        $subjectType = $context['subject_type'] ?? null;
        $subjectId = $context['subject_id'] ?? null;
        $metadata = $context['metadata'] ?? [];
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

        $metaJson = '';
        if (is_array($metadata) && !empty($metadata)) {
            $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) {
                $metaJson = $encoded;
            }
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_log (
                    event_type,
                    actor_user_id,
                    actor_name,
                    actor_email,
                    subject_type,
                    subject_id,
                    message,
                    metadata,
                    ip_address
                ) VALUES (
                    :event_type,
                    :actor_user_id,
                    :actor_name,
                    :actor_email,
                    :subject_type,
                    :subject_id,
                    :message,
                    :metadata,
                    :ip_address
                )
            ");
            $stmt->execute([
                ':event_type'   => $eventType,
                ':actor_user_id' => $actorId !== '' ? $actorId : null,
                ':actor_name'   => $actorName !== '' ? $actorName : null,
                ':actor_email'  => $actorEmail !== '' ? $actorEmail : null,
                ':subject_type' => $subjectType !== '' ? $subjectType : null,
                ':subject_id'   => $subjectId !== '' ? (string)$subjectId : null,
                ':message'      => $message,
                ':metadata'     => $metaJson !== '' ? $metaJson : null,
                ':ip_address'   => $ipAddress !== '' ? $ipAddress : null,
            ]);
        } catch (Throwable $e) {
            error_log('Activity log write failed: ' . $e->getMessage());
        }
    }
}

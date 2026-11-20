<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// ระบบนี้ไม่มีฟอร์ม login ของตัวเอง — ส่งไปหน้า login กลางของ SSO
$next = sso_safe_next(is_string($_GET['next'] ?? null) ? $_GET['next'] : '');
if (current_user() !== null) {
    header('Location: ' . $next);
    exit;
}
sso_login($next);

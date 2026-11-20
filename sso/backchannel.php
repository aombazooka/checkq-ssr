<?php
declare(strict_types=1);

/*
 * [SSO] Back-channel logout — SSO เรียกไฟล์นี้ตรงจาก server เมื่อผู้ใช้ logout / ถูกระงับ / ถูกเพิกถอน session
 * → ลบ session ของผู้ใช้คนนั้นในระบบนี้ทันที แม้เขาจะไม่ได้เปิดหน้าระบบนี้อยู่
 * ไฟล์นี้เหมือนกันทุกระบบ ห้ามแก้ (วิธีลบ session อยู่ที่ sso_hook_destroy_session ใน hooks.php)
 */

require __DIR__ . '/sso.php';
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['logout_token']) || !is_string($_POST['logout_token'])) {
    http_response_code(400);
    exit;
}

try {
    $claims = sso_client()->verifyLogoutToken($_POST['logout_token']);
} catch (SsoException $e) {
    error_log('[SSO] back-channel logout rejected: ' . $e->getMessage());
    http_response_code(400);
    exit;
}

$sid = isset($claims['sid']) ? (string)$claims['sid'] : '';
$stmt = sso_hook_db()->prepare('SELECT php_session_id FROM sso_sessions WHERE sid = ?');
$stmt->execute([$sid]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $phpSessionId) {
    sso_hook_destroy_session((string)$phpSessionId);
}
sso_hook_db()->prepare('DELETE FROM sso_sessions WHERE sid = ?')->execute([$sid]);

http_response_code(200);

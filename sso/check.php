<?php
declare(strict_types=1);

/*
 * [SSO] ตรวจความพร้อมของการเชื่อม SSO — เปิดได้จากเครื่อง server เท่านั้น
 *   curl http://localhost/<ระบบ>/sso/check.php
 * ผลลัพธ์ไม่มีความลับ (ไม่แสดง client_secret / รหัสฐานข้อมูล)
 */

$remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
if (PHP_SAPI !== 'cli' && !in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$ok = true;
$line = function (bool $pass, string $label, string $detail = '') use (&$ok) {
    $ok = $ok && $pass;
    echo ($pass ? '[ OK ] ' : '[FAIL] ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};

$line(PHP_VERSION_ID >= 70200, 'PHP 7.2 ขึ้นไป', PHP_VERSION);
foreach (['curl', 'openssl', 'json', 'pdo_mysql', 'session'] as $ext) {
    $line(extension_loaded($ext), 'extension ' . $ext);
}
if (!extension_loaded('curl') || !extension_loaded('openssl')) {
    echo "\nแก้ php.ini ที่ Apache ใช้ (ดูจาก phpinfo) แล้ว restart Apache ก่อนตรวจต่อ\n";
    exit;
}

require __DIR__ . '/sso.php';

$line(sso_enabled(), 'sso.enabled', sso_enabled() ? 'เปิด' : 'ปิดอยู่ (ระบบใช้ login เดิม)');
$line(strpos((string)sso_cfg('client_id'), '{{') === false && (string)sso_cfg('client_id') !== '', 'client_id', (string)sso_cfg('client_id'));
$line((string)sso_cfg('client_secret') !== '', 'client_secret', (string)sso_cfg('client_secret') !== '' ? 'ตั้งแล้ว' : 'ยังไม่ได้ใส่ใน sso/config.local.php');
$line(is_writable(__DIR__ . '/storage'), 'sso/storage เขียนได้');

try {
    $discovery = sso_client()->discovery();
    $line(true, 'ติดต่อ SSO ได้', (string)sso_cfg('issuer'));
    $jwks = sso_client()->httpGet($discovery['jwks_uri']);
    $line(!empty($jwks['keys']), 'โหลด public key ได้', count(isset($jwks['keys']) ? $jwks['keys'] : []) . ' กุญแจ');
} catch (Exception $e) {
    $line(false, 'ติดต่อ SSO ได้', $e->getMessage());
}

$s = sso_settings(true, true);
$line($s['sso_ok'], 'อ่านค่าตั้งของระบบจาก SSO ได้ (client_settings.php)', $s['sso_ok'] ? '' : 'SSO รุ่นนี้อาจยังไม่มี client_settings.php หรือ client_secret ผิด');
$modes = ['off' => 'SSO เท่านั้น', 'breakglass' => 'ทางเข้าฉุกเฉิน: ' . implode(', ', $s['users']) . ($s['when'] === 'always' ? ' (ตลอด)' : ' (เฉพาะตอน SSO ล่ม)'), 'both' => 'ทั้งสองแบบถึง ' . date('Y-m-d H:i', (int)$s['until'])];
echo '       การเข้าโดยตรงตอนนี้ — ' . $modes[$s['mode']] . "\n";

$line(time() > 1700000000 && date_default_timezone_get() !== '', 'เวลาเครื่อง', date('Y-m-d H:i:s T') . ' (ต้องต่างจาก SSO ไม่เกิน 60 วินาที)');

try {
    $db = sso_hook_db();
    $line(true, 'เชื่อมฐานข้อมูลได้');
    $db->query('SELECT sid, php_session_id, user_id FROM sso_sessions LIMIT 1');
    $line(true, 'ตาราง sso_sessions');
    $table = sso_ident((string)sso_cfg('users.table', 'users'));
    $sub = sso_ident((string)sso_cfg('users.sso_sub', 'sso_sub'));
    $db->query("SELECT $sub FROM $table LIMIT 1");
    $line(true, 'คอลัมน์ ' . sso_cfg('users.table') . '.' . sso_cfg('users.sso_sub'));
} catch (Exception $e) {
    $line(false, 'ฐานข้อมูล', $e->getMessage());
}

$line(function_exists('sso_hook_sign_in'), 'hooks.php โหลดแล้ว');
echo "\nredirect_uri ที่ต้องลงทะเบียนใน SSO:\n  " . sso_app_url('sso/callback.php')
    . "\npost-logout:\n  " . sso_app_url((string)sso_cfg('home'))
    . "\nback-channel logout:\n  " . sso_app_url('sso/backchannel.php') . "\n";
echo $ok ? "\nพร้อมใช้งาน\n" : "\nยังไม่พร้อม — แก้รายการ [FAIL] ด้านบน\n";

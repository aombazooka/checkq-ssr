<?php
declare(strict_types=1);

/*
 * [SSO] แกนกลางของการเชื่อม SSO — ไฟล์นี้เหมือนกันทุกระบบ ห้ามแก้ (แก้ที่ hooks.php และ config.php แทน)
 * รองรับ PHP 7.2 ขึ้นไป
 *
 * ฟังก์ชันที่โค้ดของระบบเรียกใช้:
 *   sso_enabled()          เปิด SSO อยู่หรือไม่
 *   sso_login($next)       ส่งไปหน้า login กลาง (ใช้ใน login.php)
 *   sso_check()            ใช้ใน require_login(): false = ต้องออกจากระบบ (ถอนสิทธิ์/ระงับ/ออกจากที่อื่น)
 *   sso_logout()           single logout (ใช้ใน logout.php)
 *   sso_require_mfa()      หน้าที่ต้องผ่าน 2FA มาแล้ว
 * การเข้าโดยตรงด้วยรหัสเดิม (ผู้ดูแล SSO เปิด/ปิดได้) อยู่ใน local.php:
 *   sso_local_login_open()  sso_local_login_check($username)  sso_local_login_done($username)  sso_login_choice_html($next)
 */

require_once __DIR__ . '/SsoClient.php';

function sso_cfg(string $key, $default = null)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

require_once __DIR__ . '/hooks.php';
require_once __DIR__ . '/local.php';

function sso_enabled(): bool
{
    return (bool)sso_cfg('enabled');
}

/** URL เต็มของหน้าในระบบนี้ */
function sso_app_url(string $path = ''): string
{
    return rtrim((string)sso_cfg('app_url'), '/') . '/' . ltrim($path, '/');
}

function sso_client(): SsoClient
{
    static $client = null;
    if ($client === null) {
        $client = new SsoClient([
            'issuer'        => sso_cfg('issuer'),
            'client_id'     => sso_cfg('client_id'),
            'client_secret' => sso_cfg('client_secret'),
            'redirect_uri'  => sso_app_url('sso/callback.php'),
            'scope'         => sso_cfg('scope'),
            'cache_dir'     => __DIR__ . '/storage',
            'ca_file'       => sso_cfg('ca_file', ''),
        ]);
    }
    return $client;
}

/** ชื่อตาราง/คอลัมน์จาก config (กันพิมพ์ผิดจนกลายเป็น SQL injection) */
function sso_ident(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new RuntimeException('[SSO] ชื่อตาราง/คอลัมน์ไม่ถูกต้อง: ' . $name);
    }
    return '`' . $name . '`';
}

function sso_e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** ค่าที่ระบบนี้ใช้จาก role ของ SSO (ตัวแรกใน role_map ที่ผู้ใช้มี) — null = ไม่มีสิทธิ์ */
function sso_role_value(array $roles)
{
    foreach ((array)sso_cfg('role_map', []) as $role => $value) {
        if (in_array((string)$role, $roles, true)) {
            return $value;
        }
    }
    return null;
}

/** อนุญาตให้กลับไปเฉพาะ path ภายในระบบนี้ (กัน open redirect) */
function sso_safe_next(string $next): string
{
    $base = rtrim((string)parse_url((string)sso_cfg('app_url'), PHP_URL_PATH), '/') . '/';
    if ($next === '' || strpos($next, $base) !== 0 || strpos($next, '//') === 0 || preg_match('/[\r\n\\\\]/', $next)) {
        return sso_app_url((string)sso_cfg('home'));
    }
    return $next;
}

/** ส่งผู้ใช้ไปหน้า login ของ SSO แล้วกลับมาที่ $next */
function sso_login(string $next = ''): void
{
    sso_hook_start_session();
    try {
        sso_client()->login(sso_safe_next($next));
    } catch (SsoException $e) {
        http_response_code(503);
        exit('ติดต่อระบบ SSO ไม่ได้ กรุณาลองใหม่อีกครั้ง หรือแจ้งผู้ดูแลระบบ (' . sso_e($e->getMessage()) . ')');
    }
}

/**
 * ใช้ใน sso/callback.php: แลก code → ผูกผู้ใช้ → สร้าง session แบบที่ระบบใช้
 * @return array{0: ?string, 1: string} [ข้อความผิดพลาด หรือ null, หน้าที่จะไปต่อ]
 */
function sso_complete_login(): array
{
    try {
        $result = sso_client()->handleCallback();
    } catch (SsoException $e) {
        return [$e->getMessage(), ''];
    }
    $claims = $result['claims'];
    $tokens = $result['tokens'];
    $roles = array_values(array_map('strval', isset($claims['roles']) ? (array)$claims['roles'] : []));
    $amr = isset($claims['amr']) ? (array)$claims['amr'] : [];

    $roleValue = sso_role_value($roles);
    if ($roleValue === null) {
        return ['บัญชี SSO ของคุณยังไม่มีบทบาทที่ระบบนี้รู้จัก กรุณาติดต่อผู้ดูแลระบบ', ''];
    }
    if (sso_cfg('require_mfa') && !in_array('otp', $amr, true)) {
        return ['ระบบนี้ต้องยืนยันตัวตน 2 ชั้น กรุณาเปิดใช้ 2FA ที่หน้า "บัญชีของฉัน" ของ SSO แล้วออกจากระบบและเข้าใหม่', ''];
    }

    $user = sso_hook_find_user($claims);
    if ($user === null) {
        $user = sso_hook_create_user($claims, $roles, $roleValue);
    }
    if ($user === null) {
        return ['ยังไม่มีบัญชีของคุณในระบบนี้ กรุณาติดต่อผู้ดูแลระบบ', ''];
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    sso_hook_sign_in($user, $claims, $roles, $roleValue);
    unset($_SESSION['sso_local']);
    $_SESSION['sso'] = [
        'sub'           => (string)$claims['sub'],
        'sid'           => isset($claims['sid']) ? (string)$claims['sid'] : '',
        'id_token'      => (string)$tokens['id_token'],
        'refresh_token' => isset($tokens['refresh_token']) ? (string)$tokens['refresh_token'] : '',
        'roles'         => $roles,
        'amr'           => $amr,
        'checked_at'    => time(),
    ];

    if (!empty($claims['sid'])) {
        sso_hook_db()->prepare('REPLACE INTO sso_sessions (sid, php_session_id, user_id) VALUES (?,?,?)')
            ->execute([(string)$claims['sid'], session_id(), (string)$user[(string)sso_cfg('users.id', 'id')]]);
    }
    return [null, sso_safe_next($result['return_to'])];
}

/**
 * เรียกใน require_login() ทุกหน้า — ทุก revalidate_seconds ใช้ refresh token ถาม SSO ว่ายังใช้งานได้ไหม
 * และอัปเดตสิทธิ์ตาม role ล่าสุด
 * @return bool false = ต้องออกจากระบบ (ผู้เรียกควรเรียก sso_hook_sign_out() แล้วส่งไปหน้า login)
 */
function sso_check(): bool
{
    if (!sso_enabled()) {
        return true;
    }
    sso_settings(); // ดึงค่าตั้ง/ส่งบันทึกที่ค้างไว้ เมื่อครบรอบ (ไม่ครบรอบ = อ่านจากไฟล์ในเครื่อง)
    if (isset($_SESSION['sso_local']) && is_array($_SESSION['sso_local'])) {
        return sso_local_session_valid(); // เข้าด้วยรหัสเดิมตอนที่ผู้ดูแล SSO เปิดให้ (local.php)
    }
    $sso = isset($_SESSION['sso']) ? $_SESSION['sso'] : null;
    if (!is_array($sso)) {
        return false; // session ที่ไม่ได้มาจาก SSO (เช่นค้างจากช่วงใช้ login เดิม)
    }
    if (time() - (int)$sso['checked_at'] < (int)sso_cfg('revalidate_seconds', 300)) {
        return true;
    }
    if ($sso['refresh_token'] === '') {
        return false;
    }

    try {
        $result = sso_client()->refresh($sso['refresh_token']);
    } catch (SsoException $e) {
        error_log('[SSO] revalidate failed: ' . $e->getMessage());
        return (bool)sso_cfg('fail_open', true);
    }
    if ($result === null) {
        return false; // logout แล้ว / บัญชีถูกระงับ / ไม่มีสิทธิ์ระบบนี้แล้ว
    }

    $claims = $result['claims'];
    $roles = array_values(array_map('strval', isset($claims['roles']) ? (array)$claims['roles'] : []));
    $roleValue = sso_role_value($roles);
    if ($roleValue === null) {
        return false;
    }
    sso_hook_roles_changed($claims, $roles, $roleValue);
    $_SESSION['sso']['roles'] = $roles;
    $_SESSION['sso']['id_token'] = (string)$result['tokens']['id_token'];
    $_SESSION['sso']['refresh_token'] = isset($result['tokens']['refresh_token']) ? (string)$result['tokens']['refresh_token'] : '';
    $_SESSION['sso']['checked_at'] = time();
    return true;
}

/** Single logout: ออกจากระบบนี้ + SSO + ทุกระบบที่ใช้ session เดียวกัน (ไม่กลับมาจากฟังก์ชันนี้) */
function sso_logout(): void
{
    sso_hook_start_session();
    $idToken = isset($_SESSION['sso']['id_token']) ? (string)$_SESSION['sso']['id_token'] : null;
    $viaSso = isset($_SESSION['sso']);
    if (!empty($_SESSION['sso']['sid'])) {
        sso_hook_db()->prepare('DELETE FROM sso_sessions WHERE sid = ? AND php_session_id = ?')
            ->execute([(string)$_SESSION['sso']['sid'], session_id()]);
    }
    sso_hook_sign_out();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    $home = sso_app_url((string)sso_cfg('home'));
    if (!sso_enabled() || !$viaSso) { // เข้าด้วยรหัสเดิม = ไม่มี session ที่ SSO ให้ออก
        header('Location: ' . $home);
        exit;
    }
    try {
        header('Location: ' . sso_client()->logoutUrl($idToken, $home));
    } catch (SsoException $e) {
        header('Location: ' . $home);
    }
    exit;
}

/** หน้าที่ต้องผ่านการยืนยันตัวตน 2 ชั้นมาแล้ว */
function sso_require_mfa(): void
{
    $amr = isset($_SESSION['sso']['amr']) ? (array)$_SESSION['sso']['amr'] : [];
    if (in_array('otp', $amr, true)) {
        return;
    }
    http_response_code(403);
    $account = rtrim((string)sso_cfg('issuer'), '/') . '/account.php';
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>ต้องยืนยันตัวตน 2 ชั้น</title>'
        . '<div style="font-family:sans-serif;max-width:560px;margin:48px auto;padding:0 16px;line-height:1.6">'
        . '<h1 style="font-size:20px">หน้านี้ต้องยืนยันตัวตน 2 ชั้น</h1>'
        . '<p>เปิดใช้ 2FA ที่ <a href="' . sso_e($account) . '">บัญชีของฉัน</a> ใน SSO แล้วออกจากระบบและเข้าใหม่</p>'
        . '</div>';
    exit;
}

<?php
declare(strict_types=1);

/*
 * [SSO] จุดเชื่อมระหว่าง SSO กับระบบนี้ (ระบบใหม่: ผู้ใช้ทุกคนมาจาก SSO ไม่มีรหัสผ่านในระบบนี้)
 * ตาราง users ของระบบเป็นแค่สำเนาข้อมูลผู้ใช้ ไว้ให้ตารางอื่นอ้างอิง (created_by ฯลฯ)
 */

require_once __DIR__ . '/../lib/app.php';

function sso_hook_db(): PDO
{
    return db();
}

function sso_hook_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    session_name((string)cfg('session_name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => rtrim((string)parse_url((string)sso_cfg('app_url'), PHP_URL_PATH), '/') . '/',
        'secure'   => ($_SERVER['HTTPS'] ?? 'off') !== 'off',
        'httponly' => true,
        'samesite' => 'Lax', // Lax จำเป็น: เบราว์เซอร์ต้องส่งคุกกี้ตอนถูก redirect กลับมาจาก SSO
    ]);
    session_start();
}

function sso_hook_destroy_session(string $phpSessionId): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ini_set('session.use_cookies', '0');
    ini_set('session.use_strict_mode', '0');
    session_name((string)cfg('session_name'));
    session_id($phpSessionId);
    session_start();
    session_destroy();
}

function sso_hook_find_user(array $claims): ?array
{
    return db_one('SELECT * FROM users WHERE sso_sub = ?', [(string)$claims['sub']]);
}

/** ผู้ที่มี role ของระบบนี้ใน SSO = สร้างบัญชีให้อัตโนมัติตอนเข้าครั้งแรก */
function sso_hook_create_user(array $claims, array $roles, mixed $roleValue): ?array
{
    db_exec(
        'INSERT INTO users (sso_sub, username, full_name) VALUES (?,?,?)',
        [(string)$claims['sub'], (string)($claims['preferred_username'] ?? $claims['sub']), (string)($claims['name'] ?? '')]
    );
    return sso_hook_find_user($claims);
}

function sso_hook_sign_in(array $user, array $claims, array $roles, mixed $roleValue): void
{
    // SSO คือแหล่งข้อมูลจริง: ปรับสำเนาข้อมูลผู้ใช้ให้ตรงทุกครั้งที่เข้าระบบ
    db_exec(
        'UPDATE users SET username = ?, full_name = ?, employee_code = ?, department = ?, email = ?, last_login_at = NOW() WHERE id = ?',
        [
            (string)($claims['preferred_username'] ?? $user['username']),
            (string)($claims['name'] ?? $user['full_name']),
            ($claims['employee_code'] ?? '') ?: null,
            ($claims['department'] ?? '') ?: null,
            ($claims['email'] ?? '') ?: null,
            $user['id'],
        ]
    );
    $_SESSION['user'] = [
        'id'            => (int)$user['id'],
        'sub'           => (string)$claims['sub'],
        'username'      => (string)($claims['preferred_username'] ?? $user['username']),
        'name'          => (string)($claims['name'] ?? $user['full_name']),
        'employee_code' => $claims['employee_code'] ?? null,
        'department'    => $claims['department'] ?? null,
        'roles'         => $roles,
    ];
    $_SESSION['last_seen'] = time();
}

function sso_hook_roles_changed(array $claims, array $roles, mixed $roleValue): void
{
    $_SESSION['user']['roles'] = $roles;
    $_SESSION['user']['name'] = (string)($claims['name'] ?? $_SESSION['user']['name']);
}

function sso_hook_sign_out(): void
{
    $_SESSION = [];
}

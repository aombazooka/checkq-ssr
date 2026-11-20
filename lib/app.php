<?php
declare(strict_types=1);

/*
 * ฟังก์ชันกลางของระบบ (ไม่มีผลข้างเคียงตอน include — sso/hooks.php ใช้ไฟล์นี้ด้วย)
 */

function cfg(string $key, mixed $default = null): mixed
{
    static $config = null;
    $config ??= require __DIR__ . '/../config.php';
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= new PDO(cfg('db.dsn'), cfg('db.user'), cfg('db.pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function db_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_exec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------- CSRF ----------

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(400);
        exit('CSRF token ไม่ถูกต้อง กรุณาย้อนกลับแล้วลองใหม่');
    }
}

// ---------- ผู้ใช้และสิทธิ์ (ข้อมูลผู้ใช้และ role มาจาก SSO) ----------

/** @return array{id:int, sub:string, username:string, name:string, employee_code:?string, department:?string, roles:list<string>}|null */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * ทุกหน้าที่ต้อง login เรียกฟังก์ชันนี้
 * - ยังไม่ login → ไปหน้า login ของ SSO แล้วกลับมาหน้าเดิม
 * - ไม่ได้ใช้งานนานเกิน idle_minutes → ออกจากระบบทั้งหมด (single logout)
 * - ทุก 5 นาทีตรวจกับ SSO: ถูกถอนสิทธิ์/ระงับบัญชี/ออกจากที่อื่น → ออกจากระบบนี้ด้วย
 */
function require_login(): array
{
    $here = (string)($_SERVER['REQUEST_URI'] ?? '');
    $user = current_user();
    if ($user === null) {
        sso_login($here);
    }
    if (time() - (int)($_SESSION['last_seen'] ?? 0) > (int)cfg('idle_minutes', 30) * 60) {
        sso_logout();
    }
    if (!sso_check()) {
        sso_hook_sign_out();
        session_regenerate_id(true);
        sso_login($here);
    }
    $_SESSION['last_seen'] = time();
    return current_user();
}

/** ผู้ใช้ปัจจุบันมีสิทธิ์นี้ไหม (รวมจากทุก role ของเขาในระบบนี้) */
function can(string $permission): bool
{
    $map = (array)cfg('permissions', []);
    foreach (current_user()['roles'] ?? [] as $role) {
        foreach ((array)($map[$role] ?? []) as $granted) {
            if ($granted === '*' || $granted === $permission
                || (str_ends_with($granted, '.*') && str_starts_with($permission, substr($granted, 0, -1)))) {
                return true;
            }
        }
    }
    return false;
}

function require_permission(string $permission): array
{
    $user = require_login();
    if (!can($permission)) {
        http_response_code(403);
        page_start('ไม่มีสิทธิ์');
        echo '<div class="alert alert-warning">บทบาทของคุณในระบบนี้ไม่มีสิทธิ์เปิดหน้านี้ ถ้าจำเป็นต้องใช้ ให้หัวหน้างานยืนยันแล้วแจ้งผู้ดูแล SSO</div>';
        page_end();
        exit;
    }
    return $user;
}

function role_label(string $role): string
{
    return (string)(cfg('role_labels')[$role] ?? $role);
}

<?php
declare(strict_types=1);

/*
 * [SSO] การเข้าระบบโดยตรงด้วยรหัสเดิมของระบบ (ไม่ผ่าน SSO) — ผู้ดูแล SSO เปิด/ปิดได้ที่หน้าแก้ไขระบบ
 *   off        SSO เท่านั้น
 *   breakglass เฉพาะบัญชีที่ระบุ (ตลอดเวลา หรือเฉพาะตอนติดต่อ SSO ไม่ได้)
 *   both       ทุกคนเลือกได้ จนถึงวันสิ้นสุด
 * ระบบดึงค่าจาก SSO ทุก revalidate_seconds และเก็บค่าล่าสุดไว้ใน storage/ — SSO ล่มก็ยังใช้ค่าล่าสุดได้
 * ไฟล์นี้เหมือนกันทุกระบบ ห้ามแก้ · รองรับ PHP 7.2 ขึ้นไป
 */

const SSO_SETTINGS_RETRY_SECONDS = 30; // ติดต่อ SSO ไม่ได้ → รอเท่านี้ก่อนลองใหม่ (ไม่ให้ทุกคำขอรอ timeout)
const SSO_EVENTS_MAX = 500;

function sso_storage_read(string $name): array
{
    $file = __DIR__ . '/storage/' . $name;
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

/** อ่าน-แก้-เขียนไฟล์ใน storage/ แบบล็อก (หลายคำขอพร้อมกันไม่ทับกัน) */
function sso_storage_update(string $name, callable $change): array
{
    $fp = @fopen(__DIR__ . '/storage/' . $name, 'c+');
    if ($fp === false) {
        error_log('[SSO] เขียน sso/storage/' . $name . ' ไม่ได้');
        return [];
    }
    flock($fp, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fp), true);
    $data = $change(is_array($data) ? $data : []);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

/**
 * ค่าที่มีผลตอนนี้ — ['mode', 'when', 'users', 'until', 'sso_ok' (ติดต่อ SSO ได้ล่าสุดหรือไม่)]
 * $refresh = ถาม SSO ทันที (ยกเว้นเพิ่งติดต่อไม่ได้ภายใน 30 วินาที) · $force = ถามทันทีเสมอ (check.php)
 */
function sso_settings(bool $refresh = false, bool $force = false): array
{
    static $memo = null;
    if ($memo !== null && !$refresh && !$force) {
        return $memo;
    }
    $cache = sso_storage_read('settings.json');
    $now = time();
    $ok = !empty($cache['ok']);
    $due = $force || $ok
        ? $refresh || $now - (int)(isset($cache['ok_at']) ? $cache['ok_at'] : 0) >= (int)sso_cfg('revalidate_seconds', 300)
        : $now - (int)(isset($cache['fail_at']) ? $cache['fail_at'] : 0) >= SSO_SETTINGS_RETRY_SECONDS;

    if ($due) {
        $events = sso_storage_read('events.json');
        try {
            $result = sso_client()->clientSettings($events);
            $cache = ['local_login' => $result['local_login'], 'ok' => true, 'ok_at' => $now, 'fail_at' => 0];
            if ($events !== []) {
                $sent = count($events);
                sso_storage_update('events.json', function (array $queue) use ($sent) {
                    return array_slice($queue, $sent);
                });
            }
        } catch (SsoException $e) {
            error_log('[SSO] settings: ' . $e->getMessage());
            $cache['ok'] = false;
            $cache['fail_at'] = $now;
        }
        sso_storage_update('settings.json', function () use ($cache) {
            return $cache;
        });
    }

    $local = isset($cache['local_login']) && is_array($cache['local_login']) ? $cache['local_login'] : [];
    $mode = isset($local['mode']) ? (string)$local['mode'] : 'off'; // ไม่เคยได้ค่าจาก SSO เลย = SSO เท่านั้น
    $until = isset($local['until']) ? strtotime((string)$local['until']) : false;
    if ($mode === 'both' && ($until === false || $until <= $now)) {
        $mode = 'off'; // หมดช่วงเปลี่ยนผ่าน — ใช้นาฬิกาเครื่องนี้ตัดสินด้วย แม้ SSO จะล่มอยู่
    }
    if (!in_array($mode, ['off', 'breakglass', 'both'], true)) {
        $mode = 'off';
    }
    return $memo = [
        'mode'   => $mode,
        'when'   => isset($local['when']) && $local['when'] === 'always' ? 'always' : 'sso_down',
        'users'  => isset($local['users']) ? array_map('sso_lower', (array)$local['users']) : [],
        'until'  => $mode === 'both' ? $until : null,
        'sso_ok' => !empty($cache['ok']),
    ];
}

function sso_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
}

/** หน้า login ควรแสดงฟอร์มเดิมไหม (true) หรือส่งไป SSO ทันที (false) */
function sso_local_login_open(): bool
{
    if (!sso_enabled()) {
        return true;
    }
    $s = sso_settings();
    return $s['mode'] === 'both' || ($s['mode'] === 'breakglass' && ($s['when'] === 'always' || !$s['sso_ok']));
}

function sso_local_login_allowed(string $username): bool
{
    if (!sso_enabled()) {
        return true;
    }
    $s = sso_settings();
    if ($s['mode'] === 'both') {
        return true;
    }
    return sso_local_login_open() && in_array(sso_lower($username), $s['users'], true);
}

/**
 * เรียกในหน้า login เดิม ก่อนตรวจรหัสผ่าน
 * @return string|null ข้อความแจ้งผู้ใช้เมื่อไม่อนุญาต หรือ null = ตรวจรหัสตามปกติต่อได้
 */
function sso_local_login_check(string $username): ?string
{
    if (sso_local_login_allowed($username)) {
        return null;
    }
    sso_queue_event('local_login_denied', $username, 'ไม่อยู่ในรายการที่อนุญาต');
    $s = sso_settings();
    return $s['mode'] === 'off' || !sso_local_login_open()
        ? 'ระบบนี้ให้เข้าสู่ระบบผ่าน SSO เท่านั้น'
        : 'บัญชีนี้ต้องเข้าสู่ระบบผ่าน SSO (การเข้าด้วยรหัสเดิมเปิดเฉพาะบัญชีฉุกเฉินที่ผู้ดูแลกำหนด)';
}

/** เรียกหลังตรวจรหัสเดิมผ่านและตั้ง session เดิมแล้ว — บันทึกส่ง SSO และให้ตรวจสิทธิ์ซ้ำเป็นระยะ */
function sso_local_login_done(string $username): void
{
    if (!sso_enabled()) {
        return;
    }
    $s = sso_settings();
    unset($_SESSION['sso']);
    $_SESSION['sso_local'] = ['username' => $username, 'checked_at' => time()];
    sso_queue_event('local_login', $username, $s['mode'] === 'both' ? 'ช่วงเปลี่ยนผ่าน' : ($s['sso_ok'] ? 'ทางเข้าฉุกเฉิน' : 'ทางเข้าฉุกเฉิน (ติดต่อ SSO ไม่ได้)'));
    sso_settings(true); // ส่งบันทึกทันทีถ้า SSO ใช้งานได้
}

/** ตรวจซ้ำ session ที่เข้าโดยตรง: ผู้ดูแลปิดโหมด/เอาชื่อออก/SSO กลับมาใช้ได้ (กรณีเปิดเฉพาะตอนล่ม) → false */
function sso_local_session_valid(): bool
{
    $local = $_SESSION['sso_local'];
    if (time() - (int)$local['checked_at'] < (int)sso_cfg('revalidate_seconds', 300)) {
        return true;
    }
    if (!sso_local_login_allowed((string)$local['username'])) {
        return false;
    }
    $_SESSION['sso_local']['checked_at'] = time();
    return true;
}

function sso_queue_event(string $type, string $username, string $reason = ''): void
{
    $event = [
        'type'     => $type,
        'username' => $username,
        'ip'       => isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '',
        'at'       => time(),
        'reason'   => $reason,
    ];
    error_log('[SSO] ' . $type . ' ' . $username . ' ' . $event['ip'] . ' ' . $reason);
    sso_storage_update('events.json', function (array $queue) use ($event) {
        $queue[] = $event;
        return array_slice($queue, -SSO_EVENTS_MAX);
    });
}

/** กล่องบนหน้า login เดิม: ปุ่มเข้าด้วย SSO + คำอธิบายโหมดตอนนี้ (คืน '' ถ้าไม่ต้องแสดง) */
function sso_login_choice_html(string $next = ''): string
{
    if (!sso_enabled() || !sso_local_login_open()) {
        return '';
    }
    $s = sso_settings();
    $start = sso_app_url('sso/start.php') . '?next=' . rawurlencode(sso_safe_next($next));
    if ($s['mode'] === 'both') {
        $note = 'ช่วงเปลี่ยนผ่าน: เข้าด้วยชื่อผู้ใช้และรหัสเดิมได้ถึง ' . date('d/m/Y H:i', (int)$s['until']) . ' น. หลังจากนั้นต้องเข้าผ่าน SSO';
    } else {
        $note = 'ทางเข้าฉุกเฉิน: ฟอร์มด้านล่างใช้ได้เฉพาะบัญชีที่ผู้ดูแล SSO อนุญาต ผู้ใช้ทั่วไปกรุณาเข้าผ่าน SSO';
    }
    $down = $s['sso_ok'] ? '' : '<div style="margin-top:6px;color:#b42318">ขณะนี้ติดต่อระบบ SSO ไม่ได้</div>';
    return '<div style="border:1px solid #d0d5dd;border-radius:8px;padding:12px;margin:0 0 16px;font-size:14px;line-height:1.5">'
        . '<a href="' . sso_e($start) . '" style="display:block;text-align:center;padding:10px;border-radius:6px;background:#0b5c4a;color:#fff;text-decoration:none;font-weight:600">เข้าสู่ระบบด้วย SSO</a>'
        . '<div style="margin-top:8px;color:#475467">' . sso_e($note) . '</div>' . $down
        . '</div>';
}

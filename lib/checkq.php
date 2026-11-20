<?php
declare(strict_types=1);

/*
 * ตรรกะหลักของระบบคิวแพทย์: ช่วงเวลา, เวรแพทย์, snapshot จาก HOSxP, การคำนวณภาระ และการแนะนำแพทย์
 */

const CQ_WEEKDAYS = [1 => 'จ', 2 => 'อ', 3 => 'พ', 4 => 'พฤ', 5 => 'ศ', 6 => 'ส', 7 => 'อา'];
const CQ_STATUS = ['on' => 'ออกตรวจ', 'off' => 'ไม่ออก', 'leave' => 'ลา', 'meeting' => 'ประชุม'];

// ---------- API: ตรวจสิทธิ์แบบตอบ JSON และไม่นับการโหลดอัตโนมัติเป็นการใช้งาน ----------

function api_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_require(string $permission, bool $countsAsActivity = false): array
{
    $user = current_user();
    if ($user === null || time() - (int)($_SESSION['last_seen'] ?? 0) > (int)cfg('idle_minutes', 30) * 60) {
        api_json(['ok' => false, 'error' => 'login', 'message' => 'หมดเวลาเข้าสู่ระบบ กดเพื่อเข้าใหม่'], 401);
    }
    if (!sso_check()) {
        sso_hook_sign_out();
        api_json(['ok' => false, 'error' => 'login', 'message' => 'ถูกออกจากระบบ กดเพื่อเข้าใหม่'], 401);
    }
    if (!can($permission)) {
        api_json(['ok' => false, 'error' => 'forbidden', 'message' => 'ไม่มีสิทธิ์'], 403);
    }
    if ($countsAsActivity) {
        $_SESSION['last_seen'] = time();
    }
    return $user;
}

function api_csrf(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        api_json(['ok' => false, 'message' => 'โทเคนหมดอายุ กรุณาโหลดหน้าใหม่'], 400);
    }
}

// ---------- ข้อมูลหลัก ----------

/** ทุกช่วงที่ใช้งาน รวมช่วงพัก (ใช้แสดงในหน้าโควต้า) */
function cq_slots_all(): array
{
    static $slots = null;
    return $slots ??= db_all('SELECT * FROM cq_slot WHERE active = 1 ORDER BY sort_no, start_time');
}

/** ช่วงที่รับคนไข้ (ไม่รวมช่วงพัก) — ใช้คำนวณโควต้า/คิวทั้งหมด */
function cq_slots(): array
{
    static $slots = null;
    return $slots ??= array_values(array_filter(cq_slots_all(), static fn($s) => !(int)$s['is_break']));
}

/** ป้ายช่วงเวลา เช่น "09.00–09.59 น." */
function cq_slot_range(array $s, bool $unit = true): string
{
    return str_replace(':', '.', substr($s['start_time'], 0, 5)) . '–' . str_replace(':', '.', substr($s['end_time'], 0, 5)) . ($unit ? ' น.' : '');
}

function cq_clinics(bool $activeOnly = true): array
{
    return db_all('SELECT * FROM cq_clinic' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort_no, clinic_code');
}

function cq_clinic(string $code): ?array
{
    return db_one('SELECT * FROM cq_clinic WHERE clinic_code = ?', [$code]);
}

/** ช่วงที่เวลานี้ตกอยู่ — ก่อนช่วงแรกนับเป็นช่วงแรก, ช่วงพัก 12.00–12.59 นับเป็นช่วงถัดไป (13.00), หลังช่วงสุดท้ายนับเป็นช่วงสุดท้าย */
function cq_slot_for(string $time): ?array
{
    $slots = cq_slots();
    foreach ($slots as $s) {
        if ($time <= $s['end_time']) {
            return $s;
        }
    }
    return $slots ? $slots[array_key_last($slots)] : null;
}

function cq_thai_date(string $date): string
{
    $m = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    $t = strtotime($date);
    $days = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
    return 'วัน' . $days[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . ((int)date('Y', $t) + 543);
}

function cq_valid_date(?string $d): string
{
    return (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d)) ? $d : date('Y-m-d');
}

function cq_workdays_label(string $workdays): string
{
    if ($workdays === '12345') {
        return 'จ–ศ';
    }
    if ($workdays === '1234567') {
        return 'ทุกวัน';
    }
    $out = [];
    foreach (str_split($workdays) as $d) {
        $out[] = CQ_WEEKDAYS[(int)$d] ?? '';
    }
    return implode(' ', $out);
}

/** โควต้าของ plan: [plan_id => [slot_id => quota]] */
function cq_plan_quotas(array $planIds): array
{
    if ($planIds === []) {
        return [];
    }
    $in = implode(',', array_fill(0, count($planIds), '?'));
    $out = [];
    foreach (db_all("SELECT q.plan_id, q.slot_id, q.quota FROM cq_plan_quota q JOIN cq_slot s ON s.slot_id = q.slot_id AND s.active = 1 AND s.is_break = 0 WHERE q.plan_id IN ($in)", array_values($planIds)) as $r) {
        $out[(int)$r['plan_id']][(int)$r['slot_id']] = (int)$r['quota'];
    }
    return $out;
}

// ---------- เวรแพทย์รายวัน ----------

/** สร้างเวรของวันนั้นจากวันออกตรวจที่ตั้งไว้ (ทำซ้ำได้ ไม่ทับที่เจ้าหน้าที่แก้แล้ว) — เริ่มต้นเป็น "ไม่ออก" ตาม config duty_default */
function cq_ensure_duty(string $date): void
{
    db_exec(
        "INSERT IGNORE INTO cq_duty (work_date, plan_id, status)
         SELECT ?, p.id, ? FROM cq_plan p WHERE p.active = 1 AND INSTR(p.workdays, ?) > 0",
        [$date, cfg('duty_default', 'off') === 'on' ? 'on' : 'off', date('N', strtotime($date))]
    );
}

/** เวรของวัน พร้อมชื่อแพทย์/คลินิก/โควต้า — กรองคลินิกได้ */
function cq_duty_rows(string $date, ?string $clinic = null): array
{
    cq_ensure_duty($date);
    // ห้อง: ถ้าวันนี้เลือกแพทย์ประจำห้องไว้ ใช้ห้องนั้น ไม่งั้นใช้ห้องประจำในโควต้า
    $sql = "SELECT d.id AS duty_id, d.status, d.slots_off, d.note, p.id AS plan_id, p.doctor_code, p.clinic_code,
                   COALESCE(rm.name, p.room) AS room, rd.room_id,
                   COALESCE(doc.name, p.doctor_code) AS doctor_name, c.name AS clinic_name, c.control_quota
            FROM cq_duty d
            JOIN cq_plan p ON p.id = d.plan_id
            JOIN cq_clinic c ON c.clinic_code = p.clinic_code
            LEFT JOIN cq_doctor doc ON doc.doctor_code = p.doctor_code
            LEFT JOIN cq_room_day rd ON rd.work_date = d.work_date AND rd.plan_id = p.id
            LEFT JOIN cq_room rm ON rm.room_id = rd.room_id AND rm.active = 1
            WHERE d.work_date = ?" . ($clinic !== null ? ' AND p.clinic_code = ?' : '') . "
            ORDER BY c.sort_no, c.clinic_code, doctor_name";
    $rows = db_all($sql, $clinic !== null ? [$date, $clinic] : [$date]);
    $quotas = cq_plan_quotas(array_column($rows, 'plan_id'));
    foreach ($rows as &$r) {
        $r['quotas'] = $quotas[(int)$r['plan_id']] ?? [];
        $r['off'] = array_map('intval', array_filter(explode(',', (string)$r['slots_off'])));
    }
    return $rows;
}

// ---------- snapshot จาก HOSxP (แคชไฟล์ร่วมทุกเครื่อง) ----------

/**
 * เชื่อม HOSxP (PostgreSQL หรือ MySQL/MariaDB ตาม dsn) แบบอ่านอย่างเดียว
 * pgsql: ตั้ง session เป็น read-only กันการเขียนพลาด แม้บัญชีที่ใช้จะมีสิทธิ์เขียน + จำกัดเวลา query
 */
function hosxp_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = (string)cfg('hosxp.dsn');
    $isPg = str_starts_with($dsn, 'pgsql:');
    if ($isPg && !str_contains($dsn, 'connect_timeout')) {
        $dsn = rtrim($dsn, ';') . ';connect_timeout=5';
    }
    $pdo = new PDO($dsn, cfg('hosxp.user'), cfg('hosxp.pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if ($isPg) {
        $pdo->exec("SET client_encoding TO 'UTF8'");
        $pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
        $pdo->exec("SET statement_timeout TO '8s'");
    }
    return $pdo;
}

/** รันคำสั่งจาก hosxp_sql.php พร้อมพารามิเตอร์ */
function hosxp_query(string $key, array $params = []): array
{
    $stmt = hosxp_db()->prepare(hosxp_sql($key));
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** วันที่เริ่มนับ "แพทย์ที่ออกตรวจล่าสุด" สำหรับดึงรายชื่อแพทย์ */
function hosxp_doctor_since(): string
{
    return date('Y-m-d', strtotime('-90 days'));
}

function hosxp_sql(string $key): string
{
    static $sql = null;
    $sql ??= require __DIR__ . '/hosxp_sql.php';
    return $sql[$key];
}

function cq_is_demo(): bool
{
    return cfg('hosxp.mode', 'demo') !== 'live';
}

/**
 * visit วันนี้ทั้งโรงพยาบาล: ['at' => timestamp, 'source', 'error', 'visits' => [[vn, hn, time, doctor, clinic, status]]]
 * ดึงจริงไม่เกิน 1 ครั้งต่อ cache_seconds ไม่ว่าจะเปิดกี่เครื่อง (ล็อกไฟล์กันหลายเครื่องดึงพร้อมกัน)
 */
function cq_snapshot(string $date): array
{
    $file = __DIR__ . '/../storage/snapshot_' . str_replace('-', '', $date) . '.json';
    $ttl = (int)cfg('hosxp.cache_seconds', 20);
    $read = static function () use ($file): ?array {
        $raw = @file_get_contents($file);
        $data = $raw ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    };
    $cached = $read();
    if ($cached && time() - (int)$cached['at'] < $ttl) {
        return $cached;
    }
    $lock = fopen($file . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $cached = $read();
        if ($cached && time() - (int)$cached['at'] < $ttl) {
            return $cached;
        }
        try {
            $visits = cq_is_demo() ? cq_demo_visits($date) : cq_live_visits($date);
            $snap = ['at' => time(), 'data_at' => time(), 'source' => cq_is_demo() ? 'demo' : 'hosxp', 'error' => null, 'visits' => $visits];
        } catch (Throwable $e) {
            error_log('[checkq] HOSxP: ' . $e->getMessage());
            // ดึงไม่ได้: ใช้ชุดล่าสุดต่อ บอกหน้าจอว่าข้อมูลค้าง และรอครบรอบก่อนลองใหม่ (ไม่ให้ทุกคำขอรอ timeout)
            $snap = ($cached ?: ['visits' => [], 'data_at' => 0]);
            $snap['at'] = time();
            $snap['source'] = cq_is_demo() ? 'demo' : 'hosxp';
            $snap['error'] = 'ติดต่อ HOSxP ไม่ได้ — แสดงข้อมูลล่าสุดที่มี';
        }
        file_put_contents($file, json_encode($snap, JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $snap;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * snapshot ที่แปลงคิวห้องตรวจเป็นแพทย์แล้ว — ใช้แทน cq_snapshot() ทุกที่ที่นับคิวรายแพทย์
 * คนไข้ที่ HOSxP ส่งเข้าคิวห้องตรวจแล้วแต่ ovst.doctor ยังว่าง → นับเป็นคิวของแพทย์ประจำห้องวันนั้น (cq_room_day)
 * ทำตอนอ่าน ไม่ใช่ตอนเก็บ snapshot เพราะเจ้าหน้าที่เปลี่ยนแพทย์ประจำห้องได้ตลอด
 * แต่ละ visit ได้ room_id (ห้องในระบบนี้ หรือ null) เพิ่ม; ห้องที่ยังไม่เลือกแพทย์ doctor ยังเป็น null
 */
function cq_visits(string $date): array
{
    $snap = cq_snapshot($date);
    $rooms = [];
    foreach (db_all(
        "SELECT r.room_id, r.clinic_code, r.hosxp_qcode, p.doctor_code FROM cq_room r
         LEFT JOIN cq_room_day rd ON rd.room_id = r.room_id AND rd.work_date = ?
         LEFT JOIN cq_plan p ON p.id = rd.plan_id
         WHERE r.active = 1 AND r.hosxp_qcode IS NOT NULL AND r.hosxp_qcode <> '' ORDER BY r.sort_no, r.room_id",
        [$date]
    ) as $r) {
        $rooms[$r['clinic_code'] . '|' . $r['hosxp_qcode']] ??= $r;
    }
    foreach ($snap['visits'] as &$v) {
        $room = isset($v['room_q']) ? ($rooms[$v['clinic'] . '|' . $v['room_q']] ?? null) : null;
        $v['room_id'] = $room !== null ? (int)$room['room_id'] : null;
        $v['hosxp_doctor'] = $v['doctor']; // แพทย์ที่ HOSxP บันทึกจริง (null = ยังไม่ลงตรวจ)
        if ($v['doctor'] === null && $room !== null && $room['doctor_code'] !== null) {
            $v['doctor'] = (string)$room['doctor_code'];
        }
    }
    unset($v);
    return $snap;
}

/** รหัสแพทย์จาก HOSxP → null ถ้าว่างหรือเป็นรหัส "ไม่ระบุแพทย์" */
function cq_doctor_or_null(mixed $code): ?string
{
    $code = trim((string)$code);
    return ($code === '' || in_array($code, (array)cfg('hosxp.unspecified_doctors', []), true)) ? null : $code;
}

/** main_dep → คลินิก สำหรับ visit ที่ไม่มีนัด (ไม่มี clinic_visit) */
function cq_dep_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db_all('SELECT dep_code, clinic_code FROM cq_dep_map') as $r) {
            $map[$r['dep_code']] = $r['clinic_code'];
        }
    }
    return $map;
}

/** รหัสคิวห้องตรวจใน HOSxP (เช่น 0037 "คิวพบแพทย์ห้องตรวจ 2 (B)") → คลินิกเจ้าของห้อง: [hosxp_qcode => clinic_code] */
function cq_room_q_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (db_all("SELECT hosxp_qcode, clinic_code FROM cq_room WHERE active = 1 AND hosxp_qcode IS NOT NULL AND hosxp_qcode <> '' ORDER BY sort_no, room_id") as $r) {
            $map[$r['hosxp_qcode']] ??= $r['clinic_code'];
        }
    }
    return $map;
}

/**
 * แถวจาก query visits_today → รูปแบบกลางของระบบ (null = ไม่อยู่ในคลินิกที่ระบบดูแล)
 * $roomQ = รหัสคิวห้องตรวจที่คนไข้อยู่ใน HOSxP (ถ้ามี) → นับเข้าคลินิกเจ้าของห้อง
 * $calledAt = เวลาที่ห้องตรวจเรียกคนไข้ (ช่อง "เวลาเรียก" ใน HOSxP) → ถ้ายังไม่ตรวจเสร็จ = กำลังพบแพทย์
 */
function cq_normalize_visit(array $r, array $depMap, ?string $roomQ = null, array $roomQMap = [], ?string $calledAt = null): ?array
{
    $status = in_array($r['status'], ['wait', 'exam', 'done'], true) ? $r['status'] : 'wait';
    $called = $calledAt !== null && $calledAt !== '' ? substr($calledAt, 11, 8) : null; // 'Y-m-d H:i:s' → 'H:i:s'
    if ($called !== null && $status === 'wait') {
        $status = 'exam';
    }
    $clinic = $roomQ !== null ? (string)($roomQMap[$roomQ] ?? '') : '';
    if ($clinic === '') {
        $clinic = trim((string)($r['clinic_code'] ?? ''));
    }
    if ($clinic === '') {
        $clinic = (string)($depMap[trim((string)($r['dep_code'] ?? ''))] ?? '');
    }
    if ($clinic === '') {
        return null;
    }
    return [
        'vn' => (string)$r['vn'], 'hn' => (string)$r['hn'], 'time' => substr((string)$r['arrive_time'], 0, 8),
        'doctor' => cq_doctor_or_null($r['doctor_code'] ?? null), 'clinic' => $clinic,
        'q' => isset($r['queue_no']) ? (int)$r['queue_no'] : null,
        'name' => trim(preg_replace('/\s+/u', ' ', (string)($r['patient_name'] ?? ''))),
        'status' => $status,
        'room_q' => $roomQ,
        'called' => $called,
    ];
}

function cq_live_visits(string $date): array
{
    // คิวล่าสุดของแต่ละ visit ในระบบคิว HOSxP — ถ้าเป็นคิวห้องตรวจที่จับคู่ไว้ = คนไข้รอห้องนั้น
    // (คิวก่อนหน้า เช่น จุดซักประวัติ G/S/DP ถูกทับด้วยคิวที่ใหม่กว่า)
    $roomQMap = cq_room_q_map();
    $roomOf = [];
    $calledOf = [];
    if ($roomQMap !== []) {
        $last = [];
        foreach (hosxp_query('room_slots_today', [':d' => $date]) as $q) {
            $last[(string)$q['vn']] = $q; // เรียงตามเวลาแล้ว แถวหลังทับแถวก่อน
        }
        foreach ($last as $vn => $q) {
            $code = trim((string)$q['qcode']);
            if (isset($roomQMap[$code])) {
                $roomOf[$vn] = $code;
                $calledOf[$vn] = $q['called_at'] !== null && $q['called_at'] !== '' ? (string)$q['called_at'] : null;
            }
        }
    }
    $stmt = hosxp_db()->prepare(hosxp_sql('visits_today'));
    $stmt->execute([':d' => $date]);
    $depMap = cq_dep_map();
    $out = [];
    foreach ($stmt as $r) {
        $v = cq_normalize_visit($r, $depMap, $roomOf[(string)$r['vn']] ?? null, $roomQMap, $calledOf[(string)$r['vn']] ?? null);
        if ($v !== null) {
            $out[] = $v;
        }
    }
    return $out;
}

/** ข้อมูลตัวอย่าง (ใช้จนกว่าจะใส่ query จริง) — สุ่มแบบคงที่ต่อวัน ตามเวรที่ตั้งไว้ */
function cq_demo_visits(string $date): array
{
    $now = $date === date('Y-m-d') ? date('H:i:s') : '16:00:00';
    mt_srand(crc32($date));
    $out = [];
    $n = 0;
    foreach (cq_duty_rows($date) as $d) {
        if ($d['status'] !== 'on') {
            continue;
        }

        foreach (cq_slots() as $s) {
            $q = (int)($d['quotas'][(int)$s['slot_id']] ?? 0);
            if ($q === 0 || in_array((int)$s['slot_id'], $d['off'], true) || $s['start_time'] > $now) {
                continue;
            }

            $count = (int)round($q * mt_rand(55, 105) / 100);
            $endSec = min(strtotime($date . ' ' . $s['end_time']), strtotime($date . ' ' . $now));
            $startSec = strtotime($date . ' ' . $s['start_time']) - ($s['slot_id'] == 1 ? 1800 : 0);
            for ($i = 0; $i < $count; $i++) {
                $t = date('H:i:s', mt_rand($startSec, max($startSec, $endSec)));
                $out[] = ['vn' => 'D' . (++$n), 'hn' => sprintf('%07d', mt_rand(1000, 999999)), 'time' => $t,
                    'doctor' => $d['doctor_code'], 'clinic' => $d['clinic_code'], 'status' => 'wait', 'name' => 'ผู้ป่วยตัวอย่าง ' . $n, 'q' => $n];
            }
        }
    }
    // แต่ละแพทย์ตรวจไปตามลำดับมาก่อน-ได้ก่อน เร็วพอ ๆ กับโควต้าต่อชั่วโมง (±)
    usort($out, static fn($a, $b) => strcmp($a['time'], $b['time']));
    $perHourOf = [];
    foreach (cq_duty_rows($date) as $d) {
        $perHourOf[$d['clinic_code'] . '|' . $d['doctor_code']] = max(1, max($d['quotas'] ?: [1]));
    }
    $speed = [];
    $nowSec = strtotime($date . ' ' . $now);
    $free = [];
    foreach ($out as &$v) {
        $key = $v['clinic'] . '|' . $v['doctor'];
        $speed[$key] ??= (int)(3600 / $perHourOf[$key] * mt_rand(85, 115) / 100);
        $start = max($free[$key] ?? 0, strtotime($date . ' ' . max($v['time'], '08:30:00')));
        $end = $start + $speed[$key];
        if ($end <= $nowSec) {
            $v['status'] = 'done';
            $free[$key] = $end;
        } elseif ($start <= $nowSec) {
            $v['status'] = 'exam';
            $free[$key] = $end;
        }
    }
    unset($v);
    // คนไข้ที่มาแล้วแต่ยังไม่ได้เลือกแพทย์
    foreach (array_unique(array_column($out, 'clinic')) as $c) {
        for ($i = mt_rand(0, 3); $i > 0; $i--) {
            $out[] = ['vn' => 'D' . (++$n), 'hn' => sprintf('%07d', mt_rand(1000, 999999)),
                'time' => date('H:i:s', $nowSec - mt_rand(60, 600)), 'doctor' => null, 'clinic' => $c, 'status' => 'wait', 'name' => 'ผู้ป่วยตัวอย่าง ' . $n, 'q' => $n];
        }
    }
    return $out;
}

// ---------- คำนวณภาระและแนะนำแพทย์ ----------

/**
 * กระดานของคลินิกหนึ่ง ณ ตอนนี้
 * ลำดับ: (0) ว่างรับได้ → (1) โควต้าช่วงนี้เต็ม → (2) ปิดช่วงนี้ → (3) ไม่ออก/ลา/ประชุม
 * ภายในกลุ่ม: คนรอตรวจน้อยก่อน แล้วจึงใช้โควต้าช่วงนี้ไปน้อยกว่า
 */
function cq_board(string $date, string $clinicCode, ?string $now = null): array
{
    $now ??= date('H:i:s');
    $clinic = cq_clinic($clinicCode);
    $slot = cq_slot_for($now);
    $slotId = (int)($slot['slot_id'] ?? 0);
    $snap = cq_visits($date);
    $control = (int)($clinic['control_quota'] ?? 1) === 1;
    $rooms = cq_rooms($clinicCode, $date);
    $roomName = array_column($rooms, 'name', 'room_id');

    $duty = cq_duty_rows($date, $clinicCode);
    $byDoctor = [];
    foreach ($duty as $d) {
        $byDoctor[$d['doctor_code']] = ['wait' => 0, 'exam' => 0, 'done' => 0, 'slot' => 0, 'day' => 0, 'hns' => [], 'by_slot' => []];
    }
    // คิวชั่วคราวจากการกด "ส่งไปหมอคนนี้" ที่ HOSxP ยังไม่เห็น
    $pending = db_all(
        'SELECT a.plan_id, a.slot_id, a.hn, a.created_at, p.doctor_code FROM cq_assign a JOIN cq_plan p ON p.id = a.plan_id
         WHERE a.work_date = ? AND p.clinic_code = ? AND a.created_at >= ? ORDER BY a.created_at',
        [$date, $clinicCode, date('Y-m-d H:i:s', time() - (int)cfg('pending_minutes', 10) * 60)]
    );
    $pendingByHn = [];
    foreach ($pending as $p) {
        if ($p['hn'] !== null) {
            $pendingByHn[$p['hn']] = $p; // ส่งล่าสุดของ HN นี้
        }
    }
    $docNames = [];
    foreach ($duty as $d) {
        $docNames[$d['doctor_code']] = ['name' => $d['doctor_name'], 'room' => $d['room']];
    }
    $docName = static function (string $code) use (&$docNames): array {
        if (!isset($docNames[$code])) {
            $docNames[$code] = ['name' => (string)(db_one('SELECT name FROM cq_doctor WHERE doctor_code = ?', [$code])['name'] ?? $code), 'room' => ''];
        }
        return $docNames[$code];
    };
    $nowSec = strtotime($date . ' ' . $now);

    $unassigned = 0;
    $roomStat = []; // [room_id => wait/exam/done/by_slot/hosxp]
    $inExam = [];   // กำลังพบแพทย์
    $queue = [];    // มาแล้ว รอเลือกแพทย์
    $sent = [];     // มีแพทย์แล้ว รอตรวจ
    foreach ($snap['visits'] as $v) {
        if ($v['clinic'] !== $clinicCode) {
            continue;
        }
        if ($v['status'] === 'wait') {
            $row = ['hn' => $v['hn'], 'name' => $v['name'] ?? '', 'q' => $v['q'] ?? null, 'time' => substr($v['time'], 0, 5),
                    'wait_min' => max(0, (int)floor(($nowSec - strtotime($date . ' ' . $v['time'])) / 60))];
            if ($v['doctor'] === null && isset($pendingByHn[$v['hn']])) {
                // กดส่งแล้วในระบบนี้ แต่ HOSxP ยังไม่เปลี่ยนแพทย์
                $dn = $docName($pendingByHn[$v['hn']]['doctor_code']);
                $sent[] = $row + ['code' => $pendingByHn[$v['hn']]['doctor_code'], 'doctor' => $dn['name'], 'room' => $dn['room'], 'pending' => true];
            } elseif ($v['doctor'] === null && ($v['room_id'] ?? null) !== null) {
                // HOSxP ส่งเข้าคิวห้องแล้ว แต่วันนี้ยังไม่ได้เลือกแพทย์ประจำห้องนั้นในระบบนี้
                $sent[] = $row + ['code' => '', 'doctor' => 'ยังไม่เลือกแพทย์ประจำห้อง', 'room' => (string)($roomName[$v['room_id']] ?? ''),
                                  'room_id' => $v['room_id'], 'pending' => false];
            } elseif ($v['doctor'] === null) {
                $queue[] = $row;
            } else {
                $dn = $docName($v['doctor']);
                $sent[] = $row + ['code' => $v['doctor'], 'doctor' => $dn['name'], 'room' => $dn['room'], 'pending' => false];
            }
        }
        // กำลังพบแพทย์: ห้องตรวจเรียกแล้ว (ช่อง "เวลาเรียก" ใน HOSxP) แต่ HOSxP ยังไม่บันทึกว่าตรวจเสร็จ
        if ($v['status'] === 'exam') {
            $calledAt = $v['called'] ?? null;
            $inExam[] = ['hn' => $v['hn'], 'name' => $v['name'] ?? '', 'q' => $v['q'] ?? null,
                'code' => (string)($v['doctor'] ?? ''), 'room_id' => $v['room_id'] ?? null,
                'called' => $calledAt !== null ? substr($calledAt, 0, 5) : null,
                'exam_min' => $calledAt !== null ? max(0, (int)floor(($nowSec - strtotime($date . ' ' . $calledAt)) / 60)) : null];
        }
        // นับรายห้อง (ตามคิวห้องตรวจใน HOSxP) — ตรวจแล้วกี่คน ไม่ว่าแพทย์ที่ลงใน HOSxP จะเป็นใคร
        if (($v['room_id'] ?? null) !== null) {
            $rs = &$roomStat[$v['room_id']];
            $rs ??= ['wait' => 0, 'exam' => 0, 'done' => 0, 'by_slot' => [], 'hosxp' => []];
            $rs[$v['status']]++;
            $vs = (int)(cq_slot_for($v['time'])['slot_id'] ?? 0);
            $rs['by_slot'][$vs] ??= ['wait' => 0, 'exam' => 0, 'done' => 0];
            $rs['by_slot'][$vs][$v['status']]++;
            if (($v['hosxp_doctor'] ?? null) !== null) {
                $rs['hosxp'][$v['hosxp_doctor']] = ($rs['hosxp'][$v['hosxp_doctor']] ?? 0) + 1;
            }
            unset($rs);
        }
        if ($v['doctor'] === null) {
            $unassigned += ($v['status'] === 'wait' && !isset($pendingByHn[$v['hn']]) && ($v['room_id'] ?? null) === null) ? 1 : 0;
            continue;
        }
        if (!isset($byDoctor[$v['doctor']])) {
            continue; // แพทย์ที่ไม่มีเวรในระบบนี้ (ลงใน HOSxP เอง)
        }
        $b = &$byDoctor[$v['doctor']];
        $b[$v['status']]++;
        $b['day']++;
        $b['hns'][$v['hn']] = true;
        $vs = (int)(cq_slot_for($v['time'])['slot_id'] ?? 0);
        // นับรายช่วงตามเวลามาถึง — ใช้กับปุ่มเลือกช่วงเวลาในหน้าคิวรายแพทย์
        $b['by_slot'][$vs] ??= ['wait' => 0, 'exam' => 0, 'done' => 0];
        $b['by_slot'][$vs][$v['status']]++;
        if ($vs === $slotId) {
            $b['slot']++;
        }
        unset($b);
    }

    // มาก่อนอยู่บนก่อน
    usort($queue, static fn($a, $b) => strcmp($a['time'], $b['time']));
    usort($sent, static fn($a, $b) => strcmp($a['time'], $b['time']));

    // ทุกช่วงของวัน: มาแล้ว (ตามเวลามาถึง) / โควต้า / รอ / ตรวจแล้ว — {slot_id: {...}}
    $perSlot = static function (array $d, array $bySlot): array {
        $out = [];
        foreach (cq_slots() as $s) {
            $sid = (int)$s['slot_id'];
            $c = $bySlot[$sid] ?? ['wait' => 0, 'exam' => 0, 'done' => 0];
            $off = in_array($sid, $d['off'], true);
            $out[(string)$sid] = ['in' => $c['wait'] + $c['exam'] + $c['done'], 'wait' => $c['wait'], 'exam' => $c['exam'], 'done' => $c['done'],
                                  'quota' => $off ? 0 : (int)($d['quotas'][$sid] ?? 0), 'off' => $off];
        }
        return $out;
    };
    $doctors = [];
    foreach ($duty as $d) {
        $b = $byDoctor[$d['doctor_code']];
        $pend = 0;
        foreach ($pending as $p) {
            if ((int)$p['plan_id'] === (int)$d['plan_id'] && ($p['hn'] === null || !isset($b['hns'][$p['hn']]))) {
                $pend++;
                $b['by_slot'][(int)$p['slot_id']] ??= ['wait' => 0, 'exam' => 0, 'done' => 0];
                $b['by_slot'][(int)$p['slot_id']]['wait']++;
                if ((int)$p['slot_id'] === $slotId) {
                    $b['slot']++;
                }
            }
        }
        $slotOff = in_array($slotId, $d['off'], true);
        $quota = $slotOff ? 0 : (int)($d['quotas'][$slotId] ?? 0);
        $dayQuota = 0;
        foreach ($d['quotas'] as $sid => $q) {
            $dayQuota += in_array((int)$sid, $d['off'], true) ? 0 : $q;
        }
        $wait = $b['wait'] + $pend;
        if ($d['status'] !== 'on') {
            $tier = 3;
        } elseif ($slotOff || ($control && $quota === 0)) {
            $tier = 2;
        } elseif ($control && $b['slot'] >= $quota) {
            $tier = 1;
        } else {
            $tier = 0;
        }
        $doctors[] = [
            'plan_id' => (int)$d['plan_id'], 'code' => $d['doctor_code'], 'name' => $d['doctor_name'], 'room' => $d['room'],
            'room_id' => $d['room_id'] !== null ? (int)$d['room_id'] : null,
            'status' => $d['status'], 'status_label' => CQ_STATUS[$d['status']], 'note' => $d['note'],
            'tier' => $tier, 'wait' => $wait, 'pending' => $pend, 'exam' => $b['exam'], 'done' => $b['done'],
            'slot_used' => $b['slot'], 'slot_quota' => $quota, 'slot_off' => $slotOff,
            'day_used' => $b['day'] + $pend, 'day_quota' => $dayQuota,
            'ratio' => $quota > 0 ? $b['slot'] / $quota : ($b['slot'] > 0 ? 99 : 0),
            'slots' => $perSlot($d, $b['by_slot']),
        ];
    }
    usort($doctors, static fn($a, $b) => [$a['tier'], $a['wait'], $a['ratio'], $a['name']] <=> [$b['tier'], $b['wait'], $b['ratio'], $b['name']]);

    $pick = null;
    if ($doctors && $doctors[0]['tier'] <= 1) {
        $pick = $doctors[0]['plan_id'];
    }
    return [
        'date' => $date, 'now' => substr($now, 0, 5),
        'clinic' => ['code' => $clinicCode, 'name' => $clinic['name'] ?? $clinicCode, 'control' => $control],
        'slot' => $slot ? ['id' => $slotId, 'label' => cq_slot_range($slot)] : null,
        'slots' => array_map(static fn($s) => ['id' => (int)$s['slot_id'], 'label' => cq_slot_range($s, false),
            'past' => $s['end_time'] < $now], cq_slots()),
        'unassigned' => $unassigned,
        'queue' => $queue, 'sent' => $sent,
        // เรียกล่าสุดอยู่บน (ปกติห้องละ 1 คน)
        'in_exam' => (static function (array $rows): array {
            usort($rows, static fn($a, $b) => strcmp((string)$b['called'], (string)$a['called']));
            return $rows;
        })($inExam),
        'rooms' => array_map(static function (array $r) use ($roomStat, $duty, $docName): array {
            $s = $roomStat[$r['room_id']] ?? ['wait' => 0, 'exam' => 0, 'done' => 0, 'by_slot' => [], 'hosxp' => []];
            $slots = [];
            foreach (cq_slots() as $sl) {
                $c = $s['by_slot'][(int)$sl['slot_id']] ?? ['wait' => 0, 'exam' => 0, 'done' => 0];
                $slots[(string)$sl['slot_id']] = $c + ['in' => $c['wait'] + $c['exam'] + $c['done']];
            }
            // แพทย์ที่ HOSxP บันทึกว่าตรวจในห้องนี้ แต่ไม่ใช่แพทย์ประจำห้องที่เลือกไว้ → เตือนให้เปลี่ยน
            $assigned = null;
            foreach ($duty as $d) {
                if ((int)$d['plan_id'] === (int)($r['plan_id'] ?? 0)) {
                    $assigned = $d['doctor_code'];
                }
            }
            $others = [];
            foreach ($s['hosxp'] as $code => $n) {
                if ((string)$code !== (string)$assigned) {
                    $others[] = ['code' => (string)$code, 'name' => $docName((string)$code)['name'], 'n' => $n];
                }
            }
            return $r + ['stat' => ['wait' => $s['wait'], 'exam' => $s['exam'], 'done' => $s['done'], 'slots' => $slots, 'other_doctors' => $others]];
        }, $rooms),
        'pick' => $pick, 'all_full' => $doctors && $doctors[0]['tier'] === 1,
        'doctors' => $doctors,
        'source' => $snap['source'], 'error' => $snap['error'], 'fetched_at' => date('H:i:s', (int)($snap['data_at'] ?? $snap['at'])),
    ];
}

/** จำนวนคนรอแยกคลินิก สำหรับแท็บคลินิก */
function cq_clinic_counts(string $date): array
{
    $snap = cq_visits($date);
    $out = [];
    foreach ($snap['visits'] as $v) {
        $out[$v['clinic']] ??= ['wait' => 0, 'unassigned' => 0];
        if ($v['status'] === 'wait') {
            $out[$v['clinic']][$v['doctor'] === null && ($v['room_id'] ?? null) === null ? 'unassigned' : 'wait']++;
        }
    }
    return $out;
}

/** นัดวันนี้ของ HN หนึ่งคน */
function cq_patient_today(string $date, string $hn): array
{
    if (cq_is_demo()) {
        $duty = array_values(array_filter(cq_duty_rows($date), static fn($d) => $d['status'] === 'on'));
        if ($duty === []) {
            return [];
        }
        $d = $duty[crc32($hn) % count($duty)];
        return [['hn' => $hn, 'patient_name' => 'ผู้ป่วยตัวอย่าง', 'clinic_code' => $d['clinic_code'], 'app_time' => '09:00:00',
            'doctor_code' => ((int)substr($hn, -1)) % 3 === 0 ? $d['doctor_code'] : null]];
    }
    $stmt = hosxp_db()->prepare(hosxp_sql('patient_today'));
    $stmt->execute([':d' => $date, ':hn' => $hn]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['doctor_code'] = cq_doctor_or_null($r['doctor_code'] ?? null);
    }
    return $rows;
}

/** ดึงรายชื่อแพทย์จาก HOSxP เข้า cq_doctor */
function cq_sync_doctors(): int
{
    if (cq_is_demo()) {
        $rows = [];
        foreach (['D01' => 'พญ.กมลชนก ใจดี', 'D02' => 'นพ.ธนพล รักษ์สุข', 'D03' => 'พญ.ปิยะดา สุขสันต์', 'D04' => 'นพ.วรวุฒิ มั่นคง',
                  'D05' => 'พญ.สุนิสา แสงทอง', 'D06' => 'นพ.อภิชาติ ศรีสวัสดิ์', 'D07' => 'พญ.ณัฐธิดา พรหมมา', 'D08' => 'นพ.ชยพล วงศ์ใหญ่'] as $c => $n) {
            $rows[] = ['doctor_code' => $c, 'name' => $n . ' (ตัวอย่าง)'];
        }
    } else {
        $rows = array_values(array_filter(hosxp_query('doctors', [':since' => hosxp_doctor_since(), ':since2' => hosxp_doctor_since()]),
            static fn($r) => cq_doctor_or_null($r['doctor_code']) !== null));
    }
    // แพทย์ที่ไม่อยู่ในรายชื่อล่าสุด (ลาออก/ย้าย/ไม่ใช่แพทย์) → ซ่อนจากรายการเลือก แต่โควต้าเดิมยังอยู่
    if ($rows !== []) {
        $keep = array_map(static fn($r) => (string)$r['doctor_code'], $rows);
        db_exec('UPDATE cq_doctor SET active = 0 WHERE doctor_code NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')', $keep);
    }
    $n = 0;
    foreach ($rows as $r) {
        $n += db_exec(
            'INSERT INTO cq_doctor (doctor_code, name, active, synced_at) VALUES (?,?,1,NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1, synced_at = NOW()',
            [(string)$r['doctor_code'], (string)$r['name']]
        ) > 0 ? 1 : 0;
    }
    return count($rows);
}

/**
 * คลินิกที่เลือกของหน้าคิว (จำไว้ใน session ของเครื่องนี้) + ข้อมูลสำหรับแท็บคลินิก
 * @return array{clinics: array, counts: array, on_duty: array, clinic: string}
 */
function cq_pick_clinic(string $date): array
{
    $clinics = cq_clinics();
    $counts = cq_clinic_counts($date);
    $codes = array_column($clinics, 'clinic_code');
    $onDuty = [];
    foreach (cq_duty_rows($date) as $d) {
        if ($d['status'] === 'on') {
            $onDuty[$d['clinic_code']] = ($onDuty[$d['clinic_code']] ?? 0) + 1;
        }
    }
    $clinic = (string)($_GET['clinic'] ?? ($_SESSION['cq_clinic'] ?? ''));
    if (!in_array($clinic, $codes, true)) {
        $busy = array_filter($counts, static fn($c) => $c['unassigned'] + $c['wait'] > 0);
        $clinic = array_key_first($onDuty) ?? (array_key_first($busy) ?? ($codes[0] ?? ''));
    }
    $_SESSION['cq_clinic'] = $clinic;
    return ['clinics' => $clinics, 'counts' => $counts, 'on_duty' => $onDuty, 'clinic' => (string)$clinic];
}

/** ห้องตรวจของคลินิก พร้อมแพทย์ประจำห้องของวันนั้น: [{room_id, name, plan_id|null}] */
function cq_rooms(string $clinicCode, string $date): array
{
    $rows = db_all(
        'SELECT r.room_id, r.name, rd.plan_id FROM cq_room r
         LEFT JOIN cq_room_day rd ON rd.room_id = r.room_id AND rd.work_date = ?
         WHERE r.clinic_code = ? AND r.active = 1 ORDER BY r.sort_no, r.room_id',
        [$date, $clinicCode]
    );
    return array_map(static fn($r) => ['room_id' => (int)$r['room_id'], 'name' => $r['name'],
        'plan_id' => $r['plan_id'] !== null ? (int)$r['plan_id'] : null], $rows);
}

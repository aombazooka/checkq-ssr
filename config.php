<?php
declare(strict_types=1);

// ค่าตั้งของระบบ — ค่าลับ (รหัสฐานข้อมูลจริง, รหัส HOSxP) ใส่ใน config.local.php ซึ่งทับค่าในไฟล์นี้ และห้าม commit
$config = [
    'app_name'     => 'ระบบคิวแพทย์ OPD',
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=checkq;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
    ],

    // HOSxP replica (อ่านอย่างเดียว)
    // mode: 'demo' = ใช้ข้อมูลตัวอย่าง (ยังไม่ได้ใส่ query จริง) · 'live' = อ่านจาก HOSxP ด้วย SQL ใน lib/hosxp_sql.php
    'hosxp' => [
        'mode' => 'demo',   // ตั้งเป็น 'live' ใน config.local.php
        'cache_seconds' => 10,   // ดึงข้อมูลวันนี้ทั้งโรงพยาบาลใหม่ทุกกี่วินาที (ทุกเครื่องใช้ชุดเดียวกัน) — 1 รอบ ≈ 0.1 วินาที บน replica
        // รหัสแพทย์หลอกที่ HOSxP ใช้แทน "ไม่ระบุแพทย์" — นับเป็นคนไข้รอเลือกแพทย์ และไม่แสดงในรายชื่อแพทย์
        'unspecified_doctors' => ['0029'],
    ],
    'refresh_seconds'  => 15,    // หน้าแดชบอร์ดโหลดตัวเลขใหม่ทุกกี่วินาที (ข้อมูลช้าสุด ≈ cache_seconds + ค่านี้)
    'pending_minutes'  => 10,
    'stale_minutes'    => 180, // รอเกินกี่นาทีถือว่า "รอนาน" (น่าจะกลับไปแล้วแต่ยังไม่ปิด visit) — แสดงจางพร้อมป้าย
    'duty_default'     => 'off', // เวรที่ระบบสร้างจากวันออกตรวจประจำ เริ่มเป็น 'off' (ไม่ออก — กดออกตรวจเอง) หรือ 'on'    // กด "ส่งไปหมอคนนี้" แล้วนับเป็นคิวชั่วคราวนานเท่าไร ถ้า HOSxP ยังไม่เห็นคนไข้

    // ชื่อคุกกี้ session ต้องไม่ซ้ำกับระบบอื่นบนโดเมนเดียวกัน
    'session_name' => 'CHECKQSESS',
    // ไม่มีการกด/เปิดหน้าเลยนานเกินนี้ = ออกจากระบบทั้งหมด (การโหลดตัวเลขใหม่อัตโนมัติของแดชบอร์ดไม่นับเป็นการใช้งาน)
    'idle_minutes' => 60,

    // role จาก SSO → สิทธิ์ในระบบนี้
    'permissions' => [
        'admin' => ['*'],
        'staff' => ['board.view', 'summary.view', 'duty.edit', 'assign.create'],
    ],
    'role_labels' => [
        'admin' => 'ผู้ดูแล',
        'staff' => 'เจ้าหน้าที่ลงทะเบียน',
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}
return $config;

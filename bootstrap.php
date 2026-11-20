<?php
declare(strict_types=1);

/*
 * ทุกหน้า require ไฟล์นี้ก่อน: โหลดฟังก์ชัน, เชื่อม SSO, ตั้ง header ความปลอดภัย, เปิด session
 */

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/sso/sso.php';

date_default_timezone_set('Asia/Bangkok');

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store'); // มีข้อมูลบุคคล ห้ามเก็บใน cache ของเบราว์เซอร์ที่ใช้ร่วมกัน

sso_hook_start_session();
require_once __DIR__ . '/lib/checkq.php';
require_once __DIR__ . '/ui/ui.php';

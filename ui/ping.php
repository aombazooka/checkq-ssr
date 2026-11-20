<?php
/*
 * ต่ออายุ session ให้หน้าที่เปิดค้างไว้ — assets/ui.js เรียกทุก keepalive_minutes นาที (config = 0 ปิด)
 * เขียนลง session ทุกครั้ง เวลาไฟล์ session จึงถูกต่อ ไม่ถูกล้างตอน PHP เก็บกวาด session เก่า
 */
require __DIR__ . '/ui.php';

$ok = ui_user() !== null;
if ($ok && session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['ui_seen'] = time();
}
ui_json(array('ok' => $ok));

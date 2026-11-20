<?php
declare(strict_types=1);

/*
 * [SSO] ปุ่ม "เข้าสู่ระบบด้วย SSO" บนหน้า login เดิม (ตอนที่ผู้ดูแลเปิดให้เข้าโดยตรงได้ด้วย)
 * ไฟล์นี้เหมือนกันทุกระบบ ห้ามแก้
 */

require __DIR__ . '/sso.php';
header('Cache-Control: no-store');
sso_login(isset($_GET['next']) && is_string($_GET['next']) ? $_GET['next'] : '');

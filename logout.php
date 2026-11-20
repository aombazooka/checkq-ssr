<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// ออกจากระบบนี้ + SSO + ทุกระบบที่เปิดอยู่ (single logout) — รับเฉพาะ POST ที่มี CSRF กันลิงก์หลอกให้ออก
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
csrf_verify();
sso_logout();

<?php
/*
 * บันทึกหลักฐานการตอบเรื่องคุกกี้ (PDPA: ต้องแสดงได้ว่าได้รับความยินยอมเมื่อใด อย่างไร)
 * เรียกจาก assets/cookie-consent.js — เข้าสู่ระบบหรือยังก็ได้
 */
require __DIR__ . '/ui.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ui_json(array('ok' => false), 405);
}
if (!ui_hook_csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
    ui_json(array('ok' => false), 400);
}

// กันยิงซ้ำถี่ ๆ ในเซสชันเดียว
$_SESSION['ui_cc_logs'] = (isset($_SESSION['ui_cc_logs']) ? $_SESSION['ui_cc_logs'] : 0) + 1;
if ($_SESSION['ui_cc_logs'] > 30) {
    ui_json(array('ok' => false), 429);
}

$id = isset($_POST['id']) && is_string($_POST['id']) ? $_POST['id'] : '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    ui_json(array('ok' => false), 422);
}
$method = isset($_POST['method']) && in_array($_POST['method'], array('acknowledge', 'accept_all', 'reject_all', 'custom'), true) ? $_POST['method'] : 'custom';
$optional = array_keys((array)ui_cfg('cookies_optional', array()));
$on = array_intersect($optional, explode(',', isset($_POST['on']) && is_string($_POST['on']) ? $_POST['on'] : ''));

ui_hook_audit(
    'cookie_consent',
    'ฉบับ ' . (int)ui_cfg('cookie_version', 1) . ' · ' . $method
        . ($optional !== array() ? ' · อนุญาต: ' . ($on !== array() ? implode(', ', $on) : 'เฉพาะที่จำเป็น') : '')
        . ' · อ้างอิง ' . substr($id, 0, 8)
);
ui_json(array('ok' => true));

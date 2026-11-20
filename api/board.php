<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// โหลดตัวเลขใหม่อัตโนมัติ — ไม่นับเป็นการใช้งาน (ไม่ต่ออายุ idle)
api_require('board.view');
$clinic = (string)($_GET['clinic'] ?? '');
if (cq_clinic($clinic) === null) {
    api_json(['ok' => false, 'message' => 'ไม่พบคลินิก'], 404);
}
$date = date('Y-m-d');
api_json(['ok' => true, 'board' => cq_board($date, $clinic), 'counts' => cq_clinic_counts($date)]);

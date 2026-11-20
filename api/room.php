<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// เลือกแพทย์ประจำห้องของวันนี้ (plan = 0 คือให้ห้องว่าง) — แพทย์คนเดียวอยู่ได้ห้องเดียว ย้ายห้องได้
$user = api_require('duty.edit', true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false], 405);
}
api_csrf();

$date = date('Y-m-d');
$roomId = (int)($_POST['room'] ?? 0);
$planId = (int)($_POST['plan'] ?? 0);
$room = db_one('SELECT * FROM cq_room WHERE room_id = ? AND active = 1', [$roomId]);
if ($room === null) {
    api_json(['ok' => false, 'message' => 'ไม่พบห้องนี้ กรุณาโหลดหน้าใหม่'], 404);
}

if ($planId === 0) {
    db_exec('DELETE FROM cq_room_day WHERE work_date = ? AND room_id = ?', [$date, $roomId]);
    api_json(['ok' => true, 'message' => 'ห้อง ' . $room['name'] . ' ว่างแล้ว']);
}

// ต้องเป็นแพทย์ที่ออกตรวจวันนี้ในคลินิกเดียวกับห้อง
cq_ensure_duty($date);
$plan = db_one(
    "SELECT p.id, COALESCE(d.name, p.doctor_code) AS doctor_name FROM cq_plan p
     JOIN cq_duty du ON du.plan_id = p.id AND du.work_date = ? AND du.status = 'on'
     LEFT JOIN cq_doctor d ON d.doctor_code = p.doctor_code
     WHERE p.id = ? AND p.clinic_code = ?",
    [$date, $planId, $room['clinic_code']]
);
if ($plan === null) {
    api_json(['ok' => false, 'message' => 'แพทย์คนนี้ไม่ได้ "ออกตรวจ" วันนี้ในคลินิกนี้ — กดออกตรวจที่หน้าเวรแพทย์ก่อน'], 422);
}

db()->beginTransaction();
db_exec('DELETE FROM cq_room_day WHERE work_date = ? AND (plan_id = ? OR room_id = ?)', [$date, $planId, $roomId]);
db_exec('INSERT INTO cq_room_day (work_date, room_id, plan_id, updated_by) VALUES (?,?,?,?)', [$date, $roomId, $planId, $user['id']]);
db()->commit();
api_json(['ok' => true, 'message' => $plan['doctor_name'] . ' อยู่ห้อง ' . $room['name']]);

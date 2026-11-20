<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// บันทึกการเปลี่ยนเวรทันที: field = status | slot | note
$user = api_require('duty.edit', true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false], 405);
}
api_csrf();

$id = (int)($_POST['duty'] ?? 0);
$duty = db_one('SELECT d.*, p.clinic_code FROM cq_duty d JOIN cq_plan p ON p.id = d.plan_id WHERE d.id = ?', [$id]);
if ($duty === null) {
    api_json(['ok' => false, 'message' => 'ไม่พบเวรนี้ (อาจถูกลบ) กรุณาโหลดหน้าใหม่'], 404);
}
$field = (string)($_POST['field'] ?? '');
$value = (string)($_POST['value'] ?? '');

switch ($field) {
    case 'status':
        if (!isset(CQ_STATUS[$value])) {
            api_json(['ok' => false, 'message' => 'สถานะไม่ถูกต้อง'], 422);
        }
        db_exec('UPDATE cq_duty SET status = ?, updated_by = ? WHERE id = ?', [$value, $user['id'], $id]);
        api_json(['ok' => true, 'message' => 'เปลี่ยนเป็น "' . CQ_STATUS[$value] . '" แล้ว']);

    case 'slot':
        $slot = (int)$value;
        if (!in_array($slot, array_map('intval', array_column(cq_slots(), 'slot_id')), true)) {
            api_json(['ok' => false, 'message' => 'ช่วงเวลาไม่ถูกต้อง'], 422);
        }
        $off = array_map('intval', array_filter(explode(',', (string)$duty['slots_off'])));
        $nowOff = !in_array($slot, $off, true);
        $off = $nowOff ? array_merge($off, [$slot]) : array_diff($off, [$slot]);
        sort($off);
        db_exec('UPDATE cq_duty SET slots_off = ?, updated_by = ? WHERE id = ?', [implode(',', $off), $user['id'], $id]);
        api_json(['ok' => true, 'off' => $nowOff, 'message' => $nowOff ? 'ปิดช่วงนี้แล้ว' : 'เปิดช่วงนี้แล้ว']);

    case 'note':
        $note = mb_substr(trim($value), 0, 200);
        db_exec('UPDATE cq_duty SET note = ?, updated_by = ? WHERE id = ?', [$note, $user['id'], $id]);
        api_json(['ok' => true, 'message' => 'บันทึกหมายเหตุแล้ว']);
}
api_json(['ok' => false, 'message' => 'คำขอไม่ถูกต้อง'], 422);

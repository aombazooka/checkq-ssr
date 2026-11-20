<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

// ค้นหานัดวันนี้ของ HN — บอกว่านัดระบุแพทย์ไหม และควรส่งไปหมอคนไหน
api_require('board.view', true);
$hn = trim((string)($_GET['hn'] ?? ''));
if (!preg_match('/^[0-9A-Za-z]{1,20}$/', $hn)) {
    api_json(['ok' => false, 'message' => 'HN ไม่ถูกต้อง'], 422);
}
$date = date('Y-m-d');
try {
    $apps = cq_patient_today($date, $hn);
} catch (Throwable $e) {
    error_log('[checkq] patient: ' . $e->getMessage());
    api_json(['ok' => false, 'message' => 'ติดต่อ HOSxP ไม่ได้'], 502);
}

$out = [];
foreach ($apps as $a) {
    $clinicCode = (string)$a['clinic_code'];
    $clinic = cq_clinic($clinicCode);
    $board = $clinic ? cq_board($date, $clinicCode) : null;
    $doctorCode = ($a['doctor_code'] ?? '') === '' ? null : (string)$a['doctor_code'];
    $target = null;
    $reason = 'ไม่ระบุแพทย์ → แนะนำแพทย์ที่ว่าง';
    if ($board && $doctorCode !== null) {
        foreach ($board['doctors'] as $d) {
            if ($d['code'] === $doctorCode) {
                $target = $d;
                break;
            }
        }
        if ($target === null) {
            $reason = 'นัดระบุแพทย์ที่ไม่มีเวรวันนี้ → แนะนำแพทย์ที่ว่างแทน';
        } elseif ($target['status'] !== 'on') {
            $reason = 'นัดระบุแพทย์ แต่วันนี้แพทย์' . $target['status_label'] . ' → แนะนำแพทย์ที่ว่างแทน';
            $target = null;
        } else {
            $reason = 'นัดระบุแพทย์ไว้';
        }
    }
    if ($board && $target === null && $board['pick'] !== null) {
        foreach ($board['doctors'] as $d) {
            if ($d['plan_id'] === $board['pick']) {
                $target = $d;
                break;
            }
        }
    }
    $docName = $doctorCode !== null
        ? (db_one('SELECT name FROM cq_doctor WHERE doctor_code = ?', [$doctorCode])['name'] ?? $doctorCode)
        : null;
    $out[] = [
        'clinic_code' => $clinicCode,
        'clinic_name' => $clinic['name'] ?? $clinicCode,
        'time' => substr((string)$a['app_time'], 0, 5),
        'doctor_name' => $docName,
        'reason' => $board ? $reason : 'คลินิกนี้ไม่อยู่ในระบบคิวแพทย์',
        'target' => $target ? ['plan_id' => $target['plan_id'], 'name' => $target['name'], 'room' => $target['room'], 'wait' => $target['wait']] : null,
    ];
}
api_json(['ok' => true, 'hn' => $hn, 'name' => $apps[0]['patient_name'] ?? null, 'appointments' => $out]);

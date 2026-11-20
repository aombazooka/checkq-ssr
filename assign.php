<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// popup "ส่งไปหมอคนนี้" — บันทึกเป็นคิวชั่วคราว ให้จุดลงทะเบียนอื่นเห็นทันทีก่อน HOSxP อัปเดต
$user = require_permission('assign.create');
$date = date('Y-m-d');
$planId = (int)($_GET['plan'] ?? $_POST['plan'] ?? 0);
$plan = db_one(
    'SELECT p.*, COALESCE(d.name, p.doctor_code) AS doctor_name, c.name AS clinic_name
     FROM cq_plan p JOIN cq_clinic c ON c.clinic_code = p.clinic_code LEFT JOIN cq_doctor d ON d.doctor_code = p.doctor_code
     WHERE p.id = ?',
    [$planId]
);
if ($plan === null) {
    http_response_code(404);
    exit('ไม่พบแพทย์');
}
$back = 'board.php?clinic=' . rawurlencode($plan['clinic_code']);
$board = cq_board($date, $plan['clinic_code']);
$me = null;
foreach ($board['doctors'] as $d) {
    if ($d['plan_id'] === $planId) {
        $me = $d;
    }
}
$hn = trim((string)($_POST['hn'] ?? $_GET['hn'] ?? ''));
$errors = [];
// ข้อมูลคนไข้จาก HOSxP ชุดล่าสุด: ชื่อ (ยืนยันว่าเลือกคนถูก) และแพทย์ที่มีอยู่แล้ว
$ptName = '';
$ptFound = false;
$ptDoctor = null;
$warnings = [];
if ($hn !== '') {
    foreach (cq_visits($date)['visits'] as $v) {
        if ($v['hn'] === $hn) {
            $ptFound = true;
            $ptName = (string)($v['name'] ?? '');
            if ($v['doctor'] !== null && $v['status'] === 'wait') {
                $ptDoctor = (string)(db_one('SELECT name FROM cq_doctor WHERE doctor_code = ?', [$v['doctor']])['name'] ?? $v['doctor']);
            }
            break;
        }
    }
    if (!$ptFound) {
        $warnings[] = 'ยังไม่พบ HN นี้ใน HOSxP วันนี้ — ถ้าเพิ่งลงทะเบียน รอสักครู่ได้ หรือตรวจว่าพิมพ์ HN ถูก';
    }
    if ($ptDoctor !== null) {
        $warnings[] = 'คนไข้นี้มีแพทย์ใน HOSxP แล้ว: ' . $ptDoctor . ' — ส่งซ้ำเฉพาะเมื่อต้องการเปลี่ยนแพทย์';
    }
    $prev = db_one(
        'SELECT a.created_at, COALESCE(d.name, p.doctor_code) AS doctor_name FROM cq_assign a
         JOIN cq_plan p ON p.id = a.plan_id LEFT JOIN cq_doctor d ON d.doctor_code = p.doctor_code
         WHERE a.work_date = ? AND a.hn = ? ORDER BY a.created_at DESC LIMIT 1',
        [$date, $hn]
    );
    if ($prev) {
        $warnings[] = 'ส่งไปแล้วเมื่อ ' . date('H:i', strtotime($prev['created_at'])) . ' น. ไปที่ ' . $prev['doctor_name'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // ต้องมี HN เสมอ: ระบบใช้ HN จับคู่กับ HOSxP — ถ้าไม่มี คิวชั่วคราวจะถูกนับซ้ำกับข้อมูลจริงจนครบเวลา
    if ($hn === '') {
        $errors[] = 'กรุณาเลือกคนไข้จากรายชื่อด้านซ้าย หรือกรอก HN';
    } elseif (!preg_match('/^[0-9A-Za-z]{1,20}$/', $hn)) {
        $errors[] = 'HN ไม่ถูกต้อง (ตัวเลข/ตัวอักษร ไม่เกิน 20 ตัว)';
    }
    if ($me === null || $me['status'] !== 'on') {
        $errors[] = 'แพทย์คนนี้ไม่ได้ออกตรวจวันนี้แล้ว — ปิดหน้าต่างแล้วเลือกแพทย์คนอื่น';
    }
    if ($errors === []) {
        db_exec(
            'INSERT INTO cq_assign (work_date, plan_id, slot_id, hn, suggested, created_by) VALUES (?,?,?,?,?,?)',
            [$date, $planId, (int)($board['slot']['id'] ?? 0), $hn === '' ? null : $hn, $board['pick'] === $planId ? 1 : 0, $user['id']]
        );
        ui_done($back, 'บันทึกแล้ว — อย่าลืมเลือก ' . $plan['doctor_name'] . ' ใน HOSxP');
    }
    ui_fail($errors);
}

if (!ui_is_modal()) {
    page_start('ส่งคนไข้ไปหมอ', 'board');
}
echo ui_panel_open('ส่งคนไข้ไปหมอคนนี้', $plan['clinic_code'] . ' ' . $plan['clinic_name'], 'user-plus', $errors);
?>
<form class="ui-form" id="assignForm" method="post" action="assign.php" data-ui-ajax>
  <?= csrf_field() ?>
  <input type="hidden" name="plan" value="<?= (int)$planId ?>">
  <?php if ($hn !== '') { ?>
    <div class="cq-assign-pt">คนไข้ <b>HN <?= e($hn) ?></b><?= $ptName !== '' ? ' · ' . e($ptName) : '' ?></div>
  <?php } ?>
  <?php foreach ($warnings as $w) { ?>
    <div class="cq-assign-warn" role="note"><?= ui_icon('alert', 18) ?><span><?= e($w) ?></span></div>
  <?php } ?>
  <div class="cq-assign">
    <div class="cq-assign-name"><?= e($plan['doctor_name']) ?></div>
    <?php if ($plan['room'] !== '') { ?><div class="cq-assign-room">ห้อง <?= e($plan['room']) ?></div><?php } ?>
    <?php if ($me) { ?>
      <div class="cq-muted">รอตรวจ <?= (int)$me['wait'] ?> คน · ช่วงนี้ <?= (int)$me['slot_used'] ?><?= $board['clinic']['control'] ? '/' . (int)$me['slot_quota'] : '' ?></div>
      <?php if ($me['tier'] === 1) { ?><div class="ui-chip amber">เต็มโควต้าช่วงนี้แล้ว — ส่งได้ แต่จะเกินโควต้า</div><?php } ?>
      <?php if ($board['pick'] !== null && $board['pick'] !== $planId) { ?><div class="cq-muted">ไม่ใช่แพทย์ที่ระบบแนะนำ (ระบบแนะนำแพทย์ที่คนรอน้อยกว่า)</div><?php } ?>
    <?php } ?>
  </div>
  <?php if ($hn !== '' && $ptFound) { ?>
    <input type="hidden" name="hn" value="<?= e($hn) ?>">
  <?php } else { ?>
    <div class="ui-field">
      <label for="assignHn">HN ของคนไข้</label>
      <input class="ui-input" id="assignHn" name="hn" value="<?= e($hn) ?>" inputmode="numeric" autocomplete="off" required maxlength="20"
             pattern="[0-9A-Za-z]{1,20}" placeholder="เลือกจากรายชื่อด้านซ้าย หรือพิมพ์ HN">
    </div>
  <?php } ?>
  <p class="ui-hint">ขั้นต่อไป: <b>เลือก <?= e($plan['doctor_name']) ?> ใน HOSxP</b> — ระบบนี้ไม่ได้บันทึกลง HOSxP ให้
    ระหว่างรอ HOSxP อัปเดต ระบบนับคนไข้รายนี้เป็นคิวของแพทย์คนนี้ไว้ก่อน <?= (int)cfg('pending_minutes', 10) ?> นาที</p>
</form>
<?php
echo ui_panel_close('assignForm', 'ยืนยันส่งไปหมอคนนี้', $back);
if (!ui_is_modal()) {
    page_end();
}

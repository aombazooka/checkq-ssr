<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// popup เพิ่มแพทย์เข้าเวรของวัน (แพทย์ที่ปกติไม่ได้ออกตรวจวันนี้ แต่มาช่วย/สลับเวร)
$user = require_permission('duty.edit');
$date = cq_valid_date($_GET['date'] ?? $_POST['date'] ?? null);
cq_ensure_duty($date);
$plans = db_all(
    "SELECT p.id, p.clinic_code, p.room, p.workdays, COALESCE(d.name, p.doctor_code) AS doctor_name, c.name AS clinic_name
     FROM cq_plan p JOIN cq_clinic c ON c.clinic_code = p.clinic_code LEFT JOIN cq_doctor d ON d.doctor_code = p.doctor_code
     WHERE p.active = 1 AND p.id NOT IN (SELECT plan_id FROM cq_duty WHERE work_date = ?)
     ORDER BY c.sort_no, doctor_name",
    [$date]
);
$back = 'duty.php?date=' . $date;
$errors = [];
$sel = (int)($_POST['plan'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!in_array($sel, array_map('intval', array_column($plans, 'id')), true)) {
        $errors[] = 'กรุณาเลือกแพทย์';
    }
    if ($errors === []) {
        db_exec("INSERT IGNORE INTO cq_duty (work_date, plan_id, status, updated_by) VALUES (?,?, 'on', ?)", [$date, $sel, $user['id']]);
        ui_done($back, 'เพิ่มแพทย์เข้าเวรแล้ว');
    }
    ui_fail($errors);
}

if (!ui_is_modal()) {
    page_start('เพิ่มแพทย์เข้าเวร', 'duty');
}
echo ui_panel_open('เพิ่มแพทย์เข้าเวร', cq_thai_date($date), 'plus', $errors);
?>
<form class="ui-form" id="dutyAddForm" method="post" action="duty_add.php" data-ui-ajax>
  <?= csrf_field() ?>
  <input type="hidden" name="date" value="<?= e($date) ?>">
  <?php if ($plans === []) { ?>
    <p class="ui-hint">แพทย์ทุกคนที่ตั้งโควต้าไว้อยู่ในเวรวันนี้แล้ว<?= can('quota.edit') ? ' — ถ้าเป็นแพทย์ใหม่ ให้เพิ่มโควต้าที่หน้า <a href="quota.php">โควต้าแพทย์</a> ก่อน' : ' — ถ้าเป็นแพทย์ใหม่ ให้ผู้ดูแลระบบเพิ่มโควต้าก่อน' ?></p>
  <?php } else { ?>
    <div class="cq-picklist" role="radiogroup" aria-label="เลือกแพทย์">
      <?php $last = null; foreach ($plans as $p) {
          if ($p['clinic_code'] !== $last) { $last = $p['clinic_code']; ?>
            <div class="cq-picklist-h"><?= e($p['clinic_code'] . ' ' . $p['clinic_name']) ?></div>
          <?php } ?>
        <label class="cq-pick">
          <input type="radio" name="plan" value="<?= (int)$p['id'] ?>"<?= $sel === (int)$p['id'] ? ' checked' : '' ?> required>
          <span><b><?= e($p['doctor_name']) ?></b>
            <small class="cq-muted"><?= $p['room'] !== '' ? 'ห้อง ' . e($p['room']) . ' · ' : '' ?>ปกติออก <?= e(cq_workdays_label($p['workdays'])) ?></small></span>
        </label>
      <?php } ?>
    </div>
    <p class="ui-hint">ใช้โควต้าเดิมที่ตั้งไว้ของแพทย์คนนั้น</p>
  <?php } ?>
</form>
<?php
echo ui_panel_close('dutyAddForm', 'เพิ่มเข้าเวร', $back);
if (!ui_is_modal()) {
    page_end();
}

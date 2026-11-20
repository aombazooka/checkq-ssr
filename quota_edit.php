<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// popup เพิ่ม/แก้ไขโควต้าของแพทย์ในคลินิกหนึ่ง — ตั้งครั้งเดียว ใช้ทุกวันที่ออกตรวจ
$user = require_permission('quota.edit');
$slots = cq_slots();
$id = (int)($_GET['id'] ?? 0);
$row = $id > 0 ? db_one('SELECT * FROM cq_plan WHERE id = ?', [$id]) : null;
if ($id > 0 && $row === null) {
    http_response_code(404);
    exit('ไม่พบรายการ');
}
$form = $row ?: ['doctor_code' => (string)($_GET['doctor'] ?? ''), 'clinic_code' => (string)($_GET['clinic'] ?? ''), 'room' => '', 'workdays' => '12345'];
$quota = $row ? (cq_plan_quotas([$id])[$id] ?? []) : [];
$doctors = db_all('SELECT doctor_code, name FROM cq_doctor WHERE active = 1 ORDER BY name');
$clinics = cq_clinics();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $form['doctor_code'] = trim((string)($_POST['doctor_code'] ?? ''));
    $form['clinic_code'] = trim((string)($_POST['clinic_code'] ?? ''));
    $form['room'] = mb_substr(trim((string)($_POST['room'] ?? '')), 0, 50);
    $days = array_intersect(array_map('strval', (array)($_POST['workdays'] ?? [])), ['1', '2', '3', '4', '5', '6', '7']);
    sort($days);
    $form['workdays'] = implode('', $days);
    foreach ($slots as $s) {
        $v = trim((string)($_POST['q'][$s['slot_id']] ?? '0'));
        $quota[(int)$s['slot_id']] = ctype_digit($v) ? (int)$v : -1;
    }

    if (db_one('SELECT 1 FROM cq_doctor WHERE doctor_code = ?', [$form['doctor_code']]) === null) {
        $errors[] = 'กรุณาเลือกแพทย์';
    }
    if (cq_clinic($form['clinic_code']) === null) {
        $errors[] = 'กรุณาเลือกคลินิก';
    }
    if ($form['workdays'] === '') {
        $errors[] = 'เลือกวันออกตรวจอย่างน้อย 1 วัน';
    }
    foreach ($quota as $q) {
        if ($q < 0 || $q > 300) {
            $errors[] = 'โควต้าต้องเป็นจำนวนเต็ม 0–300';
            break;
        }
    }
    if ($errors === [] && array_sum($quota) === 0) {
        $errors[] = 'ใส่โควต้าอย่างน้อย 1 ช่วงเวลา (ช่วงที่ไม่ออกตรวจใส่ 0)';
    }
    $dup = db_one('SELECT id FROM cq_plan WHERE doctor_code = ? AND clinic_code = ? AND id <> ?', [$form['doctor_code'], $form['clinic_code'], $id]);
    if ($dup) {
        $errors[] = 'แพทย์คนนี้มีโควต้าในคลินิกนี้แล้ว — แก้ไขรายการเดิมแทน';
    }

    if ($errors === []) {
        db()->beginTransaction();
        if ($row) {
            db_exec('UPDATE cq_plan SET doctor_code = ?, clinic_code = ?, room = ?, workdays = ?, updated_by = ? WHERE id = ?',
                [$form['doctor_code'], $form['clinic_code'], $form['room'], $form['workdays'], $user['id'], $id]);
        } else {
            db_exec('INSERT INTO cq_plan (doctor_code, clinic_code, room, workdays, updated_by) VALUES (?,?,?,?,?)',
                [$form['doctor_code'], $form['clinic_code'], $form['room'], $form['workdays'], $user['id']]);
            $id = (int)db()->lastInsertId();
        }
        db_exec('DELETE FROM cq_plan_quota WHERE plan_id = ?', [$id]);
        foreach ($quota as $sid => $q) {
            db_exec('INSERT INTO cq_plan_quota (plan_id, slot_id, quota) VALUES (?,?,?)', [$id, $sid, $q]);
        }
        db()->commit();
        ui_done('quota.php', 'บันทึกโควต้าแล้ว');
    }
    ui_fail($errors);
}

$selDays = str_split((string)$form['workdays']);
if (!ui_is_modal()) {
    page_start($row ? 'แก้ไขโควต้า' : 'เพิ่มโควต้าแพทย์', 'quota');
}
echo ui_panel_open($row ? 'แก้ไขโควต้าแพทย์' : 'เพิ่มโควต้าแพทย์', 'ตั้งครั้งเดียว ระบบใช้ค่านี้ทุกวันที่แพทย์ออกตรวจ', 'sliders', $errors);
?>
<form class="ui-form" id="quotaForm" method="post" action="quota_edit.php<?= $id ? '?id=' . $id : '' ?>" data-ui-ajax data-ui-dirty>
  <?= csrf_field() ?>
  <div class="ui-grid">
    <div class="ui-field"><label for="qeDoctor">แพทย์</label>
      <select class="ui-input" id="qeDoctor" name="doctor_code" required>
        <option value="">— เลือกแพทย์ —</option>
        <?php foreach ($doctors as $d) { ?>
          <option value="<?= e($d['doctor_code']) ?>"<?= $d['doctor_code'] === $form['doctor_code'] ? ' selected' : '' ?>><?= e($d['name']) ?></option>
        <?php } ?>
      </select></div>
    <div class="ui-field"><label for="qeClinic">คลินิก</label>
      <select class="ui-input" id="qeClinic" name="clinic_code" required>
        <option value="">— เลือกคลินิก —</option>
        <?php foreach ($clinics as $c) { ?>
          <option value="<?= e($c['clinic_code']) ?>"<?= $c['clinic_code'] === $form['clinic_code'] ? ' selected' : '' ?>><?= e($c['clinic_code'] . ' ' . $c['name']) ?></option>
        <?php } ?>
      </select></div>
    <div class="ui-field"><label for="qeRoom">ห้องตรวจ</label>
      <input class="ui-input" id="qeRoom" name="room" value="<?= e($form['room']) ?>" maxlength="50" placeholder="เช่น 3"></div>
  </div>

  <fieldset class="cq-fs">
    <legend class="ui-section">วันที่ออกตรวจประจำ</legend>
    <div class="cq-days">
      <?php foreach (CQ_WEEKDAYS as $n => $label) { ?>
        <label class="cq-day"><input type="checkbox" name="workdays[]" value="<?= $n ?>"<?= in_array((string)$n, $selDays, true) ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
      <?php } ?>
    </div>
  </fieldset>

  <fieldset class="cq-fs">
    <legend class="ui-section">จำนวนคนไข้ที่รับได้ต่อช่วงเวลา</legend>
    <div class="cq-fill">
      <label for="qeAll">ใส่เท่ากันทุกช่วง</label>
      <input class="ui-input" id="qeAll" type="number" min="0" max="300" inputmode="numeric">
      <button type="button" class="ui-btn" data-cq-fill="qeAll">ใส่ให้ทุกช่วง</button>
    </div>
    <div class="cq-qgrid">
      <?php foreach (cq_slots_all() as $s) { $sid = (int)$s['slot_id']; ?>
        <?php if ((int)$s['is_break']) { ?>
          <div class="ui-field cq-qbreak"><span class="cq-qlabel"><?= e(cq_slot_range($s)) ?></span>
            <div class="cq-qbreak-box" title="ช่วงพัก ไม่รับคนไข้ — คนไข้ที่มาช่วงนี้นับเข้าช่วงถัดไป">พัก</div></div>
        <?php } else { ?>
          <div class="ui-field"><label for="qeQ<?= $sid ?>"><?= e(cq_slot_range($s)) ?></label>
            <input class="ui-input cq-q" id="qeQ<?= $sid ?>" name="q[<?= $sid ?>]" type="number" min="0" max="300" inputmode="numeric" value="<?= (int)($quota[$sid] ?? 0) ?>"></div>
        <?php } ?>
      <?php } ?>
    </div>
    <p class="ui-hint">ช่วงที่ไม่ออกตรวจใส่ 0 · ช่วงพัก 12.00–12.59 น. ไม่รับคนไข้ คนที่มาช่วงพักนับเข้าช่วง 13.00 · ถ้าวันไหนลา/ประชุม/ออกช้า ปรับที่หน้า "เวรแพทย์วันนี้"</p>
  </fieldset>
</form>
<?php
echo ui_panel_close('quotaForm', $row ? 'บันทึก' : 'เพิ่ม', 'quota.php');
if (!ui_is_modal()) {
    echo asset_js('quota.js');
    page_end();
}

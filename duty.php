<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// เวรแพทย์รายวัน — ระบบสร้างให้จากวันออกตรวจประจำ เจ้าหน้าที่แค่กดเปลี่ยนสถานะ/ปิดช่วงที่ไม่อยู่ (บันทึกทันที)
$user = require_permission('duty.edit');
$date = cq_valid_date($_GET['date'] ?? null);
$rows = cq_duty_rows($date);
$slots = cq_slots();
$byClinic = [];
foreach ($rows as $r) {
    $byClinic[$r['clinic_code']]['name'] = $r['clinic_name'];
    $byClinic[$r['clinic_code']]['rows'][] = $r;
}
$onCount = count(array_filter($rows, static fn($r) => $r['status'] === 'on'));
$prev = date('Y-m-d', strtotime($date . ' -1 day'));
$next = date('Y-m-d', strtotime($date . ' +1 day'));
$isToday = $date === date('Y-m-d');

page_start('เวรแพทย์', 'duty', true);
?>
<div class="cq-head">
  <div>
    <div class="ui-eyebrow"><?= ui_icon('calendar', 16) ?><?= $isToday ? 'วันนี้' : 'วางแผนล่วงหน้า' ?></div>
    <h1 class="ui-page-title">เวรแพทย์ <?= e(cq_thai_date($date)) ?></h1>
    <p class="ui-page-sub">ออกตรวจ <b id="cqOnCount"><?= $onCount ?></b> จาก <?= count($rows) ?> รายการ · เริ่มต้นทุกคนเป็น "ไม่ออก" กด "ออกตรวจ" เฉพาะคนที่มา · บันทึกทันที ไม่ต้องกดบันทึก</p>
  </div>
  <div class="cq-actions">
    <nav class="cq-daynav" aria-label="เลือกวัน">
      <a class="ui-btn" href="?date=<?= e($prev) ?>" aria-label="วันก่อนหน้า">‹</a>
      <form method="get" class="cq-inline"><label class="cq-sr" for="dDate">วันที่</label>
        <input class="ui-input" type="date" id="dDate" name="date" value="<?= e($date) ?>" data-cq-autosubmit></form>
      <a class="ui-btn" href="?date=<?= e($next) ?>" aria-label="วันถัดไป">›</a>
      <?php if (!$isToday) { ?><a class="ui-btn ghost" href="duty.php">วันนี้</a><?php } ?>
    </nav>
    <a class="ui-btn primary" href="duty_add.php?date=<?= e($date) ?>" data-ui-popup><?= ui_icon('plus', 18) ?>เพิ่มแพทย์เข้าเวร</a>
  </div>
</div>

<?php if ($rows === []) { ?>
  <div class="ui-card cq-empty">
    <h2>วันนี้ยังไม่มีแพทย์ในเวร</h2>
    <p>กด "เพิ่มแพทย์เข้าเวร" ด้านบน<?= can('quota.edit') ? ' หรือตั้งวันออกตรวจประจำที่หน้า <a href="quota.php">โควต้าแพทย์</a> แล้วระบบจะใส่ให้ทุกวัน' : '' ?></p>
  </div>
<?php } ?>

<div id="cqDuty">
<?php foreach ($byClinic as $code => $g) { ?>
  <section class="cq-duty-clinic">
    <h2 class="cq-duty-h"><span class="cq-code"><?= e($code) ?></span> <?= e($g['name']) ?></h2>
    <?php foreach ($g['rows'] as $r) { ?>
      <article class="cq-duty-row s-<?= e($r['status']) ?>" data-duty="<?= (int)$r['duty_id'] ?>">
        <div class="cq-duty-doc">
          <b><?= e($r['doctor_name']) ?></b>
          <span class="cq-muted"><?= $r['room'] !== '' ? 'ห้อง ' . e($r['room']) : '' ?></span>
        </div>
        <div class="cq-seg" role="radiogroup" aria-label="สถานะของ <?= e($r['doctor_name']) ?>">
          <?php foreach (CQ_STATUS as $k => $label) { ?>
            <button type="button" role="radio" class="cq-seg-btn" data-status="<?= e($k) ?>" aria-checked="<?= $r['status'] === $k ? 'true' : 'false' ?>"><?= e($label) ?></button>
          <?php } ?>
        </div>
        <div class="cq-pills" aria-label="ช่วงเวลาที่ออกตรวจ (กดเพื่อปิด/เปิด)">
          <?php foreach ($slots as $s) {
              $sid = (int)$s['slot_id'];
              $q = (int)($r['quotas'][$sid] ?? 0);
              if ($q === 0) { continue; }
              $off = in_array($sid, $r['off'], true); ?>
            <button type="button" class="cq-pill" data-slot="<?= $sid ?>" aria-pressed="<?= $off ? 'false' : 'true' ?>"
                    title="<?= $off ? 'ปิดช่วงนี้อยู่ — กดเพื่อเปิด' : 'กดเพื่อปิดช่วงนี้ (เช่น ประชุม/ออกช้า)' ?>">
              <?= e($s['label']) ?> <small><?= $q ?></small></button>
          <?php } ?>
        </div>
        <label class="cq-note"><span class="cq-sr">หมายเหตุของ <?= e($r['doctor_name']) ?></span>
          <input class="ui-input" data-note value="<?= e($r['note']) ?>" maxlength="200" placeholder="หมายเหตุ เช่น เริ่ม 09.30"></label>
      </article>
    <?php } ?>
  </section>
<?php } ?>
</div>
<?php
echo asset_js('duty.js');
page_end();

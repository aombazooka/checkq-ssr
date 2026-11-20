<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// สรุปทั้งวัน: แพทย์ × ช่วงเวลา (มาแล้ว/โควต้า ตามเวลามาถึงจริง) + รอ/กำลังตรวจ/ตรวจแล้ว
$user = require_permission('summary.view');
$date = date('Y-m-d');
$slots = cq_slots();
$snap = cq_visits($date);
$rows = cq_duty_rows($date);

$stat = [];        // [clinic|doctor] => [slot_id => n, wait, exam, done, total]
$unassigned = [];  // [clinic] => n
foreach ($snap['visits'] as $v) {
    if ($v['doctor'] === null) {
        // ส่งเข้าคิวห้องใน HOSxP แล้ว (แต่ห้องยังไม่มีแพทย์ประจำ) ไม่นับเป็นรอเลือกแพทย์
        $unassigned[$v['clinic']] = ($unassigned[$v['clinic']] ?? 0) + ($v['status'] === 'wait' && ($v['room_id'] ?? null) === null ? 1 : 0);
        continue;
    }
    $k = $v['clinic'] . '|' . $v['doctor'];
    $sid = (int)(cq_slot_for($v['time'])['slot_id'] ?? 0);
    $stat[$k][$sid] = ($stat[$k][$sid] ?? 0) + 1;
    $stat[$k][$v['status']] = ($stat[$k][$v['status']] ?? 0) + 1;
    $stat[$k]['total'] = ($stat[$k]['total'] ?? 0) + 1;
}
$byClinic = [];
foreach ($rows as $r) {
    $byClinic[$r['clinic_code']]['name'] = $r['clinic_name'];
    $byClinic[$r['clinic_code']]['rows'][] = $r;
}
$nowSlot = (int)(cq_slot_for(date('H:i:s'))['slot_id'] ?? 0);

page_start('สรุปทั้งวัน', 'summary', true);
?>
<div class="cq-head">
  <div>
    <div class="ui-eyebrow"><?= ui_icon('chart', 16) ?><?= e(cq_thai_date($date)) ?></div>
    <h1 class="ui-page-title">สรุปทั้งวัน</h1>
    <p class="ui-page-sub">ช่องเวลา = มาแล้ว / โควต้า (นับตามเวลามาถึงจริง) · ข้อมูล HOSxP เวลา <?= e(date('H:i:s', (int)($snap['data_at'] ?? $snap['at']))) ?></p>
  </div>
  <div class="cq-actions"><a class="ui-btn" href="summary.php">โหลดใหม่</a></div>
</div>
<?php if (cq_is_demo()) { ?><div class="cq-banner">โหมดข้อมูลตัวอย่าง — ตัวเลขเป็นข้อมูลสมมติ</div><?php } ?>
<?php if ($snap['error']) { ?><div class="cq-banner err"><?= e($snap['error']) ?></div><?php } ?>
<?php if ($rows === []) { ?><div class="ui-card cq-empty"><h2>วันนี้ยังไม่มีแพทย์ในเวร</h2></div><?php } ?>

<?php foreach ($byClinic as $code => $g) {
    $tot = ['wait' => 0, 'exam' => 0, 'done' => 0, 'total' => 0, 'quota' => 0];
    $slotTot = []; ?>
<section class="cq-sum">
  <h2 class="cq-duty-h"><span class="cq-code"><?= e($code) ?></span> <?= e($g['name']) ?>
    <?php if (($unassigned[$code] ?? 0) > 0) { ?><span class="ui-chip amber">รอเลือกแพทย์ <?= (int)$unassigned[$code] ?></span><?php } ?></h2>
  <div class="ui-table-wrap cq-card-wrap">
    <table class="ui-table cq-sumtable">
      <thead><tr>
        <th>แพทย์</th>
        <?php foreach ($slots as $s) { ?><th class="num cq-th-slot<?= (int)$s['slot_id'] === $nowSlot ? ' now' : '' ?>"><?= e(cq_slot_range($s, false)) ?></th><?php } ?>
        <th class="num">รอตรวจ</th><th class="num">กำลังตรวจ</th><th class="num">ตรวจแล้ว</th><th class="num">รวม/โควต้า</th>
      </tr></thead>
      <tbody>
      <?php foreach ($g['rows'] as $r) {
          $st = $stat[$code . '|' . $r['doctor_code']] ?? [];
          if ($r['status'] === 'off' && $st === []) { continue; } // ไม่ออกตรวจและไม่มีคนไข้ — ไม่ต้องแสดง
          $dayQ = 0; ?>
        <tr class="<?= $r['status'] !== 'on' ? 'cq-off' : '' ?>">
          <td><b><?= e($r['doctor_name']) ?></b><?= $r['status'] !== 'on' ? ' <span class="ui-chip amber">' . e(CQ_STATUS[$r['status']]) . '</span>' : '' ?></td>
          <?php foreach ($slots as $s) {
              $sid = (int)$s['slot_id'];
              $off = $r['status'] !== 'on' || in_array($sid, $r['off'], true);
              $q = $off ? 0 : (int)($r['quotas'][$sid] ?? 0);
              $n = (int)($st[$sid] ?? 0);
              $dayQ += $q;
              $slotTot[$sid]['n'] = ($slotTot[$sid]['n'] ?? 0) + $n;
              $slotTot[$sid]['q'] = ($slotTot[$sid]['q'] ?? 0) + $q;
              $cls = $q === 0 ? ($n > 0 ? 'over' : 'cq-zero') : ($n > $q ? 'over' : ($n === $q ? 'full' : '')); ?>
            <td class="num <?= $cls ?><?= $sid === $nowSlot ? ' now' : '' ?>"><?= $q === 0 && $n === 0 ? '–' : $n . ' / ' . $q ?></td>
          <?php }
          foreach (['wait', 'exam', 'done', 'total'] as $k) { $tot[$k] += (int)($st[$k] ?? 0); }
          $tot['quota'] += $dayQ; ?>
          <td class="num"><b><?= (int)($st['wait'] ?? 0) ?></b></td>
          <td class="num"><?= (int)($st['exam'] ?? 0) ?></td>
          <td class="num"><?= (int)($st['done'] ?? 0) ?></td>
          <td class="num"><?= (int)($st['total'] ?? 0) ?> / <?= $dayQ ?></td>
        </tr>
      <?php } ?>
      </tbody>
      <tfoot><tr>
        <th>รวม</th>
        <?php foreach ($slots as $s) { $t = $slotTot[(int)$s['slot_id']] ?? ['n' => 0, 'q' => 0]; ?>
          <th class="num"><?= $t['n'] ?> / <?= $t['q'] ?></th>
        <?php } ?>
        <th class="num"><?= $tot['wait'] ?></th><th class="num"><?= $tot['exam'] ?></th><th class="num"><?= $tot['done'] ?></th>
        <th class="num"><?= $tot['total'] ?> / <?= $tot['quota'] ?></th>
      </tr></tfoot>
    </table>
  </div>
</section>
<?php } ?>
<?php
page_end();

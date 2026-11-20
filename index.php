<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// คิวรายแพทย์: แต่ละแพทย์ที่ลงตรวจ มีคนไข้คนไหนรออยู่บ้าง (เรียงตามเวลามาถึง) + คนไข้รอเลือกแพทย์
$user = require_permission('board.view');
$date = date('Y-m-d');
$pick = cq_pick_clinic($date);
$clinic = $pick['clinic'];
$board = $clinic !== '' ? cq_board($date, $clinic) : null;

page_start('คิวรายแพทย์', 'queue', true);
?>
<div class="cq-head">
  <div>
    <div class="ui-eyebrow"><?= ui_icon('calendar', 16) ?><?= e(cq_thai_date($date)) ?></div>
    <h1 class="ui-page-title">คิวรายแพทย์</h1>
    <p class="ui-page-sub">เลือกแพทย์ประจำห้องของวันนี้ แล้วรายชื่อคิวของแพทย์จะแสดงในห้อง · เลข "คิว" มาจาก HOSxP</p>
  </div>
  <div class="cq-live" aria-live="polite">
    <span class="cq-dot" aria-hidden="true"></span>
    <span class="cq-muted">ข้อมูล HOSxP เวลา <span id="cqAt"><?= e($board['fetched_at'] ?? '-') ?></span></span>
  </div>
</div>

<?php if (cq_is_demo()) { ?>
  <div class="cq-banner">โหมดข้อมูลตัวอย่าง — ตัวเลขเป็นข้อมูลสมมติ</div>
<?php } ?>
<div class="cq-banner err" id="cqError" hidden></div>

<?= clinic_tabs($pick) ?>

<?php if ($board !== null) { ?>
<div id="cqQueueBoard"
     data-board="<?= e(json_encode($board, JSON_UNESCAPED_UNICODE)) ?>"
     data-clinic="<?= e($clinic) ?>"
     data-refresh="<?= (int)cfg('refresh_seconds', 30) ?>"
     data-stale="<?= (int)cfg('stale_minutes', 180) ?>"
     data-edit="<?= can('duty.edit') ? '1' : '0' ?>">
  <div class="cq-stats2" id="cqQSum" aria-live="polite"></div>
  <div class="cq-slotbar" id="cqSlotBar"></div>
  <div class="cq-waitstrip" id="cqWaitStrip"></div>
  <p class="cq-stale-note" id="cqStaleNote" hidden></p>
  <div class="cq-roomgrid" id="cqRooms"></div>
  <div id="cqOthers"></div>
</div>
<?php } ?>
<?php
echo asset_js('queue.js');
page_end();

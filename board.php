<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// จุดคัดกรอง/ลงทะเบียน: ซ้าย = คนไข้ที่มาแล้วรอเลือกแพทย์ (กดเลือก HN) · ขวา = แพทย์ที่แนะนำ + การ์ดแพทย์
$user = require_permission('board.view');
$date = date('Y-m-d');
$pick = cq_pick_clinic($date);
$clinic = $pick['clinic'];
$board = $clinic !== '' ? cq_board($date, $clinic) : null;

page_start('คิวแพทย์ตอนนี้', 'board', true);
?>
<div class="cq-head">
  <div>
    <div class="ui-eyebrow"><?= ui_icon('calendar', 16) ?><?= e(cq_thai_date($date)) ?></div>
    <h1 class="ui-page-title">คิวแพทย์ตอนนี้</h1>
  </div>
  <div class="cq-live" aria-live="polite">
    <span class="cq-dot" aria-hidden="true"></span>
    <span>ช่วง <b id="cqSlot"><?= e($board['slot']['label'] ?? '-') ?></b></span>
    <span class="cq-muted">· ข้อมูล HOSxP เวลา <span id="cqAt"><?= e($board['fetched_at'] ?? '-') ?></span></span>
  </div>
</div>

<?php if (cq_is_demo()) { ?>
  <div class="cq-banner">โหมดข้อมูลตัวอย่าง — ยังไม่ได้เชื่อม HOSxP จริง ตัวเลขบนหน้านี้เป็นข้อมูลสมมติ</div>
<?php } ?>
<div class="cq-banner err" id="cqError" hidden></div>

<?= clinic_tabs($pick) ?>

<?php if ($board !== null) { ?>
<div class="cq-layout" id="cqBoard"
     data-board="<?= e(json_encode($board, JSON_UNESCAPED_UNICODE)) ?>"
     data-clinic="<?= e($clinic) ?>"
     data-refresh="<?= (int)cfg('refresh_seconds', 30) ?>"
     data-stale="<?= (int)cfg('stale_minutes', 180) ?>"
     data-quota="<?= can('quota.edit') ? '1' : '0' ?>">

  <aside class="cq-queue" aria-labelledby="cqQueueTitle">
    <div class="cq-queue-head">
      <h2 id="cqQueueTitle">คนไข้ <?= e($clinic) ?></h2>
      <div class="cq-seg cq-qtabs" role="tablist" aria-label="รายการคนไข้">
        <button type="button" role="tab" class="cq-seg-btn" id="cqTabQueue" aria-selected="true" aria-controls="cqList">
          รอเลือกแพทย์ <b id="cqQueueN">0</b></button>
        <button type="button" role="tab" class="cq-seg-btn" id="cqTabSent" aria-selected="false" aria-controls="cqList">
          รอตรวจ <b id="cqSentN">0</b></button>
      </div>
      <form class="cq-hn" id="cqHnForm" role="search">
        <label class="cq-sr" for="cqHn">ค้นหา HN หรือชื่อ</label>
        <div class="cq-hn-row">
          <input class="ui-input" id="cqHn" name="hn" autocomplete="off" placeholder="ค้นหา HN / ชื่อ · Enter = ดูนัดวันนี้">
          <button class="ui-btn" type="submit" aria-label="ดูนัดวันนี้ของ HN"><?= ui_icon('search', 18) ?></button>
        </div>
      </form>
      <div class="cq-hn-result" id="cqHnResult" aria-live="polite"></div>
    </div>
    <ul class="cq-list" id="cqList" role="listbox" aria-label="คนไข้รอเลือกแพทย์"></ul>
    <p class="cq-queue-foot cq-muted" id="cqQueueFoot"></p>
  </aside>

  <div class="cq-right">
    <section class="cq-hero" aria-labelledby="cqHeroTitle">
      <div class="cq-hero-main" id="cqHeroMain">
        <div class="cq-hero-label" id="cqHeroTitle">คนไข้ไม่ระบุแพทย์ · ส่งไปที่</div>
        <div class="cq-hero-name" id="cqPickName">-</div>
        <div class="cq-hero-why" id="cqPickWhy"></div>
        <a class="ui-btn primary cq-hero-btn" id="cqPickBtn" href="#cqList"><?= ui_icon('tick', 20) ?><span>ส่งไปหมอคนนี้</span></a>
      </div>
    </section>

    <div class="cq-legend">
      <span><i class="cq-sw g"></i>ยังรับได้</span><span><i class="cq-sw y"></i>ใกล้เต็ม 70%+</span>
      <span><i class="cq-sw r"></i>เต็มโควต้าช่วงนี้</span><span><i class="cq-sw x"></i>ลา / ประชุม / ปิดช่วงนี้</span>
    </div>
    <div class="cq-grid" id="cqCards"></div>
    <p class="cq-hidden-note" id="cqHiddenNote" hidden></p>
  </div>
</div>
<?php } ?>
<?php
echo asset_js('board.js');
page_end();

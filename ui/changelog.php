<?php
/*
 * หน้าอัปเดตระบบ — แต่ละวันระบบเปลี่ยนส่วนไหนบ้าง แสดงเฉพาะที่เกี่ยวกับสิทธิ์ของผู้ใช้ (ข้อมูลจาก changelog_data.php)
 */
require __DIR__ . '/ui.php';

$user = ui_user();
if ($user === null) {
    header('Location: ' . ui_cfg('login_url'));
    exit;
}
$log = ui_changelog($user);
$types = array(
    'new'     => array('เพิ่มใหม่', 'ok', 'plus-circle'),
    'improve' => array('ปรับปรุง', 'info', 'arrow-up-circle'),
    'fix'     => array('แก้ไข', 'amber', 'wrench'),
);
$count = array();
foreach ($log as $day) {
    foreach ($day['items'] as $item) {
        $count[$item[0]] = (isset($count[$item[0]]) ? $count[$item[0]] : 0) + 1;
    }
}

ui_hook_page_start('อัปเดตระบบ');
?>
<main class="ui-page">
  <div class="ui-eyebrow"><?= ui_icon('sparkles', 16) ?>อัปเดตระบบ</div>
  <h1 class="ui-page-title">มีอะไรใหม่ในระบบ <?= ui_e(ui_cfg('app_name')) ?></h1>
  <p class="ui-page-sub">บันทึกว่าแต่ละวันระบบปรับปรุงส่วนไหนบ้าง · แสดงเฉพาะส่วนที่เกี่ยวกับสิทธิ์ของคุณ</p>

  <?php if ($log === array()) { ?>
    <p class="ui-page-sub">ยังไม่มีบันทึกการอัปเดต</p>
  <?php } else { ?>
    <div class="ui-cl-filter" data-ui-cl-filter hidden>
      <button type="button" class="on" data-type="" aria-pressed="true">ทั้งหมด</button>
      <?php foreach ($types as $key => $type) { ?>
        <?php if (!empty($count[$key])) { ?>
          <button type="button" data-type="<?= $key ?>" aria-pressed="false"><?= ui_e($type[0]) ?> (<?= $count[$key] ?>)</button>
        <?php } ?>
      <?php } ?>
    </div>

    <div class="ui-cl">
      <?php foreach ($log as $i => $day) { ?>
        <section class="ui-cl-day<?= $i === 0 ? ' latest' : '' ?>" data-ui-cl-day>
          <div class="ui-cl-date"><?= ui_e(ui_thai_date($day['date'])) ?><?php if ($i === 0) { ?><span class="ui-chip ok">ล่าสุด</span><?php } ?></div>
          <h2 class="ui-cl-title"><?= ui_e($day['title']) ?></h2>
          <ul class="ui-cl-items">
            <?php foreach ($day['items'] as $item) {
                $type = isset($types[$item[0]]) ? $types[$item[0]] : $types['improve']; ?>
              <li data-type="<?= ui_e($item[0]) ?>">
                <span class="ui-chip <?= $type[1] ?>"><?= ui_icon($type[2], 16) ?><?= ui_e($type[0]) ?></span>
                <span class="area"><?= ui_e($item[1]) ?></span>
                <span class="txt"><?= ui_e($item[2]) ?><?php if ($item[3] === 'dev') { ?> <span class="dev">(บันทึกทางเทคนิค)</span><?php } ?></span>
              </li>
            <?php } ?>
          </ul>
        </section>
      <?php } ?>
    </div>
  <?php } ?>
</main>
<?php
ui_hook_page_end();

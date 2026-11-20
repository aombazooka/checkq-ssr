<?php
/*
 * นโยบายคุกกี้ — เปิดได้โดยไม่ต้องเข้าสู่ระบบ
 * รายการคุกกี้มาจาก ui_cookie_catalog() ชุดเดียวกับหน้าต่างตั้งค่า
 */
require __DIR__ . '/ui.php';

$catalog = ui_cookie_catalog();
$optional = (array)ui_cfg('cookies_optional', array());
$external = (array)ui_cfg('external_services', array());
$name = ui_cfg('app_name');
$number = 2;

ui_hook_page_start('นโยบายคุกกี้');
?>
<main class="ui-page ui-policy">
  <h1 class="ui-page-title">นโยบายคุกกี้</h1>
  <p class="ui-page-sub"><?= ui_e($name) ?> · ปรับปรุงล่าสุด <?= ui_e(ui_thai_date((string)ui_cfg('cookie_updated'))) ?> · ฉบับที่ <?= (int)ui_cfg('cookie_version', 1) ?></p>

  <div class="ui-policy-status">
    <div class="text"><b>การตั้งค่าคุกกี้ของคุณ</b><span data-ui-cc-status>ยังไม่ได้เลือก — ตอนนี้ใช้เฉพาะคุกกี้ที่จำเป็น</span></div>
    <button type="button" class="ui-btn primary" data-ui-cc-open hidden><?= ui_icon('sliders', 18) ?>ตั้งค่าคุกกี้</button>
  </div>

  <section class="ui-card">
    <h2>1. คุกกี้คืออะไร</h2>
    <p>คุกกี้ (Cookie) คือไฟล์ข้อความขนาดเล็กที่เว็บไซต์บันทึกไว้ในเบราว์เซอร์ของคุณ เพื่อจดจำข้อมูลบางอย่างระหว่างการใช้งาน เช่น สถานะการเข้าสู่ระบบ</p>
  </section>

  <section class="ui-card">
    <h2>2. คุกกี้ที่ระบบใช้</h2>
    <p>ทุกรายการเป็นของ <?= ui_e($name) ?> เอง ไม่มีการส่งต่อให้ผู้อื่น และไม่มีคุกกี้โฆษณา</p>
    <div class="ui-table-wrap">
      <table class="ui-table">
        <thead><tr><th scope="col">ประเภท</th><th scope="col">ชื่อ</th><th scope="col">วัตถุประสงค์</th><th scope="col">อายุ</th></tr></thead>
        <tbody>
        <?php foreach ($catalog as $cat) { ?>
          <?php foreach ($cat['items'] as $item) { ?>
            <tr><td><?= ui_e($cat['title']) ?></td><td><code><?= ui_e($item['name']) ?></code></td><td><?= ui_e($item['purpose']) ?></td><td><?= ui_e($item['expiry']) ?></td></tr>
          <?php } ?>
        <?php } ?>
        </tbody>
      </table>
    </div>
  </section>

  <?php if ($external !== array()) { $number++; ?>
    <section class="ui-card">
      <h2><?= $number ?>. บริการภายนอกที่หน้าเว็บเรียกใช้</h2>
      <p>หน้าเว็บโหลดไฟล์จาก <b><?= ui_e(implode(', ', $external)) ?></b> ผู้ให้บริการจะได้รับที่อยู่ IP และข้อมูลเบราว์เซอร์ตามปกติของการเชื่อมต่ออินเทอร์เน็ต</p>
    </section>
  <?php } ?>

  <section class="ui-card">
    <h2><?= ++$number ?>. ฐานทางกฎหมาย</h2>
    <p>คุกกี้ที่จำเป็นใช้โดยไม่ต้องขอความยินยอม เพราะระบบให้บริการไม่ได้หากไม่มีคุกกี้เหล่านี้ <?= $optional === array() ? 'ปัจจุบันระบบไม่มีคุกกี้ประเภทอื่น หากจะเพิ่มในอนาคต ระบบจะขอความยินยอมจากคุณก่อนใช้งาน' : 'คุกกี้ประเภทอื่นใช้เมื่อได้รับความยินยอมจากคุณเท่านั้น ตาม พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562' ?></p>
  </section>

  <section class="ui-card">
    <h2><?= ++$number ?>. การจัดการคุกกี้</h2>
    <p>ดูรายการคุกกี้ได้จากปุ่ม “ตั้งค่าคุกกี้” และลบหรือบล็อกคุกกี้ได้จากการตั้งค่าเบราว์เซอร์ แต่ถ้าบล็อกคุกกี้ที่จำเป็นจะเข้าสู่ระบบไม่ได้ ระบบจำคำตอบของคุณ <?= (int)ui_cfg('cookie_days', 365) ?> วันต่อเบราว์เซอร์ เมื่อครบกำหนดหรือนโยบายเปลี่ยนสาระสำคัญ ระบบจะถามใหม่</p>
  </section>

  <section class="ui-card">
    <h2><?= ++$number ?>. การเก็บหลักฐาน</h2>
    <p>ทุกครั้งที่คุณเลือก ระบบบันทึกวันเวลา ตัวเลือก ฉบับนโยบาย และรหัสอ้างอิงของเบราว์เซอร์ไว้ในบันทึกการใช้งาน ผู้ดูแลระบบเท่านั้นที่เข้าถึงได้</p>
  </section>

  <section class="ui-card">
    <h2><?= ++$number ?>. ติดต่อเรา</h2>
    <p>หากมีคำถามเกี่ยวกับนโยบายนี้ หรือต้องการใช้สิทธิ์ตามกฎหมายคุ้มครองข้อมูลส่วนบุคคล ติดต่อ <?= ui_e(ui_cfg('contact')) ?></p>
  </section>
</main>
<?php
ui_hook_page_end();

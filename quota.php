<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// หน้าตั้งค่าโควต้าแพทย์ (ผู้ดูแล)
$user = require_permission('quota.edit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)($_POST['do'] ?? '');
    if ($action === 'sync') {
        try {
            $n = cq_sync_doctors();
            ui_flash('ดึงรายชื่อแพทย์แล้ว ' . $n . ' คน — ยังไม่ได้ตั้งโควต้า ' . (int)(db_one('SELECT COUNT(*) n FROM cq_doctor d WHERE d.active = 1 AND NOT EXISTS (SELECT 1 FROM cq_plan p WHERE p.doctor_code = d.doctor_code)')['n'] ?? 0) . ' คน');
        } catch (Throwable $e) {
            error_log('[checkq] sync doctors: ' . $e->getMessage());
            ui_flash('ติดต่อ HOSxP ไม่ได้ ดึงรายชื่อแพทย์ไม่สำเร็จ', 'err');
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // เวรของแผนนี้ลบตาม (ON DELETE CASCADE) ส่วนบันทึกการส่งคนไข้ (cq_assign) ยังอยู่
        db_exec('DELETE FROM cq_plan WHERE id = ?', [$id]);
        ui_flash('ลบโควต้าแล้ว');
    }
    header('Location: quota.php');
    exit;
}

$slots = cq_slots_all(); // รวมช่วงพัก (แสดงเป็น "พัก")
$clinics = cq_clinics();
$fClinic = (string)($_GET['clinic'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = '1=1';
if ($fClinic !== '') {
    $where .= ' AND p.clinic_code = ?';
    $params[] = $fClinic;
}
if ($q !== '') {
    $where .= ' AND (d.name LIKE ? OR p.doctor_code LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$rows = db_all(
    "SELECT p.*, COALESCE(d.name, p.doctor_code) AS doctor_name, c.name AS clinic_name
     FROM cq_plan p JOIN cq_clinic c ON c.clinic_code = p.clinic_code LEFT JOIN cq_doctor d ON d.doctor_code = p.doctor_code
     WHERE $where ORDER BY doctor_name, c.sort_no",
    $params
);
$quotas = cq_plan_quotas(array_column($rows, 'id'));
$doctorCount = (int)(db_one('SELECT COUNT(*) n FROM cq_doctor WHERE active = 1')['n'] ?? 0);
// แพทย์ที่ดึงมาแล้วแต่ยังไม่มีโควต้าคลินิกไหนเลย — แสดงให้เห็นว่าดึงมาแล้ว และกดตั้งโควต้าได้ทันที
$noPlan = db_all('SELECT d.doctor_code, d.name FROM cq_doctor d
                  WHERE d.active = 1 AND NOT EXISTS (SELECT 1 FROM cq_plan p WHERE p.doctor_code = d.doctor_code)
                  ORDER BY d.name');

page_start('โควต้าแพทย์', 'quota', true);
?>
<div class="cq-head">
  <div>
    <h1 class="ui-page-title">โควต้าแพทย์</h1>
    <p class="ui-page-sub">ตั้งครั้งเดียว ระบบใช้ค่านี้ทุกวันที่แพทย์ออกตรวจ · แพทย์คนเดียวมีได้หลายคลินิก โควต้าแยกตามคลินิก</p>
  </div>
  <div class="cq-actions">
    <form method="post" class="cq-inline" data-ui-confirm="ดึงรายชื่อแพทย์จาก HOSxP?" data-ui-note="อัปเดตชื่อแพทย์ที่มีอยู่ และเพิ่มแพทย์ใหม่ ไม่ลบโควต้าที่ตั้งไว้">
      <?= csrf_field() ?><input type="hidden" name="do" value="sync">
      <button class="ui-btn"><?= ui_icon('download', 18) ?>ดึงรายชื่อแพทย์ (<?= $doctorCount ?>)</button>
    </form>
    <?php if ($doctorCount > 0) { ?>
      <a class="ui-btn primary" href="quota_edit.php<?= $fClinic !== '' ? '?clinic=' . e(rawurlencode($fClinic)) : '' ?>" data-ui-popup><?= ui_icon('plus', 18) ?>เพิ่มโควต้าแพทย์</a>
    <?php } ?>
  </div>
</div>

<?php if ($doctorCount === 0) { ?>
  <div class="ui-card cq-empty">
    <h2>เริ่มจากดึงรายชื่อแพทย์</h2>
    <p>กดปุ่ม "ดึงรายชื่อแพทย์" ด้านบนเพื่อนำรายชื่อแพทย์จาก HOSxP มาใช้ แล้วจึงเพิ่มโควต้าให้แต่ละคน</p>
  </div>
<?php } ?>

<?php if ($noPlan !== []) { ?>
  <section class="ui-card cq-noplan" aria-labelledby="noPlanH">
    <h2 id="noPlanH">แพทย์ที่ยังไม่ได้ตั้งโควต้า <span class="ui-chip amber"><?= count($noPlan) ?> คน</span></h2>
    <p>กดชื่อแพทย์เพื่อตั้งโควต้า — แพทย์ที่ยังไม่มีโควต้าจะไม่ขึ้นในหน้าคิวแพทย์</p>
    <div class="cq-noplan-list">
      <?php foreach ($noPlan as $d) { ?>
        <a class="ui-btn outline" href="quota_edit.php?doctor=<?= e(rawurlencode($d['doctor_code'])) ?><?= $fClinic !== '' ? '&clinic=' . e(rawurlencode($fClinic)) : '' ?>" data-ui-popup>
          <?= ui_icon('plus', 16) ?><?= e($d['name']) ?></a>
      <?php } ?>
    </div>
  </section>
<?php } ?>

<form class="ui-filters" method="get" role="search">
  <div class="ui-field wide"><label for="fq">ค้นหาแพทย์</label>
    <input class="ui-input" id="fq" name="q" type="search" value="<?= e($q) ?>" placeholder="พิมพ์ชื่อหรือรหัสแพทย์"></div>
  <div class="ui-field"><label for="fclinic">คลินิก</label>
    <select class="ui-input" id="fclinic" name="clinic">
      <option value="">ทุกคลินิก</option>
      <?php foreach ($clinics as $c) { ?>
        <option value="<?= e($c['clinic_code']) ?>"<?= $c['clinic_code'] === $fClinic ? ' selected' : '' ?>><?= e($c['clinic_code'] . ' ' . $c['name']) ?></option>
      <?php } ?>
    </select></div>
  <button class="ui-btn primary"><?= ui_icon('search', 18) ?>ค้นหา</button>
</form>
<p class="ui-count cq-gap"><?= count($rows) ?> รายการ</p>

<div class="ui-table-wrap cq-card-wrap">
  <table class="ui-table cq-qtable">
    <thead><tr>
      <th>แพทย์</th><th>คลินิก</th><th>ห้อง</th><th>วันออกตรวจ</th>
      <?php foreach ($slots as $s) { ?><th class="num cq-th-slot<?= (int)$s['is_break'] ? ' brk' : '' ?>"><?= e(str_replace('–', '–', cq_slot_range($s, false))) ?></th><?php } ?>
      <th class="num">รวม/วัน</th><th><span class="cq-sr">จัดการ</span></th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []) { ?>
      <tr><td colspan="<?= 6 + count($slots) ?>" class="cq-muted">ยังไม่มีโควต้า<?= $q !== '' || $fClinic !== '' ? 'ที่ตรงกับการค้นหา' : '' ?></td></tr>
    <?php } ?>
    <?php foreach ($rows as $r) {
        $qq = $quotas[(int)$r['id']] ?? []; ?>
      <tr>
        <td><b><?= e($r['doctor_name']) ?></b></td>
        <td><span class="cq-code"><?= e($r['clinic_code']) ?></span> <?= e($r['clinic_name']) ?></td>
        <td><?= e($r['room'] !== '' ? $r['room'] : '-') ?></td>
        <td><?= e(cq_workdays_label($r['workdays'])) ?></td>
        <?php foreach ($slots as $s) { $v = (int)($qq[(int)$s['slot_id']] ?? 0); ?>
          <?php if ((int)$s['is_break']) { ?><td class="num cq-brk">พัก</td><?php continue; } ?>
          <td class="num<?= $v === 0 ? ' cq-zero' : '' ?>"><?= $v === 0 ? '–' : $v ?></td>
        <?php } ?>
        <td class="num"><b><?= array_sum($qq) ?></b></td>
        <td class="cq-row-actions">
          <a class="ui-btn" href="quota_edit.php?id=<?= (int)$r['id'] ?>" data-ui-popup aria-label="แก้ไขโควต้าของ <?= e($r['doctor_name']) ?>">แก้ไข</a>
          <form method="post" class="cq-inline" data-ui-confirm="ลบโควต้าของ <?= e($r['doctor_name']) ?> ที่คลินิก <?= e($r['clinic_code']) ?>?" data-ui-note="เวรทุกวันของแพทย์คนนี้ในคลินิกนี้จะหายไปด้วย" data-ui-tone="danger">
            <?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <button class="ui-btn ghost" aria-label="ลบโควต้าของ <?= e($r['doctor_name']) ?>"><?= ui_icon('trash', 18) ?></button>
          </form>
        </td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
</div>
<?php
echo asset_js('quota.js');
page_end();

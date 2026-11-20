<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

// ตั้งค่าคลินิก:
// 1) ใช้ในระบบไหม / คุมโควต้าไหม (คลินิกที่ไม่คุม แนะนำแพทย์ตามคนรออย่างเดียว)
// 2) จุดซักประวัติ (ovst.main_dep) → คลินิก สำหรับคนไข้ที่ไม่มีนัด เพราะ HOSxP ใส่รหัสคลินิกให้เฉพาะ visit ที่มาตามนัด
$user = require_permission('quota.edit');
$clinics = cq_clinics(false);
$rooms = db_all('SELECT r.*, c.name AS clinic_name FROM cq_room r JOIN cq_clinic c ON c.clinic_code = r.clinic_code WHERE r.active = 1 ORDER BY c.sort_no, r.sort_no, r.room_id');
$deps = db_all('SELECT * FROM cq_dep_map ORDER BY clinic_code IS NULL, clinic_code, dep_code');
$codes = array_column($clinics, 'clinic_code');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $do = (string)($_POST['do'] ?? '');
    if ($do === 'clinics') {
        $active = (array)($_POST['active'] ?? []);
        $control = (array)($_POST['control'] ?? []);
        foreach ($codes as $code) {
            db_exec('UPDATE cq_clinic SET active = ?, control_quota = ? WHERE clinic_code = ?',
                [isset($active[$code]) ? 1 : 0, isset($control[$code]) ? 1 : 0, $code]);
        }
        ui_flash('บันทึกการตั้งค่าคลินิกแล้ว');
    } elseif ($do === 'rooms') {
        // แก้ชื่อ / ลบ (ปิดใช้) ห้องเดิม แล้วเพิ่มห้องใหม่
        foreach ($rooms as $r) {
            $rid = (int)$r['room_id'];
            $name = mb_substr(trim((string)($_POST['room_name'][$rid] ?? $r['name'])), 0, 50);
            if (isset($_POST['room_del'][$rid]) || $name === '') {
                db_exec('UPDATE cq_room SET active = 0 WHERE room_id = ?', [$rid]);
                db_exec('DELETE FROM cq_room_day WHERE room_id = ? AND work_date >= CURDATE()', [$rid]);
            } else {
                $dep = mb_substr(trim((string)($_POST['room_q'][$rid] ?? ($r['hosxp_qcode'] ?? ''))), 0, 10);
                db_exec('UPDATE cq_room SET name = ?, hosxp_qcode = ? WHERE room_id = ?', [$name, $dep === '' ? null : $dep, $rid]);
            }
        }
        $newName = mb_substr(trim((string)($_POST['new_room'] ?? '')), 0, 50);
        $newClinic = (string)($_POST['new_room_clinic'] ?? '');
        $newDep = mb_substr(trim((string)($_POST['new_room_q'] ?? '')), 0, 10);
        if ($newName !== '' && in_array($newClinic, $codes, true)) {
            $next = (int)(db_one('SELECT COALESCE(MAX(sort_no), 0) + 1 n FROM cq_room WHERE clinic_code = ?', [$newClinic])['n'] ?? 1);
            db_exec('INSERT INTO cq_room (clinic_code, name, hosxp_qcode, sort_no) VALUES (?,?,?,?)', [$newClinic, $newName, $newDep === '' ? null : $newDep, $next]);
        }
        ui_flash('บันทึกห้องตรวจแล้ว');
    } elseif ($do === 'deps') {
        foreach ((array)($_POST['map'] ?? []) as $dep => $clinic) {
            $clinic = (string)$clinic;
            db_exec('UPDATE cq_dep_map SET clinic_code = ? WHERE dep_code = ?',
                [in_array($clinic, $codes, true) ? $clinic : null, (string)$dep]);
        }
        $newCode = trim((string)($_POST['new_code'] ?? ''));
        if ($newCode !== '') {
            $newClinic = (string)($_POST['new_clinic'] ?? '');
            db_exec('INSERT INTO cq_dep_map (dep_code, dep_name, clinic_code) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE dep_name = VALUES(dep_name), clinic_code = VALUES(clinic_code)',
                [mb_substr($newCode, 0, 10), mb_substr(trim((string)($_POST['new_name'] ?? '')), 0, 150),
                 in_array($newClinic, $codes, true) ? $newClinic : null]);
        }
        ui_flash('บันทึกการจับคู่จุดซักประวัติแล้ว');
    }
    header('Location: settings.php');
    exit;
}

page_start('ตั้งค่าคลินิก', 'settings');
?>
<h1 class="ui-page-title">ตั้งค่าคลินิก</h1>
<p class="ui-page-sub">คลินิกที่ไม่คุมโควต้า (เช่น ยาเดิม, Tele, ยาไปรษณีย์) ระบบจะแนะนำแพทย์ที่คนรอน้อยที่สุดอย่างเดียว ไม่ดูโควต้า</p>

<form method="post" data-ui-dirty>
  <?= csrf_field() ?><input type="hidden" name="do" value="clinics">
  <div class="ui-table-wrap cq-card-wrap">
    <table class="ui-table">
      <thead><tr><th>คลินิก</th><th>ใช้ในระบบ</th><th>คุมโควต้าแพทย์</th></tr></thead>
      <tbody>
      <?php foreach ($clinics as $c) { $code = $c['clinic_code']; ?>
        <tr>
          <td><span class="cq-code"><?= e($code) ?></span> <?= e($c['name']) ?></td>
          <td><label class="ui-check"><input type="checkbox" name="active[<?= e($code) ?>]" value="1"<?= $c['active'] ? ' checked' : '' ?>>ใช้</label></td>
          <td><label class="ui-check"><input type="checkbox" name="control[<?= e($code) ?>]" value="1"<?= $c['control_quota'] ? ' checked' : '' ?>>คุมโควต้า</label></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <p class="cq-gap"><button class="ui-btn primary"><?= ui_icon('tick', 18) ?>บันทึกคลินิก</button></p>
</form>

<h2 class="cq-duty-h" id="rooms">ห้องตรวจ</h2>
<p class="ui-page-sub">ห้องตรวจของแต่ละคลินิก แสดงเป็นช่องในหน้า "คิวรายแพทย์" ให้เลือกแพทย์ประจำห้องรายวัน · ใส่เฉพาะเลข/ชื่อห้อง ระบบแสดงเป็น "ห้อง …" เอง<br>
  รหัสคิว HOSxP = รหัสในช่อง "แพทย์" ของหน้าจอคิว HOSxP (เช่น 0037 = คิวพบแพทย์ห้องตรวจ 2 (B)) — คนไข้ในคิวห้องนั้นจะนับเป็นคิวของแพทย์ประจำห้อง แม้มาจากคลินิกอื่น</p>
<form method="post" data-ui-dirty>
  <?= csrf_field() ?><input type="hidden" name="do" value="rooms">
  <div class="ui-table-wrap cq-card-wrap">
    <table class="ui-table">
      <thead><tr><th>คลินิก</th><th>เลข/ชื่อห้อง</th><th>รหัสคิว HOSxP</th><th>ลบ</th></tr></thead>
      <tbody>
      <?php foreach ($rooms as $r) { $rid = (int)$r['room_id']; ?>
        <tr>
          <td><span class="cq-code"><?= e($r['clinic_code']) ?></span> <?= e($r['clinic_name']) ?></td>
          <td><label class="cq-sr" for="rn<?= $rid ?>">ชื่อห้อง</label><input class="ui-input" id="rn<?= $rid ?>" name="room_name[<?= $rid ?>]" value="<?= e($r['name']) ?>" maxlength="50"></td>
          <td><label class="cq-sr" for="rd<?= $rid ?>">รหัสคิว HOSxP ของห้อง <?= e($r['name']) ?></label><input class="ui-input" id="rd<?= $rid ?>" name="room_q[<?= $rid ?>]" value="<?= e((string)($r['hosxp_qcode'] ?? '')) ?>" maxlength="10" placeholder="เช่น 0037" inputmode="numeric"></td>
          <td><label class="ui-check"><input type="checkbox" name="room_del[<?= $rid ?>]" value="1">ลบห้องนี้</label></td>
        </tr>
      <?php } ?>
        <tr>
          <td><label class="cq-sr" for="nrc">คลินิกของห้องใหม่</label>
            <select class="ui-input" id="nrc" name="new_room_clinic">
              <?php foreach ($clinics as $c) { ?><option value="<?= e($c['clinic_code']) ?>"><?= e($c['clinic_code'] . ' ' . $c['name']) ?></option><?php } ?>
            </select></td>
          <td><label class="cq-sr" for="nrn">เลข/ชื่อห้องใหม่</label><input class="ui-input" id="nrn" name="new_room" maxlength="50" placeholder="เพิ่มห้อง เช่น 6"></td>
          <td><label class="cq-sr" for="nrd">รหัสคิว HOSxP ของห้องใหม่</label><input class="ui-input" id="nrd" name="new_room_q" maxlength="10" placeholder="เช่น 0073" inputmode="numeric"></td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>
  <p class="cq-gap"><button class="ui-btn primary"><?= ui_icon('tick', 18) ?>บันทึกห้องตรวจ</button></p>
</form>

<h2 class="cq-duty-h" id="deps">คนไข้ไม่มีนัด: จุดซักประวัติ → คลินิก</h2>
<p class="ui-page-sub">HOSxP ใส่รหัสคลินิกให้เฉพาะคนไข้ที่มาตามนัด คนไข้ที่ไม่มีนัดระบบจึงดูจากจุดซักประวัติ (แผนก) ที่ลงทะเบียน
  เลือก "ไม่นับ" สำหรับบริการที่ไม่ต้องพบแพทย์จิตเวช</p>
<form method="post" data-ui-dirty>
  <?= csrf_field() ?><input type="hidden" name="do" value="deps">
  <div class="ui-table-wrap cq-card-wrap">
    <table class="ui-table">
      <thead><tr><th>รหัสแผนก</th><th>จุดซักประวัติ / แผนก</th><th>นับเข้าคลินิก</th></tr></thead>
      <tbody>
      <?php foreach ($deps as $d) { ?>
        <tr>
          <td><span class="cq-code"><?= e($d['dep_code']) ?></span></td>
          <td><?= e($d['dep_name']) ?></td>
          <td><label class="cq-sr" for="dm<?= e($d['dep_code']) ?>">คลินิกของ <?= e($d['dep_name']) ?></label>
            <select class="ui-input" id="dm<?= e($d['dep_code']) ?>" name="map[<?= e($d['dep_code']) ?>]">
              <option value="">— ไม่นับ —</option>
              <?php foreach ($clinics as $c) { ?>
                <option value="<?= e($c['clinic_code']) ?>"<?= $c['clinic_code'] === $d['clinic_code'] ? ' selected' : '' ?>><?= e($c['clinic_code'] . ' ' . $c['name']) ?></option>
              <?php } ?>
            </select></td>
        </tr>
      <?php } ?>
        <tr>
          <td><label class="cq-sr" for="newCode">รหัสแผนกใหม่</label><input class="ui-input" id="newCode" name="new_code" maxlength="10" placeholder="เพิ่มรหัส"></td>
          <td><label class="cq-sr" for="newName">ชื่อแผนกใหม่</label><input class="ui-input" id="newName" name="new_name" maxlength="150" placeholder="ชื่อแผนก"></td>
          <td><label class="cq-sr" for="newClinic">คลินิกของแผนกใหม่</label>
            <select class="ui-input" id="newClinic" name="new_clinic">
              <option value="">— ไม่นับ —</option>
              <?php foreach ($clinics as $c) { ?><option value="<?= e($c['clinic_code']) ?>"><?= e($c['clinic_code'] . ' ' . $c['name']) ?></option><?php } ?>
            </select></td>
        </tr>
      </tbody>
    </table>
  </div>
  <p class="cq-gap"><button class="ui-btn primary"><?= ui_icon('tick', 18) ?>บันทึกการจับคู่</button></p>
</form>
<?php
page_end();

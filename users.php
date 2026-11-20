<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

require_permission('users.view');

$users = db_all('SELECT * FROM users ORDER BY last_login_at DESC, id DESC');
$ssoAdmin = rtrim((string)sso_cfg('issuer'), '/') . '/admin/users.php';

page_start('ผู้ใช้', 'users');
?>
<h1 class="ui-page-title">ผู้ใช้ที่เคยเข้าระบบนี้</h1>
<p class="ui-page-sub">ข้อมูลผู้ใช้มาจาก SSO และอัปเดตทุกครั้งที่เข้าระบบ · การเพิ่ม/ถอนสิทธิ์ทำที่ <a href="<?= e($ssoAdmin) ?>">หน้าจัดการ SSO</a></p>
<div class="ui-table-wrap cq-card-wrap">
  <table class="ui-table">
    <thead><tr><th>ชื่อ</th><th>ผู้ใช้</th><th>รหัสพนักงาน</th><th>หน่วยงาน</th><th>เข้าล่าสุด</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u) { ?>
      <tr>
        <td><?= e($u['full_name']) ?></td>
        <td><code><?= e($u['username']) ?></code></td>
        <td><?= e($u['employee_code'] ?? '-') ?></td>
        <td><?= e($u['department'] ?? '-') ?></td>
        <td><?= e($u['last_login_at'] ? date('d/m/Y H:i', strtotime($u['last_login_at'])) : '-') ?></td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
</div>
<?php
page_end();

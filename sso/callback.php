<?php
declare(strict_types=1);

/*
 * [SSO] redirect_uri — SSO ส่งผู้ใช้กลับมาที่นี่พร้อม code
 * แลก token → ตรวจลายเซ็น → ผูกผู้ใช้ → สร้าง session แบบเดิม → ไปหน้าที่ตั้งใจไว้
 * ไฟล์นี้เหมือนกันทุกระบบ ห้ามแก้
 */

require __DIR__ . '/sso.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

sso_hook_start_session();
list($error, $next) = sso_complete_login();
if ($error === null) {
    header('Location: ' . $next);
    exit;
}

http_response_code(403);
$retry = sso_app_url((string)sso_cfg('login_page'));
$portal = rtrim((string)sso_cfg('issuer'), '/') . '/';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>เข้าสู่ระบบไม่สำเร็จ</title>
  <style>
    body { font-family: "Sarabun", "Tahoma", sans-serif; background: #f6f7f9; color: #1f2933; margin: 0; }
    main { max-width: 560px; margin: 64px auto; padding: 0 16px; }
    .card { background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
    h1 { font-size: 20px; color: #b42318; margin: 0 0 12px; }
    a { display: inline-block; margin: 16px 12px 0 0; color: #0b5cad; }
  </style>
</head>
<body>
<main>
  <div class="card">
    <h1>เข้าสู่ระบบไม่สำเร็จ</h1>
    <p><?= sso_e($error) ?></p>
    <a href="<?= sso_e($retry) ?>">ลองอีกครั้ง</a>
    <a href="<?= sso_e($portal) ?>">ไปหน้ารวมระบบ SSO</a>
  </div>
</main>
</body>
</html>

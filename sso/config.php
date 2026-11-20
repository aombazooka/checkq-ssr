<?php
/*
 * [SSO] ค่าตั้งการเชื่อม SSO ของระบบนี้ — client_secret อยู่ใน sso/config.local.php (ห้าม commit)
 * role ที่ระบบรู้จัก ฐานข้อมูล และ session มาจาก config.php ของระบบ
 */

$app = require __DIR__ . '/../config.php';
$roles = array_keys($app['permissions']);

$config = [
    'enabled'       => true,
    'issuer'        => 'http://localhost/sso',
    'client_id'     => 'checkq',
    'client_secret' => '',
    'app_url'       => 'http://localhost/checkq',
    'scope'         => 'openid profile email employee offline_access',
    'ca_file'       => '',

    'home'          => 'index.php',
    'login_page'    => 'login.php',

    // ผู้ใช้ต้องมีอย่างน้อยหนึ่ง role ที่อยู่ใน permissions ของ config.php
    'role_map'      => array_combine($roles, $roles),

    'revalidate_seconds' => 300,
    'fail_open'     => true,   // false = SSO ล่มตอนตรวจซ้ำแล้วให้ออกจากระบบ (ระบบที่ข้อมูลอ่อนไหวมาก)
    'require_mfa'   => false,  // true = ต้องยืนยัน 2 ชั้นที่ SSO มาแล้ว (เปิดได้เมื่อ SSO เปิดใช้ 2FA)

    'users' => ['table' => 'users', 'id' => 'id', 'sso_sub' => 'sso_sub'],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}
return $config;

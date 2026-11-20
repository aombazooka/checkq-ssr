<?php
declare(strict_types=1);
// หน้าคิวรายแพทย์ย้ายไปเป็นหน้าแรก (index.php) — คงไฟล์นี้ไว้ให้ลิงก์/บุ๊กมาร์กเดิมใช้ได้
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: index.php' . ($qs !== '' ? '?' . $qs : ''), true, 302);
exit;

<?php
declare(strict_types=1);

/*
 * ตรวจการเชื่อม HOSxP replica หลังใส่ค่าใน config.local.php — รันจาก command line เท่านั้น
 *   C:\xampp\php\php.exe C:\xampp\htdocs\checkq\tools\check_hosxp.php [YYYY-MM-DD]
 * แสดงเฉพาะจำนวน/สรุป ไม่แสดง HN หรือชื่อผู้ป่วย
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/checkq.php';
date_default_timezone_set('Asia/Bangkok');

$date = cq_valid_date($argv[1] ?? null);
$ok = true;
$say = static function (bool $pass, string $msg) use (&$ok): void {
    $ok = $ok && $pass;
    echo ($pass ? '[ OK ] ' : '[FAIL] ') . $msg . PHP_EOL;
};

echo "ตรวจ HOSxP สำหรับวันที่ $date" . PHP_EOL;
$say(!cq_is_demo(), 'hosxp.mode = ' . cfg('hosxp.mode', 'demo') . (cq_is_demo() ? ' (ยังเป็นโหมดตัวอย่าง ตั้งเป็น live ใน config.local.php)' : ''));

try {
    $t = microtime(true);
    $pdo = hosxp_db();
    $say(true, 'เชื่อมต่อ replica ได้ (' . round((microtime(true) - $t) * 1000) . ' ms) · ' . $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)
        . ' ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
} catch (Throwable $e) {
    $say(false, 'เชื่อมต่อ replica ไม่ได้: ' . $e->getMessage());
    exit(1);
}

// 1) visit วันนี้
try {
    $t = microtime(true);
    $stmt = $pdo->prepare(hosxp_sql('visits_today'));
    $stmt->execute([':d' => $date]);
    $raw = $stmt->fetchAll();
    $ms = round((microtime(true) - $t) * 1000);
    $say($ms < 1000, "Query visit: " . count($raw) . " แถว ใช้เวลา $ms ms" . ($ms >= 1000 ? ' (ช้ากว่า 1 วินาที ควรให้ DBA ดู index)' : ''));

    $map = cq_dep_map();
    $known = array_column(cq_clinics(), 'clinic_code');
    $per = [];
    $skipped = [];
    foreach ($raw as $r) {
        $v = cq_normalize_visit($r, $map);
        if ($v === null) {
            $dep = (string)($r['dep_code'] ?? '');
            $skipped[$dep] = ($skipped[$dep] ?? 0) + 1;
            continue;
        }
        $c = $v['clinic'];
        $per[$c] ??= ['wait' => 0, 'exam' => 0, 'done' => 0, 'no_doc' => 0];
        $per[$c][$v['status']]++;
        $per[$c]['no_doc'] += $v['doctor'] === null ? 1 : 0;
    }
    ksort($per);
    echo PHP_EOL . "  คลินิก   รอ  กำลังตรวจ  ตรวจแล้ว  ไม่ระบุแพทย์" . PHP_EOL;
    foreach ($per as $c => $n) {
        printf("  %-6s %4d %10d %9d %13d%s\n", $c, $n['wait'], $n['exam'], $n['done'], $n['no_doc'],
            in_array($c, $known, true) ? '' : '  ← ไม่มีในตาราง cq_clinic');
    }
    if ($skipped) {
        ksort($skipped);
        echo PHP_EOL . '  ไม่นับ (แผนกที่ตั้งเป็น "ไม่นับ" หรือยังไม่ได้จับคู่):' . PHP_EOL;
        foreach ($skipped as $dep => $n) {
            echo "    main_dep $dep: $n" . (array_key_exists($dep, $map) ? '' : '  ← ยังไม่ได้จับคู่ ตั้งที่หน้า "ตั้งค่าคลินิก"') . PHP_EOL;
        }
    }
    echo PHP_EOL;
} catch (Throwable $e) {
    $say(false, 'Query visit ผิดพลาด: ' . $e->getMessage());
}

// 2) นัดวันนี้ (ใช้ HN แรกของวันเป็นตัวอย่าง ไม่แสดง HN)
try {
    $hn = $raw[0]['hn'] ?? '';
    $stmt = $pdo->prepare(hosxp_sql('patient_today'));
    $stmt->execute([':d' => $date, ':hn' => $hn]);
    $say(true, 'Query นัดของ HN: รันได้ (' . count($stmt->fetchAll()) . ' นัดของคนไข้ตัวอย่าง)');
} catch (Throwable $e) {
    $say(false, 'Query นัดของ HN ผิดพลาด: ' . $e->getMessage());
}

// 3) รายชื่อแพทย์
try {
    $docs = hosxp_query('doctors', [':since' => hosxp_doctor_since(), ':since2' => hosxp_doctor_since()]);
    $unspec = array_filter($docs, static fn($d) => cq_doctor_or_null($d['doctor_code']) === null);
    $say(count($docs) > 0, 'Query รายชื่อแพทย์: ' . count($docs) . ' คน (ตัดรหัส "ไม่ระบุแพทย์" ออก ' . count($unspec) . ' รหัส)');
} catch (Throwable $e) {
    $say(false, 'Query รายชื่อแพทย์ผิดพลาด: ' . $e->getMessage());
}

echo PHP_EOL . ($ok ? 'พร้อมใช้งาน' : 'ยังมีข้อที่ต้องแก้') . PHP_EOL;
exit($ok ? 0 : 1);

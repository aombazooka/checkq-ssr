<?php
declare(strict_types=1);

// ส่วนหัว/ท้ายของทุกหน้า — ใช้ชุดหน้าจอมาตรฐาน (ui/) เมนู popup toast ท้ายหน้า
// $active = key ของเมนู, $wide = หน้ากว้าง (แดชบอร์ด/ตาราง)

function page_start(string $title, string $active = '', bool $wide = false): void
{
    ?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= e(csrf_token()) ?>">
  <title><?= e($title) ?> — <?= e(cfg('app_name')) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
  <?= ui_head() ?>
  <link href="assets/app.css?v=<?= e((string)@filemtime(__DIR__ . '/../assets/app.css')) ?>" rel="stylesheet">
</head>
<body class="ui-sticky">
<?= ui_nav($active) ?>
<main class="cq-main<?= $wide ? ' wide' : '' ?>">
<?php
}

function page_end(): void
{
    echo "</main>\n" . ui_footer() . ui_end() . "\n</body>\n</html>\n";
}

function asset_js(string $file): string
{
    return '<script src="assets/' . e($file) . '?v=' . e((string)@filemtime(__DIR__ . '/../assets/' . $file)) . '" defer></script>';
}

/** แท็บคลินิก — แสดงคลินิกที่มีแพทย์ในเวรหรือมีคนไข้รอ พร้อมตัวเลขรอตรวจ/รอเลือกแพทย์ */
function clinic_tabs(array $pick): string
{
    $out = '<nav class="cq-tabs" aria-label="เลือกคลินิก">';
    foreach ($pick['clinics'] as $c) {
        $cc = $c['clinic_code'];
        $cnt = $pick['counts'][$cc] ?? ['wait' => 0, 'unassigned' => 0];
        $on = $cc === $pick['clinic'];
        if (!isset($pick['on_duty'][$cc]) && !$on && $cnt['wait'] + $cnt['unassigned'] === 0) {
            continue;
        }
        $out .= '<a class="cq-tab' . ($on ? ' on' : '') . '" href="?clinic=' . e(rawurlencode($cc)) . '"' . ($on ? ' aria-current="page"' : '')
            . ' data-clinic="' . e($cc) . '"><span class="cq-tab-code">' . e($cc) . '</span>' . e($c['name'])
            . '<span class="cq-tab-n" title="รอตรวจ (มีแพทย์แล้ว)">' . (int)$cnt['wait'] . '</span>'
            . '<span class="cq-tab-u" title="มาแล้ว รอเลือกแพทย์"' . ($cnt['unassigned'] > 0 ? '' : ' hidden') . '>' . (int)$cnt['unassigned'] . '</span></a>';
    }
    return $out . '</nav>';
}

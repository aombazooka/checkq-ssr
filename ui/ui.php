<?php
/*
 * app-ui-kit — แกนกลางฝั่ง PHP (ห้ามแก้ อัปเดตด้วย install_ui.php --upgrade)
 * ส่วนที่ปรับต่อระบบอยู่ที่ ui/config.php, ui/hooks.php, ui/changelog_data.php, ui/assets/ui-theme.css
 * รองรับ PHP 7.2+ เพราะต้องใช้กับระบบเก่าได้ — ห้ามใช้ fn(), match, str_contains ฯลฯ
 *
 * หน้าเว็บใช้: ui_head() ใน <head> · ui_nav() ต่อจาก <body> · ui_footer() ตรงท้ายหน้า · ui_end() ก่อน </body>
 */

const UI_KIT_VERSION = '2';

// เก็บใน $GLOBALS เพราะระบบอาจ require ไฟล์นี้จากในฟังก์ชัน
$GLOBALS['uiConfig'] = require __DIR__ . '/config.php';
if (!empty($GLOBALS['uiConfig']['bootstrap'])) {
    require_once $GLOBALS['uiConfig']['bootstrap'];
}
require_once __DIR__ . '/hooks.php';

function ui_cfg($key, $default = null)
{
    return array_key_exists($key, $GLOBALS['uiConfig']) ? $GLOBALS['uiConfig'][$key] : $default;
}

function ui_e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL ภายใต้โฟลเดอร์ ui/ เช่น ui_url('changelog.php') */
function ui_url($path = '')
{
    return rtrim((string)ui_cfg('base_url', ''), '/') . '/ui/' . ltrim($path, '/');
}

function ui_icon($name, $size = 20)
{
    static $paths = array(
        'mark'      => '<circle cx="8.5" cy="12" r="4.5"/><circle cx="15.5" cy="12" r="4.5"/>',
        'alert'     => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5v.01"/>',
        'trash'     => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v5"/><path d="M14 11v5"/>',
        'x'         => '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
        'tick'      => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'plus'      => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>',
        'download'  => '<path d="M12 3v12"/><path d="M7 11l5 5 5-5"/><path d="M4 20h16"/>',
        'user-plus' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M19 8v6"/><path d="M16 11h6"/>',
        'sparkles'  => '<path d="M12 4l1.8 5.2L19 11l-5.2 1.8L12 18l-1.8-5.2L5 11l5.2-1.8z"/><path d="M19 3v4"/><path d="M17 5h4"/>',
        'plus-circle'     => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8"/><path d="M8 12h8"/>',
        'arrow-up-circle' => '<circle cx="12" cy="12" r="9"/><path d="M12 16V8"/><path d="M8.5 11.5L12 8l3.5 3.5"/>',
        'wrench'    => '<path d="M14.5 6.5a4 4 0 0 1 5-1.2l-2.7 2.7 1.7 1.7 2.7-2.7a4 4 0 0 1-5.4 5.1L8 19.8a1.9 1.9 0 0 1-2.8-2.8l7.7-7.7a4 4 0 0 1 1.6-2.8z"/>',
        'sliders'   => '<path d="M4 7h9"/><path d="M17 7h3"/><path d="M4 17h3"/><path d="M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
        'shield'    => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'home'      => '<path d="M4 11l8-7 8 7"/><path d="M6 9.5V20h12V9.5"/><path d="M10 20v-6h4v6"/>',
        'users'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.8a3.5 3.5 0 0 1 0 6.4"/><path d="M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
        'chart'     => '<path d="M4 20h16"/><path d="M7 20v-6"/><path d="M12 20V6"/><path d="M17 20v-9"/>',
        'calendar'  => '<rect x="4" y="5.5" width="16" height="15" rx="2"/><path d="M4 10h16"/><path d="M8.5 3v4"/><path d="M15.5 3v4"/>',
        'book'      => '<path d="M12 7c-2-2-5-2.5-8-2v14c3-.5 6 0 8 2 2-2 5-2.5 8-2V5c-3-.5-6 0-8 2z"/><path d="M12 7v14"/>',
        'file'      => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6"/><path d="M9 16h6"/>',
        'menu'      => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
        'chevron-down' => '<path d="M6 9.5l6 6 6-6"/>',
        'logout'    => '<path d="M10 4H5v16h5"/><path d="M15 8l4 4-4 4"/><path d="M19 12H10"/>',
    );
    return '<svg width="' . (int)$size . '" height="' . (int)$size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . (isset($paths[$name]) ? $paths[$name] : '') . '</svg>';
}

/** ใส่ใน <head> — ไม่มีสคริปต์ inline จึงใช้กับ CSP script-src 'self' ได้ */
function ui_head()
{
    $v = '?v=' . UI_KIT_VERSION;
    $keepAlive = (int)ui_cfg('keepalive_minutes', 0);
    return '<link href="' . ui_e(ui_url('assets/ui-theme.css')) . $v . '" rel="stylesheet">'
        . '<link href="' . ui_e(ui_url('assets/ui.css')) . $v . '" rel="stylesheet">'
        . ($keepAlive > 0 && ui_user() !== null ? '<meta name="ui-keepalive" content="' . ui_e(ui_url('ping.php')) . '" data-minutes="' . $keepAlive . '">' : '')
        . (ui_cfg('native_dialogs', false) ? '<meta name="ui-native-dialogs" content="1">' : '')
        . '<script src="' . ui_e(ui_url('assets/ui.js')) . $v . '" defer></script>'
        . '<script src="' . ui_e(ui_url('assets/cookie-consent.js')) . $v . '" defer></script>';
}

/** ใส่ก่อน </body> — กล่อง popup ที่ทุกหน้าใช้ร่วมกัน และค่าของแบนเนอร์คุกกี้ */
function ui_end()
{
    return '<div id="uiCookieConfig" hidden data-config="' . ui_e(json_encode(ui_cookie_config(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"></div>'
        . ui_flash_tag()
        . '<dialog class="ui-dlg" id="uiRemote"></dialog>'
        . '<dialog class="ui-dlg" id="uiDetail" aria-labelledby="uiDetailTitle">'
        . '<div class="ui-dlg-head"><h2 id="uiDetailTitle"></h2><button type="button" class="ui-dlg-x" data-ui-close aria-label="ปิด">' . ui_icon('x') . '</button></div>'
        . '<div class="ui-dlg-body"><dl class="ui-dl" id="uiDetailList"></dl></div>'
        . '<div class="ui-dlg-foot"><button type="button" class="ui-btn" data-ui-close>ปิด</button></div></dialog>';
}

/* ---------- ผู้ใช้ / CSRF / บันทึก (ผ่าน hooks.php) ---------- */

/** null = ยังไม่เข้าสู่ระบบ · ['id','username','is_admin'] */
function ui_user()
{
    return ui_hook_user();
}

/** โทเคน CSRF — ถ้า session ยังไม่เริ่มและส่ง HTML ไปแล้ว คืนค่าว่าง (แบนเนอร์ยังทำงาน แต่จะไม่บันทึกหลักฐาน) */
function ui_csrf()
{
    if (session_status() !== PHP_SESSION_ACTIVE && headers_sent()) {
        return '';
    }
    return ui_hook_csrf_token();
}

/* ---------- ข้อความแจ้งผล ---------- */

/** เก็บข้อความไว้แสดงเป็น toast ในหน้าถัดไป (อยู่รอดข้าม redirect) — ชนิด: ok | err | warn | info */
function ui_flash($message, $type = 'ok')
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['ui_flash'][] = array((string)$type, (string)$message);
    }
}

/** ส่งข้อความที่ค้างใน session ให้ assets/ui.js แล้วล้างทิ้ง จึงไม่ขึ้นซ้ำเมื่อโหลดหน้าใหม่ */
function ui_flash_tag()
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['ui_flash'])) {
        return '';
    }
    $items = array_values($_SESSION['ui_flash']);
    unset($_SESSION['ui_flash']);
    return '<div id="uiFlash" hidden data-flash="' . ui_e(json_encode($items, JSON_UNESCAPED_UNICODE)) . '"></div>';
}

/* ---------- ฟอร์มใน popup ---------- */

/** เปิดเนื้อหาเป็น popup อยู่ไหม — ?modal=1 = ส่งเฉพาะ panel ไม่มีหัว/ท้ายหน้า */
function ui_is_modal()
{
    return !empty($_GET['modal']);
}

/** ฟอร์มใน popup ส่งผ่าน fetch จึงต้องตอบ JSON แทนการ redirect */
function ui_wants_json()
{
    return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
}

function ui_json(array $data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** จบการบันทึก: popup ให้ JS พาไปหน้าถัดไป ฟอร์มปกติ redirect · ใส่ $message เพื่อแสดง toast ในหน้าถัดไป */
function ui_done($location, $message = '')
{
    if ($message !== '') {
        ui_flash($message);
    }
    if (ui_wants_json()) {
        ui_json(array('ok' => true, 'redirect' => $location));
    }
    header('Location: ' . $location);
    exit;
}

/** ฟอร์มใน popup ไม่ผ่าน: ส่งข้อความกลับไปแสดงใน popup ค่าที่กรอกไว้จึงไม่หาย (ฟอร์มปกติ ให้หน้าเดิมแสดงเอง) */
function ui_fail(array $errors)
{
    if ($errors !== array() && ui_wants_json()) {
        ui_json(array('ok' => false, 'errors' => array_values($errors)), 422);
    }
}

/** ส่วนหัว panel — ใช้คู่กับ ui_panel_close() ได้ทั้งใน popup และเปิดเป็นหน้าเต็ม */
function ui_panel_open($title, $sub = '', $iconName = '', array $errors = array())
{
    $modal = ui_is_modal();
    $html = ($modal ? '' : '<section class="ui-page-panel">') . '<div class="ui-panel"><div class="ui-dlg-head"><div>'
        . '<h2>' . ($iconName !== '' ? ui_icon($iconName, 22) : '') . ui_e($title) . '</h2>'
        . ($sub !== '' ? '<p class="ui-dlg-sub">' . ui_e($sub) . '</p>' : '') . '</div>'
        . ($modal ? '<button type="button" class="ui-dlg-x" data-ui-close aria-label="ปิด">' . ui_icon('x') . '</button>' : '') . '</div>'
        . '<div class="ui-dlg-body"><div class="ui-alert" role="alert" data-ui-errors' . ($errors === array() ? ' hidden' : '') . '>' . ui_icon('alert')
        . '<div class="ui-pre" data-ui-errors-text>' . ui_e(implode("\n", $errors)) . '</div></div>';
    return $html;
}

/** ส่วนท้าย panel — ปุ่มยกเลิก + ปุ่มส่งฟอร์ม $formId */
function ui_panel_close($formId, $submitLabel, $cancelHref)
{
    return '</div><div class="ui-dlg-foot">'
        . (ui_is_modal()
            ? '<button type="button" class="ui-btn" data-ui-close>ยกเลิก</button>'
            : '<a class="ui-btn" href="' . ui_e($cancelHref) . '">ยกเลิก</a>')
        . '<button type="submit" class="ui-btn primary" form="' . ui_e($formId) . '">' . ui_icon('tick', 18) . ui_e($submitLabel) . '</button>'
        . '</div></div>' . (ui_is_modal() ? '' : '</section>');
}

/** ค่าของ data-ui-detail สำหรับปุ่มเปิด popup รายละเอียด — $rows = [[ป้าย, ค่า], ...] */
function ui_detail_attr($title, array $rows)
{
    return ui_e(json_encode(array('title' => $title, 'rows' => $rows), JSON_UNESCAPED_UNICODE));
}

/* ---------- ส่งออก Excel ---------- */

/**
 * ส่ง CSV ที่ Excel เปิดแล้วอ่านภาษาไทยถูก และจบการทำงาน — เรียกก่อนส่ง HTML
 * ใส่ ' นำหน้าค่าที่ขึ้นต้นด้วย = + - @ กัน Excel ตีความเป็นสูตร (ข้อมูลมาจากผู้ใช้)
 * ทุกการส่งออกถูกบันทึก เพราะมักมีข้อมูลบุคคล
 */
function ui_csv_download($filename, array $headers, array $rows, $note = '')
{
    ui_hook_audit('ui_exported', $filename . ' · ' . count($rows) . ' แถว' . ($note !== '' ? ' · ' . $note : ''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ',', '"', '\\'); // ระบุ escape เอง: PHP 8.4 เตือนถ้าใช้ค่าเริ่มต้น
    foreach ($rows as $row) {
        foreach ($row as $i => $value) {
            $value = (string)$value;
            $row[$i] = preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
        }
        fputcsv($out, array_values($row), ',', '"', '\\');
    }
    exit;
}

/* ---------- แถบเมนู ---------- */

/** ที่อยู่ของลิงก์เมนู: ค่าที่ขึ้นต้นด้วย / หรือ http ใช้ตามเดิม นอกนั้นต่อจาก base_url */
function ui_app_url($href)
{
    if ($href === '' || $href[0] === '/' || preg_match('#^https?://#', $href)) {
        return $href;
    }
    return rtrim((string)ui_cfg('base_url', ''), '/') . '/' . $href;
}

function ui_nav_link(array $item, $active, $class = 'ui-nav-link')
{
    $on = isset($item['key']) && $item['key'] === $active;
    return '<a class="' . $class . ($on ? ' active' : '') . '" href="' . ui_e(ui_app_url($item['href'])) . '" title="' . ui_e($item['label']) . '"' . ($on ? ' aria-current="page"' : '') . '>'
        . (!empty($item['icon']) ? ui_icon($item['icon'], 18) : '') . '<span class="lb">' . ui_e($item['label']) . '</span></a>';
}

/** ปุ่มออกจากระบบ: ระบบที่รับเฉพาะ POST (เช่น sso-connect) ส่ง logout_fields มาเป็นช่องซ่อน */
function ui_nav_logout(array $nav)
{
    if (empty($nav['logout'])) {
        return '';
    }
    $url = ui_e(ui_app_url($nav['logout']));
    if (!isset($nav['logout_fields'])) {
        return '<a class="ui-nav-out" href="' . $url . '" title="ออกจากระบบ" aria-label="ออกจากระบบ">' . ui_icon('logout', 18) . '</a>';
    }
    $html = '<form class="ui-nav-outform" method="post" action="' . $url . '">';
    foreach ($nav['logout_fields'] as $name => $value) {
        $html .= '<input type="hidden" name="' . ui_e($name) . '" value="' . ui_e($value) . '">';
    }
    return $html . '<button type="submit" class="ui-nav-out" title="ออกจากระบบ" aria-label="ออกจากระบบ">' . ui_icon('logout', 18) . '</button></form>';
}

/**
 * แถบเมนูบน + เมนูมือถือ ใส่ต่อจาก <body> — รายการมาจาก ui_hook_nav() ซึ่งกรองตามสิทธิ์แล้ว
 * ใช้ <details> จึงเปิดเมนูได้แม้ไม่มี JavaScript · $active = key ของเมนูหน้านี้
 */
function ui_nav($active = '')
{
    $nav = function_exists('ui_hook_nav') ? ui_hook_nav() : array();
    $items = isset($nav['items']) ? $nav['items'] : array();
    $user = ui_user();
    $name = $user !== null ? trim((string)$user['username']) : '';
    $logo = (string)ui_cfg('logo_text', '');
    $main = '';
    $mobile = '';
    foreach ($items as $item) {
        if (empty($item['items'])) {
            $main .= ui_nav_link($item, $active);
            $mobile .= ui_nav_link($item, $active);
            continue;
        }
        $keys = array();
        $menu = '';
        foreach ($item['items'] as $sub) {
            $menu .= ui_nav_link($sub, $active, 'ui-nav-item');
            $mobile .= ($keys === array() ? '<div class="ui-nav-group">' . ui_e($item['label']) . '</div>' : '') . ui_nav_link($sub, $active);
            $keys[] = isset($sub['key']) ? $sub['key'] : '';
        }
        $main .= '<details class="ui-nav-drop"><summary class="ui-nav-link' . (in_array($active, $keys, true) ? ' active' : '') . '" title="' . ui_e($item['label']) . '">'
            . (!empty($item['icon']) ? ui_icon($item['icon'], 18) : '') . '<span class="lb">' . ui_e($item['label']) . '</span>' . ui_icon('chevron-down', 14) . '</summary>'
            . '<div class="ui-nav-menu">' . $menu . '</div></details>';
    }
    $initial = preg_match('/./us', $name, $first) ? $first[0] : '?'; // ไม่ใช้ mb_substr เพราะระบบเก่าอาจไม่เปิด mbstring
    $who = '<span class="who"><b>' . ui_e($name) . '</b>' . (!empty($nav['role']) ? '<small>' . ui_e($nav['role']) . '</small>' : '') . '</span>'
        . '<span class="av" aria-hidden="true">' . ui_e($initial) . '</span>';
    return '<header class="ui-nav" data-ui-nav><div class="ui-nav-inner">'
        . '<a class="ui-nav-brand" href="' . ui_e(ui_app_url(isset($nav['home']) ? $nav['home'] : 'index.php')) . '"><span class="ui-nav-logo" aria-hidden="true">' . ($logo !== '' ? ui_e($logo) : ui_icon('mark', 20)) . '</span>'
        . '<span class="ui-nav-name">' . ui_e(ui_cfg('app_name', '')) . '<small>' . ui_e(ui_cfg('org_name', '')) . '</small></span></a>'
        . ($main !== '' ? '<nav class="ui-nav-main" aria-label="เมนูหลัก">' . $main . '</nav>' : '')
        . '<span class="ui-nav-spacer"></span>'
        . ($user === null ? '' : (!empty($nav['account'])
            ? '<a class="ui-nav-user" href="' . ui_e(ui_app_url($nav['account'])) . '" title="บัญชีของฉัน">' . $who . '</a>'
            : '<span class="ui-nav-user">' . $who . '</span>') . ui_nav_logout($nav))
        . ($mobile !== '' ? '<details class="ui-nav-burger"><summary aria-label="เมนู">' . ui_icon('menu', 22) . '</summary><nav class="ui-nav-mobile" aria-label="เมนูหลัก">' . $mobile . '</nav></details>' : '')
        . '</div></header>';
}

/* ---------- วันที่ / อัปเดตระบบ / ท้ายหน้า ---------- */

/** วันที่แบบไทยย่อ เช่น 30 ต.ค. 2569 */
function ui_thai_date($date)
{
    $months = array('ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.');
    $ts = strtotime($date);
    return date('j', $ts) . ' ' . $months[(int)date('n', $ts) - 1] . ' ' . ((int)date('Y', $ts) + 543);
}

/** บันทึกการอัปเดตที่ผู้ใช้นี้เห็น — ตัดรายการที่ไม่มีสิทธิ์ และวันที่ที่ไม่เหลือรายการ */
function ui_changelog($user)
{
    $admin = !empty($user['is_admin']);
    $out = array();
    foreach (require __DIR__ . '/changelog_data.php' as $day) {
        $items = array();
        foreach ($day['items'] as $item) {
            if ($item[3] === 'all' || $admin) {
                $items[] = $item;
            }
        }
        if ($items !== array()) {
            $day['items'] = $items;
            $out[] = $day;
        }
    }
    usort($out, function ($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
    return $out;
}

function ui_cookie_links()
{
    return '<span class="ui-cc-links"><a href="' . ui_e(ui_url('cookie_policy.php')) . '">นโยบายคุกกี้</a>'
        . '<button type="button" data-ui-cc-open hidden>ตั้งค่าคุกกี้</button></span>';
}

/**
 * ท้ายหน้า: © ปี + ชื่อระบบ + ผู้พัฒนา + อัปเดตล่าสุด (ซ้าย) · ลิงก์คุกกี้ (ขวา)
 * ป้าย "ใหม่" แสดง 7 วันหลังอัปเดต นับจากวันที่แทนการจำในเบราว์เซอร์ จึงไม่ต้องขอความยินยอมเพิ่ม
 */
function ui_footer()
{
    $now = (int)date('Y');
    $since = min((int)ui_cfg('since', $now), $now);
    $user = ui_user();
    $log = $user !== null ? ui_changelog($user) : array();
    $latest = $log !== array() ? $log[0]['date'] : null;
    $logo = (string)ui_cfg('logo_text', '');
    $name = trim(ui_cfg('app_name', '') . ' ' . ui_cfg('org_name', ''));
    $dev = (string)ui_cfg('developer', '');
    return '<footer class="ui-foot"><div class="ui-foot-left"><span class="ui-foot-logo" aria-hidden="true">' . ($logo !== '' ? ui_e($logo) : ui_icon('mark', 18)) . '</span>'
        . '<div class="ui-foot-text">© ' . ($since < $now ? $since . '–' . $now : $now) . ' <b>' . ui_e($name) . '</b>'
        . ($dev !== '' ? '<span class="ui-foot-dot">·</span><span class="ui-foot-dev">พัฒนาโดย ' . ui_e($dev) . '</span>' : '')
        . ($latest !== null
            ? '<span class="ui-foot-dot">·</span><a class="ui-foot-upd" href="' . ui_e(ui_url('changelog.php')) . '">' . ui_icon('sparkles', 16) . 'อัปเดตล่าสุด ' . ui_e(ui_thai_date($latest)) . '</a>'
                . (strtotime($latest) > time() - 7 * 86400 ? '<span class="ui-foot-new">ใหม่</span>' : '')
            : '')
        . '</div></div>' . ui_cookie_links() . '</footer>';
}

/* ---------- คุกกี้ ---------- */

/**
 * รายการคุกกี้ที่ระบบใช้จริง — แหล่งเดียวของแบนเนอร์ หน้าต่างตั้งค่า และหน้านโยบาย
 * ถ้า config มี cookies_optional (ประเภทที่ไม่จำเป็น) แบนเนอร์จะเปลี่ยนจาก "รับทราบ" เป็นยอมรับ/ปฏิเสธเอง
 */
function ui_cookie_catalog()
{
    $days = (int)ui_cfg('cookie_days', 365);
    $necessary = array(
        array('name' => session_name(), 'kind' => 'คุกกี้', 'purpose' => 'รหัสเซสชันการเข้าสู่ระบบ + โทเคนป้องกัน CSRF', 'expiry' => 'เมื่อปิดเบราว์เซอร์'),
        array('name' => 'ui_consent', 'kind' => 'คุกกี้', 'purpose' => 'จำคำตอบเรื่องคุกกี้ของคุณ เพื่อไม่ถามซ้ำ', 'expiry' => $days . ' วัน'),
    );
    $catalog = array('necessary' => array(
        'title'    => 'คุกกี้ที่จำเป็น',
        'desc'     => 'ทำให้เข้าสู่ระบบและใช้งานได้อย่างปลอดภัย ระบบทำงานไม่ได้หากไม่มีคุกกี้กลุ่มนี้ จึงเปิดไว้เสมอ',
        'required' => true,
        'items'    => array_merge($necessary, (array)ui_cfg('cookies_necessary', array())),
    ));
    foreach ((array)ui_cfg('cookies_optional', array()) as $key => $cat) {
        $cat['required'] = false;
        $catalog[$key] = $cat;
    }
    return $catalog;
}

/** ค่าที่ assets/cookie-consent.js ใช้ — ส่งผ่าน data-config เพราะ CSP อาจห้าม <script> แบบ inline */
function ui_cookie_config()
{
    $base = (string)parse_url((string)ui_cfg('base_url', ''), PHP_URL_PATH);
    return array(
        'version' => (int)ui_cfg('cookie_version', 1),
        'days'    => (int)ui_cfg('cookie_days', 365),
        'path'    => $base !== '' ? $base : '/',
        'policy'  => ui_url('cookie_policy.php'),
        'log'     => ui_url('consent.php'),
        'csrf'    => ui_csrf(),
        'secure'  => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'cats'    => ui_cookie_catalog(),
    );
}

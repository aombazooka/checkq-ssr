<?php
/*
 * ADAPT: จุดเชื่อมกับระบบนี้ (ระบบจาก sso-connect) ไม่ถูกทับตอน --upgrade
 */

function ui_hook_user()
{
    $u = current_user();
    return $u === null ? null : array('id' => $u['id'], 'username' => $u['username'], 'is_admin' => can('*'));
}

function ui_hook_csrf_token()
{
    return csrf_token();
}

function ui_hook_csrf_valid($token)
{
    return is_string($token) && hash_equals(csrf_token(), $token);
}

function ui_hook_audit($action, $detail)
{
    error_log('[checkq/ui] ' . $action . ': ' . $detail);
}

function ui_hook_nav()
{
    $items = array();
    if (can('board.view')) {
        $items[] = array('key' => 'queue', 'label' => 'คิวรายแพทย์', 'icon' => 'book', 'href' => 'index.php');
        $items[] = array('key' => 'board', 'label' => 'คิวแพทย์ตอนนี้', 'icon' => 'users', 'href' => 'board.php');
    }
    if (can('summary.view')) {
        $items[] = array('key' => 'summary', 'label' => 'สรุปทั้งวัน', 'icon' => 'chart', 'href' => 'summary.php');
    }
    if (can('duty.edit')) {
        $items[] = array('key' => 'duty', 'label' => 'เวรแพทย์วันนี้', 'icon' => 'calendar', 'href' => 'duty.php');
    }
    if (can('quota.edit')) {
        $items[] = array('label' => 'ตั้งค่า', 'icon' => 'sliders', 'items' => array(
            array('key' => 'quota', 'label' => 'โควต้าแพทย์', 'icon' => 'sliders', 'href' => 'quota.php'),
            array('key' => 'settings', 'label' => 'คลินิก', 'icon' => 'home', 'href' => 'settings.php'),
            array('key' => 'users', 'label' => 'ผู้ใช้', 'icon' => 'shield', 'href' => 'users.php'),
        ));
    }
    $u = current_user();
    return array(
        'home' => 'index.php',
        'items' => $items,
        'role' => $u ? implode(', ', array_map('role_label', $u['roles'])) : '',
        'logout' => 'logout.php',
        'logout_fields' => array('csrf' => csrf_token()),
    );
}

function ui_hook_page_start($title)
{
    page_start($title);
}

function ui_hook_page_end()
{
    page_end();
}

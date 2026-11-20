/* แบนเนอร์คุกกี้ + หน้าต่างตั้งค่า (ตาม พ.ร.บ.คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562)
 * - รายการคุกกี้มาจาก ui_cookie_catalog() ใน ui/ui.php ผ่าน data-config ของ #uiCookieConfig
 * - คุกกี้ที่จำเป็นเปิดเสมอ ประเภทอื่นปิดไว้ก่อนจนกว่าผู้ใช้จะยินยอม
 * - ไม่มีประเภทที่เลือกได้ = แสดงปุ่ม "รับทราบ" อย่างเดียว เพราะไม่มีอะไรให้ปฏิเสธ
 * API: UiConsent.allowed(ประเภท) · UiConsent.get() · UiConsent.open() */
(function () {
    'use strict';

    var holder = document.getElementById('uiCookieConfig');
    if (!holder) return;
    var C = JSON.parse(holder.dataset.config);
    var NAME = 'ui_consent';
    var bar, dlg, bodyPad = '';

    var ICON = {
        cookie: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9 4 4 0 0 1-4.5-4.5A4 4 0 0 1 12 3z"/><path d="M8.5 9.5h.01"/><path d="M8 15h.01"/><path d="M13 13h.01"/><path d="M14 17h.01"/></svg>',
        lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
        sliders: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h9"/><path d="M17 7h3"/><path d="M4 17h3"/><path d="M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/></svg>',
        tick: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
        x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12"/><path d="M18 6L6 18"/></svg>'
    };

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function optional() {
        return Object.keys(C.cats).filter(function (k) { return !C.cats[k].required; });
    }
    function newId() {
        var a = new Uint8Array(16);
        crypto.getRandomValues(a);
        return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }

    /* ---------- อ่าน/เขียนคำตอบ ---------- */
    function read() {
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + NAME + '=([^;]*)'));
        if (!m) return null;
        try {
            var o = JSON.parse(decodeURIComponent(m[1]));
            return o && o.v === C.version && o.c ? o : null;
        } catch (e) {
            return null;
        }
    }
    function allowed(cat) {
        if (C.cats[cat] && C.cats[cat].required) return true;
        var o = read();
        return !!(o && o.c[cat]);
    }
    function save(choices, method) {
        var prev = read();
        var o = { v: C.version, id: (prev && prev.id) || newId(), ts: new Date().toISOString(), c: choices };
        document.cookie = NAME + '=' + encodeURIComponent(JSON.stringify(o)) + '; Max-Age=' + (C.days * 86400)
            + '; Path=' + C.path + '; SameSite=Lax' + (C.secure ? '; Secure' : '');
        var body = new URLSearchParams({
            csrf: C.csrf,
            id: o.id,
            method: method,
            on: Object.keys(choices).filter(function (k) { return choices[k]; }).join(',')
        });
        fetch(C.log, { method: 'POST', credentials: 'same-origin', keepalive: true, body: body }).catch(function () {});
        showStatus();
    }
    function decide(method, custom) {
        var c = {};
        optional().forEach(function (k) {
            c[k] = method === 'accept_all' ? true : method === 'custom' ? !!custom[k] : false;
        });
        save(c, method);
        hideBar();
        if (dlg && dlg.open) dlg.close();
    }

    /* ---------- แบนเนอร์ ---------- */
    function pad() {
        if (bar) document.body.style.paddingBottom = bar.offsetHeight + 'px';
    }
    function buildBar() {
        var hasChoice = optional().length > 0;
        bar = document.createElement('section');
        bar.className = 'ui-cc-bar';
        bar.setAttribute('aria-label', 'การใช้คุกกี้');
        bar.innerHTML = '<div class="ui-cc-inner"><span class="ui-cc-art">' + ICON.cookie + '</span>'
            + '<div class="ui-cc-copy"><h2>' + (hasChoice ? 'เราขออนุญาตใช้คุกกี้' : 'ระบบนี้ใช้เฉพาะคุกกี้ที่จำเป็น') + '</h2>'
            + '<p>ใช้คุกกี้เพื่อให้คุณเข้าสู่ระบบได้อย่างปลอดภัย ไม่มีคุกกี้โฆษณาหรือติดตามพฤติกรรม · '
            + '<a href="' + esc(C.policy) + '">อ่านนโยบายคุกกี้</a></p></div>'
            + '<div class="ui-cc-actions">'
            + '<button type="button" class="ui-btn ghost" data-ui-cc="settings">' + ICON.sliders + (hasChoice ? 'ตั้งค่า' : 'ดูรายละเอียด') + '</button>'
            + (hasChoice
                ? '<button type="button" class="ui-btn" data-ui-cc="reject">ปฏิเสธที่ไม่จำเป็น</button>'
                  + '<button type="button" class="ui-btn primary" data-ui-cc="accept">' + ICON.tick + 'ยอมรับทั้งหมด</button>'
                : '<button type="button" class="ui-btn primary" data-ui-cc="ack">' + ICON.tick + 'รับทราบ</button>')
            + '</div></div>';
        bar.addEventListener('click', function (e) {
            var b = e.target.closest('[data-ui-cc]');
            if (!b) return;
            act(b.dataset.uiCc);
        });
        document.body.appendChild(bar);
        bodyPad = document.body.style.paddingBottom;
        pad();
        window.addEventListener('resize', pad);
    }
    function hideBar() {
        if (!bar) return;
        bar.remove();
        bar = null;
        window.removeEventListener('resize', pad);
        document.body.style.paddingBottom = bodyPad;
    }
    function act(a) {
        if (a === 'settings') open();
        if (a === 'close') dlg.close();
        if (a === 'ack') decide('acknowledge');
        if (a === 'accept') decide('accept_all');
        if (a === 'reject') decide('reject_all');
        if (a === 'save') {
            var c = {};
            dlg.querySelectorAll('[data-cat]').forEach(function (s) { c[s.dataset.cat] = s.checked; });
            decide('custom', c);
        }
    }

    /* ---------- หน้าต่างตั้งค่า ---------- */
    function buildDialog() {
        var hasChoice = optional().length > 0;
        var cats = Object.keys(C.cats).map(function (k) {
            var c = C.cats[k];
            var items = (c.items || []).map(function (it) {
                return '<div class="ui-cc-item"><code>' + esc(it.name) + '</code><span>' + esc(it.purpose) + '</span><span>อายุ: ' + esc(it.expiry) + '</span></div>';
            }).join('');
            var control = c.required
                ? '<span class="ui-chip ok">' + ICON.tick + 'เปิดเสมอ</span>'
                : '<input type="checkbox" class="ui-cc-switch" role="switch" data-cat="' + esc(k) + '" id="cc_' + esc(k) + '">';
            return '<div class="ui-cc-cat"><div class="ui-cc-cat-head"><span class="ui-cc-art">' + (c.required ? ICON.lock : ICON.sliders) + '</span>'
                + '<label' + (c.required ? '' : ' for="cc_' + esc(k) + '"') + '>' + esc(c.title) + '</label>' + control + '</div>'
                + '<p>' + esc(c.desc) + '</p>'
                + (items ? '<details><summary>ดูรายการคุกกี้ (' + c.items.length + ')</summary><div class="ui-cc-items">' + items + '</div></details>' : '')
                + '</div>';
        }).join('');

        dlg = document.createElement('dialog');
        dlg.className = 'ui-dlg';
        dlg.setAttribute('aria-labelledby', 'ccDialogTitle');
        dlg.innerHTML = '<div class="ui-dlg-head"><div><h2 id="ccDialogTitle">ตั้งค่าคุกกี้</h2>'
            + '<p class="ui-dlg-sub">' + (hasChoice ? 'เลือกประเภทคุกกี้ที่อนุญาตให้ใช้ในเบราว์เซอร์นี้ เปลี่ยนได้ทุกเมื่อ' : 'ระบบนี้ใช้เฉพาะคุกกี้ที่จำเป็น จึงไม่มีรายการให้เลือกปิด') + '</p></div>'
            + '<button type="button" class="ui-dlg-x" data-ui-cc="close" aria-label="ปิด">' + ICON.x + '</button></div>'
            + '<div class="ui-dlg-body">' + cats + '<p class="ui-hint">รายละเอียดเพิ่มเติมอยู่ใน <a href="' + esc(C.policy) + '">นโยบายคุกกี้</a></p></div>'
            + '<div class="ui-dlg-foot">'
            + (hasChoice
                ? '<button type="button" class="ui-btn" data-ui-cc="reject">ปฏิเสธทั้งหมด</button>'
                  + '<button type="button" class="ui-btn" data-ui-cc="save">บันทึกการตั้งค่า</button>'
                  + '<button type="button" class="ui-btn primary" data-ui-cc="accept">' + ICON.tick + 'ยอมรับทั้งหมด</button>'
                : '<button type="button" class="ui-btn primary" data-ui-cc="ack">' + ICON.tick + 'รับทราบ</button>')
            + '</div>';
        dlg.addEventListener('click', function (e) {
            var b = e.target.closest('[data-ui-cc]');
            if (b) act(b.dataset.uiCc);
            else if (e.target === dlg) dlg.close();
        });
        document.body.appendChild(dlg);
    }
    function open() {
        if (!dlg) buildDialog();
        var o = read();
        dlg.querySelectorAll('[data-cat]').forEach(function (s) { s.checked = !!(o && o.c[s.dataset.cat]); });
        dlg.showModal();
    }

    /* ---------- สถานะในหน้านโยบายคุกกี้ ---------- */
    function showStatus() {
        var box = document.querySelector('[data-ui-cc-status]');
        var o = read();
        if (!box || !o) return;
        var on = Object.keys(o.c).filter(function (k) { return o.c[k]; }).map(function (k) { return C.cats[k].title; });
        box.textContent = (on.length ? 'อนุญาต: คุกกี้ที่จำเป็น, ' + on.join(', ') : 'ใช้เฉพาะคุกกี้ที่จำเป็น')
            + ' · ตอบเมื่อ ' + new Date(o.ts).toLocaleString('th-TH', { dateStyle: 'long', timeStyle: 'short' })
            + ' · รหัสอ้างอิง ' + o.id.slice(0, 8).toUpperCase();
    }

    function init() {
        document.querySelectorAll('[data-ui-cc-open]').forEach(function (b) { b.hidden = false; });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('[data-ui-cc-open]')) return;
            e.preventDefault();
            open();
        });
        showStatus();
        if (!read()) buildBar();
    }

    window.UiConsent = { get: read, allowed: allowed, open: open };
    init();
})();

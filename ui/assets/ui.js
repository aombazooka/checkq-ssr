/* app-ui-kit — พฤติกรรมฝั่ง browser (ห้ามแก้) ทุกอย่างทำงานผ่าน data-ui-* จึงไม่ต้องเขียนสคริปต์ในหน้า
 * ถ้าปิด JavaScript ลิงก์ popup ยังเปิดเป็นหน้าเต็ม ฟอร์มยังส่งแบบปกติได้ และเมนูยังเปิดได้เพราะใช้ <details>
 * เรียกจากสคริปต์ของระบบได้: UiToast(ข้อความ, ชนิด) · UiConfirm(หัวข้อ, opts) · UiAlert(ข้อความ, ชนิด) · UiChoose(ข้อความ, opts) */
(function () {
    'use strict';

    var SVG_OPEN = '<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
    var ICONS = {
        ok: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        err: '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6"/><path d="M9 9l6 6"/>',
        warn: '<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5v.01"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.5v.01"/>',
        danger: '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v5"/><path d="M14 11v5"/>',
        x: '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>'
    };
    var TITLES = { ok: 'สำเร็จ', err: 'ไม่สำเร็จ', warn: 'โปรดทราบ', info: 'แจ้งให้ทราบ' };

    function icon(name) {
        return SVG_OPEN + ICONS[name] + '</svg>';
    }
    function normType(type) {
        type = String(type || 'info').toLowerCase();
        if (type === 'success') return 'ok';
        if (type === 'error' || type === 'danger') return 'err';
        if (type === 'warning') return 'warn';
        return TITLES[type] ? type : 'info';
    }

    /* ---------- Toast: แจ้งผลมุมบนขวา หายเอง เอาเมาส์ชี้เพื่อหยุดนับเวลา ---------- */
    function toastStack() {
        var stack = document.getElementById('uiToasts');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'uiToasts';
            stack.className = 'ui-toasts';
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        return stack;
    }

    function toast(message, type, ms) {
        type = normType(type);
        if (ms == null) ms = type === 'err' ? 9000 : type === 'warn' ? 7000 : 4500; // ข้อผิดพลาดค้างนานกว่า ให้อ่านทัน
        var box = document.createElement('div');
        box.className = 'ui-toast ' + type;
        box.setAttribute('role', type === 'err' ? 'alert' : 'status');
        box.innerHTML = '<span class="ui-toast-icon">' + icon(type) + '</span>'
            + '<div class="ui-toast-body"><b></b><span></span></div>'
            + '<button type="button" class="ui-toast-x" aria-label="ปิด">' + icon('x') + '</button><i class="ui-toast-bar"></i>';
        box.querySelector('b').textContent = TITLES[type];
        box.querySelector('.ui-toast-body span').textContent = message;
        box.querySelector('.ui-toast-bar').style.animationDuration = ms + 'ms'; // ผ่าน CSSOM จึงไม่ติด CSP style-src

        var timer, left = ms, started;
        function close() {
            clearTimeout(timer);
            box.classList.add('out');
            setTimeout(function () { box.remove(); }, 260);
        }
        function run() {
            started = Date.now();
            timer = setTimeout(close, left);
        }
        box.querySelector('.ui-toast-x').addEventListener('click', close);
        box.addEventListener('mouseenter', function () {
            clearTimeout(timer);
            left -= Date.now() - started;
            box.classList.add('paused');
        });
        box.addEventListener('mouseleave', function () {
            box.classList.remove('paused');
            run();
        });
        toastStack().appendChild(box);
        requestAnimationFrame(function () { box.classList.add('in'); });
        run();
        return box;
    }

    /* ---------- กล่องโต้ตอบ: สร้างใหม่ทุกครั้ง เรียกได้แม้หน้านั้นไม่ได้ใส่ ui_end() ---------- */

    // o = {title, note, tone, buttons: [{value, label, cls}], dismiss, focus}
    function dialog(o) {
        return new Promise(function (resolve) {
            var box = document.createElement('dialog');
            var tone = o.tone || 'warn';
            box.className = 'ui-confirm';
            box.innerHTML = '<span class="ui-confirm-icon ' + tone + '">' + icon(tone) + '</span><h2></h2><p hidden></p>'
                + '<div class="ui-confirm-actions' + (o.buttons.length > 2 ? ' stack' : '') + '"></div>';
            box.querySelector('h2').textContent = o.title;
            if (o.note) {
                box.querySelector('p').textContent = o.note;
                box.querySelector('p').hidden = false;
            }
            var actions = box.querySelector('.ui-confirm-actions');
            o.buttons.forEach(function (b, i) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'ui-btn ' + (b.cls || '');
                btn.dataset.index = i;
                btn.textContent = b.label;
                actions.appendChild(btn);
            });
            var finished = false;
            function finish(value) {
                if (finished) return;
                finished = true;
                box.close();
                box.remove();
                resolve(value);
            }
            box.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-index]');
                if (btn) finish(o.buttons[+btn.dataset.index].value);
                else if (e.target === box && o.buttons.length > 1) finish(o.dismiss); // คลิกพื้นหลัง = ยกเลิก
            });
            box.addEventListener('cancel', function (e) { // ปุ่ม Esc
                e.preventDefault();
                finish(o.dismiss);
            });
            document.body.appendChild(box);
            box.showModal();
            actions.children[o.focus != null ? o.focus : o.buttons.length - 1].focus();
        });
    }

    // tone: danger = ลบ, stop = มีผลรุนแรงแต่ไม่ใช่การลบ, ไม่ระบุ = ทั่วไป
    // สองแบบแรกใช้ปุ่มแดงและตั้งโฟกัสที่ "ไม่ใช่" กันกด Enter พลาด
    function confirmDialog(title, opts) {
        opts = opts || {};
        var danger = opts.tone === 'danger' || opts.tone === 'stop';
        return dialog({
            title: title, note: opts.note, tone: opts.tone === 'danger' ? 'danger' : 'warn', dismiss: false, focus: danger ? 0 : 1,
            buttons: [
                { value: false, label: opts.cancel || 'ไม่ใช่' },
                { value: true, label: opts.ok || 'ยืนยัน', cls: danger ? 'danger-solid' : 'primary' }
            ]
        });
    }

    function alertDialog(message, type, note) {
        return dialog({ title: message, note: note, tone: normType(type || 'warn'), dismiss: true, buttons: [{ value: true, label: 'ตกลง', cls: 'primary' }] });
    }

    // หลายทางเลือก: opts = {note, tone, buttons: [{value, label, cls}], dismiss, focus} — ค่าที่คืนคือ value ของปุ่มที่กด
    function chooseDialog(message, opts) {
        opts = opts || {};
        return dialog({ title: message, note: opts.note, tone: opts.tone, buttons: opts.buttons, dismiss: opts.dismiss != null ? opts.dismiss : null, focus: opts.focus });
    }

    window.UiToast = toast;
    window.UiConfirm = confirmDialog;
    window.UiAlert = alertDialog;
    window.UiChoose = chooseDialog;

    /* ---------- แทน confirm()/alert() ของเบราว์เซอร์ (เปิดด้วย native_dialogs ใน config) ----------
       confirm() เดิมหยุดรอคำตอบ แต่กล่องนี้รอไม่ได้ จึงคืน false ไปก่อนแล้วเปิดกล่อง ถ้ากดยืนยันจะกดปุ่ม/ส่งฟอร์มเดิมซ้ำ
       และครั้งนั้น confirm() คืน true · ถ้าหาต้นทางไม่ได้ (เรียกนอกการคลิก/ส่งฟอร์ม) ใช้ confirm() เดิม */
    if (document.querySelector('meta[name="ui-native-dialogs"]')) {
        var nativeConfirm = window.confirm.bind(window);
        var bypass = false;

        window.confirm = function (message) {
            if (bypass) {
                bypass = false;
                return true;
            }
            var ev = window.event;
            var replay = null;
            if (ev && ev.type === 'submit' && ev.target && ev.target.tagName === 'FORM') {
                var form = ev.target;
                var submitter = ev.submitter;
                replay = function () { form.requestSubmit(submitter || undefined); };
            } else if (ev && ev.type === 'click') {
                var el = ev.currentTarget && ev.currentTarget.click ? ev.currentTarget : ev.target.closest('button,a,input');
                if (el) replay = function () { el.click(); };
            }
            if (!replay) return nativeConfirm(message);
            // บรรทัดแรก (หรือถึงเครื่องหมาย ?) เป็นหัวข้อ ที่เหลือเป็นคำอธิบาย
            var text = String(message);
            var parts = text.match(/^([^\n?]*\?)\s*([\s\S]+)$/) || text.match(/^([^\n]*)\n([\s\S]*)$/);
            var title = parts ? parts[1] : text;
            confirmDialog(title, { note: parts ? parts[2].trim() : '', tone: /ลบ|ล้าง|รีเซ็ต|เอาออก|ทับ/.test(title) ? 'danger' : '' }).then(function (ok) {
                if (!ok) return;
                bypass = true;
                try { replay(); } finally { bypass = false; }
            });
            return false;
        };
        window.alert = function (message) { alertDialog(String(message)); };
    }

    document.addEventListener('DOMContentLoaded', function () {
        // ข้อความจาก ui_flash() ฝั่ง PHP
        var flash = document.getElementById('uiFlash');
        if (flash) {
            JSON.parse(flash.dataset.flash).forEach(function (item, i) {
                setTimeout(function () { toast(item[1], item[0]); }, 120 + i * 150);
            });
        }

        // ปุ่มแสดง/ซ่อนรหัสผ่าน (เรียกซ้ำกับเนื้อหา popup ที่โหลดมาทีหลัง)
        function initPasswordToggles(root) {
            root.querySelectorAll('[data-ui-password]').forEach(function (btn) {
                var input = document.getElementById(btn.dataset.uiPassword);
                if (!input) return;
                btn.hidden = false;
                btn.addEventListener('click', function () {
                    var show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    btn.textContent = show ? 'ซ่อน' : 'แสดง';
                    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                });
            });
        }
        initPasswordToggles(document);

        /* ---------- แถบเมนู ---------- */
        var navBar = document.querySelector('[data-ui-nav]');
        if (navBar) {
            var navInner = navBar.querySelector('.ui-nav-inner');
            var closeNavMenus = function (except) {
                navBar.querySelectorAll('details[open]').forEach(function (d) {
                    if (d !== except) d.open = false;
                });
            };
            // ย่อทีละขั้นจนไม่ล้นจอ: 1 ซ่อนชื่อผู้ใช้ · 2 ซ่อนชื่อระบบ · 3 เมนูเดี่ยวเหลือไอคอน · 4 ทุกเมนูเหลือไอคอน · 5 ใช้ปุ่มเมนู
            var fitNav = function () {
                var k;
                for (k = 1; k <= 5; k++) navBar.classList.remove('fit' + k);
                for (k = 1; k <= 5 && navInner.scrollWidth > navInner.clientWidth + 1; k++) navBar.classList.add('fit' + k);
            };
            closeNavMenus();
            fitNav();
            if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitNav);
            window.addEventListener('load', fitNav);
            window.addEventListener('resize', function () {
                closeNavMenus();
                fitNav();
            });
            navBar.querySelectorAll('.ui-nav-drop').forEach(function (drop) {
                drop.addEventListener('toggle', function () {
                    if (!drop.open) return;
                    closeNavMenus(drop);
                    var menu = drop.querySelector('.ui-nav-menu');
                    menu.classList.remove('flip');
                    if (menu.getBoundingClientRect().right > window.innerWidth - 8) menu.classList.add('flip');
                });
            });
            document.addEventListener('click', function (e) {
                if (!e.target.closest('details')) closeNavMenus();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeNavMenus();
            });
        }

        /* ---------- ต่ออายุ session ขณะเปิดหน้าค้าง: หมดอายุแล้วเตือนครั้งเดียว ไม่ปลุกซ้ำ ---------- */
        var keepAlive = document.querySelector('meta[name="ui-keepalive"]');
        if (keepAlive) {
            var pingTimer = setInterval(function () {
                fetch(keepAlive.content, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
                    return r.redirected ? { ok: false } : r.json();
                }).then(function (data) {
                    if (data.ok === false) {
                        clearInterval(pingTimer);
                        toast('หมดเวลาเข้าสู่ระบบแล้ว ข้อมูลที่ยังไม่บันทึกอาจส่งไม่สำเร็จ กรุณาเข้าสู่ระบบใหม่', 'warn', 30000);
                    }
                }).catch(function () {}); // เครือข่ายสะดุดชั่วคราว รอบถัดไปลองใหม่
            }, Math.max(1, +keepAlive.dataset.minutes) * 60000);
        }

        /* ---------- เตือนเมื่อออกจากหน้าโดยยังไม่บันทึก: data-ui-dirty ที่ฟอร์ม ---------- */
        // เทียบค่ากับตอนเปิดหน้า แก้แล้วแก้กลับจึงไม่ถูกถาม
        function formState(form) {
            var parts = [];
            new FormData(form).forEach(function (value, key) {
                parts.push(key + '=' + (typeof value === 'string' ? value : value.name));
            });
            return parts.join('&');
        }
        function trackDirty(root) {
            root.querySelectorAll('form[data-ui-dirty]').forEach(function (form) {
                form.uiSavedState = formState(form);
            });
        }
        function isDirty(root) {
            return Array.prototype.some.call(root.querySelectorAll('form[data-ui-dirty]'), function (form) {
                return !form.uiSaving && form.uiSavedState !== undefined && formState(form) !== form.uiSavedState;
            });
        }
        function askLeave() {
            return chooseDialog('ยังไม่ได้บันทึกข้อมูลที่แก้ไว้', {
                note: 'ถ้าออกตอนนี้ ข้อมูลที่แก้จะหายไป', tone: 'warn', dismiss: 'stay', focus: 1,
                buttons: [
                    { value: 'leave', label: 'ออกโดยไม่บันทึก', cls: 'danger' },
                    { value: 'stay', label: 'อยู่ต่อ', cls: 'primary' }
                ]
            });
        }
        var leaving = false;
        trackDirty(document);
        window.addEventListener('beforeunload', function (e) {
            if (!leaving && isDirty(document)) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        document.addEventListener('click', function (e) {
            var link = e.target.closest('a[href]');
            if (!link || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            if (link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('data-ui-popup')) return;
            if (link.getAttribute('href').charAt(0) === '#' || !/^https?:/.test(link.href) || !isDirty(document)) return;
            e.preventDefault();
            askLeave().then(function (answer) {
                if (answer === 'leave') {
                    leaving = true;
                    location.href = link.href;
                }
            });
        });

        /* ---------- ฟอร์ม: กล่องยืนยัน + ส่งใน popup ---------- */

        /** ข้อความยืนยันของฟอร์มนี้: data-ui-confirm บนฟอร์ม หรือ data-ui-confirm-off บน checkbox ที่เพิ่งถูกปิด */
        function confirmFor(form) {
            if (form.dataset.uiConfirm) {
                return { title: form.dataset.uiConfirm, note: form.dataset.uiNote, tone: form.dataset.uiTone };
            }
            var off = form.querySelector('[data-ui-confirm-off]');
            if (off && off.defaultChecked && !off.checked) {
                return { title: off.dataset.uiConfirmOff, note: off.dataset.uiNote, tone: 'stop' };
            }
            return null;
        }

        function showFormErrors(form, errors) {
            var box = form.closest('.ui-panel').querySelector('[data-ui-errors]');
            box.querySelector('[data-ui-errors-text]').textContent = errors.join('\n');
            box.hidden = false;
            box.scrollIntoView({ block: 'nearest' });
        }

        // ฟอร์มใน popup: ส่งผ่าน fetch เพื่อให้ข้อผิดพลาดขึ้นใน popup โดยค่าที่กรอกไว้ไม่หาย
        // ใช้ getAttribute เพราะช่องชื่อ action ในฟอร์มจะบัง form.action
        function submitInPopup(form, submitter) {
            if (submitter) submitter.disabled = true;
            fetch(form.getAttribute('action') || location.href, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                if (data.ok) {
                    location.href = data.redirect;
                    return;
                }
                showFormErrors(form, data.errors);
                form.uiSaving = false;
                if (submitter) submitter.disabled = false;
            }).catch(function () {
                showFormErrors(form, ['บันทึกไม่สำเร็จ อาจหมดเวลาเข้าสู่ระบบ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง']);
                form.uiSaving = false;
                if (submitter) submitter.disabled = false;
            });
        }

        document.addEventListener('submit', function (e) {
            var form = e.target;
            var submitter = e.submitter;
            var ask = confirmFor(form);
            if (ask && form.dataset.uiConfirmed !== '1') {
                e.preventDefault();
                confirmDialog(ask.title, ask).then(function (ok) {
                    if (!ok) return;
                    form.dataset.uiConfirmed = '1';
                    form.requestSubmit(submitter || undefined);
                });
                return;
            }
            delete form.dataset.uiConfirmed;
            if (e.defaultPrevented) return; // สคริปต์ของหน้าหยุดการส่งไว้เอง
            form.uiSaving = true;
            if (form.hasAttribute('data-ui-ajax')) {
                e.preventDefault();
                submitInPopup(form, submitter);
            }
        });

        // popup ที่โหลดเนื้อหาจากเซิร์ฟเวอร์: ลิงก์ยังเปิดเป็นหน้าเต็มได้ถ้าไม่มี JavaScript หรือโหลดไม่สำเร็จ
        var remote = document.getElementById('uiRemote');
        if (!remote) return; // หน้านี้ไม่ได้เรียก ui_end()
        var loading = false;
        remote.addEventListener('close', function () { remote.textContent = ''; });

        function openRemote(link) {
            if (loading || remote.open) return;
            loading = true;
            fetch(link.href + (link.href.indexOf('?') === -1 ? '?' : '&') + 'modal=1', { credentials: 'same-origin' }).then(function (r) {
                if (!r.ok || r.redirected) throw new Error('popup');
                return r.text();
            }).then(function (html) {
                remote.innerHTML = html;
                remote.setAttribute('aria-label', remote.querySelector('h2').textContent);
                initPasswordToggles(remote);
                trackDirty(remote);
                remote.showModal();
                loading = false;
            }).catch(function () {
                location.href = link.href;
            });
        }

        /** ปิด popup: ถ้ามีค่าที่ยังไม่บันทึกให้ถามก่อน */
        function closeDialog(box) {
            if (!isDirty(box)) {
                box.close();
                return;
            }
            askLeave().then(function (answer) {
                if (answer === 'leave') box.close();
            });
        }
        remote.addEventListener('cancel', function (e) { // ปุ่ม Esc
            e.preventDefault();
            closeDialog(remote);
        });

        // popup แสดงรายละเอียด: data-ui-detail='{"title":"...","rows":[["ป้าย","ค่า"],...]}' (ใส่ค่าด้วย textContent)
        var detail = document.getElementById('uiDetail');
        function openDetail(data) {
            var list = document.getElementById('uiDetailList');
            document.getElementById('uiDetailTitle').textContent = data.title;
            list.textContent = '';
            data.rows.forEach(function (row) {
                var dt = document.createElement('dt');
                var dd = document.createElement('dd');
                dt.textContent = row[0];
                dd.textContent = row[1] || '—';
                list.appendChild(dt);
                list.appendChild(dd);
            });
            detail.showModal();
        }
        detail.addEventListener('click', function (e) {
            if (e.target === detail) detail.close();
        });

        document.addEventListener('click', function (e) {
            var closer = e.target.closest('[data-ui-close]');
            if (closer) {
                closeDialog(closer.closest('dialog'));
                return;
            }
            var link = e.target.closest('[data-ui-popup]');
            if (link && !e.ctrlKey && !e.metaKey && !e.shiftKey) {
                e.preventDefault();
                openRemote(link);
                return;
            }
            var opener = e.target.closest('[data-ui-detail]');
            if (opener) openDetail(JSON.parse(opener.dataset.uiDetail));
        });

        // หน้าอัปเดตระบบ: กรองตามชนิด และซ่อนวันที่ไม่เหลือรายการ
        var clFilter = document.querySelector('[data-ui-cl-filter]');
        if (clFilter) {
            clFilter.hidden = false;
            clFilter.addEventListener('click', function (e) {
                var btn = e.target.closest('button');
                if (!btn) return;
                clFilter.querySelectorAll('button').forEach(function (b) {
                    b.classList.toggle('on', b === btn);
                    b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
                });
                document.querySelectorAll('[data-ui-cl-day]').forEach(function (day) {
                    var shown = 0;
                    day.querySelectorAll('li').forEach(function (li) {
                        li.hidden = btn.dataset.type !== '' && li.dataset.type !== btn.dataset.type;
                        if (!li.hidden) shown++;
                    });
                    day.hidden = shown === 0;
                });
            });
        }
    });
})();

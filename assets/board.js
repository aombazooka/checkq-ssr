/* หน้าคิวแพทย์ (จุดคัดกรอง)
 * ซ้าย: คนไข้มาแล้วรอเลือกแพทย์ / รอตรวจ — กดเลือก HN ได้ ค้นหา HN/ชื่อได้
 * ขวา: แพทย์ที่แนะนำ + การ์ดแพทย์ — ปุ่ม "ส่ง" พา HN ที่เลือกไปด้วย
 * โหลดตัวเลขใหม่เป็นระยะ (หยุดระหว่างเปิด popup) และจำ HN ที่เลือกไว้ข้ามการโหลด
 */
(function () {
    'use strict';
    var root = document.getElementById('cqBoard');
    if (!root) return;

    var clinic = root.dataset.clinic;
    var canQuota = root.dataset.quota === '1';
    var refresh = Math.max(10, parseInt(root.dataset.refresh, 10) || 30) * 1000;
    var board = JSON.parse(root.dataset.board);
    var cards = document.getElementById('cqCards');
    var list = document.getElementById('cqList');
    var errBox = document.getElementById('cqError');
    var search = document.getElementById('cqHn');
    var hnOut = document.getElementById('cqHnResult');
    var tab = 'queue';
    var selected = null; // {hn, name}
    var OLD_MIN = parseInt(root.dataset.stale, 10) || 180; // รอนานเกินนี้ น่าจะกลับไปแล้วแต่ยังไม่ปิด visit

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function assignUrl(planId) {
        return 'assign.php?plan=' + encodeURIComponent(planId) + (selected ? '&hn=' + encodeURIComponent(selected.hn) : '');
    }

    function level(d) {
        if (d.tier >= 2) return 'x';
        if (d.tier === 1) return 'r';
        if (!board.clinic.control) return 'g';
        return d.ratio >= 0.7 ? 'y' : 'g';
    }

    function find(planId) {
        for (var i = 0; i < board.doctors.length; i++) {
            if (board.doctors[i].plan_id === planId) return board.doctors[i];
        }
        return null;
    }

    function waitText(min) {
        if (min < 60) return 'รอ ' + min + ' นาที';
        return 'รอ ' + Math.floor(min / 60) + ' ชม. ' + (min % 60) + ' นาที';
    }

    function quotaText(d) {
        if (d.status !== 'on') return d.status_label + (d.note ? ' · ' + d.note : '');
        if (d.slot_off) return 'ปิดช่วงนี้' + (d.note ? ' · ' + d.note : '');
        if (!board.clinic.control) return 'ช่วงนี้รับแล้ว ' + d.slot_used + ' คน (ไม่คุมโควต้า)';
        if (d.slot_quota === 0) return 'ไม่มีโควต้าช่วงนี้' + (d.slot_used ? ' · มีคนไข้แล้ว ' + d.slot_used : '') + ' — ตั้งได้ที่หน้าโควต้าแพทย์';
        var left = d.slot_quota - d.slot_used;
        return 'ช่วงนี้ ' + d.slot_used + '/' + d.slot_quota + (left > 0 ? ' · รับได้อีก ' + left : left === 0 ? ' · เต็ม' : ' · เกิน ' + (-left));
    }

    // ---------- ซ้าย: รายชื่อคนไข้ ----------

    function matches(p, q) {
        if (!q) return true;
        q = q.toLowerCase();
        return p.hn.toLowerCase().indexOf(q) !== -1 || (p.name || '').toLowerCase().indexOf(q) !== -1;
    }

    function renderList() {
        var q = search.value.trim();
        var rows = (tab === 'queue' ? board.queue : board.sent).filter(function (p) { return matches(p, q); });
        document.getElementById('cqQueueN').textContent = board.queue.length;
        document.getElementById('cqSentN').textContent = board.sent.length;
        document.getElementById('cqTabQueue').setAttribute('aria-selected', tab === 'queue' ? 'true' : 'false');
        document.getElementById('cqTabSent').setAttribute('aria-selected', tab === 'sent' ? 'true' : 'false');
        list.setAttribute('aria-label', tab === 'queue' ? 'คนไข้รอเลือกแพทย์' : 'คนไข้มีแพทย์แล้ว รอตรวจ');
        list.textContent = '';

        if (!rows.length) {
            var empty = el('li', 'cq-list-empty', q ? 'ไม่พบ "' + q + '" ในรายการนี้ — กด Enter เพื่อดูนัดวันนี้'
                : (tab === 'queue' ? 'ไม่มีคนไข้รอเลือกแพทย์' : 'ไม่มีคนไข้รอตรวจ'));
            list.appendChild(empty);
        }
        rows.forEach(function (p) {
            var li = el('li', 'cq-pt' + (p.wait_min >= OLD_MIN ? ' old' : '') + (tab === 'sent' ? ' sent' : ''));
            var isSel = tab === 'queue' && selected && selected.hn === p.hn;
            if (tab === 'queue') {
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', isSel ? 'true' : 'false');
                li.tabIndex = 0;
                li.dataset.hn = p.hn;
                if (isSel) li.classList.add('sel');
            }
            var top = el('div', 'cq-pt-top');
            top.appendChild(el('b', 'cq-pt-hn', p.hn));
            if (p.q) top.appendChild(el('span', 'cq-pt-q', 'คิว ' + p.q));
            top.appendChild(el('span', 'cq-pt-time', (p.wait_min >= OLD_MIN ? 'รอนาน · ' : '') + p.time + ' · ' + waitText(p.wait_min)));
            li.appendChild(top);
            li.appendChild(el('div', 'cq-pt-name', p.name || '-'));
            if (tab === 'sent') {
                li.appendChild(el('div', 'cq-pt-doc', '→ ' + p.doctor + (p.room ? ' · ห้อง ' + p.room : '') + (p.pending ? ' (รอ HOSxP อัปเดต)' : '')));
            }
            if (p.wait_min >= OLD_MIN) li.title = 'รอนานผิดปกติ — อาจกลับไปแล้วแต่ยังไม่ได้ปิด visit ใน HOSxP';
            list.appendChild(li);
        });

        var foot = document.getElementById('cqQueueFoot');
        foot.textContent = tab === 'queue'
            ? (selected ? '' : 'กดเลือกคนไข้ แล้วกด "ส่งไปหมอคนนี้" ด้านขวา')
            : 'แสดงเฉพาะคนที่ยังไม่ได้ตรวจ';
    }

    function select(hn) {
        var p = null;
        board.queue.forEach(function (x) { if (x.hn === hn) p = x; });
        selected = (p && !(selected && selected.hn === hn)) ? { hn: p.hn, name: p.name } : null;
        renderList();
        renderHero();
        renderCards();
    }

    list.addEventListener('click', function (e) {
        var li = e.target.closest('[data-hn]');
        if (li) select(li.dataset.hn);
    });
    list.addEventListener('keydown', function (e) {
        var li = e.target.closest('[data-hn]');
        if (!li) return;
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); select(li.dataset.hn); }
        if (e.key === 'ArrowDown' && li.nextElementSibling) { e.preventDefault(); li.nextElementSibling.focus(); }
        if (e.key === 'ArrowUp' && li.previousElementSibling) { e.preventDefault(); li.previousElementSibling.focus(); }
    });
    document.getElementById('cqTabQueue').addEventListener('click', function () { tab = 'queue'; renderList(); });
    document.getElementById('cqTabSent').addEventListener('click', function () { tab = 'sent'; renderList(); });
    search.addEventListener('input', function () { hnOut.textContent = ''; renderList(); });

    // ---------- ขวา: แพทย์ที่แนะนำ + การ์ด ----------

    function renderHero() {
        var d = board.pick !== null ? find(board.pick) : null;
        var label = document.getElementById('cqHeroTitle');
        var name = document.getElementById('cqPickName');
        var why = document.getElementById('cqPickWhy');
        var btn = document.getElementById('cqPickBtn');
        var main = document.getElementById('cqHeroMain');
        main.classList.toggle('warn', !!board.all_full);
        main.classList.toggle('none', !d);
        label.textContent = selected
            ? 'ส่ง HN ' + selected.hn + (selected.name ? ' · ' + selected.name : '') + ' ไปที่'
            : 'คนไข้ไม่ระบุแพทย์ · ส่งไปที่';
        main.classList.toggle('has-pt', !!selected);
        if (!d) {
            name.textContent = board.doctors.length ? 'ไม่มีแพทย์ที่รับได้' : 'ยังไม่มีแพทย์ในเวรวันนี้';
            why.textContent = board.doctors.length
                ? 'ยังไม่มีแพทย์ที่ออกตรวจและรับได้ช่วงนี้ — กด "ออกตรวจ" ที่หน้าเวรแพทย์วันนี้'
                : (canQuota ? 'ตั้งโควต้าแพทย์ของคลินิกนี้ที่หน้า "โควต้าแพทย์" ก่อน' : 'ให้ผู้ดูแลระบบตั้งโควต้าแพทย์ของคลินิกนี้ก่อน');
            btn.hidden = true;
            btn.removeAttribute('data-ui-popup');
            btn.href = '#cqList';
            return;
        }
        name.textContent = d.name + (d.room ? ' · ห้อง ' + d.room : '');
        why.textContent = (board.all_full ? 'ทุกคนเต็มโควต้าช่วงนี้แล้ว — คนนี้เกินน้อยที่สุด · ' : '')
            + 'รอตรวจ ' + d.wait + ' คน · ' + quotaText(d);
        btn.hidden = false;
        // ต้องเลือก HN ก่อน: ระบบใช้ HN จับคู่กับ HOSxP กันนับคิวซ้ำ
        if (selected) {
            btn.href = assignUrl(d.plan_id);
            btn.setAttribute('data-ui-popup', '');
            btn.classList.remove('need');
            btn.querySelector('span').textContent = 'ส่ง HN ' + selected.hn + ' ไปหมอคนนี้';
        } else {
            btn.href = '#cqList';
            btn.removeAttribute('data-ui-popup');
            btn.classList.add('need');
            btn.querySelector('span').textContent = board.queue.length ? 'เลือกคนไข้ทางซ้ายก่อน' : 'ค้นหา HN ทางซ้ายก่อน';
        }
    }

    document.getElementById('cqPickBtn').addEventListener('click', function (e) {
        if (selected) return;
        e.preventDefault();
        tab = 'queue';
        renderList();
        var first = list.querySelector('[data-hn]');
        if (first) { first.focus(); } else { search.focus(); }
        var q = document.querySelector('.cq-queue');
        q.classList.remove('flash');
        void q.offsetWidth;
        q.classList.add('flash');
    });

    function card(d) {
        var c = el('article', 'cq-card ' + level(d) + (d.plan_id === board.pick ? ' pick' : ''));
        var head = el('div', 'cq-card-head');
        var title = el('div', 'cq-card-title');
        title.appendChild(el('h3', null, d.name));
        title.appendChild(el('div', 'cq-muted', d.room ? 'ห้อง ' + d.room : ''));
        head.appendChild(title);
        if (d.plan_id === board.pick) head.appendChild(el('span', 'ui-chip ok', '★ แนะนำ'));
        else if (d.status !== 'on') head.appendChild(el('span', 'ui-chip amber', d.status_label));
        c.appendChild(head);

        var nums = el('div', 'cq-nums');
        var w = el('div', 'cq-big');
        w.appendChild(el('b', null, String(d.wait)));
        w.appendChild(el('span', null, 'รอตรวจ' + (d.pending ? ' (ส่งแล้ว ' + d.pending + ')' : '')));
        nums.appendChild(w);
        var small = el('div', 'cq-small');
        if (d.exam) small.appendChild(el('div', null, 'กำลังตรวจ ' + d.exam));
        small.appendChild(el('div', null, 'ตรวจแล้ว ' + d.done));
        small.appendChild(el('div', null, 'ทั้งวัน ' + d.day_used + (board.clinic.control && d.day_quota ? '/' + d.day_quota : '')));
        nums.appendChild(small);
        c.appendChild(nums);

        var bar = el('div', 'cq-bar');
        var fill = el('i');
        var pct = d.slot_quota > 0 ? Math.min(100, Math.round(d.slot_used * 100 / d.slot_quota)) : (d.slot_used > 0 ? 100 : 0);
        fill.style.width = pct + '%';
        bar.appendChild(fill);
        bar.setAttribute('role', 'img');
        bar.setAttribute('aria-label', quotaText(d));
        c.appendChild(bar);
        c.appendChild(el('div', 'cq-quota', quotaText(d)));
        var lk = el('a', 'cq-card-link', 'ดูรายชื่อคิว (' + d.wait + ') →');
        lk.href = 'index.php?clinic=' + encodeURIComponent(clinic) + '#d-' + encodeURIComponent(d.code);
        c.appendChild(lk);

        if (d.tier <= 1 && selected) {
            var a = el('a', 'ui-btn ' + (d.plan_id === board.pick ? 'primary' : 'outline') + ' cq-send',
                'ส่ง HN ' + selected.hn + ' ไปหมอคนนี้');
            a.href = assignUrl(d.plan_id);
            a.setAttribute('data-ui-popup', '');
            c.appendChild(a);
        }
        return c;
    }

    // แสดงเฉพาะแพทย์ที่ "ออกตรวจ" วันนี้ — ไม่ออก/ลา/ประชุมไม่แสดง (ยังเปลี่ยนได้ที่หน้าเวรแพทย์)
    function renderCards() {
        cards.textContent = '';
        var hidden = 0;
        board.doctors.forEach(function (d) {
            if (d.status !== 'on') { hidden++; return; }
            cards.appendChild(card(d));
        });
        var note = document.getElementById('cqHiddenNote');
        note.textContent = '';
        if (hidden) {
            note.appendChild(document.createTextNode('ไม่แสดงแพทย์ที่ไม่ได้ออกตรวจวันนี้ ' + hidden + ' คน · '));
            var a = el('a', null, 'เปลี่ยนที่หน้าเวรแพทย์');
            a.href = 'duty.php';
            note.appendChild(a);
        }
        note.hidden = !hidden;
    }

    function render() {
        document.getElementById('cqSlot').textContent = board.slot ? board.slot.label : '-';
        document.getElementById('cqAt').textContent = board.fetched_at;
        errBox.hidden = !board.error;
        errBox.textContent = board.error || '';
        // HN ที่เลือกไว้ถูกส่งแพทย์แล้ว/ออกจากคิว → ล้างการเลือก
        if (selected && !board.queue.some(function (p) { return p.hn === selected.hn; })) selected = null;
        renderList();
        renderHero();
        renderCards();
    }

    function renderCounts(counts) {
        document.querySelectorAll('.cq-tab[data-clinic]').forEach(function (t) {
            var c = counts[t.dataset.clinic] || { wait: 0, unassigned: 0 };
            t.querySelector('.cq-tab-n').textContent = c.wait;
            var u = t.querySelector('.cq-tab-u');
            u.textContent = c.unassigned;
            u.hidden = !c.unassigned;
        });
    }

    var timer;
    function load() {
        if (document.querySelector('dialog[open]') || document.hidden) return; // ไม่วาดใหม่ระหว่างกรอก popup
        fetch('api/board.php?clinic=' + encodeURIComponent(clinic), { credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.status === 401) {
                    errBox.hidden = false;
                    errBox.textContent = '';
                    var a = el('a', null, res.body.message || 'หมดเวลาเข้าสู่ระบบ กดเพื่อเข้าใหม่');
                    a.href = location.href;
                    errBox.appendChild(a);
                    clearInterval(timer);
                    return;
                }
                if (!res.body.ok) throw new Error(res.body.message);
                board = res.body.board;
                render();
                renderCounts(res.body.counts);
            })
            .catch(function () {
                errBox.hidden = false;
                errBox.textContent = 'โหลดข้อมูลใหม่ไม่ได้ — ตัวเลขอาจไม่เป็นปัจจุบัน จะลองใหม่อัตโนมัติ';
            });
    }

    // Enter ในช่องค้นหา: ถ้ามีคนเดียวในรายการ → เลือกเลย · ไม่เจอ → ดูนัดวันนี้จาก HOSxP
    document.getElementById('cqHnForm').addEventListener('submit', function (e) {
        e.preventDefault();
        var q = search.value.trim();
        if (!q) return;
        var hits = board.queue.filter(function (p) { return matches(p, q); });
        if (hits.length === 1) {
            tab = 'queue';
            selected = null;
            select(hits[0].hn);
            return;
        }
        if (!/^[0-9A-Za-z]{1,20}$/.test(q)) return;
        hnOut.textContent = 'กำลังดูนัดวันนี้...';
        fetch('api/patient.php?hn=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                hnOut.textContent = '';
                if (!j.ok) { hnOut.appendChild(el('div', 'cq-hn-err', j.message || 'ค้นหาไม่ได้')); return; }
                if (!j.appointments.length) {
                    // walk-in ที่เพิ่งลงทะเบียน ยังไม่ขึ้นในรายชื่อ → ส่งไปแพทย์ที่แนะนำพร้อม HN ได้เลย
                    var box0 = el('div', 'cq-hn-app');
                    box0.appendChild(el('div', null, 'HN ' + j.hn + ' ไม่มีนัดวันนี้ (ยังไม่ขึ้นในรายชื่อ อาจเพิ่งลงทะเบียน)'));
                    var pick = board.pick !== null ? find(board.pick) : null;
                    if (pick) {
                        var b0 = el('a', 'ui-btn primary', 'ส่ง HN ' + j.hn + ' ไป ' + pick.name);
                        b0.href = 'assign.php?plan=' + encodeURIComponent(pick.plan_id) + '&hn=' + encodeURIComponent(j.hn);
                        b0.setAttribute('data-ui-popup', '');
                        box0.appendChild(b0);
                    }
                    hnOut.appendChild(box0);
                    return;
                }
                if (j.name) hnOut.appendChild(el('div', 'cq-hn-name', j.name + ' · HN ' + j.hn));
                j.appointments.forEach(function (a) {
                    var box = el('div', 'cq-hn-app' + (a.doctor_name ? ' fixed' : ''));
                    box.appendChild(el('div', null, 'นัด ' + a.clinic_code + ' ' + a.clinic_name + ' เวลา ' + a.time + ' น.'));
                    box.appendChild(el('div', 'cq-hn-doc', a.doctor_name ? 'ระบุแพทย์: ' + a.doctor_name : 'ไม่ระบุแพทย์'));
                    box.appendChild(el('div', 'cq-muted', a.reason));
                    if (a.target) {
                        var btn = el('a', 'ui-btn primary', 'ส่งไป ' + a.target.name + (a.target.room ? ' (ห้อง ' + a.target.room + ')' : ''));
                        btn.href = 'assign.php?plan=' + encodeURIComponent(a.target.plan_id) + '&hn=' + encodeURIComponent(j.hn);
                        btn.setAttribute('data-ui-popup', '');
                        box.appendChild(btn);
                    }
                    hnOut.appendChild(box);
                });
            })
            .catch(function () { hnOut.textContent = 'ค้นหาไม่ได้ ลองใหม่อีกครั้ง'; });
    });

    render();
    timer = setInterval(load, refresh);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
})();

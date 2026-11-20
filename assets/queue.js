/* คิวรายแพทย์ (มุมมองห้องตรวจ)
 * - ห้องที่เลือกแพทย์แล้ว: ชื่อแพทย์ (กดเปลี่ยนได้) + จำนวนรอ + รายชื่อคนไข้เรียงตามเวลามาถึง (คนแรก = ถัดไป)
 * - ห้องว่าง: แสดงแค่เลขห้องกับปุ่มเลือกแพทย์
 * - แพทย์ออกตรวจที่ยังไม่ได้เข้าห้อง: ชิปชื่อ + จำนวนรอ (ยังไม่แสดงรายชื่อ)
 * - คลินิกที่ไม่มีห้องตรวจ: แสดงการ์ดรายแพทย์แบบเดียวกัน
 */
(function () {
    'use strict';
    var root = document.getElementById('cqQueueBoard');
    if (!root) return;

    var clinic = root.dataset.clinic;
    var refresh = Math.max(10, parseInt(root.dataset.refresh, 10) || 30) * 1000;
    var board = JSON.parse(root.dataset.board);
    var canEdit = root.dataset.edit === '1';
    var csrfMeta = document.querySelector('meta[name="csrf"]');
    var csrf = csrfMeta ? csrfMeta.content : '';
    var errBox = document.getElementById('cqError');
    var elStats = document.getElementById('cqQSum');
    var elWait = document.getElementById('cqWaitStrip');
    var elRooms = document.getElementById('cqRooms');
    var elOthers = document.getElementById('cqOthers');
    var OLD_MIN = parseInt(root.dataset.stale, 10) || 180; // รอนานเกินนี้ = น่าจะกลับไปแล้วแต่ยังไม่ปิด visit

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function waitText(min) {
        return min < 60 ? min + ' นาที' : Math.floor(min / 60) + ' ชม. ' + (min % 60) + ' นาที';
    }

    // ---------- คนไข้ ----------

    var FULL_ROWS = 3; // แสดงเต็ม 3 คนแรก ที่เหลือเป็นแถวย่อบรรทัดเดียว (รองรับ 20+ คิวต่อห้อง)

    function ptRowCompact(p, i) {
        var li = el('li', 'cq-rpc' + (p.wait_min >= OLD_MIN ? ' old' : '') + (p.pending ? ' pend' : ''));
        li.appendChild(el('span', 'cq-rpc-i', String(i + 1)));
        li.appendChild(el('b', 'cq-rpc-q', p.q ? String(p.q) : '–'));
        li.appendChild(el('span', 'cq-rpc-name', p.name || ('HN ' + p.hn)));
        li.appendChild(el('span', 'cq-rpc-wait', (p.wait_min >= OLD_MIN ? 'รอนาน · ' : '') + waitShort(p.wait_min)));
        li.title = 'คิว ' + (p.q || '-') + ' · HN ' + p.hn + ' · มา ' + p.time + ' · รอ ' + waitText(p.wait_min)
            + (p.pending ? ' · ส่งจากระบบนี้ รอ HOSxP อัปเดต' : '')
            + (p.wait_min >= OLD_MIN ? ' · รอนานผิดปกติ อาจกลับไปแล้ว' : '');
        return li;
    }

    function waitShort(min) {
        return min < 60 ? min + ' น.' : Math.floor(min / 60) + ' ชม. ' + (min % 60) + ' น.';
    }

    function ptRow(p, i) {
        if (i >= FULL_ROWS) return ptRowCompact(p, i);
        var li = el('li', 'cq-rp' + (i === 0 ? ' next' : '') + (p.wait_min >= OLD_MIN ? ' old' : '') + (p.pending ? ' pend' : ''));
        var q = el('div', 'cq-rp-q');
        q.appendChild(el('small', null, 'คิว'));
        q.appendChild(el('b', null, p.q ? String(p.q) : '–'));
        li.appendChild(q);
        var body = el('div', 'cq-rp-body');
        var top = el('div', 'cq-rp-top');
        top.appendChild(el('b', 'cq-rp-name', p.name || ('HN ' + p.hn)));
        if (p.wait_min >= OLD_MIN) top.appendChild(el('span', 'cq-rp-tag stale', 'รอนาน'));
        else if (i === 0) top.appendChild(el('span', 'cq-rp-tag', 'ถัดไป'));
        body.appendChild(top);
        // 2 บรรทัดคงที่ (ไม่ตัดบรรทัดตามความกว้าง) ให้แถวคิวสูงเท่ากันทุกห้อง
        body.appendChild(el('div', 'cq-rp-sub', 'HN ' + p.hn));
        body.appendChild(el('div', 'cq-rp-sub', 'มา ' + p.time + ' · รอ ' + waitText(p.wait_min)));
        if (p.pending) body.appendChild(el('div', 'cq-rp-pend', 'ส่งจากระบบนี้ รอ HOSxP อัปเดต'));
        li.appendChild(body);
        if (p.wait_min >= OLD_MIN) li.title = 'รอนานผิดปกติ — อาจกลับไปแล้วแต่ยังไม่ได้ปิด visit ใน HOSxP';
        return li;
    }

    // busy = มีคนกำลังพบแพทย์อยู่ → ไม่บอกว่า "พร้อมรับคนไข้"
    function ptList(rows, busy) {
        if (!rows.length) {
            var ok = el('div', 'cq-room-free' + (busy ? ' busy' : ''));
            ok.appendChild(el('span', 'cq-room-free-dot'));
            ok.appendChild(el('span', null, busy ? 'ไม่มีคิวรอต่อ' : 'ไม่มีคิวรอ พร้อมรับคนไข้'));
            return ok;
        }
        var wrap = el('div', 'cq-rp-wrap');
        // 3 คนแรกอยู่กับที่ (ไม่เลื่อนหาย) · ที่เหลือเป็นรายการย่อที่เลื่อนในกล่อง
        var top = el('ol', 'cq-rp-list top');
        rows.slice(0, FULL_ROWS).forEach(function (p, i) { top.appendChild(ptRow(p, i)); });
        wrap.appendChild(top);
        if (rows.length <= FULL_ROWS) return wrap;
        wrap.appendChild(el('div', 'cq-rp-sep', 'คิวถัดไปอีก ' + (rows.length - FULL_ROWS) + ' คน'));
        var ol = el('ol', 'cq-rp-list rest');
        ol.start = FULL_ROWS + 1;
        rows.slice(FULL_ROWS).forEach(function (p, i) { ol.appendChild(ptRow(p, i + FULL_ROWS)); });
        wrap.appendChild(ol);
        // รายการยาวจนต้องเลื่อน: เงาจางด้านล่าง + บอกว่ายังมีอีก (ซ่อนเมื่อเลื่อนถึงท้าย)
        var foot = el('div', 'cq-rp-foot', 'ทั้งหมด ' + rows.length + ' คิว · เลื่อนในกล่องดูเพิ่ม ↓');
        foot.hidden = true;
        wrap.appendChild(foot);
        var check = function () {
            var more = ol.scrollHeight - ol.scrollTop - ol.clientHeight > 4;
            wrap.classList.toggle('more', more);
            foot.hidden = !more;
        };
        ol.addEventListener('scroll', check, { passive: true });
        requestAnimationFrame(check);
        setTimeout(check, 60); // เผื่อแท็บอยู่เบื้องหลัง (requestAnimationFrame ไม่ทำงาน)
        return wrap;
    }

    // กำลังพบแพทย์ = ห้องเรียกแล้ว (ช่อง "เวลาเรียก" ใน HOSxP) แต่ HOSxP ยังไม่บันทึกว่าตรวจเสร็จ
    // ความสูงคงที่ทุกการ์ด (มี/ไม่มีคนในห้อง) ให้รายชื่อคิวรอของทุกห้องเริ่มระดับเดียวกัน
    // แสดงคนที่เรียกล่าสุดคนเดียว ถ้าเรียกค้างไว้หลายคน บอกจำนวนที่หัวกล่อง (ดูทั้งหมดได้จาก title)
    function examBox(rows) {
        if (!rows.length) {
            var idle = el('div', 'cq-inexam idle');
            idle.appendChild(el('div', 'cq-inexam-h', 'กำลังพบแพทย์'));
            idle.appendChild(el('div', 'cq-inexam-idle', 'ยังไม่มี — ห้องยังไม่ได้เรียกคิวใน HOSxP'));
            return idle;
        }
        var p = rows[0];
        var box = el('div', 'cq-inexam');
        box.appendChild(el('div', 'cq-inexam-h', 'กำลังพบแพทย์' + (rows.length > 1 ? ' · เรียกไว้ ' + rows.length + ' คน' : '')));
        if (rows.length > 1) {
            box.title = rows.map(function (x) { return 'คิว ' + (x.q || '-') + ' ' + (x.name || x.hn) + (x.called ? ' · เรียก ' + x.called : ''); }).join('\n')
                + '\nคนที่ตรวจเสร็จแล้วจะหายไปเมื่อ HOSxP บันทึกผลตรวจ';
        }
        var r = el('div', 'cq-inexam-row');
        var q = el('div', 'cq-inexam-q');
        q.appendChild(el('small', null, 'คิว'));
        q.appendChild(el('b', null, p.q ? String(p.q) : '–'));
        r.appendChild(q);
        var b = el('div', 'cq-inexam-body');
        b.appendChild(el('b', 'cq-inexam-name', p.name || ('HN ' + p.hn)));
        b.appendChild(el('div', 'cq-inexam-sub', 'HN ' + p.hn));
        b.appendChild(el('div', 'cq-inexam-sub', (p.called ? 'เรียก ' + p.called + ' น.' : 'เรียกแล้ว')
            + (p.exam_min !== null && p.exam_min !== undefined ? ' · ' + waitText(p.exam_min) + 'แล้ว' : '')));
        r.appendChild(b);
        box.appendChild(r);
        return box;
    }

    // ---------- แพทย์ / ห้อง ----------

    function chips(d, rs) {
        var wrap = el('div', 'cq-room-chips');
        var done = rs ? rs.done : d.done;
        var dc = el('span', 'cq-mchip ok', 'ตรวจแล้ววันนี้ ' + done);
        if (rs) dc.title = 'นับคนไข้ที่อยู่คิวห้องนี้ใน HOSxP และตรวจเสร็จแล้ว';
        wrap.appendChild(dc);
        // "★ แนะนำ" อยู่ใต้เลขห้อง · หมายเหตุเวรอยู่ท้ายการ์ด — แถวนี้จึงสูงเท่ากันทุกการ์ด
        return wrap;
    }

    // ---------- ช่วงเวลา: เลือกดูว่าช่วงไหนมาแล้วกี่คน / ตรวจแล้วกี่คน (นับตามเวลามาถึง) ----------

    var viewSlot = null; // null = ช่วงปัจจุบัน (เลื่อนตามเวลาเอง)

    function curSlotId() { return board.slot ? board.slot.id : null; }
    function shownSlotId() { return viewSlot !== null ? viewSlot : curSlotId(); }
    function slotInfo(id) {
        var s = null;
        (board.slots || []).forEach(function (x) { if (x.id === id) s = x; });
        return s;
    }

    // แถบสถิติของช่วงที่เลือกในการ์ดแพทย์
    // rs = สถิติห้อง (นับตามคิวห้องใน HOSxP) — ถ้ามี ใช้จำนวนคนของห้อง ส่วนโควต้า/ปิดช่วงยังเป็นของแพทย์
    function slotLine(d, rs) {
        var id = shownSlotId();
        var q = d.slots && d.slots[String(id)];
        var info = slotInfo(id);
        if (!q || !info) return null;
        var c = rs && rs.slots ? rs.slots[String(id)] : null;
        var s = c ? { in: c.in, wait: c.wait, exam: c.exam, done: c.done, quota: q.quota, off: q.off } : q;
        // 4 ช่องคงที่ (สูงเท่ากันทุกการ์ด ไม่ตัดบรรทัดตามจำนวนชิป)
        var line = el('div', 'cq-slotline' + (id === curSlotId() ? ' now' : ''));
        line.appendChild(el('div', 'cq-slotline-t', (id === curSlotId() ? 'ช่วงนี้ ' : 'ช่วง ') + info.label + ' น.'));
        var grid = el('div', 'cq-slotcells');
        function cell(val, label, cls, title) {
            var c = el('div', 'cq-slotcell' + (cls ? ' ' + cls : ''));
            c.appendChild(el('b', null, val));
            c.appendChild(el('span', null, label));
            if (title) c.title = title;
            grid.appendChild(c);
        }
        if (s.off) {
            cell('ปิด', 'ช่วงนี้', 'warn', 'ปิดรับคนไข้ช่วงนี้');
        } else if (board.clinic.control && s.quota > 0) {
            cell(s.in + '/' + s.quota, 'มาแล้ว', s.in >= s.quota ? 'bad' : '', 'มาแล้ว / โควต้า (นับตามเวลามาถึง)');
        } else {
            cell(String(s.in), board.clinic.control ? 'มา·ไม่มีโควต้า' : 'มาแล้ว', board.clinic.control ? 'warn' : '');
        }
        cell(String(s.done), 'ตรวจแล้ว', s.done ? 'ok' : '', 'คนไข้ที่มาช่วงนี้ และตรวจเสร็จแล้ว');
        // ไม่แสดง "ยังรอ / กำลังพบแพทย์" ซ้ำกับตัวเลขรอและกล่องกำลังพบแพทย์ด้านบน — แสดงโควต้าที่เหลือแทน
        if (!s.off && board.clinic.control && s.quota > 0) {
            var left = s.quota - s.in;
            cell(left > 0 ? String(left) : (left === 0 ? 'เต็ม' : '+' + (-left)), left >= 0 ? 'รับได้อีก' : 'เกินโควต้า',
                left > 0 ? '' : 'bad', 'โควต้าที่เหลือของช่วงนี้');
        }
        line.appendChild(grid);
        return line;
    }

    function renderSlotBar(docs) {
        var bar = document.getElementById('cqSlotBar');
        bar.textContent = '';
        var slots = board.slots || [];
        if (!slots.length) return;
        var id = shownSlotId();
        if (viewSlot !== null && !slotInfo(viewSlot)) viewSlot = null;
        bar.appendChild(el('span', 'cq-slotbar-label', 'ช่วงเวลา'));
        var group = el('div', 'cq-slotbar-btns');
        group.setAttribute('role', 'radiogroup');
        group.setAttribute('aria-label', 'เลือกช่วงเวลาที่จะดูจำนวนคนไข้');
        slots.forEach(function (s) {
            var isCur = s.id === curSlotId();
            var b = el('button', 'cq-slotbtn' + (isCur ? ' now' : '') + (s.past ? ' past' : ''));
            b.type = 'button';
            b.setAttribute('role', 'radio');
            b.setAttribute('aria-checked', s.id === id ? 'true' : 'false');
            b.dataset.slot = s.id;
            b.appendChild(el('span', null, s.label));
            if (isCur) b.appendChild(el('small', null, 'ตอนนี้'));
            group.appendChild(b);
        });
        bar.appendChild(group);
        // รวมในช่วงที่เลือก: คลินิกที่มีห้องตรวจนับตามห้อง (คิวห้องใน HOSxP) · ไม่มีห้องนับตามแพทย์ · โควต้ารวมของแพทย์
        var tot = { in: 0, quota: 0, done: 0, wait: 0 };
        var rooms = (board.rooms || []).filter(function (r) { return r.stat; });
        docs.forEach(function (d) {
            var s = d.slots && d.slots[String(id)];
            if (!s) return;
            tot.quota += s.quota;
            if (!rooms.length) { tot.in += s.in; tot.done += s.done; tot.wait += s.wait; }
        });
        rooms.forEach(function (r) {
            var s = r.stat.slots[String(id)];
            if (s) { tot.in += s.in; tot.done += s.done; tot.wait += s.wait; }
        });
        var sum = el('span', 'cq-slotbar-sum');
        sum.appendChild(el('b', null, String(tot.in)));
        sum.appendChild(document.createTextNode((board.clinic.control && tot.quota ? '/' + tot.quota : '') + ' มาแล้ว · '));
        sum.appendChild(el('b', null, String(tot.done)));
        sum.appendChild(document.createTextNode(' ตรวจแล้ว'));
        // จำนวนรอดูได้จาก "รอตรวจทั้งหมด" ด้านบน — ตรงนี้บอกโควต้าที่เหลือของช่วงแทน
        if (board.clinic.control && tot.quota) {
            var tl = tot.quota - tot.in;
            sum.appendChild(document.createTextNode(' · '));
            sum.appendChild(el('b', null, tl > 0 ? String(tl) : (tl === 0 ? 'เต็ม' : '+' + (-tl))));
            sum.appendChild(document.createTextNode(tl >= 0 ? (tl > 0 ? ' รับได้อีก' : '') : ' เกินโควต้า'));
        }
        sum.title = 'นับตามเวลามาถึงของคนไข้ในช่วงนี้';
        bar.appendChild(sum);
    }

    document.getElementById('cqSlotBar').addEventListener('click', function (e) {
        var b = e.target.closest('[data-slot]');
        if (!b) return;
        var id = parseInt(b.dataset.slot, 10);
        viewSlot = id === curSlotId() ? null : id;
        render();
    });

    // ชื่อแพทย์ = ตัวเลือก (เฉพาะผู้มีสิทธิ์จัดเวร) · แพทย์ที่อยู่ห้องอื่นเลือกได้ = ย้ายห้อง
    function picker(room, docs, current) {
        if (!canEdit) return el('div', 'cq-room-doc', current ? current.name : 'ยังไม่มีแพทย์');
        var sel = el('select', 'cq-room-sel' + (current ? '' : ' empty'));
        sel.setAttribute('aria-label', 'แพทย์ประจำห้อง ' + room.name);
        sel.dataset.room = room.room_id;
        var none = el('option', null, current ? '— ให้ห้องว่าง —' : '+ เลือกแพทย์');
        none.value = '0';
        sel.appendChild(none);
        var roomOf = {};
        (board.rooms || []).forEach(function (r) { if (r.plan_id !== null) roomOf[r.plan_id] = r.name; });
        docs.forEach(function (d) {
            var o = el('option', null, d.name + (roomOf[d.plan_id] && d.plan_id !== room.plan_id ? '  (ห้อง ' + roomOf[d.plan_id] + ')' : ''));
            o.value = String(d.plan_id);
            if (current && d.plan_id === current.plan_id) o.selected = true;
            sel.appendChild(o);
        });
        if (!current) none.selected = true;
        if (!current) return sel;
        // มีแพทย์แล้ว: แสดงชื่อเป็นข้อความ (ตัดบรรทัดได้) แล้ววางตัวเลือกโปร่งใสทับ กดชื่อ = เปลี่ยนแพทย์
        var wrap = el('label', 'cq-room-pickname');
        wrap.appendChild(el('span', 'cq-room-doc', current.name));
        wrap.appendChild(el('span', 'cq-room-caret', '▾'));
        sel.classList.add('overlay');
        wrap.appendChild(sel);
        return wrap;
    }

    function roomCard(opts) {
        var d = opts.doctor;
        var rs = opts.stat || null;
        var card = el('section', 'cq-room' + (d ? '' : ' empty') + (d && d.plan_id === board.pick ? ' pick' : '') + (d && d.slot_off ? ' paused' : ''));
        if (opts.id) card.id = opts.id;
        var head = el('header', 'cq-room-head');
        var isPick = !!d && d.plan_id === board.pick;
        if (opts.roomName !== undefined || isPick) {
            // คอลัมน์ซ้าย: เลขห้อง + ป้ายแนะนำ (ไม่ดันความสูงฝั่งชื่อแพทย์)
            var side = el('div', 'cq-room-side');
            if (opts.roomName !== undefined) {
                var no = el('div', 'cq-room-no');
                no.appendChild(el('small', null, 'ห้อง'));
                no.appendChild(el('b', null, opts.roomName));
                side.appendChild(no);
            }
            if (isPick) side.appendChild(el('span', 'cq-room-pickbadge', '★ แนะนำ'));
            head.appendChild(side);
        }
        var who = el('div', 'cq-room-who');
        who.appendChild(opts.title);
        if (d) {
            // จำนวนรอ (ตัวใหญ่) อยู่ใต้ชื่อแพทย์ แถวเดียวกับป้ายตรวจแล้ว/โควต้า — ชื่อแพทย์ได้ความกว้างเต็ม
            var stat = el('div', 'cq-room-stat');
            var n = el('div', 'cq-room-n');
            n.appendChild(el('b', null, String(opts.rows.length)));
            n.appendChild(el('span', null, 'รอ'));
            stat.appendChild(n);
            stat.appendChild(chips(d, rs));
            who.appendChild(stat);
        } else if (rs && rs.done) {
            who.appendChild(chips({ done: 0 }, rs));
        }
        head.appendChild(who);
        card.appendChild(head);
        // ลำดับคงที่ทุกการ์ด: หัว → กำลังพบแพทย์ → ช่วงเวลา → คิวรอ → (คำเตือน/หมายเหตุท้ายการ์ด)
        // ส่วนที่สูงไม่เท่ากันอยู่ท้ายการ์ด รายชื่อคิวรอของทุกห้องจึงเริ่มระดับเดียวกัน
        var exam = opts.exam || [];
        if (d || exam.length) card.appendChild(examBox(exam));
        var sl = d ? slotLine(d, rs) : null;
        if (sl) card.appendChild(sl);
        if (d || opts.rows.length) card.appendChild(ptList(opts.rows, exam.length > 0));
        // HOSxP บันทึกแพทย์ผู้ตรวจในห้องนี้เป็นคนอื่น → แพทย์ประจำห้องที่เลือกไว้น่าจะไม่ตรง
        if (rs && rs.other_doctors && rs.other_doctors.length) {
            var warn = el('div', 'cq-room-mismatch');
            warn.appendChild(el('b', null, 'HOSxP: ห้องนี้ตรวจโดย '));
            warn.appendChild(document.createTextNode(rs.other_doctors.map(function (o) { return o.name + ' (' + o.n + ' คน)'; }).join(', ')));
            if (d) warn.appendChild(el('span', null, ' — ไม่ตรงกับแพทย์ที่เลือกไว้ กดชื่อแพทย์เพื่อเปลี่ยน'));
            card.appendChild(warn);
        }
        if (d && d.note) card.appendChild(el('div', 'cq-room-note', 'หมายเหตุ: ' + d.note));
        return card;
    }

    // ---------- วาดทั้งหน้า ----------

    function render() {
        document.getElementById('cqAt').textContent = board.fetched_at;
        errBox.hidden = !board.error;
        errBox.textContent = board.error || '';

        var byCode = {};
        // code '' = HOSxP ส่งเข้าคิวห้องแล้ว แต่ห้องยังไม่มีแพทย์ประจำ → แสดงในการ์ดห้องนั้น
        var byRoom = {};
        board.sent.forEach(function (p) {
            if (p.code) (byCode[p.code] = byCode[p.code] || []).push(p);
            else (byRoom[p.room_id] = byRoom[p.room_id] || []).push(p);
        });
        // กำลังพบแพทย์: ห้องที่ใช้คิว HOSxP ดูตามห้อง · ไม่มีห้อง ดูตามแพทย์
        var examRoom = {}, examCode = {};
        (board.in_exam || []).forEach(function (p) {
            if (p.room_id !== null && p.room_id !== undefined) (examRoom[p.room_id] = examRoom[p.room_id] || []).push(p);
            else if (p.code) (examCode[p.code] = examCode[p.code] || []).push(p);
        });
        var docs = board.doctors.filter(function (d) { return d.status === 'on'; })
            .sort(function (a, b) { return a.name.localeCompare(b.name, 'th'); });
        var offDocs = {};
        board.doctors.forEach(function (d) { if (d.status !== 'on') offDocs[d.code] = d; });
        var byPlan = {};
        docs.forEach(function (d) { byPlan[d.plan_id] = d; });
        var rooms = board.rooms || [];
        var shown = {};
        var total = board.queue.length + board.sent.length;

        // สถิติ
        elStats.textContent = '';
        var roomed = rooms.filter(function (r) { return r.plan_id !== null && byPlan[r.plan_id]; }).length;
        [['รอตรวจทั้งหมด', total, ''], ['รอเลือกแพทย์', board.queue.length, board.queue.length ? 'warn' : ''],
         ['แพทย์ออกตรวจ', docs.length, ''], rooms.length ? ['เข้าห้องแล้ว', roomed + '/' + rooms.length, ''] : null]
            .forEach(function (s) {
                if (!s) return;
                var c = el('div', 'cq-stat2 ' + s[2]);
                c.appendChild(el('b', null, String(s[1])));
                c.appendChild(el('span', null, s[0]));
                elStats.appendChild(c);
            });

        var inRoom = {};
        rooms.forEach(function (r) { if (r.plan_id !== null) inRoom[r.plan_id] = true; });
        renderSlotBar(board.doctors.filter(function (d) { return d.status === 'on' || inRoom[d.plan_id]; }));

        // แถบรอเลือกแพทย์
        elWait.textContent = '';
        elWait.className = 'cq-waitstrip' + (board.queue.length ? ' has' : '');
        if (board.queue.length) {
            var h = el('div', 'cq-waitstrip-head');
            h.appendChild(el('b', null, 'รอเลือกแพทย์ ' + board.queue.length + ' คน'));
            var go = el('a', null, 'ไปเลือกแพทย์ →');
            go.href = 'board.php?clinic=' + encodeURIComponent(clinic);
            h.appendChild(go);
            elWait.appendChild(h);
            var ul = el('ul', 'cq-waitstrip-list');
            board.queue.forEach(function (p) {
                var li = el('li', p.wait_min >= OLD_MIN ? 'old' : '');
                if (p.q) li.appendChild(el('b', null, 'คิว ' + p.q));
                li.appendChild(el('span', null, (p.name || 'HN ' + p.hn) + ' · รอ ' + waitText(p.wait_min)));
                ul.appendChild(li);
            });
            elWait.appendChild(ul);
        } else {
            elWait.appendChild(el('span', null, '✓ ไม่มีคนไข้รอเลือกแพทย์'));
        }

        // คำอธิบายแถวจาง (แสดงเฉพาะเมื่อมีคนรอนาน)
        var staleN = board.queue.concat(board.sent).filter(function (p) { return p.wait_min >= OLD_MIN; }).length;
        var note = document.getElementById('cqStaleNote');
        if (note) {
            note.hidden = !staleN;
            note.textContent = staleN ? 'แถวสีจางและป้าย "รอนาน" = รอเกิน ' + (OLD_MIN % 60 ? OLD_MIN + ' นาที' : (OLD_MIN / 60) + ' ชั่วโมง')
                + ' นับจากเวลามาถึง (' + staleN + ' คน) — ส่วนใหญ่ตรวจ/กลับไปแล้ว แต่ยังไม่ได้ปิด visit ใน HOSxP จึงยังนับเป็นคิวรอ' : '';
        }

        // ห้องตรวจ / การ์ดแพทย์
        elRooms.textContent = '';
        elRooms.className = 'cq-roomgrid' + (rooms.length ? ' n' + Math.min(rooms.length, 6) : '');
        if (rooms.length) {
            rooms.forEach(function (room) {
                var d = room.plan_id !== null ? byPlan[room.plan_id] : null;
                if (d) shown[d.code] = true;
                elRooms.appendChild(roomCard({
                    id: d ? 'd-' + d.code : 'r-' + room.room_id, roomName: room.name, doctor: d, stat: room.stat,
                    exam: (examRoom[room.room_id] || []).concat(d ? (examCode[d.code] || []) : []),
                    title: picker(room, docs, d), rows: d ? (byCode[d.code] || []) : (byRoom[room.room_id] || [])
                }));
            });
        } else {
            docs.forEach(function (d) {
                shown[d.code] = true;
                var t = el('div', 'cq-room-doc', d.name);
                elRooms.appendChild(roomCard({
                    id: 'd-' + d.code, roomName: d.room || undefined, doctor: d, title: t, rows: byCode[d.code] || [],
                    exam: examCode[d.code] || []
                }));
            });
        }

        // แพทย์ที่ยังไม่ได้เข้าห้อง / แพทย์ไม่ได้ออกตรวจแต่ยังมีคนไข้รอ
        elOthers.textContent = '';
        var pending = docs.filter(function (d) { return !shown[d.code]; });
        var pendingCodes = {};
        pending.forEach(function (d) { pendingCodes[d.code] = true; });
        var leftover = Object.keys(byCode).filter(function (c) { return !shown[c] && !pendingCodes[c]; });
        if (pending.length || leftover.length) {
            var box = el('div', 'cq-others');
            if (pending.length) {
                var row = el('div', 'cq-others-row');
                row.appendChild(el('span', 'cq-others-label', 'ออกตรวจแต่ยังไม่ได้เข้าห้อง'));
                pending.forEach(function (d) {
                    var c = el('span', 'cq-dchip', d.name);
                    c.id = 'd-' + d.code;
                    c.appendChild(el('b', null, String((byCode[d.code] || []).length)));
                    c.title = 'เลือกห้องให้แพทย์คนนี้เพื่อดูรายชื่อคิว';
                    row.appendChild(c);
                });
                if (canEdit) row.appendChild(el('span', 'cq-others-hint', 'เลือกจากช่อง "+ เลือกแพทย์" ในห้องว่าง'));
                box.appendChild(row);
            }
            if (leftover.length) {
                var row2 = el('div', 'cq-others-row');
                row2.appendChild(el('span', 'cq-others-label warn', 'มีคนไข้รอ แต่แพทย์ไม่ได้ออกตรวจตามเวร'));
                leftover.forEach(function (code) {
                    var rows = byCode[code];
                    var c = el('span', 'cq-dchip warn', rows[0].doctor + (offDocs[code] ? ' (' + offDocs[code].status_label + ')' : ''));
                    c.id = 'd-' + code;
                    c.appendChild(el('b', null, String(rows.length)));
                    c.title = rows.map(function (p) { return (p.q ? 'คิว ' + p.q + ' · ' : '') + (p.name || p.hn); }).join('\n');
                    row2.appendChild(c);
                });
                var lk = el('a', 'cq-others-hint', 'แก้ที่หน้าเวรแพทย์');
                lk.href = 'duty.php';
                row2.appendChild(lk);
                box.appendChild(row2);
            }
            elOthers.appendChild(box);
        }

        if (location.hash && !render.scrolled) {
            var target = document.getElementById(location.hash.slice(1));
            if (target) { target.scrollIntoView({ block: 'center' }); target.classList.add('focus'); }
            render.scrolled = true;
        }
    }

    // ---------- บันทึกแพทย์ประจำห้อง ----------

    elRooms.addEventListener('change', function (e) {
        var sel = e.target.closest('select[data-room]');
        if (!sel) return;
        sel.disabled = true;
        var body = new URLSearchParams({ room: sel.dataset.room, plan: sel.value, csrf: csrf });
        fetch('api/room.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF': csrf } })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.message || 'บันทึกไม่สำเร็จ'); return j; }); })
            .then(function (j) { window.UiToast && UiToast(j.message, 'ok'); })
            .catch(function (err) { window.UiToast && UiToast(err.message || 'บันทึกไม่สำเร็จ', 'err'); })
            .finally(function () { sel.blur(); load(true); });
    });

    // ---------- โหลดใหม่ ----------

    var timer;
    function load(force) {
        if (document.hidden) return;
        // กำลังเลือกแพทย์ประจำห้องอยู่ → ไม่วาดใหม่ทับ
        if (!force && document.activeElement && document.activeElement.matches('select[data-room]')) return;
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
                document.querySelectorAll('.cq-tab[data-clinic]').forEach(function (t) {
                    var c = res.body.counts[t.dataset.clinic] || { wait: 0, unassigned: 0 };
                    t.querySelector('.cq-tab-n').textContent = c.wait;
                    var u = t.querySelector('.cq-tab-u');
                    u.textContent = c.unassigned;
                    u.hidden = !c.unassigned;
                });
            })
            .catch(function () {
                errBox.hidden = false;
                errBox.textContent = 'โหลดข้อมูลใหม่ไม่ได้ — จะลองใหม่อัตโนมัติ';
            });
    }

    render();
    timer = setInterval(load, refresh);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
})();

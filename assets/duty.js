/* หน้าเวรแพทย์: กดแล้วบันทึกทันที แสดงผลก่อน แล้วย้อนกลับถ้าบันทึกไม่สำเร็จ */
(function () {
    'use strict';
    var root = document.getElementById('cqDuty');
    var csrf = document.querySelector('meta[name="csrf"]').content;

    var dateInput = document.querySelector('[data-cq-autosubmit]');
    if (dateInput) dateInput.addEventListener('change', function () { dateInput.form.submit(); });
    if (!root) return;

    function save(row, field, value) {
        var body = new URLSearchParams({ duty: row.dataset.duty, field: field, value: value, csrf: csrf });
        row.classList.add('saving');
        return fetch('api/duty.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF': csrf } })
            .then(function (r) {
                return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.message || 'บันทึกไม่สำเร็จ'); return j; });
            })
            .then(function (j) { window.UiToast && UiToast(j.message, 'ok'); return j; })
            .catch(function (e) {
                window.UiToast && UiToast((e && e.message) || 'บันทึกไม่สำเร็จ กรุณาโหลดหน้าใหม่', 'err');
                throw e;
            })
            .finally(function () { row.classList.remove('saving'); });
    }

    root.addEventListener('click', function (e) {
        var seg = e.target.closest('.cq-seg-btn');
        if (seg) {
            var row = seg.closest('[data-duty]');
            if (seg.getAttribute('aria-checked') === 'true') return;
            var before = row.querySelector('.cq-seg-btn[aria-checked="true"]');
            var setState = function (btn) {
                row.querySelectorAll('.cq-seg-btn').forEach(function (b) { b.setAttribute('aria-checked', b === btn ? 'true' : 'false'); });
                row.className = row.className.replace(/\bs-\w+/, 's-' + btn.dataset.status);
                var cnt = document.getElementById('cqOnCount');
                if (cnt) cnt.textContent = root.querySelectorAll('.cq-seg-btn[data-status="on"][aria-checked="true"]').length;
            };
            setState(seg);
            save(row, 'status', seg.dataset.status).catch(function () { if (before) setState(before); });
            return;
        }
        var pill = e.target.closest('.cq-pill');
        if (pill) {
            var r = pill.closest('[data-duty]');
            var was = pill.getAttribute('aria-pressed');
            pill.setAttribute('aria-pressed', was === 'true' ? 'false' : 'true');
            save(r, 'slot', pill.dataset.slot).catch(function () { pill.setAttribute('aria-pressed', was); });
        }
    });

    // หมายเหตุ: บันทึกเมื่อออกจากช่องหรือกด Enter และเฉพาะเมื่อค่าเปลี่ยน
    root.querySelectorAll('[data-note]').forEach(function (inp) {
        inp.dataset.saved = inp.value;
        function commit() {
            if (inp.value === inp.dataset.saved) return;
            var v = inp.value;
            save(inp.closest('[data-duty]'), 'note', v).then(function () { inp.dataset.saved = v; }, function () {});
        }
        inp.addEventListener('change', commit);
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
    });
})();

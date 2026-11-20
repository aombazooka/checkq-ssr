/* popup โควต้า: ปุ่ม "ใส่ให้ทุกช่วง" (ผูกแบบ delegate เพราะเนื้อหา popup โหลดทีหลัง) */
(function () {
    'use strict';
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-cq-fill]');
        if (!btn) return;
        var form = btn.closest('form');
        var src = document.getElementById(btn.getAttribute('data-cq-fill'));
        var v = src && src.value.trim();
        if (v === '' || !/^\d+$/.test(v)) { src && src.focus(); return; }
        form.querySelectorAll('.cq-q').forEach(function (inp) {
            inp.value = v;
            inp.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
    // Enter ในช่อง "ใส่เท่ากันทุกช่วง" = เติมทุกช่วง ไม่ใช่ส่งฟอร์ม
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.id === 'qeAll') {
            e.preventDefault();
            var b = e.target.closest('form').querySelector('[data-cq-fill]');
            b && b.click();
        }
    });
})();

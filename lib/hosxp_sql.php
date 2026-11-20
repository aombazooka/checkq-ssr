<?php
declare(strict_types=1);

/*
 * SQL ที่อ่านจาก HOSxP replica — ใช้เมื่อ config hosxp.mode = 'live'
 * ได้จาก AI ของ HOSxP ที่ตรวจโครงสร้างฐานจริง (2026-11-19) + ปรับตามผลตรวจข้อมูลวันที่ 2026-11-18
 *
 * ข้อเท็จจริงที่ใช้ออกแบบ:
 * - รหัสคลินิก 001/002/... อยู่ที่ clinic_visit ซึ่งมีเฉพาะ visit ที่มาตามนัด
 *   visit ที่ไม่มีนัด (walk-in) ไม่มี clinic_visit → ระบบแปลง main_dep เป็นคลินิกด้วยตาราง cq_dep_map (หน้า "ตั้งค่าคลินิก")
 * - "ไม่ระบุแพทย์" ใช้รหัสแพทย์หลอก (0029) ไม่ใช่ค่าว่าง → ตั้งใน config hosxp.unspecified_doctors
 * - ovstost 01 รอผลตรวจ / 02 รอพบแพทย์ = รอ, รหัสอื่น = ตรวจแล้ว/ส่งต่อแล้ว
 * - cur_dep_busy ในฐานนี้เป็น 'N' ทั้งหมด จึงยังไม่มีสถานะ "กำลังตรวจ" จริง
 *
 * HOSxP ของโรงพยาบาลเป็น PostgreSQL — SQL ทุกตัวเขียนแบบที่ใช้ได้ทั้ง PostgreSQL และ MySQL
 * (ไม่ใช้ DATE_SUB/CURDATE/IFNULL/backtick · วันที่คำนวณจาก PHP แล้วส่งเป็นพารามิเตอร์)
 * ทุก query ใช้ named parameter (:d = วันที่ Y-m-d, :hn, :since) และแต่ละชื่อใช้ครั้งเดียว
 */

return [

    // 1) รายชื่อแพทย์ → doctor_code, name
    //    เฉพาะตำแหน่งแพทย์ (position_id = 1) ที่ยังทำงานอยู่: มี visit ใน 90 วันล่าสุด หรือมีนัดตั้งแต่ 90 วันก่อนเป็นต้นไป
    //    (ovst.doctor มีพยาบาล/ทันตแพทย์/แพทย์แผนไทยปนอยู่ จึงต้องกรองตำแหน่ง)
    'doctors' => "
        SELECT d.code AS doctor_code, d.name AS name
        FROM doctor d
        WHERE d.active = 'Y'
          AND d.position_id = 1
          AND (EXISTS (SELECT 1 FROM ovst o WHERE o.doctor = d.code AND o.vstdate >= :since)
               OR EXISTS (SELECT 1 FROM oapp a WHERE a.doctor = d.code AND a.nextdate >= :since2))
        ORDER BY d.name",

    // 2) visit วันนี้ทั้งโรงพยาบาล (OPD)
    //    → vn, hn, arrive_time, doctor_code, clinic_code (NULL = ไม่มีนัด), dep_code (main_dep ใช้แทนเมื่อไม่มีคลินิก),
    //      patient_name (แสดงในรายชื่อคิวที่จุดคัดกรอง), status
    'visits_today' => "
        SELECT
            o.vn                  AS vn,
            o.hn                  AS hn,
            o.vsttime             AS arrive_time,
            o.oqueue              AS queue_no,
            NULLIF(o.doctor, '')  AS doctor_code,
            cv.clinic             AS clinic_code,
            o.main_dep            AS dep_code,
            CONCAT(COALESCE(p.pname,''), COALESCE(p.fname,''), ' ', COALESCE(p.lname,'')) AS patient_name,
            CASE
                WHEN o.cur_dep_busy = 'Y'
                     AND (o.ovstost IS NULL OR o.ovstost IN ('01','02')) THEN 'exam'
                WHEN o.ovstost IS NULL OR o.ovstost IN ('01','02')       THEN 'wait'
                ELSE 'done'
            END                   AS status
        FROM ovst o
        LEFT JOIN clinic_visit cv ON cv.vn = o.vn
        LEFT JOIN patient p ON p.hn = o.hn
        WHERE o.vstdate = :d
          AND (o.an IS NULL OR o.an = '')
          AND NOT EXISTS (SELECT 1 FROM ovst_cancel c WHERE c.vn = o.vn)",

    // 2.1) ระบบคิว HOSxP วันนี้ (opd_qs_slot) — แหล่งเดียวกับหน้าจอคิวที่ช่อง "แพทย์" แสดง "คิวพบแพทย์ห้องตรวจ 2 (B)"
    //      แต่ละ visit มีหลายคิว: จุดซักประวัติ (G/S/DP/...) แล้วคิวห้องตรวจ (A/B/C/D/E) — คิวห้องตรวจใช้รหัสแพทย์หลอก
    //      0031/0037/0038/0039/0059 (ตั้งที่ cq_room.hosxp_qcode) ขณะที่ ovst.doctor ยังว่างจนกว่าแพทย์จะลงตรวจ
    //      ส่งทุกคิวของวัน PHP ใช้คิวล่าสุดของแต่ละ visit · called_at = ห้องเรียกคนไข้แล้ว (ช่อง "เวลาเรียก")
    //      (ไม่ใช้ opd_dep_queue — ห้องในตารางนั้นไม่ตรงกับหน้าจอคิว เช่น ส่งซ้ำหลายห้อง ตรวจ 2026-11-20)
    'room_slots_today' => "
        SELECT s.vn AS vn, s.doctor_code AS qcode, s.queue_datetime AS queued_at, s.call_datetime AS called_at
        FROM opd_qs_slot s
        WHERE s.schedule_date = :d
          AND s.vn IS NOT NULL AND s.vn <> ''
        ORDER BY s.queue_datetime, s.opd_qs_slot_id",

    // 3) นัดวันนี้ของ HN หนึ่งคน → hn, patient_name, clinic_code, app_time, doctor_code
    'patient_today' => "
        SELECT
            a.hn AS hn,
            CONCAT(COALESCE(p.pname,''), COALESCE(p.fname,''), ' ', COALESCE(p.lname,'')) AS patient_name,
            a.clinic   AS clinic_code,
            a.nexttime AS app_time,
            NULLIF(a.doctor, '') AS doctor_code
        FROM oapp a
        JOIN patient p ON p.hn = a.hn
        WHERE a.nextdate = :d
          AND a.hn = :hn
          AND (a.oapp_status_id IS NULL OR a.oapp_status_id <> 4)
          AND NOT EXISTS (SELECT 1 FROM oapp_cancel oc WHERE oc.oapp_id = a.oapp_id)
        ORDER BY a.nexttime, a.clinic",
];

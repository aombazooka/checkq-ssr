-- ฐานข้อมูลของ ระบบคิวแพทย์ OPD
SET NAMES utf8mb4;

-- สำเนาข้อมูลผู้ใช้จาก SSO (ไม่มีรหัสผ่าน) ไว้ให้ตารางอื่นอ้างอิง
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sso_sub        CHAR(36)     NOT NULL UNIQUE COMMENT 'sub จาก SSO (ถาวร ไม่เปลี่ยน)',
    username       VARCHAR(64)  NOT NULL,
    full_name      VARCHAR(150) NOT NULL DEFAULT '',
    employee_code  VARCHAR(32)  NULL,
    department     VARCHAR(100) NULL,
    email          VARCHAR(150) NULL,
    last_login_at  DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- จับคู่ sid ของ SSO กับ PHP session เพื่อให้ back-channel logout ลบ session ได้ถูกตัว
CREATE TABLE IF NOT EXISTS sso_sessions (
    sid             CHAR(64)     NOT NULL,
    php_session_id  VARCHAR(128) NOT NULL,
    user_id         VARCHAR(64)  NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (sid, php_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ตารางของระบบเริ่มเขียนต่อจากตรงนี้ เช่น
-- CREATE TABLE records (
--     id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--     ...
--     created_by  INT UNSIGNED NOT NULL,
--     created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     CONSTRAINT fk_records_user FOREIGN KEY (created_by) REFERENCES users(id)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================= ตารางของระบบคิวแพทย์ =================

-- คลินิก (รหัสตรงกับ HOSxP) + สวิตช์คุมโควต้า
CREATE TABLE IF NOT EXISTS cq_clinic (
    clinic_code    VARCHAR(10)  NOT NULL PRIMARY KEY,
    name           VARCHAR(150) NOT NULL,
    control_quota  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = ไม่คุมโควต้า แนะนำตามคนรออย่างเดียว',
    sort_no        INT          NOT NULL DEFAULT 0,
    active         TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ช่วงเวลาตรวจ
CREATE TABLE IF NOT EXISTS cq_slot (
    slot_id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label       VARCHAR(20) NOT NULL,
    start_time  TIME        NOT NULL,
    end_time    TIME        NOT NULL,
    sort_no     INT         NOT NULL DEFAULT 0,
    active      TINYINT(1)  NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายชื่อแพทย์ (สำเนาจาก HOSxP)
CREATE TABLE IF NOT EXISTS cq_doctor (
    doctor_code  VARCHAR(20)  NOT NULL PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    active       TINYINT(1)   NOT NULL DEFAULT 1,
    synced_at    DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- โควต้าประจำของแพทย์ในแต่ละคลินิก (ใช้ค่าเดิมทุกวันที่ออกตรวจ)
CREATE TABLE IF NOT EXISTS cq_plan (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    doctor_code  VARCHAR(20) NOT NULL,
    clinic_code  VARCHAR(10) NOT NULL,
    room         VARCHAR(50) NOT NULL DEFAULT '',
    workdays     VARCHAR(7)  NOT NULL DEFAULT '12345' COMMENT '1=จันทร์ ... 7=อาทิตย์',
    active       TINYINT(1)  NOT NULL DEFAULT 1,
    updated_by   INT UNSIGNED NULL,
    updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plan (doctor_code, clinic_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cq_plan_quota (
    plan_id  INT UNSIGNED NOT NULL,
    slot_id  INT UNSIGNED NOT NULL,
    quota    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (plan_id, slot_id),
    CONSTRAINT fk_pq_plan FOREIGN KEY (plan_id) REFERENCES cq_plan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- เวรแพทย์รายวัน (สร้างอัตโนมัติจากวันออกตรวจใน cq_plan · เจ้าหน้าที่ปรับสถานะ/ปิดบางช่วงได้)
CREATE TABLE IF NOT EXISTS cq_duty (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_date    DATE        NOT NULL,
    plan_id      INT UNSIGNED NOT NULL,
    status       ENUM('on','leave','meeting') NOT NULL DEFAULT 'on',
    slots_off    VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'slot_id ที่ปิด คั่นด้วย ,',
    note         VARCHAR(200) NOT NULL DEFAULT '',
    updated_by   INT UNSIGNED NULL,
    updated_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_duty (work_date, plan_id),
    CONSTRAINT fk_duty_plan FOREIGN KEY (plan_id) REFERENCES cq_plan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- กด "ส่งไปหมอคนนี้" — นับเป็นคิวชั่วคราวจนกว่า HOSxP จะเห็นคนไข้ (กันสองจุดลงทะเบียนส่งหมอคนเดียวกันพร้อมกัน)
CREATE TABLE IF NOT EXISTS cq_assign (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    work_date    DATE         NOT NULL,
    plan_id      INT UNSIGNED NOT NULL,
    slot_id      INT UNSIGNED NOT NULL,
    hn           VARCHAR(20)  NULL,
    suggested    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = ตรงกับที่ระบบแนะนำ',
    created_by   INT UNSIGNED NOT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_assign_day (work_date, plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cq_slot (slot_id, label, start_time, end_time, sort_no) VALUES
 (1,'08.00','08:00:00','08:59:59',1),(2,'09.00','09:00:00','09:59:59',2),(3,'10.00','10:00:00','10:59:59',3),
 (4,'11.00','11:00:00','11:59:59',4),(5,'13.00','13:00:00','13:59:59',5);

INSERT IGNORE INTO cq_clinic (clinic_code, name, sort_no) VALUES
 ('001','จิตเวชทั่วไป',1),('002','ยาเดิม',2),('004','คลินิกสุขภาพใจผู้สูงอายุ',3),('005','คลินิกใจดี',4),
 ('006','คลินิกเปี่ยมสุข',5),('007','คลินิกฟ้าใส',6),('008','คลินิกสราญใจวัยสูงอายุ',7),('017','ให้คำปรึกษาผู้ป่วย',8),
 ('021','Tele ยาไปรษณีย์',9),('022','OPD',10);
-- จุดซักประวัติ/แผนก (ovst.main_dep) → คลินิก ใช้กับ visit ที่ไม่มีนัด (ไม่มี clinic_visit)
CREATE TABLE IF NOT EXISTS cq_dep_map (
    dep_code     VARCHAR(10)  NOT NULL PRIMARY KEY,
    dep_name     VARCHAR(150) NOT NULL DEFAULT '',
    clinic_code  VARCHAR(10)  NULL COMMENT 'NULL = ไม่นับในระบบคิวแพทย์'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cq_clinic (clinic_code, name, sort_no) VALUES
 ('009','คลินิกติดตามต่อเนื่อง',11),('024','ยาไปรษณีย์',12);

INSERT IGNORE INTO cq_dep_map (dep_code, dep_name, clinic_code) VALUES
 ('115','จุดซักประวัติจิตเวชทั่วไป','001'),('004','ซักประวัติสุขภาพใจผู้สูงอายุ','004'),
 ('008','ซักประวัติใจดี','005'),('006','ซักประวัติเปี่ยมสุข','006'),('007','ซักประวัติฟ้าใส','007'),
 ('126','สราญใจวัยสูงอายุ','008'),('032','ซักประวัติคลายเครียด','017'),('130','ห้องบริการยาเดิม','002'),
 ('134','งาน Tele ยาไปรษณีย์','021'),('034','งานยาไปรษณีย์','024'),
 ('009','คลินิกจิตเวชฉุกเฉิน','015'),('030','แพทย์แผนไทย',NULL),('114','ศูนย์ญาติเยี่ยมและจำหน่าย',NULL),
 ('037','กายภาพบำบัด',NULL),('022','ทันตกรรม',NULL);
INSERT IGNORE INTO cq_clinic (clinic_code, name, control_quota, sort_no) VALUES ('015','จิตเวชฉุกเฉิน',0,13);
UPDATE cq_dep_map SET clinic_code = '015' WHERE dep_code = '009';
-- ช่วงเวลา 09.00–16.59 น. (ปิด 08.00) + ช่วงพัก 12.00–12.59 (ไม่มีโควต้า คนไข้ที่มาช่วงพักนับเข้าช่วงถัดไป)
ALTER TABLE cq_slot ADD COLUMN IF NOT EXISTS is_break TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = ช่วงพัก ไม่รับคนไข้';
UPDATE cq_slot SET active = 0 WHERE slot_id = 1;
INSERT IGNORE INTO cq_slot (slot_id, label, start_time, end_time, sort_no, is_break) VALUES
 (6,'12.00','12:00:00','12:59:59',12,1),(7,'14.00','14:00:00','14:59:59',14,0),
 (8,'15.00','15:00:00','15:59:59',15,0),(9,'16.00','16:00:00','16:59:59',16,0);
UPDATE cq_slot SET sort_no = HOUR(start_time);
-- สถานะ "ไม่ออก" เป็นค่าเริ่มต้นของเวรที่สร้างอัตโนมัติ (เจ้าหน้าที่กด "ออกตรวจ" เฉพาะคนที่มา)
ALTER TABLE cq_duty MODIFY status ENUM('on','off','leave','meeting') NOT NULL DEFAULT 'off';
UPDATE cq_duty SET status = 'off' WHERE work_date > CURDATE() AND status = 'on' AND updated_by IS NULL;
-- ห้องตรวจของแต่ละคลินิก
CREATE TABLE IF NOT EXISTS cq_room (
    room_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clinic_code  VARCHAR(10)  NOT NULL,
    name         VARCHAR(50)  NOT NULL,
    sort_no      INT          NOT NULL DEFAULT 0,
    active       TINYINT(1)   NOT NULL DEFAULT 1,
    KEY ix_room_clinic (clinic_code, sort_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- แพทย์ประจำห้องรายวัน (1 ห้อง = 1 แพทย์, 1 แพทย์ = 1 ห้อง ต่อวัน)
CREATE TABLE IF NOT EXISTS cq_room_day (
    work_date   DATE         NOT NULL,
    room_id     INT UNSIGNED NOT NULL,
    plan_id     INT UNSIGNED NOT NULL,
    updated_by  INT UNSIGNED NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (work_date, room_id),
    UNIQUE KEY uq_room_day_plan (work_date, plan_id),
    CONSTRAINT fk_rd_room FOREIGN KEY (room_id) REFERENCES cq_room(room_id) ON DELETE CASCADE,
    CONSTRAINT fk_rd_plan FOREIGN KEY (plan_id) REFERENCES cq_plan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cq_room (clinic_code, name, sort_no)
SELECT '001', CAST(n AS CHAR), n FROM (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) x
WHERE NOT EXISTS (SELECT 1 FROM cq_room WHERE clinic_code = '001');

-- ห้องตรวจ ↔ รหัสคิวห้องตรวจในระบบคิว HOSxP (opd_qs_slot.doctor_code เช่น 0037 "คิวพบแพทย์ห้องตรวจ 2 (B)")
-- คนไข้ที่อยู่ในคิวห้องนี้ใน HOSxP นับเป็นคิวของแพทย์ประจำห้องวันนั้น แม้ ovst.doctor ยังว่าง และแม้มาจากคลินิกอื่น
ALTER TABLE cq_room ADD COLUMN IF NOT EXISTS hosxp_qcode VARCHAR(10) NULL COMMENT 'รหัสคิวห้องตรวจใน HOSxP (opd_qs_slot.doctor_code)' AFTER name;
UPDATE cq_room SET hosxp_qcode = CASE name WHEN '1' THEN '0031' WHEN '2' THEN '0037' WHEN '3' THEN '0038' WHEN '4' THEN '0039' WHEN '5' THEN '0059' END
WHERE clinic_code = '001' AND hosxp_qcode IS NULL AND name IN ('1','2','3','4','5');
ALTER TABLE cq_room DROP COLUMN IF EXISTS hosxp_dep;

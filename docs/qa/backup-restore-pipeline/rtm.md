# Requirement Traceability Matrix — InsightX Backup: Export/Import Pipeline Rewrite

| Requirement ID | Requirement / การเปลี่ยนแปลง | Test Case ID | Test Type | Status |
|---|---|---|---|---|
| REQ-001 | Import ต้องไม่ค้างถาวรเมื่อ `wp_options` เกิน 300 แถว (atomic table fix) | TC-DB-001, TC-DB-002, TC-DB-003, TC-DB-004, TC-DB-005 | Manual + Automation | Not Run |
| REQ-002 | Safety net: เติม `active_plugins` กลับถ้าหายหลัง import | TC-DB-006 | Manual | Not Run |
| REQ-003 | DB dump เป็น SQL text จริง อ่านด้วยตาได้ (`CREATE TABLE`/`INSERT INTO`) | TC-SQL-001~006 | Manual + Automation | Not Run |
| REQ-004 | Table-prefix rewrite จำกัดเฉพาะ `meta_key`/`option_name` (leading-match) | TC-SQL-006 | Manual | Not Run |
| REQ-005 | Compression ต่อ entry ระหว่างแพ็ก (raw-DEFLATE, stream-safe) | TC-CMP-001, TC-CMP-002, TC-CMP-003 | Manual + Automation | Not Run |
| REQ-006 | Backward-compat: แพ็กเกจ format เก่า (manifest.json/database.isxdb, T\t/R\t, gzip ทั้งไฟล์) ยัง import ได้ | TC-CMP-004, TC-COMPAT-001, TC-COMPAT-002 | Manual + Automation | Not Run |
| REQ-007 | Metadata เปลี่ยนชื่อ `manifest.json` → `package.json` | TC-COMPAT-001 (ครอบคลุมทั้งคู่) | Manual | Not Run |
| REQ-008 | File scope กวาดทั้ง `wp-content/` (ไม่ใช่แค่ 4 โฟลเดอร์เดิม) | TC-FILE-001 | Manual | Not Run |
| REQ-009 | Cache exclude เป็น opt-in เท่านั้น (ไม่ exclude อัตโนมัติ) | TC-FILE-002, TC-FILE-003 | Manual | Not Run |
| REQ-010 | `upgrade/` ยัง exclude เสมอ | TC-FILE-004 | Manual | Not Run |
| REQ-011 | Session poll รอดแม้ WP session หลุดกลางทาง (`wp_ajax_nopriv_isx_run`) | TC-SESSION-001, TC-SESSION-002, TC-SESSION-003 | Manual | Not Run |
| REQ-012 | ปิด Heartbeat ระหว่าง export/import/restore | TC-SESSION-001, TC-SESSION-002 | Manual | Not Run |
| REQ-013 | ตั้งโฟลเดอร์เก็บ backup/job scratch เองได้ | TC-UI-001, TC-UI-002 | Manual | Not Run |
| REQ-014 | Backup อัตโนมัติผ่าน WP-Cron + retention | TC-UI-003 | Manual | Not Run |
| REQ-015 | หน้า "การเชื่อมต่อ" แยกจาก "ตั้งค่า Storage" ทดสอบ connection จริง | TC-UI-004 | Manual | Not Run |
| REQ-016 | หน้า "ดูรายการ" โชว์ทุกไฟล์ (รวม package.json/database.sql) + ยอดรวมต่อโฟลเดอร์ถูกต้อง | TC-UI-005 | Manual | Not Run |
| REQ-017 | WP-CLI: `wp isx export`/`import`/`providers` | TC-CLI-001~005 | Manual + Automation | Not Run |
| REQ-018 | Progress % ตอน import คำนวณจากจำนวนไฟล์จริง | (ครอบคลุมโดย observation ระหว่าง TC-DB-*, TC-COMPAT-002) | Manual | Not Run |
| REQ-019 | Chunked upload ไม่ทำให้ไฟล์บวม/เสียหาย (ปัญหาที่ยังไม่ปิดจาก dev session) | TC-UPLOAD-001, TC-UPLOAD-002, TC-UPLOAD-003 | Manual + Automation | Not Run |
| REQ-020 | Performance: atomic-options ไม่ทำให้ 1 request ช้าเกินเกณฑ์ | PERF-001, PERF-002, PERF-003 | Performance | Not Run |
| REQ-021 | Performance: compression ไม่กิน memory ตามขนาดไฟล์ (ต้อง stream) | PERF-004, PERF-005 | Performance | Not Run |
| REQ-022 | Performance: chunked upload ไฟล์ 1GB+ ไม่ timeout/เพี้ยน | PERF-006, PERF-007 | Performance | Not Run |

## สรุป Coverage

- Requirement ทั้งหมด: 22
- มี Test Case รองรับ: 22/22 (100%)
- **Blocker ก่อนปล่อยจริง**: REQ-001 (atomic wp_options) และ REQ-019 (chunked upload integrity) — ต้อง Pass ก่อนเสมอ ตามที่ระบุใน Exit Criteria ของ Test Strategy

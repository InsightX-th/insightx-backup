# Test Strategy — InsightX Backup: Export/Import Pipeline Rewrite

## หมายเหตุที่มาของเอกสาร

ไม่มี PRD/Technical Breakdown/Implementation Plan แบบเอกสารทางการ (repo นี้เป็น WordPress plugin ธรรมดา ไม่ได้ใช้ doc convention `/docs/ways-of-work/`) เอกสารชุดนี้สร้างจากสรุปการเปลี่ยนแปลงจริงที่ทำในรอบพัฒนาล่าสุดของปลั๊กอิน `insightx-backup` แทน

## Scope

### In scope

| พื้นที่ | รายละเอียดที่เปลี่ยน |
|---|---|
| DB dump format | เปลี่ยนจาก custom base64+serialize เป็น SQL text จริง (`CREATE TABLE`/`INSERT INTO`) ยังอ่าน format เก่า (`T\t`/`R\t`) ได้เพื่อ backward-compat |
| Atomic wp_options | แก้บั๊ก import ค้างถาวรเมื่อ `wp_options` มีแถวเกิน batch size (300 บรรทัด/request) |
| Compression | ย้ายจาก gzip ทั้งไฟล์ตอนจบ เป็น compress ต่อ entry ระหว่างแพ็ก (raw-DEFLATE ผ่าน stream filter) |
| Metadata | เปลี่ยนชื่อ `manifest.json` → `package.json` (รองรับทั้ง 2 ชื่อตอน import) |
| Table-prefix rewrite | จำกัดเฉพาะคอลัมน์ `meta_key`/`option_name` แบบ leading-match แทน blanket replace |
| File scope | กวาดทั้ง `wp-content/` แทนที่จะ whitelist แค่ 4 โฟลเดอร์ |
| Session survival | `wp_ajax_nopriv_isx_run` + ปิด Heartbeat ระหว่าง export/import/restore |
| Storage path | ตั้งโฟลเดอร์เก็บ backup/job scratch เองได้ |
| Scheduled backup | export อัตโนมัติผ่าน WP-Cron (รายวัน/สัปดาห์/เดือน) + retention |
| WP-CLI | `wp isx export` / `wp isx import` / `wp isx providers` |
| UI | แยกเมนู "การเชื่อมต่อ" ออกจาก "ตั้งค่า Storage", icon dropdown, progress % คำนวณจากจำนวนไฟล์จริง, list-content โชว์ครบ+ยอดรวมต่อโฟลเดอร์ |

### Out of scope

- Multisite support (ยังไม่มีในปลั๊กอิน ไม่ใช่ regression ของรอบนี้)
- Cross-database (SQLite) — ไม่มีในปลั๊กอิน
- S3 client เอง (`class-isx-s3-client.php`) — ไม่ได้แก้ในรอบนี้ ทดสอบผ่าน smoke test พื้นฐานพอ ไม่ deep-dive
- Frontend/theme ของเว็บที่ import — ไม่ใช่ความรับผิดชอบของปลั๊กอิน backup

## Quality Objectives

- Critical bug (ทำให้ import ค้างถาวร/ทำเว็บพัง) = 0 ก่อนแจกจ่าย
- ไม่มีการสูญเสียข้อมูลระหว่าง export→import round-trip (byte-exact สำหรับ DB, file checksum เท่ากันสำหรับไฟล์)
- Backward compatibility: แพ็กเกจที่สร้างจากเวอร์ชันก่อนหน้ายัง import ได้ 100%

## Risk Assessment

| ความเสี่ยง | Impact | เหตุผลที่เสี่ยงสูง | Priority |
|---|---|---|---|
| DB import ทำเว็บ bootstrap ไม่ได้ (wp_options ไม่ครบ) | Critical | เพิ่งแก้ไปสดๆ, กระทบทุกเว็บที่มี wp_options เกิน 300 แถว (เว็บจริงส่วนใหญ่) | สูงสุด |
| SQL parser (hand-rolled) parse ข้อมูลผิดเงียบๆ | Critical | เขียนเอง ไม่ใช่ library ที่ผ่านการทดสอบจากคนอื่นมาก่อน ความเสี่ยง silent data loss | สูงสุด |
| Compression ทำไฟล์เสียหาย (โดยเฉพาะไฟล์ใหญ่) | High | เปลี่ยน mechanism ใหม่ทั้งหมด (stream filter) | สูง |
| Backward-compat กับแพ็กเกจเก่าพัง | High | ผู้ใช้อาจมีแพ็กเกจเก่าเก็บไว้ใช้ restore ทีหลัง | สูง |
| Chunked upload เพี้ยน (ไฟล์ archive บวม/เสีย) | Medium | พบพฤติกรรมแปลกระหว่างทดสอบ (ไฟล์บวมผิดปกติ) ต้องสืบเพิ่ม | กลาง — ดู "ปัญหาที่ยังไม่ปิด" ด้านล่าง |
| UI icon dropdown ใช้งานผิดเพี้ยน | Low | ไม่กระทบข้อมูล แค่ UX | ต่ำ |

### ปัญหาที่ยังไม่ปิด (ต้องติดตามต่อ)

ระหว่างทดสอบพบว่า job directory ของ import ผ่าน chunked-upload มีไฟล์ `archive.wpress` ขนาดใหญ่ผิดปกติ (~7.8GB จากไฟล์ต้นทางที่ควรเล็กกว่ามาก) — สรุปตอนนั้นว่าเป็นเพราะอัปโหลดไฟล์ผิด (ไฟล์ backup เว็บใหญ่ ไม่ใช่บั๊ก) แต่**ยังไม่ได้พิสูจน์แน่ชัด 100%** ว่าไม่มีปัญหาการอัปโหลดซ้ำ/เพี้ยนปนอยู่ด้วย — ระบุเป็น test case เฉพาะด้านล่าง (TC-UPLOAD-*) ให้ตรวจอย่างเป็นระบบ

## Test Approach

- **Manual**: 100% ของ feature ใหม่ทั้งหมด ก่อนอื่นใด โดยเฉพาะ DB round-trip กับข้อมูลจริงที่มีอักขระพิเศษ (Thai, emoji, serialized data, NULL)
- **Automation**: เฉพาะ core round-trip (export→import แล้วตรวจ DB/ไฟล์ตรงกัน) และ WP-CLI smoke test — เหมาะ automate เพราะรันซ้ำได้ deterministic
- **Performance**: เฉพาะ import ของเว็บที่มี `wp_options` เกิน 300 แถว (ทดสอบ atomic-table fix โดยตรง) และไฟล์ใหญ่ (compression/chunked upload)

### ISTQB Technique ที่ใช้

- **Boundary Value Analysis**: จำนวนแถว `wp_options` รอบๆ ค่า `DB_LINES_PER_BATCH = 300` (299, 300, 301, 301+)
- **Equivalence Partitioning**: ประเภทค่าใน DB (string ธรรมดา, serialized PHP, NULL, unicode/emoji, ค่าที่มี quote/backslash ปน)
- **State Transition Testing**: job state (`init → extract → database → finalize`) โดยเฉพาะจุดเปลี่ยน step ระหว่าง compressed/uncompressed entry
- **Exploratory Testing**: ลองพังจากมุมที่ script ไม่ครอบ (ปิดแท็บกลางทาง, สลับ tab ระหว่าง WP-Cron vs browser poll แข่งกัน)

## Entry / Exit Criteria

**Entry**
- [ ] Build ล่าสุด (zip จาก `insightx-backup/`) ติดตั้งได้บน WP test site โดยไม่ error
- [ ] มี test site อย่างน้อย 2 ไซต์: ไซต์เล็ก (< 300 wp_options rows) และไซต์ใหญ่ (> 300 rows, มี WooCommerce/ปลั๊กอินเยอะ)

**Exit**
- [ ] TC-DB-* (atomic wp_options) ผ่านทั้งหมด — เงื่อนไขบังคับ ปล่อยไม่ได้ถ้าไม่ผ่าน
- [ ] TC-SQL-* (parser round-trip) ผ่านทั้งหมด
- [ ] TC-COMPAT-* (backward compat) ผ่านทั้งหมด
- [ ] ไม่มี Critical/High bug เปิดค้าง
- [ ] TC-UPLOAD-* อธิบายได้ชัดเจนว่าปัญหาไฟล์บวมเกิดจากอะไรแน่ (ปิด "ปัญหาที่ยังไม่ปิด" ด้านบน)

## Metrics ที่ต้อง Report

- Test execution pass rate ต่อ category (DB, Compression, UI, CLI, Scheduled)
- Defect count แยก severity หลัง exploratory testing
- Byte-diff / checksum ผลของ round-trip test (0 = ผ่าน)

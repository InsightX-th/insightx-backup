# Manual Test Cases — InsightX Backup: Export/Import Pipeline Rewrite

Precondition ร่วมทุก TC (นอกจากระบุเพิ่ม): ปลั๊กอิน `insightx-backup` เวอร์ชันล่าสุด ติดตั้ง+เปิดใช้งานบน WP test site, login เป็น admin

---

## กลุ่ม A — Atomic wp_options (บั๊กที่เพิ่งแก้ — priority สูงสุด)

| ID | Precondition | Steps | Expected Result | Priority |
|---|---|---|---|---|
| TC-DB-001 | เว็บทดสอบมี `wp_options` **น้อยกว่า** 300 แถว | 1. Export ทั้งเว็บ 2. Import กลับเข้าเว็บเดิม (หรือเว็บใหม่) | Import จบสมบูรณ์ ไม่ค้าง, `active_plugins` ครบ, เข้า wp-admin ได้ | High |
| TC-DB-002 | เว็บทดสอบมี `wp_options` **มากกว่า** 300 แถว (ใช้เว็บจริงที่มี WooCommerce/ปลั๊กอินเยอะ) | 1. Export ทั้งเว็บ 2. Import กลับเข้าเว็บอื่น | Import จบสมบูรณ์ ไม่ค้างที่ "การเชื่อมต่อล้มเหลว", query `wp_options` หลัง import พบ `active_plugins`/`siteurl`/`template`/`stylesheet` ครบทุกแถว | **Critical** |
| TC-DB-003 | เหมือน TC-DB-002 แต่ `wp_options` มีแถวพอดี 299/300/301 (boundary) | Export+Import 3 รอบ ปรับจำนวนแถว options ให้ตรง boundary แต่ละค่า | ทุกกรณีผ่านเหมือนกัน ไม่มีจุดใดพังเฉพาะ boundary | High |
| TC-DB-004 | ระหว่าง import กำลังประมวลผล `wp_options` (progress ค้างที่ ~62-65%) | เปิด DevTools Network ดู request `isx_run` ระหว่างนั้น | เห็น request คร่อมเวลานานกว่าปกติ 1 ครั้ง (เพราะ wp_options ไม่ตัด batch) แต่ยังตอบกลับ 200 ไม่ timeout, ไม่มี request ไหนตอบ 400 | Medium |
| TC-DB-005 | Import ระหว่างที่ WP-Cron background driver ทำงานพร้อมกับ browser poll (เปิดแท็บทิ้งไว้นานๆ) | เริ่ม import แล้วสลับไปทำอย่างอื่น 2-3 นาที ไม่ปิดแท็บ | Import เดินต่อจนจบโดยไม่มี double-processing/duplicate row (เช็ค row count table สำคัญตรงกับต้นฉบับ) | Medium |
| TC-DB-006 | หลัง import จบแล้ว (safety net ใน `finalize()`) | ลอง simulate active_plugins หายด้วยมือ (ลบแถวออกจาก DB กลางทาง) แล้วปล่อยให้ import ไปต่อจน finalize | `finalize()` เติม plugin ตัวเองกลับเข้า `active_plugins` อัตโนมัติ, import จบและกดปุ่ม "ไปหน้าล็อกอิน" ได้ | Medium (safety-net เฉพาะ, ไม่ควรเกิดจริงถ้า TC-DB-002 ผ่าน) |

## กลุ่ม B — SQL Dump Parser Round-trip

| ID | Precondition | Steps | Expected Result | Priority |
|---|---|---|---|---|
| TC-SQL-001 | เว็บมี option ที่เก็บ serialized PHP array (เช่น widget settings, theme_mods) | Export→Import→เทียบค่า option นั้นก่อน-หลัง | ค่าตรงกัน 100% (byte-exact), `unserialize()` ที่ปลายทางไม่ error | Critical |
| TC-SQL-002 | เว็บมี field ที่มี quote/backslash/newline ปนกัน (เช่น post content ที่มี `it's`, `\`, code block) | Export→Import→เทียบ post content | ตรงกัน 100% ไม่มีอักขระหาย/เพี้ยน | Critical |
| TC-SQL-003 | เว็บมี field ที่เป็นภาษาไทย + emoji (post title/content, user display name) | Export→Import→เทียบ | ตรงกัน 100% (utf8mb4 ไม่ถูกตัด/เพี้ยน) | High |
| TC-SQL-004 | Field ที่เป็น NULL จริง (ไม่ใช่ empty string) | Export→Import→เทียบ | คอลัมน์ยังเป็น NULL ไม่กลายเป็น empty string หรือ "NULL" literal | High |
| TC-SQL-005 | Field ขนาดใหญ่มาก (>1MB เช่น serialized option ก้อนโต) | Export→Import→เทียบ | ตรงกัน, ไม่ timeout, ไม่ truncate | Medium |
| TC-SQL-006 | Table prefix ต้นทาง≠ปลายทาง (เช่น `wpxyz_` → `wp_`) | Export จากเว็บ prefix แปลก → Import เข้าเว็บ prefix ปกติ | ทุกตารางถูก rename ถูกต้อง, `meta_key`/`option_name` ที่ฝัง prefix (เช่น `wp_capabilities`) ถูกแก้ตาม, login เข้า wp-admin ได้ (ไม่เจอ "Sorry, you are not allowed") | Critical |

## กลุ่ม C — Compression

| ID | Precondition | Steps | Expected Result | Priority |
|---|---|---|---|---|
| TC-CMP-001 | เลือก "บีบอัด GZip" ตอน export | Export→ดูขนาดไฟล์เทียบกับไม่บีบอัด→Import กลับ | ไฟล์เล็กลงชัดเจน, import กลับมาข้อมูลตรงกับต้นฉบับ 100% | Critical |
| TC-CMP-002 | ไฟล์ media ขนาดใหญ่ (>50MB เช่นวิดีโอ) ใน uploads | Export (compress) → Import → เทียบ checksum ไฟล์ | Checksum ตรงกัน, ไม่ memory error ระหว่าง compress/decompress | High |
| TC-CMP-003 | ไม่เลือกบีบอัด (ค่าเริ่มต้น) | Export→Import | ทำงานปกติเหมือนก่อนมี feature compression (regression check) | Medium |
| TC-CMP-004 | Backup เก่าที่เคย gzip ทั้งไฟล์ (สร้างจากเวอร์ชันก่อนแก้) | นำไฟล์เก่ามา Import บนเวอร์ชันใหม่ | ยัง import ได้ปกติ (detect gzip ทั้งไฟล์ตัวเก่า, decompress ทีเดียวก่อน extract) | Critical (backward-compat) |

## กลุ่ม D — Backward Compatibility

| ID | Precondition | Steps | Expected Result | Priority |
|---|---|---|---|---|
| TC-COMPAT-001 | มีไฟล์ `.wpress` ที่สร้างจากเวอร์ชันก่อนหน้า (format `manifest.json`/`database.isxdb`, T\t/R\t lines) | Import เข้าเว็บที่รันปลั๊กอินเวอร์ชันใหม่ | Import สำเร็จ ข้อมูลครบ (entry-name เก่าและ dump format เก่าถูกตรวจจับและอ่านถูกทั้งคู่) | Critical |
| TC-COMPAT-002 | ไฟล์เก่าที่ไม่มี `total_files` ใน manifest | Import | Progress bar ใช้สูตรเดา fallback (ไม่ error, ไม่ค้างที่ 60%ตลอดไปอย่างน้อยก็ขยับ) | Medium |

## กลุ่ม E — Upload Path (ปัญหาที่ยังไม่ปิดจาก Test Strategy)

| ID | Precondition | Steps | Expected Result | Priority |
|---|---|---|---|---|
| TC-UPLOAD-001 | ไฟล์ `.wpress` ขนาดที่ทราบแน่ชัด (เช่น เช็ค `ls -la` ก่อน) | Drag-and-drop ไฟล์เข้าหน้า "นำเข้า" → หลังอัปโหลดเสร็จ (ก่อนเริ่ม extract) เช็คขนาดไฟล์ `archive.wpress` ใน job directory บน server | ขนาดไฟล์บน server **เท่ากับ** ต้นฉบับเป๊ะ ไม่บวม/ไม่หด | **Critical — ปิดปัญหาที่ค้างจาก session ก่อน** |
| TC-UPLOAD-002 | จำลอง network ช้า/ไม่เสถียร (throttle ผ่าน DevTools) ระหว่างอัปโหลด | อัปโหลดไฟล์ใหญ่ (>50MB) ขณะ throttle | ถ้า retry เกิดขึ้น (สูงสุด 5 ครั้งต่อ chunk) ไฟล์ปลายทางต้องยังขนาดถูกต้อง ไม่ duplicate chunk | Critical |
| TC-UPLOAD-003 | อัปโหลดไฟล์เดิมซ้ำ 2 ครั้งติดกัน (คนละ job) | เทียบขนาด `archive.wpress` ของทั้ง 2 job | ขนาดเท่ากันทั้งคู่ และเท่ากับต้นฉบับ | High |

## กลุ่ม F — File Scope (กวาดทั้ง wp-content/)

| ID | Precondition | Steps | Expected Result | Priority |
|---|---|---|---|---|
| TC-FILE-001 | เว็บมีไฟล์/โฟลเดอร์นอกเหนือ 4 โฟลเดอร์เดิม (เช่น `wp-content/languages/`, drop-in `object-cache.php`) | Export→เปิด "ดูรายการ"→เช็คว่าไฟล์เหล่านี้ปรากฏ | ปรากฏครบ, ขนาดรวมต่อโฟลเดอร์แสดงถูกต้อง | High |
| TC-FILE-002 | เว็บมี `wp-content/cache/` ที่มีข้อมูลจริง (ปลั๊กอิน cache) | Export โดย**ไม่ติ๊ก** "ไม่รวมไฟล์แคช" | ไฟล์ cache ถูกรวมเข้า backup (default include, ไม่ exclude อัตโนมัติ) | Medium |
| TC-FILE-003 | เหมือน TC-FILE-002 แต่**ติ๊ก** "ไม่รวมไฟล์แคช" | Export | ไฟล์ cache ถูก exclude | Medium |
| TC-FILE-004 | เว็บมี `wp-content/upgrade/` (ปกติว่าง) | Export | โฟลเดอร์นี้ไม่ถูกรวม (excluded เสมอ) | Low |

## กลุ่ม G — Session Survival / Heartbeat

| ID | Precondition | Steps | Expected Result | Priority |
|---|---|---|---|---|
| TC-SESSION-001 | Import ที่จะเขียนทับ `wp_users`/`wp_usermeta` | เริ่ม import แล้วสังเกตหน้าจอทั้งกระบวนการ | ไม่มี modal "Your session has expired" ของ WP core โผล่มาแทรก | High |
| TC-SESSION-002 | เหมือนบน | สังเกต Network tab ตลอด import | ไม่มี request ไหนไป `admin-ajax.php?action=heartbeat` ระหว่างอยู่หน้าปลั๊กอิน | Medium |
| TC-SESSION-003 | Import เสร็จสมบูรณ์ (DB ถูกเขียนทับ, session เดิมหลุดจริง) | หลัง import จบ กด "ไปหน้าล็อกอิน" | เห็นปุ่มนี้ (ไม่ใช่ error), login ใหม่ได้ปกติ | Critical |

## กลุ่ม H — Storage Path / Scheduled Backup / Connections UI

| ID | Precondition | Steps | Expected Result | Priority |
|---|---|---|---|---|
| TC-UI-001 | หน้า "ตั้งค่า Storage" | ตั้ง path ใหม่ (โฟลเดอร์แม่มีอยู่จริง เขียนได้) → บันทึก → export ใหม่ | ไฟล์ export ไปอยู่ path ใหม่จริง, path เดิม/ไฟล์เก่าไม่ถูกย้าย | High |
| TC-UI-002 | ตั้ง path ที่ไม่มีอยู่จริง/เขียนไม่ได้ | บันทึก | แสดง error ชัดเจน ไม่เงียบ/ไม่ crash | Medium |
| TC-UI-003 | หน้า "ตั้งค่า Storage" > Backup อัตโนมัติ | เปิดสวิตช์, ตั้งความถี่รายวัน, retain=2, บันทึก → รัน `wp cron event run isx_scheduled_backup` (จำลอง cron tick) | Backup ใหม่ถูกสร้าง, ถ้าเกิน 2 ไฟล์ ตัวเก่าสุดถูกลบอัตโนมัติ | High |
| TC-UI-004 | หน้า "การเชื่อมต่อ" | ตั้งค่า provider ผิด (endpoint/key ผิด) แล้วบันทึก | ขึ้น badge "เชื่อมต่อไม่สำเร็จ" พร้อม error จริงจาก server (403/404/etc ไม่ใช่ค่า mock) | High |
| TC-UI-005 | หน้า "ข้อมูลสำรอง" > ดูรายการ | เปิดดูเนื้อหาแพ็กเกจที่มีทั้ง compressed/uncompressed entry ปน | เห็นทุกไฟล์รวม `package.json`/`database.sql`, ยอดรวมต่อโฟลเดอร์ตรงกับผลรวมไฟล์ย่อยจริง, ผลรวมทั้งหมดตรงกับขนาดจริงของเนื้อหา (ไม่ใช่ขนาดบีบอัด) | Medium |

## กลุ่ม I — WP-CLI

| ID | Precondition | Steps | Expected Result | Priority |
|---|---|---|---|---|
| TC-CLI-001 | มี WP-CLI บนเซิร์ฟเวอร์ | `wp isx export` | สร้างไฟล์ backup สำเร็จ, print path/ขนาด, exit code 0 | High |
| TC-CLI-002 | มีไฟล์ `.wpress` บน server | `wp isx import <file>` (ไม่ใส่ `--yes`) | มี prompt ยืนยันก่อนเขียนทับ | High |
| TC-CLI-003 | เหมือนบน | `wp isx import <file> --yes` | Import โดยไม่ถาม, จบสมบูรณ์เหมือน import ผ่าน UI | High |
| TC-CLI-004 | ตั้งค่า provider ไว้แล้ว | `wp isx providers` | แสดงรายชื่อ provider พร้อมสถานะ configured/not configured ถูกต้อง | Low |
| TC-CLI-005 | - | `wp isx export --to=<slug ที่ไม่ configure>` | แสดง error ชัดเจน ไม่ export ค้าง | Medium |

---

## Bug Report Template (ใช้กับทุก TC ที่ Fail)

```markdown
## Bug Title


## Environment
- WP version / PHP version / MySQL version:
- Plugin version (commit/zip date):
- Source site vs Target site (ถ้าข้ามเว็บ):

## Steps to Reproduce
1.
2.
3.

## Expected Result


## Actual Result


## Severity / Priority
- Severity:
- Priority:

## Attachment
(screenshot, job state.json, PHP error log, Network tab)
```

## Severity vs Priority Matrix

| | Priority: Urgent | Priority: High | Priority: Medium | Priority: Low |
|---|---|---|---|---|
| **Severity: Critical** (ทำให้ import ค้างถาวร/ข้อมูลหาย) | แก้ก่อนแจกจ่ายทันที | แก้ก่อน release | - | - |
| **Severity: High** (ฟีเจอร์หลักใช้ไม่ได้) | แก้ก่อน release | แก้รอบนี้ | แก้รอบหน้า | - |
| **Severity: Medium** (UX ผิดปกติ ไม่กระทบข้อมูล) | - | แก้รอบนี้ | แก้เมื่อมีเวลา | Backlog |
| **Severity: Low** (คำผิด/UI เล็กน้อย) | - | - | Backlog | Backlog |

## Regression Checklist (รันก่อนทุก release ถัดไป)

- [ ] TC-DB-002 (atomic wp_options — ตัวบั๊กหลักของรอบนี้ ต้องรันทุกครั้งที่แตะ `database()`)
- [ ] TC-SQL-001, TC-SQL-002 (parser round-trip พื้นฐาน)
- [ ] TC-COMPAT-001 (backward-compat)
- [ ] TC-CMP-001 (compression พื้นฐาน)
- [ ] Core export→import round-trip บนเว็บเล็กและเว็บใหญ่ อย่างละ 1 ครั้ง

## UAT Coordination Checklist

- [ ] ให้เจ้าของเว็บจริง (InsightX) ลอง export เว็บ production จริงแล้ว import เข้าเว็บ staging ด้วยตัวเอง
- [ ] เก็บ feedback เรื่องเวลาที่ใช้ (perceived performance) ไม่ใช่แค่ผลถูก/ผิด
- [ ] แยกให้ชัดว่า issue ที่เจอเป็นบั๊ก หรือ scope ที่ตั้งใจไม่รองรับ (เช่น multisite)
- [ ] Sign-off ก่อนแจกจ่ายให้ลูกค้าใช้งานจริง

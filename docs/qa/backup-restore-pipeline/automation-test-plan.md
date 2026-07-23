# Automation Test Plan — InsightX Backup: Export/Import Pipeline Rewrite

## หลักการเลือก Test Case ที่ automate

Automate เฉพาะที่ **deterministic + รันซ้ำบ่อย + ตรวจผลด้วยข้อมูลจริงได้ (ไม่ใช่แค่ดู UI)** — ตรงเงื่อนไขของ core DB/file round-trip พอดี ส่วน UI ที่เพิ่งทำ (icon dropdown, การ์ด provider) ยัง**ไม่ automate** เพราะ UI ยังเปลี่ยนบ่อยและ manual test ครอบคลุมพอแล้ว

| Test Case | Automate? | เหตุผล |
|---|---|---|
| TC-DB-001/002/003 (atomic wp_options) | ✅ ใช่ | Deterministic, ตรวจผลจาก DB query ตรงๆ, เป็น regression suite หลักที่ต้องรันทุก release |
| TC-SQL-001~006 (parser round-trip) | ✅ ใช่ | มี "ชุดข้อมูลยาก" ที่ fix ไว้ตายตัวได้ (serialized, unicode, NULL, quote) เทียบ byte-exact อัตโนมัติได้ |
| TC-CMP-001/004 (compression + backward compat) | ✅ ใช่ | เทียบ checksum ไฟล์ก่อน-หลังอัตโนมัติได้ |
| TC-COMPAT-001 (backward compat format เก่า) | ✅ ใช่ | ใช้ fixture ไฟล์ `.wpress` เก่าคงที่ รันซ้ำได้เสมอ |
| TC-CLI-001~003 (WP-CLI) | ✅ ใช่ | เป็น shell command ตรงไปตรงมา, เหมาะ CI |
| TC-UPLOAD-001~003 (chunked upload integrity) | ✅ ใช่ | เทียบขนาด/checksum ไฟล์ได้ตรงๆ, สำคัญเพราะเป็นปัญหาที่ยังไม่ปิด |
| TC-UI-001~005 (Settings/Connections UI) | ❌ ไม่ | UI เปลี่ยนบ่อย, ต้องดูด้วยตา (icon, layout) — เสีย ROI ถ้า automate ตอนนี้ |
| TC-SESSION-001/002 (Heartbeat modal) | ❌ ไม่ (ตอนนี้) | ต้องสังเกต DOM modal แบบ visual, ทำได้แต่ ROI ต่ำกว่าตัวอื่น — พิจารณา automate รอบหน้าถ้ามีเวลา |
| Exploratory (ปิดแท็บกลางทาง, race WP-Cron) | ❌ ไม่ | โดยธรรมชาติต้องใช้ human judgement |

## Framework

- **PHP-level round-trip (DB/parser/compression)**: PHPUnit หรือ script-based test ตรง (มีแบบร่างจาก dev session แล้ว — ดูหัวข้อ "Test harness ที่มีอยู่") ไม่ต้องพึ่ง browser
- **WP-CLI smoke test**: Bash script เรียก `wp isx ...` ตรงๆ เทียบ exit code + output
- **Chunked upload integrity**: Playwright (มี `mcp__claude-in-chrome__*` ใช้ ad-hoc ระหว่าง dev ได้ผลดีมาแล้วรอบนี้ — แปลงเป็น Playwright script ถาวรสำหรับ CI)

## Test Harness ที่มีอยู่แล้ว (นำกลับมาใช้เป็นฐาน automation)

ระหว่างแก้บั๊กจริงในรอบนี้ มีการเขียน standalone PHP test harness ไว้แล้ว (เชื่อมต่อ MySQL จริงผ่าน `FakeWpdb` wrapper, เรียก `ISX_Database::build_insert()`/`parse_insert()`/`import_line()` ตรงๆ ผ่าน Reflection) ควรนำมา formalize เป็นชุด PHPUnit แทนที่จะเป็น throwaway script:

```
tests/
  bootstrap.php              # ตั้ง FakeWpdb / mysqli connection แบบใน dev session
  DatabaseRoundTripTest.php  # TC-SQL-001~006
  AtomicOptionsTest.php      # TC-DB-001~003 (จำลอง batch size เล็กบังคับให้ตัดกลางตาราง เหมือนที่ verify บั๊กจริงมาแล้ว)
  ArchiveCompressionTest.php # TC-CMP-001/002 (ผ่าน ISX_Archive::add_file/stream_entry_to_file ตรงๆ)
  BackwardCompatTest.php     # TC-COMPAT-001 ใช้ fixture .wpress เก่าเก็บไว้ใน tests/fixtures/
```

### ชุดข้อมูลทดสอบ (fixtures) ที่ต้องเตรียม

- `tests/fixtures/legacy-format.wpress` — แพ็กเกจที่สร้างจากเวอร์ชันก่อน rewrite (เก็บไว้ถาวร ห้ามลบ/regenerate)
- `tests/fixtures/tricky-rows.sql` — แถวตัวอย่างที่มี serialized array ซ้อน, unicode/emoji, quote+backslash ปน, NULL (สกัดจากข้อมูลจริงที่เจอปัญหาระหว่าง dev)

## CI/CD Integration

- [ ] รัน `DatabaseRoundTripTest`/`AtomicOptionsTest`/`ArchiveCompressionTest`/`BackwardCompatTest` ทุก PR ที่แตะ `includes/class-isx-database.php`, `class-isx-import.php`, `class-isx-export.php`, `class-isx-archive.php` (smoke, รันเร็ว — ไม่ต้องมี WP เต็ม)
- [ ] รัน WP-CLI smoke test + chunked-upload Playwright test ทุก merge เข้า main (ต้องมี WP test site จริง, รันช้ากว่า — ไม่ต้องรันทุก commit)
- [ ] แจ้งเตือนทันทีถ้า `AtomicOptionsTest` fail (นี่คือ regression ของบั๊ก critical ที่เพิ่งแก้ — ห้ามหลุดกลับมาอีก)

## Script Maintenance

- [ ] Fixture `.wpress` เก่าต้อง**ไม่ regenerate** ทับ (จุดประสงค์คือ freeze format เก่าไว้ทดสอบ backward-compat) — ถ้าจะเพิ่ม fixture ใหม่ให้สร้างไฟล์ใหม่แยก ไม่ทับของเดิม
- [ ] Review ทุกไตรมาส: ถ้า UI (TC-UI-*) เริ่มเสถียรพอ (ไม่เปลี่ยนบ่อยแล้ว) ค่อยพิจารณา automate เพิ่ม

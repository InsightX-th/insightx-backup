# Performance Test Plan — InsightX Backup: Export/Import Pipeline Rewrite

ทำเฉพาะจุดที่กระทบ resource/เวลาโดยตรงจากการเปลี่ยนแปลงรอบนี้ ไม่ทำ full load test ทั้งปลั๊กอิน (ไม่ใช่ระบบ high-traffic — เป็นเครื่องมือ admin ใช้เป็นครั้งคราว)

## เครื่องมือ

- **PHP-level timing**: `microtime()`/Xdebug profiler รอบ `run_step()` ต่อ 1 poll — วัดง่ายกว่าใช้ JMeter เพราะ target ไม่ใช่ HTTP throughput แต่เป็น "1 request ใช้เวลา/หน่วยความจำเท่าไหร่"
- **Apache JMeter**: เฉพาะ simulate หลาย browser tab/WP-Cron poll พร้อมกัน (concurrency scenario)

## ประเด็นที่ต้องวัด (เจาะจงจากการเปลี่ยนแปลงรอบนี้)

### 1. Atomic wp_options — คุ้มกับความเสี่ยง "1 request ใช้เวลานานผิดปกติ"

การแก้บั๊กทำให้ตาราง `wp_options` ไม่ตัด batch คร่อม request — ถ้าเว็บมี `wp_options` เยอะมาก (หลักพันแถว) 1 request อาจกิน CPU/เวลานานกว่าปกติ (300 บรรทัด) มาก

| Test | เกณฑ์ |
|---|---|
| Import เว็บที่มี `wp_options` 500 แถว | 1 request (ตอนเจอ options table) ใช้เวลา < 5 วินาที |
| Import เว็บที่มี `wp_options` 2,000 แถว | < 15 วินาที ต่อ request, ไม่ hit PHP memory limit เริ่มต้น (128M) |
| Import เว็บที่มี `wp_options` 5,000+ แถว (worst case จริง) | ไม่ fatal error แม้ใช้เวลานาน (มี `set_time_limit(0)` อยู่แล้ว) — ถ้าเกิน 30 วินาที ให้ flag เป็น "ควรพิจารณา sub-batch ภายในตาราง options เองในอนาคต" ไม่ใช่ blocker ตอนนี้ |

### 2. Compression overhead (CPU) ตอน export

| Test | เกณฑ์ |
|---|---|
| Export ไฟล์ 100MB โดยไม่บีบอัด vs บีบอัด | เวลาที่ใช้เพิ่มไม่เกิน 3 เท่า (raw-DEFLATE ไม่ควรช้ากว่านี้) |
| Export ไฟล์ media ใหญ่ (500MB+) พร้อมบีบอัด | Memory usage คงที่ (ไม่โตตามขนาดไฟล์ — stream filter ต้องไม่ buffer ทั้งไฟล์ในหน่วยความจำ) ตรวจด้วย `memory_get_peak_usage()` |

### 3. Chunked upload — ไฟล์ใหญ่

| Test | เกณฑ์ |
|---|---|
| อัปโหลดไฟล์ 1GB ผ่านหน้า "นำเข้า" | อัปโหลดจบโดยไม่ timeout (chunk 4MB/ครั้ง), ขนาดปลายทางตรงกับต้นฉบับเป๊ะ (ผูกกับ TC-UPLOAD-001) |
| อัปโหลดไฟล์ 5GB+ (เว็บใหญ่จริงแบบที่เจอระหว่าง dev — 7GB+) | ไม่ hit `post_max_size`/`upload_max_filesize` ต่อ chunk (แต่ละ chunk แค่ 4MB จึงไม่ควรติด), เวลารวมสมเหตุสมผลตาม bandwidth |

### 4. DB dump ขนาดใหญ่ (SQL text format ใหญ่กว่า format เดิมหรือไม่)

| Test | เกณฑ์ |
|---|---|
| เทียบขนาดไฟล์ `database.sql` (ใหม่) vs `database.isxdb` (เก่า) จากเว็บเดียวกัน | บันทึกไว้เป็น baseline — ถ้าใหญ่กว่าเดิมเกิน 50% ให้ทบทวนว่าคุ้มกับความอ่านง่ายที่ได้มาไหม (ข้อมูลอ้างอิง ไม่ใช่ blocker) |

## Threshold สรุป

| Metric | เกณฑ์ |
|---|---|
| 1 poll request (ตาราง options ใหญ่) | < 15 วินาที (2,000 แถว) |
| Memory peak ระหว่าง compress/decompress ไฟล์ใหญ่ | ไม่แปรผันตรงตามขนาดไฟล์ (ต้อง stream) |
| Error rate ระหว่าง chunked upload ไฟล์ 1GB+ | 0% (ไม่มี chunk เพี้ยน/หาย) |

## ขั้นตอนวิเคราะห์ผล

1. เทียบผลจริงกับ threshold ข้างบน
2. ถ้า atomic-options request ช้าเกินเกณฑ์ → พิจารณา sub-batch ภายในตาราง options เอง (เช่น ทุก 1,000 แถวค่อยยอม cut ถ้าไม่ใช่แถวสุดท้ายของ dump ทั้งไฟล์) เป็น follow-up ticket ไม่ใช่แก้ตอนนี้
3. ถ้า memory โตตามขนาดไฟล์ผิดปกติ → เช็คว่า `stream_filter_append`/`stream_entry_to_file()` มีจุดไหน buffer เต็มไฟล์โดยไม่ตั้งใจ (regression กลับไปที่จุดออกแบบเดิมที่ตั้งใจให้ stream)
4. สรุปเป็น report แนบ threshold vs actual ให้ทีม dev

=== InsightX Backup ===
Contributors: insightx
Tags: backup, migration, export, import, s3
Requires at least: 3.3
Tested up to: 7.1.2
Requires PHP: 7.4
Stable tag: 0.1.30
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

ย้าย/สำรอง WordPress ทั้งเว็บ (ฐานข้อมูล + ไฟล์) เป็นแพ็กเกจเดียว แล้วนำเข้ากลับ หรือส่งขึ้น S3-compatible storage ได้โดยตรง

== Description ==

InsightX Backup เขียนขึ้นใหม่ทั้งหมดโดย InsightX ไม่ได้ fork หรือดัดแปลงจากปลั๊กอินอื่น — engine ทุกส่วน (archive format, dump/import ฐานข้อมูล, serialized-safe find & replace, S3 client) เขียนขึ้นใหม่จากศูนย์

**คุณสมบัติหลัก:**

* **ส่งออก (Export)** — แพ็กฐานข้อมูล + ทั้ง wp-content เป็นไฟล์ .wpress เดียว ดาวน์โหลดเป็นไฟล์ หรือส่งขึ้น S3 โดยตรง
* **นำเข้า (Import)** — อัปโหลดไฟล์ .wpress หรือนำเข้าจาก S3 โดยตรง กู้คืนไฟล์ + ฐานข้อมูล พร้อมแทนที่ URL/path/table-prefix อัตโนมัติ แบบ clean-then-restore
* **ข้อมูลสำรอง (Backups)** — ทุกครั้งที่ export จะถูกเก็บสำเนาไว้ในเครื่องเสมอ ดูรายการ/กู้คืน/ดาวน์โหลด/ดูเนื้อหา/ลบได้จากหน้าเดียว
* **การเชื่อมต่อ Storage** — Amazon S3, Minio, Garage, Cloudflare R2, DigitalOcean Spaces, Google Cloud Storage หรือปลายทาง S3-compatible อื่นๆ พร้อมกันหลายเจ้า
* **Backup อัตโนมัติ** — ตั้งเวลา export อัตโนมัติผ่าน WP-Cron (รายวัน/รายสัปดาห์/รายเดือน)
* **Find & Replace** — แทนที่ข้อความในฐานข้อมูลตอน export ได้หลายคู่
* **ศูนย์รีเซ็ต (Reset Hub)** — ล้างปลั๊กอิน / รีเซ็ตธีม / ล้างคลังสื่อ / รีเซ็ตฐานข้อมูล / รีเซ็ตทั้งเว็บไซต์ พร้อมยืนยันด้วยรหัสผ่านก่อนทำรายการทุกครั้ง
* **WP-CLI** — `wp isx export` / `wp isx import <file>` / `wp isx providers` / `wp isx cleanup-uploads`

> ⚠️ ไฟล์ .wpress ของปลั๊กอินนี้เป็นฟอร์แมตของ InsightX เอง ไม่ compatible กับไฟล์ .wpress ของ All-in-One WP Migration แม้ใช้นามสกุลเดียวกัน

== Installation ==

= ติดตั้งครั้งแรก =

1. ดาวน์โหลดไฟล์ `insightx-backup-vX.X.X.zip` จากหัวข้อ Assets ของ GitHub Releases: https://github.com/InsightX-th/insightx-backup/releases (ใช้ไฟล์นี้ ไม่ใช่ "Source code")
2. ในหลังบ้าน WordPress ไปที่ ปลั๊กอิน → เพิ่มปลั๊กอินใหม่ → อัปโหลดปลั๊กอิน เลือกไฟล์ ZIP แล้วกด ติดตั้งเดี๋ยวนี้
3. กด เปิดใช้งาน แล้วเมนู InsightX Backup จะขึ้นที่ sidebar ของหลังบ้าน
4. (ถ้าจะส่ง backup ขึ้น Storage) ไปที่ InsightX Backup → การเชื่อมต่อ เลือกผู้ให้บริการ กรอก endpoint, bucket และ key แล้วทดสอบการเชื่อมต่อ
5. ไปที่ InsightX Backup → ส่งออก กด ส่งออกเป็นไฟล์ เพื่อทดลองสำรองครั้งแรก ไฟล์จะถูกเก็บไว้ในเมนู ข้อมูลสำรอง ด้วย
6. (ไม่บังคับ) เปิด Backup อัตโนมัติ ได้ที่ InsightX Backup → ตั้งค่า Storage เลือกความถี่ ปลายทาง และจำนวน backup ที่จะเก็บไว้

= ย้ายเว็บไปเครื่องใหม่ =

1. ที่เว็บต้นทาง: ส่งออกเป็นไฟล์ `.wpress` (หรือส่งขึ้น Storage)
2. ที่เว็บปลายทาง: ติดตั้ง WordPress เปล่าและปลั๊กอินนี้
3. ไปที่ InsightX Backup → นำเข้า แล้วอัปโหลดไฟล์ `.wpress` หรือเลือกไฟล์จาก Storage
4. ระบบจะแทนที่ URL และ path ให้ตรงกับเว็บปลายทางอัตโนมัติ เสร็จแล้วต้องล็อกอินใหม่ด้วยบัญชีจากเว็บต้นทาง

= ติดตั้งตรงจากโฟลเดอร์ (dev/local) =

วางโฟลเดอร์ `insightx-backup` ไว้ที่ `wp-content/plugins/` แล้วเปิดใช้งานจากเมนู ปลั๊กอิน

= ความต้องการของระบบ =

* PHP 7.4 ขึ้นไป
* ส่วนขยาย cURL (สำหรับ Storage), zlib (สำหรับบีบอัด GZip) และ openssl (สำหรับเข้ารหัสด้วยรหัสผ่าน)
* พื้นที่ดิสก์ว่างประมาณขนาดฐานข้อมูล + ขนาด wp-content

== Frequently Asked Questions ==

= ใช้ไฟล์ .wpress ของ All-in-One WP Migration ได้ไหม? =

ไม่ได้ ไฟล์ `.wpress` ของปลั๊กอินนี้เป็นฟอร์แมตของ InsightX เอง แม้ใช้นามสกุลเดียวกันก็เปิดข้ามกันไม่ได้

= นำเข้าแล้วข้อมูลเดิมของเว็บปลายทางหายไหม? =

หาย การนำเข้าจะแทนที่เว็บปัจจุบันทั้งหมด (ฐานข้อมูลและไฟล์ใน wp-content) ด้วยเนื้อหาในแพ็กเกจ ควรสำรองเว็บปลายทางก่อนเสมอ ระบบจะตรวจว่าแพ็กเกจสมบูรณ์ก่อนเริ่มลบอะไร

= ไฟล์ backup เก็บไว้ที่ไหน? =

ค่าเริ่มต้นคือ `wp-content/insightx-backup/` เปลี่ยนได้ที่ InsightX Backup → ตั้งค่า Storage (การเปลี่ยน path ไม่ย้ายไฟล์เก่าให้)

= ลืมรหัสผ่านที่ใช้เข้ารหัส backup ทำอย่างไร? =

กู้คืนไม่ได้ ระบบไม่เก็บรหัสผ่านไว้ ต้องจดเก็บเองทุกครั้งที่เปิดตัวเลือกเข้ารหัส

= ขึ้นว่า "พื้นที่ดิสก์ไม่พอ เขียนไฟล์ไม่สำเร็จ" =

ดู path ที่ระบุในข้อความ ไม่ใช่พื้นที่รวมของเครื่อง บนโฮสต์ที่แบ่งดิสก์หลายก้อน ก้อนที่เต็มมักไม่ใช่ก้อนที่ติดตั้ง WordPress ต้องมีที่ว่างประมาณขนาดฐานข้อมูล + ขนาด wp-content

= สั่งงานผ่าน command line ได้ไหม? =

ได้ ผ่าน WP-CLI: `wp isx export`, `wp isx import <ไฟล์>`, `wp isx providers` และ `wp isx cleanup-uploads`

= อัปเดตปลั๊กอินอย่างไร? =

ปลั๊กอินตรวจ GitHub Releases ให้เอง เมื่อมีเวอร์ชันใหม่จะแจ้งในหน้า ปลั๊กอิน ให้กด อัปเดตเดี๋ยวนี้

== Upgrade Notice ==

= 0.1.25 =
ดาวน์โหลด backup ที่เก็บไว้ในเครื่องเก็บไว้ก่อนอัปเดต: เวอร์ชันก่อนหน้าเก็บ backup ไว้ในโฟลเดอร์ปลั๊กอิน ซึ่ง WordPress ลบทิ้งระหว่างอัปเดต ตั้งแต่เวอร์ชันนี้ backup จะอยู่ที่ wp-content/insightx-backup และไม่หายตอนอัปเดตอีก (backup บน Storage/S3 ไม่ได้รับผลกระทบ)

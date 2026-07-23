=== InsightX Backup ===
Contributors: insightx
Tags: backup, migration, export, import, s3
Requires at least: 3.3
Tested up to: 7.0.2
Requires PHP: 5.3
Stable tag: 0.1.1
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
* **WP-CLI** — `wp isx export` / `wp isx import <file>` / `wp isx providers`

> ⚠️ ไฟล์ .wpress ของปลั๊กอินนี้เป็นฟอร์แมตของ InsightX เอง ไม่ compatible กับไฟล์ .wpress ของ All-in-One WP Migration แม้ใช้นามสกุลเดียวกัน

== Installation ==

**ดาวน์โหลด zip จาก GitLab**

1. เข้า `https://gitlab.insightx.dev/plugin-wordpress/insightx-backup`
2. เมนู Deploy → Releases เลือกเวอร์ชันล่าสุด ดาวน์โหลด `insightx-backup.zip` จากส่วน assets
3. ไปที่เว็บ WordPress ปลายทาง → Plugins → Add New → Upload Plugin
4. เลือกไฟล์ zip → Install Now → Activate Plugin

**ติดตั้งตรงจากโฟลเดอร์ (dev/local)**

1. วางโฟลเดอร์ `insightx-backup` ไว้ที่ `wp-content/plugins/`
2. เปิดใช้งานปลั๊กอินจากเมนู Plugins
3. เมนู InsightX Backup จะปรากฏใน sidebar ของ wp-admin

**ความต้องการของระบบ:** PHP 7.4+, ส่วนขยาย cURL/zlib/openssl

== Changelog ==

= 0.1.1 =
* เพิ่ม readme.txt เพื่อให้หน้า "View details" แสดง Description/Installation/Changelog + เลขเวอร์ชันที่รองรับ

= 0.1.0 =
* ส่งออก/นำเข้าเว็บทั้งเว็บเป็นไฟล์ .wpress เดียว (ฐานข้อมูล + wp-content)
* Import แบบ clean-then-restore พร้อมแทนที่ URL/path/table-prefix อัตโนมัติ
* เชื่อมต่อ S3-compatible storage หลาย provider พร้อมกัน
* ข้อมูลสำรองในเครื่อง + backup อัตโนมัติผ่าน WP-Cron
* WP-CLI commands (`wp isx export` / `wp isx import` / `wp isx providers`)
* ตรวจสอบเวอร์ชันใหม่อัตโนมัติผ่าน GitLab

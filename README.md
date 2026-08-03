# 🛒 Smart POS System (نظام إدارة الكاشير ونقاط البيع)

![Version](https://img.shields.io/badge/version-1.1.39-blue.svg)
![React](https://img.shields.io/badge/React-19.2-61DAFB?logo=react&logoColor=black)
![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![Electron](https://img.shields.io/badge/Electron-43.2-47848F?logo=electron&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?logo=docker&logoColor=white)

نظام نقاط بيع (POS) متكامل وسريع تم تصميمه خصيصاً لإدارة السوبر ماركت والمحلات التجارية بكفاءة عالية. النظام مبني باستخدام **React 19** للواجهة الأمامية وتطبيق سطح المكتب (Electron)، مع واجهة برمجة تطبيقات (API) قوية مبنية بـ **PHP Native MVC**.

---

## ✨ المميزات الرئيسية (Features)

- ⚡ **نقطة بيع فائقة السرعة (Fast POS):** دعم كامل لأجهزة قراءة الباركود، اختصارات لوحة المفاتيح، والبحث اللحظي مع تنبيهات صوتية.
- 📶 **دعم العمل بدون إنترنت (Offline-First):** إمكانية البيع والعمل حتى في حال انقطاع الاتصال بالسيرفر بفضل `IndexedDB` والمزامنة التلقائية عند عودة الاتصال.
- 📦 **إدارة المخزون والمشتريات:** تتبع دقيق للمخزون، تنبيهات بالنواقص، وإدارة فواتير المشتريات وحسابات الموردين (Ledger).
- 👥 **إدارة العملاء والولاء:** تسجيل بيانات العملاء، إدارة الحسابات الآجلة (الديون)، ونظام نقاط الولاء للعملاء المتميزين.
- 🖨️ **طباعة الفواتير والباركود:** تكامل مع `QZ Tray` لطباعة الإيصالات والباركود بصمت وبسرعة على طابعات الكاشير والحرارية.
- 🖥️ **تطبيق Portable Desktop:** النظام يعمل كتطبيق ويب (PWA) أو تطبيق سطح مكتب مستقل مبني بـ `Electron` يدمج بداخله (PHP و MySQL) ليعمل بدون أي تثبيت مسبق.
- 🚀 **تحديثات لحظية (Real-time):** الاعتماد على تقنيات الـ `ETag Caching` و `Server-Sent Events` لضمان تزامن البيانات لحظياً بين المستخدمين دون التحميل على السيرفر.

---

## 🛠️ التقنيات المستخدمة (Tech Stack)

### 🎨 الواجهة الأمامية (Frontend)
- **الإطار:** React 19.2 + Vite
- **إدارة الحالة:** Zustand
- **التوجيه:** React Router 7.18.2
- **قاعدة البيانات المحلية:** IndexedDB (idb)
- **الرسوم البيانية:** Recharts

### ⚙️ الواجهة الخلفية (Backend)
- **اللغة:** PHP 8.2 (Service Layer + Repository Pattern)
- **قواعد البيانات:** MySQL 8.0
- **نظام التخزين المؤقت:** Redis / APCu / File Cache مع دعم الإبطال التلقائي (Cache Invalidation).
- **المهام بالخلفية:** Job Queue & Event Dispatcher.

### 💻 تطبيق سطح المكتب (Desktop App)
- **الإطار:** Electron 43.2
- **إدارة الخدمات:** تشغيل (PHP Server، MySQL المدمج، HTTPS Proxy) في الخلفية.
- **التحديث التلقائي:** عبر GitHub Releases (electron-updater).

---

## 📂 هيكل المشروع (Project Structure)

```text
/pos
├── /backend          ← PHP Native MVC API (الواجهة الخلفية)
│   ├── config/       ← إعدادات قاعدة البيانات والتطبيق
│   ├── Controllers/  ← دوال التحكم (HTTP)
│   ├── Services/     ← منطق الأعمال (Business Logic)
│   ├── Repositories/ ← الوصول لقاعدة البيانات
│   ├── Models/       ← الكيانات الأساسية
│   └── index.php     ← نقطة الدخول (Entry Point)
│
├── /frontend         ← React 19 + Vite (الواجهة الأمامية)
│
├── /electron         ← تطبيق سطح المكتب (إدارة الخدمات)
│   ├── main.js       ← ملف الإطلاق الأساسي
│   └── services/     ← خدمات PHP و MySQL المدمجة
│
├── /portable         ← بيئات التشغيل المستقلة (PHP, MySQL, Java) المدمجة مع التطبيق
│
├── /tray             ← إعدادات وملفات QZ Tray لطباعة الفواتير
│
├── /database         ← الجداول وسكربتات التهيئة (Migrations & Schema)
│
├── /certs            ← شهادات الـ SSL للاتصال الآمن محلياً
│
└── docker-compose.yml
```

---

## 🚀 طريقة التشغيل (Installation & Setup)

### الخيار الأول: باستخدام Docker (مُوصى به للإنتاج)
بإمكانك تشغيل النظام كاملاً بأمر واحد:
```bash
docker-compose up -d --build
```
سيكون النظام متاحاً على: `http://localhost:8000`

### الخيار الثاني: التشغيل المحلي (XAMPP / Node.js) للتطوير

**1. إعداد المتغيرات البيئية (Environment Variables):**
قم بنسخ ملف الإعدادات الافتراضي للواجهة الخلفية وتعديله بما يتناسب مع بيئتك (بيانات قاعدة البيانات):
```bash
cd backend
cp .env.example .env
```

**2. إعداد قاعدة البيانات (Database Setup):**
استورد ملف الـ Schema لتجهيز الجداول:
```bash
mysql -u root -p pos < C:\xampp\htdocs\pos\database\pos_schema.sql
```
ثم قم ببذر البيانات الافتراضية (الصلاحيات وغيرها):
```bash
mysql -u root -p pos < C:\xampp\htdocs\pos\database\seeders\permissions_seed.sql
```

**3. تشغيل الواجهة الأمامية (Frontend):**
```bash
cd frontend
npm install
npm run dev
```

**3. تشغيل تطبيق سطح المكتب (Electron):**
```bash
npm run electron:dev
```
لعمل حزمة تثبيت (Build) مستقلة (Portable EXE):
```bash
npm run electron:build
```

---

## 🔑 إنشاء مدير النظام الأول

لا يزرع النظام حسابات تفاعلية أو كلمات مرور افتراضية. بعد تهيئة قاعدة البيانات،
شغّل أداة bootstrap محلياً على جهاز الخادم. الأداة تتطلب كلمة مرور فريدة من
14 محرفاً على الأقل، وترفض التنفيذ إذا كان هناك مدير نشط بالفعل.

PowerShell:

```powershell
$env:INITIAL_ADMIN_EMAIL = Read-Host 'Admin email'
$env:INITIAL_ADMIN_NAME = Read-Host 'Admin name'
$securePassword = Read-Host 'Admin password (14+ characters)' -AsSecureString
$env:INITIAL_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new('', $securePassword).Password
C:\xampp\php\php.exe backend\cli\bootstrap-admin.php
Remove-Item Env:INITIAL_ADMIN_EMAIL, Env:INITIAL_ADMIN_NAME, Env:INITIAL_ADMIN_PASSWORD
```

Bash:

```bash
read -r -p 'Admin email: ' INITIAL_ADMIN_EMAIL
read -r -p 'Admin name: ' INITIAL_ADMIN_NAME
read -r -s -p 'Admin password (14+ characters): ' INITIAL_ADMIN_PASSWORD
echo
export INITIAL_ADMIN_EMAIL INITIAL_ADMIN_NAME INITIAL_ADMIN_PASSWORD
php backend/cli/bootstrap-admin.php
unset INITIAL_ADMIN_EMAIL INITIAL_ADMIN_NAME INITIAL_ADMIN_PASSWORD
```

نفّذ هذا الإجراء من طرفية محلية موثوقة، ولا تضع بيانات المدير في ملف `.env`
أو سجل أو سكربت محفوظ.

---

## 🧪 الاختبارات (Testing)

النظام يحتوي على بنية اختبارات متكاملة لضمان الجودة:

- **اختبارات الواجهة الخلفية (Backend Unit Tests):**
  باستخدام `PHPUnit` لاختبار الـ Services والتأكد من صحة العمليات الحسابية ومنطق الأعمال.
  ```bash
  cd backend
  vendor/bin/phpunit tests/
  ```

- **اختبارات الواجهة الأمامية (Frontend E2E Tests):**
  باستخدام `Playwright` لاختبار رحلة المستخدم (User Journey) للصفحات الحرجة (مثل المصروفات، الإعدادات، وإدارة الجلسات).
  ```bash
  cd frontend
  npm run test:e2e
  ```

---

## 📖 توثيق الـ API (API Documentation)
تم توثيق الـ API بشكل احترافي باستخدام **OpenAPI (Swagger)**.
ملف التوثيق متوفر في: `backend/openapi.yaml`. يمكن استيراده في Postman أو عرضه عبر أدوات Swagger لمعرفة جميع الـ Endpoints وكيفية التخاطب معها.

---

## 📝 الترخيص (License)
هذا النظام مخصص للاستخدام التجاري الخاص. لا يُسمح بإعادة التوزيع أو البيع دون إذن مسبق.

## Desktop first run and factory reset 

The desktop runtime keeps its database and logs in the per-user application
data directory, so a new installation starts with an empty database and no
historical error log. It seeds the packaged defaults and creates
`admin@pos.local` with a cryptographically random temporary password. The
password is shown once in the local login screen and must be changed after the
first sign-in; no shared password is embedded in the release.

An administrator can use **System & Maintenance → Factory reset** to drop the
application database, restore the schema and default seed data, clear runtime
logs/session/cache state, and create a new temporary administrator credential.
The reset requires typing `RESET_POS_DATA` and deliberately preserves backup
files so they remain available for recovery. Existing installations are never
erased automatically during an upgrade.

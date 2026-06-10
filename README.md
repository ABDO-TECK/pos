# 🛒 Smart POS System (نظام إدارة الكاشير ونقاط البيع)

![Version](https://img.shields.io/badge/version-1.1.32-blue.svg)
![React](https://img.shields.io/badge/React-19.2-61DAFB?logo=react&logoColor=black)
![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![Electron](https://img.shields.io/badge/Electron-30.0-47848F?logo=electron&logoColor=white)
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
- **التوجيه:** React Router 7.14
- **قاعدة البيانات المحلية:** IndexedDB (idb)
- **الرسوم البيانية:** Recharts

### ⚙️ الواجهة الخلفية (Backend)
- **اللغة:** PHP 8.2 (Service Layer + Repository Pattern)
- **قواعد البيانات:** MySQL 8.0
- **نظام التخزين المؤقت:** Redis / APCu / File Cache مع دعم الإبطال التلقائي (Cache Invalidation).
- **المهام بالخلفية:** Job Queue & Event Dispatcher.

### 💻 تطبيق سطح المكتب (Desktop App)
- **الإطار:** Electron 30.0
- **إدارة الخدمات:** تشغيل (PHP Server، MySQL المدمج، HTTPS Proxy، WebSocket) في الخلفية.
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
سيكون النظام متاحاً على: `http://localhost:8080`

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

## 🔑 بيانات الدخول الافتراضية

| الدور (Role) | البريد الإلكتروني (Email) | كلمة المرور الافتراضية |
|--------------|---------------------------|-------------------------|
| مدير النظام | admin@pos.com             | password                |

> [!WARNING]
> النظام يجبر المستخدم على تغيير كلمة المرور الافتراضية عند تسجيل الدخول لأول مرة لضمان الأمان.

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

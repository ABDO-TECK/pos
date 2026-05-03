# 🛒 Smart POS System (نظام إدارة الكاشير ونقاط البيع)

![Version](https://img.shields.io/badge/version-1.1.26-blue.svg)
![React](https://img.shields.io/badge/React-19.0-61DAFB?logo=react&logoColor=black)
![PHP](https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white)
![Electron](https://img.shields.io/badge/Electron-Desktop-47848F?logo=electron&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?logo=mysql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?logo=docker&logoColor=white)

نظام نقاط بيع (POS) متكامل وسريع تم تصميمه خصيصاً لإدارة السوبر ماركت والمحلات التجارية بكفاءة عالية. النظام مبني باستخدام **React 19** للواجهة الأمامية وتطبيق سطح المكتب (Electron)، مع واجهة برمجة تطبيقات (API) قوية مبنية بـ **PHP Native MVC**.

---

## ✨ المميزات الرئيسية (Features)

- ⚡ **نقطة بيع فائقة السرعة (Fast POS):** دعم كامل لأجهزة قراءة الباركود، اختصارات لوحة المفاتيح، والبحث اللحظي مع تنبيهات صوتية.
- 📶 **دعم العمل بدون إنترنت (Offline-First):** إمكانية البيع والعمل حتى في حال انقطاع الاتصال بالسيرفر بفضل `IndexedDB` والمزامنة التلقائية (Sync Queue) عند عودة الاتصال.
- 📦 **إدارة المخزون والمشتريات:** تتبع دقيق للمخزون، تنبيهات بالنواقص، وإدارة فواتير المشتريات وحسابات الموردين (Ledger).
- 👥 **إدارة العملاء:** تسجيل بيانات العملاء، إدارة الحسابات الآجلة (الديون)، وتسديد الدفعات.
- 🖨️ **طباعة الفواتير والباركود:** تكامل مع `QZ Tray` لطباعة الإيصالات والباركود بصمت وبسرعة على طابعات الكاشير والحرارية.
- 📊 **تقارير وتحليلات ذكية:** رسوم بيانية تفاعلية (`Recharts`) للإيرادات، الأرباح، والمصروفات اليومية والشهرية، بالإضافة لتحديد المنتجات الأكثر مبيعاً وربحية.
- 🖥️ **تطبيق سطح مكتب (Desktop App):** النظام يعمل كتطبيق ويب (PWA) أو تطبيق سطح مكتب مستقل مبني بـ `Electron`.
- 🔒 **أمان وحماية:** توثيق آمن، حماية ضد ثغرات SQL Injection عبر `PDO`، وتحديد صلاحيات دقيق (مدير / كاشير).

---

## 🛠️ التقنيات المستخدمة (Tech Stack)

### الواجهة الأمامية (Frontend)
- **الإطار:** React 19 + Vite
- **تطبيق سطح المكتب:** Electron
- **إدارة الحالة:** Zustand
- **الرسوم البيانية:** Recharts
- **الطباعة:** QZ Tray (للطباعة المباشرة بدون نوافذ حوار)
- **تخزين محلي:** IndexedDB لعمليات الأوفلاين (Offline Mode)

### الواجهة الخلفية (Backend)
- **اللغة:** PHP 8.2 (Native MVC Architecture)
- **قواعد البيانات:** MySQL / SQLite
- **الحماية:** Rate Limiting, Parameter Binding, Content Security Policy (CSP)
- **بيئة التشغيل:** XAMPP أو Docker (متوفر `docker-compose.yml`)

---

## 🚀 طريقة التشغيل (Installation & Setup)

### الخيار الأول: باستخدام Docker (مُوصى به للإنتاج)
بإمكانك تشغيل النظام كاملاً بأمر واحد:
```bash
docker-compose up -d --build
```
سيكون النظام متاحاً على: `http://localhost:8080`

### الخيار الثاني: التشغيل المحلي (XAMPP / Node.js)

#### 1. إعداد قاعدة البيانات (Database Setup)
1. قم بفتح `http://localhost/phpmyadmin`.
2. قم بإنشاء قاعدة بيانات (يفضل تسميتها `pos`).
3. استورد ملف الـ Schema لتجهيز الجداول:
   ```bash
   mysql -u root -p pos < C:\xampp\htdocs\pos\database\pos_schema.sql
   ```

#### 2. إعداد الخادم (Backend)
- الـ API تعمل على المسار: `http://localhost/pos/backend/api`
- تأكد من ضبط إعدادات الاتصال بقاعدة البيانات في ملف: `backend/config/config.php`

#### 3. تشغيل الواجهة الأمامية (Frontend)
```bash
cd C:\xampp\htdocs\pos\frontend
npm install
npm run dev
```
- واجهة الويب متاحة على: `http://localhost:5173`

#### 4. تشغيل تطبيق سطح المكتب (Electron)
لتشغيل البرنامج كتطبيق Desktop:
```bash
cd C:\xampp\htdocs\pos
npm run electron:dev
```
لعمل حزمة تثبيت (Build) لتطبيق Desktop:
```bash
npm run electron:build
```

---

## 🔑 بيانات الدخول الافتراضية

| الدور (Role) | البريد الإلكتروني (Email) | كلمة المرور (Password) |
|--------------|---------------------------|-------------------------|
| مدير النظام | admin@pos.com             | password                |
| كاشير        | cashier@pos.com           | password                |

> **ملاحظة:** يُنصح بشدة تغيير كلمة المرور للمدير بعد تسجيل الدخول الأول لضمان الأمان.

---

## 📂 هيكل المشروع (Project Structure)

```text
/pos
├── /backend          ← PHP Native MVC API
│   ├── config/       ← إعدادات قاعدة البيانات والتطبيق
│   ├── core/         ← الموجه (Router) والمتحكم الأساسي
│   ├── controllers/  ← Auth, Products, Sales, Suppliers, Reports
│   ├── models/       ← User, Product, Invoice, Supplier, Customer
│   ├── middleware/   ← Auth, Admin, RateLimiter
│   ├── routes/       ← api.php
│   └── index.php     ← Entry point
│
├── /frontend         ← React + Vite
│   └── src/
│       ├── api/      ← Axios + API endpoints
│       ├── store/    ← Zustand stores (auth, cart, products)
│       ├── pages/    ← POS, Products, Inventory, Reports, Users...
│       ├── components/
│       └── utils/    ← IDB (Offline), Formatters, QZ Print helpers
│
├── /database         ← ملفات هيكل قاعدة البيانات
│   └── pos_schema.sql
│
├── /docker           ← ملفات إعداد حاويات Docker
├── main.js           ← ملف الإطلاق لتطبيق Electron
└── docker-compose.yml
```

---

## 📖 توثيق الـ API (API Documentation)
تم توثيق الـ API بشكل احترافي باستخدام **OpenAPI (Swagger)**.
ملف التوثيق متوفر في: `backend/openapi.yaml`. يمكن استيراده في Postman أو عرضه عبر أدوات Swagger لمعرفة جميع الـ Endpoints وكيفية التخاطب معها.

---

## 📝 الترخيص (License)
هذا النظام مخصص للاستخدام التجاري الخاص. لا يُسمح بإعادة التوزيع أو البيع دون إذن مسبق.

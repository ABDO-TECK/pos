# نظام الكاشير — Supermarket POS System

نظام POS متكامل لإدارة سوبر ماركت مبني على **PHP Native MVC** و**React + Vite**.

---

## المتطلبات

- XAMPP (PHP 8.1+, MySQL 8+, Apache)
- Node.js 18+

---

## طريقة التشغيل

### 1. إعداد قاعدة البيانات

افتح `http://localhost/phpmyadmin` ثم نفّذ:

```sql
SOURCE C:/xampp/htdocs/pos/database/pos_schema.sql;
```

أو من Command Line:
```bash
mysql -u root -p < C:\xampp\htdocs\pos\database\pos_schema.sql
```

### 2. إعداد Backend

تأكد أن Apache يعمل من XAMPP.
الـ API سيعمل على: `http://localhost/pos/backend/api`

تحقق من إعدادات الاتصال في:
```
backend/config/config.php
```

### 3. تشغيل Frontend (Development)

```bash
cd C:\xampp\htdocs\pos\frontend
npm install
npm run dev
```

الـ Frontend سيعمل على: `http://localhost:5173`

### 4. بناء للإنتاج

```bash
cd frontend
npm run build
```

ثم انسخ محتوى `dist/` إلى `C:\xampp\htdocs\pos\frontend-dist\`

---

## بيانات الدخول الافتراضية

| الدور | البريد | كلمة المرور |
|-------|--------|-------------|
| مدير النظام | admin@pos.com | password |
| كاشير | cashier@pos.com | password |

---

## هيكل المشروع

```
/pos
├── /backend          ← PHP Native MVC API
│   ├── config/       ← إعدادات DB وتطبيق
│   ├── core/         ← Router, Controller
│   ├── controllers/  ← Auth, Products, Sales, Suppliers, Reports
│   ├── models/       ← User, Product, Invoice, Supplier
│   ├── middleware/   ← Auth, Admin
│   ├── routes/       ← api.php
│   ├── helpers/      ← Response, Cache
│   └── index.php     ← Entry point
│
├── /frontend         ← React + Vite
│   └── src/
│       ├── api/      ← Axios + endpoints
│       ├── store/    ← Zustand (auth, cart, products)
│       ├── pages/    ← POS, Products, Inventory, Suppliers, Reports, Users
│       ├── components/
│       └── utils/    ← IDB, formatters, offline sync
│
└── /database
    └── pos_schema.sql
```

---

## API Endpoints

| Method | Endpoint | الوصف |
|--------|----------|-------|
| POST | /api/login | تسجيل دخول |
| GET | /api/products | قائمة المنتجات |
| POST | /api/products | إضافة منتج |
| POST | /api/sales | إنشاء فاتورة بيع |
| GET | /api/inventory/low-stock | منتجات المخزون المنخفض |
| GET | /api/reports/daily | تقرير يومي |
| GET | /api/reports/monthly | تقرير شهري |
| GET | /api/reports/summary | ملخص عام |

---

## المميزات

- **POS سريع**: مسح الباركود + إضافة فورية + صوت beep
- **Offline Mode**: IndexedDB + sync queue عند العودة للإنترنت
- **إدارة كاملة**: منتجات، مخزون، موردون، تقارير
- **أدوار المستخدمين**: Admin و Cashier
- **أمان**: Token auth، PDO prepared statements، password hashing
- **تقارير**: يومية، شهرية، أفضل منتجات، رسوم بيانية

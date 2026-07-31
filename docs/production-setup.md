# دليل إعداد الإنتاج — ABDO-TECK POS System

هذا الدليل يوضح كيفية نشر نظام POS في بيئة إنتاج حقيقية باستخدام Apache + PHP على Windows (XAMPP) أو Linux، وكذلك عبر Docker.

---

## 1. المتطلبات

| المكون        | الحد الأدنى           |
|--------------|----------------------|
| PHP          | 8.1+                 |
| MySQL/MariaDB| 8.0+ / 10.6+        |
| Node.js      | 18+ (للبناء فقط)     |
| Apache       | 2.4+ مع mod_rewrite  |
| Composer     | 2.x                  |

---

## 2. بناء الواجهة الأمامية (Frontend Build)

```bash
cd frontend
npm ci
npm run build
```

## Cleanup scheduling for non-Electron deployments

Run `backend/cli/cleanup-logs.php` from an absolute path as a dedicated,
least-privileged service account. Do not run it as root or from an Electron
scheduler. The account needs write access only to `backend/logs`.

Linux systemd service and timer:

```ini
# /etc/systemd/system/pos-log-cleanup.service
[Service]
User=pos
Group=pos
WorkingDirectory=/var/www/pos/backend
ExecStart=/usr/bin/php /var/www/pos/backend/cli/cleanup-logs.php
NoNewPrivileges=true
PrivateTmp=true
```

```ini
# /etc/systemd/system/pos-log-cleanup.timer
[Timer]
OnCalendar=*-*-* 02:15:00
Persistent=true
[Install]
WantedBy=timers.target
```

Cron alternative: `15 2 * * * pos /usr/bin/php /var/www/pos/backend/cli/cleanup-logs.php`.

Windows Task Scheduler: run daily at 02:15 as a dedicated service account,
with Start in `C:\pos\backend`, using
`C:\PHP\php.exe C:\pos\backend\cli\cleanup-logs.php`; grant that account
write access only to `C:\pos\backend\logs` and read/execute access to the
application, never to `.env`.

سيتم إنشاء مجلد `frontend-dist/` الذي يحتوي على ملفات الإنتاج الجاهزة (HTML, JS, CSS).

> **ملاحظة:** Vite proxy يعمل فقط في بيئة التطوير (`npm run dev`). في الإنتاج يجب استخدام Apache VirtualHost لتوجيه الطلبات.

---

## 3. إعداد قاعدة البيانات

```bash
# إنشاء قاعدة البيانات
mysql -u root -p -e "CREATE DATABASE pos_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# استيراد المخطط
mysql -u root -p pos_db < database/pos_schema.sql

# تشغيل التهجيرات (Migrations)
# قم بتشغيل جميع ملفات SQL في مجلد database/migrations/ بالترتيب:
for f in database/migrations/*.sql; do mysql -u root -p pos_db < "$f"; done
```

---

## 4. إعداد الباك إند (Backend)

```bash
cd backend
composer install --no-dev --optimize-autoloader
```

### ملف البيئة `.env`

```ini
# قاعدة البيانات
DB_HOST=localhost
DB_NAME=pos_db
DB_USER=pos_user
DB_PASS=YOUR_STRONG_PASSWORD

# التطبيق
APP_ENV=production
APP_DEBUG=false

# المصادقة (Token lifetime بالثواني — 7 أيام)
TOKEN_LIFETIME=604800

# المخزون
LOW_STOCK_THRESHOLD=5

# الضريبة
TAX_RATE=0.15

# الواجهة الأمامية
FRONTEND_URL=https://pos.yourdomain.com

# الأمان
ALLOW_WEB_RESTORE=false
```

> **⚠️ تحذير:** تأكد من أن `APP_DEBUG=false` و `ALLOW_WEB_RESTORE=false` في الإنتاج.

---

## 5. إعداد Apache VirtualHost

### Windows (XAMPP)

عدّل ملف `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName pos.local
    DocumentRoot "C:/xampp/htdocs/pos/frontend-dist"

    # ── الواجهة الأمامية (SPA) ──────────────────────────────
    <Directory "C:/xampp/htdocs/pos/frontend-dist">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # SPA fallback — أي مسار غير موجود يُعاد توجيهه إلى index.html
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_URI} !^/api/
        RewriteRule . /index.html [L]
    </Directory>

    # ── الباك إند (API Proxy) ───────────────────────────────
    # توجيه طلبات /api/* إلى مجلد الباك إند
    Alias /api "C:/xampp/htdocs/pos/backend/api"

    # طريقة بديلة باستخدام ProxyPass (تحتاج mod_proxy):
    # ProxyPass        /api http://localhost/pos/backend/api
    # ProxyPassReverse /api http://localhost/pos/backend/api

    <Directory "C:/xampp/htdocs/pos/backend">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # ── الأمان ──────────────────────────────────────────────
    <FilesMatch "\.(env|log|sql|md)$">
        Require all denied
    </FilesMatch>

    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</VirtualHost>
```

### Linux (Ubuntu/Debian)

```apache
<VirtualHost *:443>
    ServerName pos.yourdomain.com
    DocumentRoot /var/www/pos/frontend-dist

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/pos.yourdomain.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/pos.yourdomain.com/privkey.pem

    <Directory /var/www/pos/frontend-dist>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_URI} !^/api/
        RewriteRule . /index.html [L]
    </Directory>

    # API reverse proxy
    ProxyPreserveHost On
    ProxyPass        /api http://127.0.0.1:8080/pos/backend/api
    ProxyPassReverse /api http://127.0.0.1:8080/pos/backend/api

    <Directory /var/www/pos/backend>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch "\.(env|log|sql|md)$">
        Require all denied
    </FilesMatch>

    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
</VirtualHost>

# HTTP → HTTPS redirect
<VirtualHost *:80>
    ServerName pos.yourdomain.com
    Redirect permanent / https://pos.yourdomain.com/
</VirtualHost>
```

---

## 6. النشر عبر Docker

راجع ملفات `Dockerfile` و `docker-compose.yml` في جذر المشروع.

```bash
# بناء وتشغيل
docker-compose up -d --build

# عرض اللوجات
docker-compose logs -f app
```

### المتغيرات البيئية في Docker

```yaml
environment:
  - DB_HOST=db
  - DB_NAME=pos_db
  - DB_USER=root
  - DB_PASS=your_strong_password
  - APP_ENV=production
  - APP_DEBUG=false
```

---

## 7. الأمان — قائمة مراجعة الإنتاج

- [ ] `APP_DEBUG=false`
- [ ] `ALLOW_WEB_RESTORE=false`
- [ ] كلمات المرور الافتراضية تم تغييرها (الحقل `force_password_change`)
- [ ] HTTPS مُفعَّل (Let's Encrypt أو شهادة مخصصة)
- [ ] حماية ملفات `.env` و `logs/` من الوصول العام
- [ ] تشغيل MySQL بمستخدم محدود الصلاحيات (ليس `root`)
- [ ] `error_reporting` و `display_errors` معطلة في `php.ini`
- [ ] نسخة احتياطية تلقائية لقاعدة البيانات (cron job)

---

## 8. النسخ الاحتياطي

### يدوي

استخدم لوحة التحكم: **الإعدادات → نسخ احتياطي**.

### تلقائي (Cron)

```bash
# يومياً الساعة 2:00 صباحاً
0 2 * * * mysqldump -u pos_user -p'PASSWORD' pos_db | gzip > /backups/pos_$(date +\%Y\%m\%d).sql.gz
```

---

## 9. المراقبة والصيانة

- **ملفات اللوج:** `backend/logs/pos-YYYY-MM-DD.log`
- **تدوير اللوج:** تلقائي (يحتفظ بآخر 30 يوم)
- **Health Check:** `GET /api/health` — يعيد حالة النظام وقاعدة البيانات
- **أخطاء الواجهة:** تُسجَّل تلقائياً عبر `POST /api/client-log`

---

## 10. التحديث

```bash
# 1. سحب آخر تحديث
git pull origin main

# 2. تحديث الباك إند
cd backend && composer install --no-dev --optimize-autoloader

# 3. إعادة بناء الواجهة
cd ../frontend && npm ci && npm run build

# 4. تشغيل التهجيرات الجديدة
for f in database/migrations/*.sql; do mysql -u pos_user -p pos_db < "$f"; done

# 5. مسح الكاش (إن وُجد)
# يمكن إعادة تشغيل Apache إذا لزم الأمر
sudo systemctl restart apache2
```

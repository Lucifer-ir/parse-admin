# Parse Admin

پنل مدیریت Parse با رابط فارسی، راست‌چین و واکنش‌گرا، به‌همراه API مستقل PHP و دیتابیس MySQL.

## اجزای پروژه

- **Admin UI:** HTML/CSS/JavaScript استاتیک روی `panel.aiparseh.ir`.
- **API:** PHP 8.2+ و PDO MySQL روی `api.aiparseh.ir`.
- **Database:** MySQL؛ دیتابیس `parse_admin` و حساب محدود برنامه.
- **Installer:** نصب و به‌روزرسانی پنل، PHP-FPM، Nginx، MySQL، ساخت جداول و ساخت اولین مدیر.

## استقرار یا به‌روزرسانی روی Ubuntu 24.04 / Debian

ابتدا دو رکورد DNS نوع A بسازید و هر دو را به IP سرور متصل کنید:

| Host | Type | Value |
|---|---|---|
| `panel` | A | IP سرور |
| `api` | A | IP سرور |

پورت‌های TCP شماره 80 و 443 باید از فایروال/پنل دیتاسنتر قابل دسترسی باشند. سپس اجرا کنید:

```bash
curl -fsSL https://raw.githubusercontent.com/Lucifer-ir/parse-admin/main/install.sh -o /root/parse-admin-install.sh
sudo bash /root/parse-admin-install.sh
```

در اجرای اول، اسکریپت ایمیل و رمز مدیر را می‌پرسد. رمز باید حداقل ۱۴ کاراکتر باشد. رمز در مخزن یا فایل لاگ ذخیره نمی‌شود. اگر حساب مدیر با آن ایمیل از قبل وجود داشته باشد، رمز و حساب را تغییر نمی‌دهد.

برای صدور گواهی با ایمیل مشخص:

```bash
sudo LE_EMAIL=admin@aiparseh.ir bash /root/parse-admin-install.sh
```

همین دستور برای به‌روزرسانی‌های بعدی هم استفاده می‌شود. جداول با `CREATE TABLE IF NOT EXISTS` ایجاد می‌شوند؛ داده‌های دیتابیس حذف نمی‌شوند و رمز دیتابیس در اجرای مجدد حفظ خواهد شد. برای تغییر ساختار دیتابیس باید migration افزایشی اضافه شود؛ schema این اسکریپت داده‌های موجود را drop نمی‌کند.

## به‌روزرسانی فقط پنل (بدون دست‌زدن به دیتابیس)

برای انتشار تغییرات HTML/CSS/JavaScript پس از نصب اولیه، روی سرور اجرا کنید:

```bash
curl -fsSL https://raw.githubusercontent.com/Lucifer-ir/parse-admin/main/update-panel.sh -o /root/update-parse-panel.sh
bash /root/update-parse-panel.sh
```

این اسکریپت فقط فایل‌های رابط مدیریت را منتشر و Nginx را reload می‌کند؛ دیتابیس، رمزهای مدیر و تنظیمات API را تغییر نمی‌دهد.

## API

Base URL: `https://api.aiparseh.ir`

- `GET /health`
- `POST /auth/login` — JSON: `{"email":"admin@example.com","password":"..."}`
- `GET /auth/me` — نیازمند Bearer token
- `POST /auth/logout` — نیازمند Bearer token
- `GET /admin/stats` — نیازمند مدیر
- `GET /admin/users` — نیازمند مدیر
- `GET /admin/questions?limit=200&offset=0` — نیازمند مدیر
- `DELETE /admin/questions/{id}` — نیازمند مدیر

## امنیت اعمال‌شده

- رمزها با `password_hash()` و `password_verify()` ذخیره می‌شوند؛ رمز خام ذخیره نمی‌شود.
- توکن تصادفی ۲۵۶ بیتی به کلاینت داده می‌شود؛ فقط SHA-256 آن در دیتابیس ذخیره می‌شود و انقضای ۱۲ ساعته دارد.
- دسترسی API با Bearer token و نقش `admin` کنترل می‌شود.
- محدودیت تلاش ناموفق ورود: ۱۲ بار در بازه ۱۵ دقیقه برای IP یا ایمیل.
- PDO با prepared statements و خطای دیتابیس بدون افشای جزئیات به کاربر.
- CORS فقط برای `https://panel.aiparseh.ir` مجاز است؛ wildcard استفاده نمی‌شود.
- دیتابیس فقط روی localhost و با کاربر محدود برنامه در دسترس است.
- فایل تنظیمات و رمز دیتابیس در `/etc/parse-api.env` با دسترسی محدود root:www-data نگهداری می‌شود.
- سورس PHP خارج از web root پنل نگهداری می‌شود؛ Nginx فقط `backend/public` را برای API سرو می‌کند.
- رخدادهای ورود و عملیات حذف سؤال در `admin_audit_log` ثبت می‌شوند.
- خروج از پنل توکن سمت سرور را revoke می‌کند.

## ساختار

```
index.html
assets/
backend/
  config.php
  schema.sql
  public/index.php
  bin/create-admin.php
install.sh
```

## نکات عملیاتی

- این API با دیتابیس اختصاصی پنل و مدل داده فعلی همین ریپو شروع می‌شود. اگر اپلیکیشن Flutter قرار است کاربران/سؤالات/منابع خودش را در همین دیتابیس ذخیره کند، باید endpointهای مرتبط با قرارداد اپلیکیشن نیز اضافه شوند.
- برای بکاپ مطمئن، از دیتابیس به فضای خارج از سرور نیز نسخه پشتیبان منظم بگیرید.
- «امنیت کامل» مطلق نیست؛ قبل از استفاده عمومی، تست نفوذ، مانیتورینگ، بکاپ خارج از سرور و بازبینی دوره‌ای وابستگی‌ها ضروری است.

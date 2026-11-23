# Parse Admin

پنل مدیریت مستقل Parse با رابط فارسی، راست‌چین و واکنش‌گرا.

## امکانات
- ورود با همان حساب مدیر Parse API از مسیر `POST /auth/login`؛ حساب باید `role=admin` داشته باشد.
- تنظیم و ذخیره Base URL مربوط به API در خود پنل.
- بررسی سلامت API از مسیر `GET /health`.
- داشبورد آمار کاربران، کاربران فعال، سؤالات و منابع.
- نمایش و جست‌وجوی کاربران.
- مشاهده، جست‌وجو و حذف سؤال‌ها با تأیید.
- نگهداری توکن فقط در `sessionStorage` و آدرس API در `localStorage`.

## انتشار روی GitHub Pages
در Settings → Pages، گزینه GitHub Actions را انتخاب کنید. Workflow با هر push روی `main` پنل را منتشر می‌کند.

## نصب روی سرور Ubuntu/Debian و دامنه اختصاصی

1. رکورد DNS نوع `A` برای `panel.aiparseh.ir` را به IPv4 سرور متصل کنید.
2. مطمئن شوید پورت‌های ورودی TCP `80` و `443` از فایروال و پنل دیتاسنتر باز هستند.
3. روی سرور اجرا کنید:

```bash
apt-get update && apt-get install -y curl
curl -fsSL https://raw.githubusercontent.com/Lucifer-ir/parse-admin/main/install.sh -o install.sh
sudo bash install.sh
```

Installer نیازمندی‌های سیستم (Git، Nginx، rsync و Certbot) را نصب می‌کند، مخزن را در `/opt/parse-admin` دریافت می‌کند، فایل‌های استاتیک را در `/var/www/parse-admin` قرار می‌دهد، Nginx را پیکربندی می‌کند و در صورت آماده بودن DNS گواهی Let's Encrypt می‌گیرد.

برای ثبت ایمیل هنگام صدور گواهی (توصیه می‌شود):

```bash
sudo LE_EMAIL=admin@aiparseh.ir bash install.sh
```

پس از نصب، وارد [https://panel.aiparseh.ir](https://panel.aiparseh.ir) شوید و در صفحه ورود، Base URL سرویس API را وارد و اتصال را تست کنید.

## تنظیم CORS در API

در محیط اجرای Parse API، مقدار `CORS_ORIGINS` باید دامنه پنل را نیز شامل شود. originهای قبلی را نگه دارید و فقط این origin را اضافه کنید:

```env
CORS_ORIGINS=https://lucifer-ir.github.io,https://panel.aiparseh.ir
```

سپس سرویس API را restart/redeploy کنید. مسیرهای مورد استفاده پنل:
- `GET /health`
- `POST /auth/login`
- `GET /auth/me`
- `GET /admin/stats`
- `GET /admin/users`
- `GET /admin/questions`
- `DELETE /admin/questions/{id}`

این مسیرهای مدیریتی باید در API نصب‌شده وجود داشته باشند. پنل در حال حاضر مدیریت وضعیت فعال/غیرفعال کاربران را انجام نمی‌دهد، چون API فعلی endpoint تغییر وضعیت کاربر را ارائه نمی‌کند.

## نکات امنیتی
- هیچ رمز عبور پیش‌فرضی در پنل وجود ندارد؛ ورود فقط با اعتبارنامه معتبر API انجام می‌شود.
- برای استقرار واقعی، API را فقط روی HTTPS ارائه کنید.
- `install.sh` را فقط از مخزن مورد اعتماد اجرا کنید.
- این مخزن رابط کاربری و نصب‌کننده است؛ ساخت یا تغییر حساب مدیر باید از مسیر امن مدیریتی/دیتابیس API انجام شود.

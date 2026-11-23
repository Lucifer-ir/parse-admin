# Parse Admin — PHP AI Control Center

کنسول مدیریتی Parse با رندر سمت سرور PHP، نشست امن مدیر، دیتابیس MySQL و درگاه سمت‌سرور Paasta AI.

این پروژه دیگر یک پنل HTML استاتیک نیست. ورودی وب‌سایت `app/public/index.php` است و احراز هویت، نشست کاربر، کنترل CSRF، ارتباط دیتابیس و درخواست‌های هوش مصنوعی از سمت سرور انجام می‌شوند.

## معماری

- **پنل مدیریت:** PHP 8.2+ و PHP-FPM روی `panel.aiparseh.ir`
- **API مستقل پلتفرم:** سرویس PHP روی `api.aiparseh.ir` حفظ می‌شود.
- **Database:** MySQL با دیتابیس `parse_admin`
- **AI Provider:** Paasta AI Gateway؛ تماس خروجی از سرور Parse
- **Secret storage:** کلید Provider با Sodium Secretbox رمز می‌شود. کلید رمزنگاری در `/etc/parse-api.env` است و در Git یا web root قرار نمی‌گیرد.

## به‌روزرسانی سرور فعلی

روی سروری که نسخه قبلی Parse Admin روی آن نصب شده، با کاربر root این دو دستور را اجرا کنید:

```bash
curl -fsSL https://raw.githubusercontent.com/Lucifer-ir/parse-admin/main/update-panel.sh -o /root/update-parse-panel.sh
bash /root/update-parse-panel.sh
```

این اسکریپت نسخه PHP را منتشر می‌کند، migration افزایشی را اعمال می‌کند و Nginx را به PHP-FPM متصل می‌کند. جداول، حساب مدیر، رمزها و داده‌های قبلی حذف یا بازنشانی نمی‌شوند.

## نصب تازه

ابتدا DNS نوع A برای هر دو زیردامنه به IP سرور تنظیم شود:

| Host | Type | مقصد |
|---|---|---|
| `panel` | A | IP سرور |
| `api` | A | IP سرور |

سپس روی Ubuntu 24.04 / Debian اجرا کنید:

```bash
curl -fsSL https://raw.githubusercontent.com/Lucifer-ir/parse-admin/main/install.sh -o /root/parse-admin-install.sh
bash /root/parse-admin-install.sh
```

پورت‌های TCP شماره 80 و 443 باید از فایروال و پنل دیتاسنتر باز باشند. نصب‌کننده ابتدا PHP API، MySQL، دیتابیس و حساب مدیر را آماده می‌کند و در پایان پنل PHP را با `update-panel.sh` منتشر می‌کند.

در نصب تازه، ایمیل و رمز مدیر اولیه در ترمینال پرسیده می‌شود. رمز باید حداقل ۱۴ کاراکتر باشد و در مخزن یا فایل لاگ ذخیره نمی‌شود. در نصب مجدد، حساب مدیر موجود با همان ایمیل تغییر نمی‌کند.

برای ثبت ایمیل Let's Encrypt:

```bash
LE_EMAIL=admin@aiparseh.ir bash /root/parse-admin-install.sh
```

## ورود و نشست

ورود با فرم سمت‌سرور PHP انجام می‌شود؛ رمز عبور در JavaScript به API فرستاده نمی‌شود. PHP اعتبار حساب مدیر موجود در جدول `users` را با `password_verify()` بررسی می‌کند و نشست سمت‌سرور ایجاد می‌کند.

کوکی نشست HttpOnly و SameSite Strict است و در HTTPS با پرچم Secure ارسال می‌شود. شناسه نشست پس از ورود بازتولید می‌شود، CSRF برای درخواست‌های تغییردهنده بررسی می‌شود و نشست پس از ۳۰ دقیقه عدم فعالیت منقضی می‌شود.

## راه‌اندازی Paasta AI

پس از ورود، به **تنظیمات اتصال** بروید، کلید API پاستا را وارد و «تست کلید و اتصال» را اجرا کنید.

آدرس پایه در بک‌اند ثابت است:

```text
https://console.paasta.cloud/api/ai/v1
```

درخواست‌ها از PHP سرور ارسال می‌شوند. کلید Provider به مرورگر بازگردانده نمی‌شود؛ در جدول `ai_provider_settings` به‌صورت رمز‌شده نگهداری می‌شود. کلید رمزنگاری `APP_ENCRYPTION_KEY` در `/etc/parse-api.env` باقی می‌ماند و اسکریپت به‌روزرسانی آن را در اجراهای بعدی تغییر نمی‌دهد.

برای Anthropic Messages مسیر اختصاصی مستندشده با base URL زیر استفاده می‌شود:

```text
https://console.paasta.cloud/api/ai
```

## قابلیت‌های پنل

### استودیوی آموزشی
- تولید سؤال با موضوع، پایه/مخاطب، تعداد، سطح دشواری و دستورالعمل
- طراحی تمرین و فعالیت آموزشی
- بررسی متن از نظر املا، دستور زبان، انسجام، وضوح و بازنویسی
- ذخیره اختیاری خروجی‌ها در تاریخچه تولید
- ذخیره خروجی سؤال در بانک سؤال

### کاتالوگ مدل‌ها
- مدل‌های متنی
- مدل‌های تصمیم‌گیری
- مدل‌های تصویر
- مدل‌های صوت
- مدل‌ها و Voiceهای گفتار
- مدل‌های Embedding

کاتالوگ از خود API دریافت می‌شود؛ شناسه و قابلیت مدل‌ها ممکن است با توجه به کلید Provider متفاوت باشد.

### AI Playground
- Chat Completions
- پاسخ جریانی SSE
- Responses API با `store:false`
- Anthropic Messages
- Function Tools و `tool_choice`
- JSON Schema و Structured Outputs
- تنظیمات تکمیلی JSON برای ورودی ساختاریافته و `pdf_processing`
- ورودی Messages/Responses با محتوای چندبخشی

**نکته Function Tools:** مدل نام تابع و آرگومان‌ها را پیشنهاد می‌دهد؛ اجرای تابع، کنترل دسترسی و ارسال نتیجه با نقش `tool` مسئولیت برنامه فراخوان است. پنل تابع دلخواه را خودکار روی سرور اجرا نمی‌کند.

### ابزارهای تصویر، صوت و داده
- تولید تصویر با `POST /images/generations`
- ویرایش تصویر با `POST /images/edits`؛ با ۱ تا ۴ نشانی عمومی HTTPS
- Batch تصویر، دریافت وضعیت و توقف صف Batch
- گفتار به متن با `POST /audio/transcriptions` (multipart)
- متن به گفتار با `POST /audio/speech`
- Embeddings با `POST /embeddings`
- جست‌وجوی Knowledge Base با `POST /knowledge-bases/{id}/search`
- Decision API با `POST /decisions`
- Batch متنی و دریافت وضعیت
- استعلام رسید درخواست با `GET /generation?id=...`

### پیوست PDF
مدل پولی دارای قابلیت فایل می‌تواند PDF را در Chat، Responses و Messages پردازش کند. Playground ورودی ساختاریافته Messages و Responses و گزینه `pdf_processing` را در اختیار می‌گذارد. برای جلوگیری از OCR خارجی می‌توان `{"allow_external":false}` تنظیم کرد. محدودیت‌های مستندشده سرویس: حداکثر ۴ مگابایت و ۱۰۰ صفحه برای هر فایل؛ حداکثر ۳ فایل و ۶ مگابایت در هر درخواست و محدودیت OCR نیز اعمال می‌شود. فایل‌ها باید با ساختار ورودی فایل مورد پشتیبانی همان API و مدل ارسال شوند.

### Batch
- Batch متنی: ۱ تا ۲۰ درخواست از یک مدل پشتیبان، هر ردیف با `custom_id` یکتا
- Batch تصویر: ۱ تا ۱۰ درخواست
- استعلام وضعیت Batch متنی و تصویری
- توقف صف Batch تصویر

Batch متنی فایل JSONL، استریم، لغو یا چند مدل در یک Batch را پشتیبانی نمی‌کند. اگر Provider وضعیت `submission_uncertain` برگرداند، با شناسه تازه دوباره ارسال نکنید؛ ابتدا همان شناسه را بررسی کنید.

## گزارش مصرف و هزینه

پنل اطلاعات برگشتی از درخواست‌های عبوری از خودش را ثبت می‌کند:
- شناسه درخواست و نوع عملیات
- مدل، endpoint و کد وضعیت
- توکن ورودی، خروجی، مجموع و توکن کش‌شده در صورت ارائه
- مبلغ نهایی `charged_toman` در صورت ارائه از سوی Provider
- زمان پاسخ و کد خطا
- خروجی CSV و تاریخچه تولید

گزارش محلی فقط درخواست‌هایی را شامل می‌شود که از همین پنل ارسال شده‌اند. درخواست‌های اپلیکیشن Flutter، سایر کلاینت‌ها یا کلیدهای دیگر در این دیتابیس دیده نمی‌شوند. برای تطبیق مالی کامل، گزارش کنسول Paasta را نیز بررسی کنید. متن prompt و response در جدول گزارش مالی ذخیره نمی‌شود؛ ذخیره تاریخچه تولید جداگانه و اختیاری است.

## مسیرهای داخلی کنسول

همه مسیرهای زیر same-origin و نیازمند نشست مدیر PHP هستند:

- `GET /api/bootstrap`, `GET /api/dashboard`, `GET /api/settings`
- `POST /api/settings`, `POST /api/settings/test`
- `GET /api/catalog/models|decisions|images|audio|speech|embeddings`
- `POST /api/generate`, `POST /api/ai/chat`, `POST /api/ai/chat/stream`
- `POST /api/ai/responses`, `POST /api/ai/messages`, `POST /api/ai/decisions`
- `POST /api/ai/images`, `POST /api/ai/image-edit`
- `POST /api/ai/transcription`, `POST /api/ai/speech`
- `POST /api/ai/embeddings`, `POST /api/ai/knowledge/{id}/search`
- `POST /api/ai/batches`, `GET /api/ai/batches/{id}`
- `POST /api/ai/image-batches`, `GET|DELETE /api/ai/image-batches/{id}`
- `GET /api/generation?id=...`, `GET /api/usage`, `GET /api/usage.csv`
- `GET /api/history`, `POST /api/questions/save`

## دیتابیس و migration

فایل `backend/migrations/20261123_ai_console.sql` جداول تنظیمات Provider، گزارش مصرف، تاریخچه تولید و قالب‌های prompt را به‌صورت افزایشی ایجاد می‌کند.

جداول اصلی:
- `users`, `auth_tokens`, `login_attempts`, `questions`, `sources`, `admin_audit_log`
- `ai_provider_settings`, `ai_usage_logs`, `ai_generation_history`, `ai_prompt_templates`

این migration از `CREATE TABLE IF NOT EXISTS` و `INSERT IGNORE` استفاده می‌کند؛ هیچ جدول یا داده موجودی را حذف نمی‌کند.

## امنیت و عملیات

- نشست PHP سمت‌سرور، کوکی HttpOnly/SameSite Strict، بازتولید شناسه نشست و timeout عدم فعالیت
- CSRF برای درخواست‌های تغییردهنده
- PDO prepared statements و اعتبارسنجی ورودی
- کلید Provider رمز‌شده با Sodium Secretbox و کلید رمزنگاری خارج از web root
- عدم ارسال کلید Provider به مرورگر
- TLS verification برای ارتباط خروجی و عدم دنبال‌کردن redirect به سرویس Provider
- فایل‌های private PHP و تنظیمات خارج از document root عمومی
- MySQL محدود به localhost و حساب برنامه با دسترسی محدود
- بکاپ روزانه دیتابیس در `/var/backups/parse-admin`

کلید رمزنگاری باید همراه بکاپ در محل امن و خارج از سرور نگهداری شود؛ بدون آن کلید Provider قابل بازیابی نیست. امنیت مطلق وجود ندارد؛ برای استفاده عمومی، بکاپ خارج از سرور، پایش، تست نفوذ مستقل و بازبینی دوره‌ای ضروری است.

## ساختار پروژه

```
app/
  bootstrap.php
  public/
    index.php
    api.php
    assets/admin.css
    assets/admin.js
backend/
  config.php
  public/index.php
  schema.sql
  migrations/
  bin/create-admin.php
  bin/backup-db.sh
install.sh
update-panel.sh
```

## CI

GitHub Actions فایل‌های PHP، JavaScript و اسکریپت‌های Bash را از نظر syntax بررسی می‌کند. این بررسی جایگزین تست عملی اتصال Provider، مرورگر و دیتابیس روی سرور production نیست.

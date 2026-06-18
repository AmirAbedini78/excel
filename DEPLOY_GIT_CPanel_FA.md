# راهنمای دیپلوی با Git روی ساب‌دامین cPanel

## نکته اصلی
محتویات همین پوشه باید ریشه‌ی Repository باشد؛ یعنی فایل‌های `index.php`، `install.php` و `.cpanel.yml` باید مستقیم در Root ریپو باشند، نه داخل یک پوشه‌ی اضافه مثل `accounting_cpanel_app`.

## مرحله ۱: مسیر ساب‌دامین را پیدا کن
در cPanel از بخش **Domains** یا **Subdomains**، مسیر **Document Root** ساب‌دامین را ببین.
مثلاً یکی از این حالت‌هاست:

```text
/home2/username/accounting.example.com/
/home2/username/public_html/accounting/
/home3/username/accounting.example.com/
```

بعد فایل `.cpanel.yml` را باز کن و مقدار `DEPLOYPATH` را دقیقاً همان مسیر بگذار.

## مرحله ۲: پروژه را روی GitHub/GitLab بفرست
داخل همین پوشه این دستورات را بزن:

```bash
git init
git add .
git commit -m "Initial accounting web app"
git branch -M main
git remote add origin YOUR_REPOSITORY_URL
git push -u origin main
```

## مرحله ۳: در cPanel ریپو را Clone کن
از cPanel برو به:

```text
Files > Git Version Control > Create
```

گزینه **Clone a Repository** را فعال کن، آدرس ریپو را بده، و Repository Path را یک مسیر خارج از Document Root بگذار، مثلاً:

```text
/home2/username/repositories/accounting-app
```

پیشنهاد نمی‌شود Repository Path را مستقیم روی مسیر ساب‌دامین بگذاری. بهتر است ریپو جدا باشد و با `.cpanel.yml` به Document Root دیپلوی شود.

## مرحله ۴: Deploy کن
بعد از Clone:

```text
Git Version Control > Manage > Pull or Deploy
```

اول **Update from Remote** را بزن، بعد **Deploy HEAD Commit** را بزن.

## مرحله ۵: نصب وب‌اپ
بعد از Deploy، آدرس زیر را باز کن:

```text
https://YOUR-SUBDOMAIN/install.php
```

اطلاعات دیتابیس MySQL و کاربر مدیر را وارد کن.

## آپدیت‌های بعدی
هر وقت روی سیستم خودت تغییر دادی:

```bash
git add .
git commit -m "Update accounting app"
git push origin main
```

بعد در cPanel دوباره:

```text
Update from Remote > Deploy HEAD Commit
```

## نکات مهم امنیتی
- فایل `app/config.php` داخل Git نرود؛ داخل `.gitignore` قرار داده شده است.
- بعد از نصب، اگر دیگر به نصب مجدد نیاز نداری، بهتر است فایل `install.php` را از Document Root حذف یا تغییرنام بدهی.
- پوشه‌ی Repository را داخل مسیر عمومی سایت نگذار.
- کلیدهای Google، Gmail SMTP و قاصدک را فقط داخل پنل تنظیمات وب‌اپ وارد کن، نه داخل Git.

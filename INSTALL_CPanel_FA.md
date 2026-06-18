# راهنمای نصب روی cPanel

## ۱) ساخت دیتابیس

در cPanel وارد **MySQL Databases** شوید و یک دیتابیس و یک یوزر بسازید. سپس یوزر را به دیتابیس وصل کنید و همه دسترسی‌ها را بدهید.

نمونه:

- Database: `cpuser_accounting`
- User: `cpuser_accounting_user`
- Password: رمز قوی

## ۲) آپلود فایل‌ها

فایل ZIP پروژه را داخل مسیر ساب‌دامین آپلود کنید، مثلاً:

`/home2/USERNAME/public_html/accounting`

سپس Extract کنید. باید فایل `index.php` مستقیماً داخل ریشه ساب‌دامین باشد.

## ۳) اجرای نصب

در مرورگر باز کنید:

`https://subdomain.yourdomain.com/install.php`

اطلاعات دیتابیس، آدرس ساب‌دامین و کاربر مدیر را وارد کنید. نصب به صورت خودکار جدول‌ها، داده‌های اولیه و کاربر مدیر را می‌سازد.

## ۴) تنظیم ایمیل Gmail SMTP

از داخل وب‌اپ وارد **تنظیمات** شوید و این مقادیر را وارد کنید:

- SMTP Host: `smtp.gmail.com`
- Port: `587`
- Encryption: `TLS / STARTTLS`
- Username: ایمیل کامل Gmail
- Password: App Password گوگل، نه رمز اصلی جیمیل
- From Email: همان ایمیل Gmail

در Gmail معمولاً باید 2-Step Verification روشن باشد تا بتوانید App Password بسازید.

## ۵) تنظیم پیامک قاصدک

از داخل وب‌اپ وارد **تنظیمات** شوید و این موارد را وارد کنید:

- Ghasedak API Key
- Line Number
- شماره گیرنده‌های SMS

وب‌اپ از endpoint ارسال تکی قاصدک استفاده می‌کند:

`https://gateway.ghasedak.me/rest/api/v1/WebService/SendSingleSMS`

## ۶) تنظیم ورود با گوگل

در Google Cloud Console یک OAuth Client از نوع Web Application بسازید. Redirect URI را دقیقاً برابر مقداری قرار دهید که در صفحه تنظیمات وب‌اپ نوشته شده است، مثل:

`https://subdomain.yourdomain.com/index.php?page=google_callback`

بعد Client ID و Client Secret را در تنظیمات وب‌اپ وارد کنید.

## ۷) تنظیم Cron Job برای یادآوری خودکار

در cPanel وارد **Cron Jobs** شوید و روزی یک بار، مثلاً ساعت ۸ صبح، این دستور را بگذارید. آدرس دقیق داخل صفحه تنظیمات وب‌اپ نمایش داده می‌شود:

```bash
curl -s "https://subdomain.yourdomain.com/cron.php?secret=YOUR_SECRET" >/dev/null 2>&1
```

## ۸) بعد از نصب

بعد از اطمینان از نصب، بهتر است فایل `install.php` را تغییر نام دهید یا حذف کنید تا کسی نصب را دوباره اجرا نکند.

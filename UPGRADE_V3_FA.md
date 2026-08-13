# ارتقا به Accounting CRM V3

## قبل از شروع

- از دیتابیس cPanel یک Export/Backup بگیرید.
- `app/config.php` را حذف یا Commit نکنید.
- نام کاربری و رمز سامانه‌ها را داخل GitHub قرار ندهید؛ Repository فعلی عمومی است.

## روش Patch در Windows

پوشه `accounting_v3_patch` را در Root پروژه لوکال قرار دهید و از PowerShell در Root پروژه اجرا کنید:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\accounting_v3_patch\apply_accounting_v3_patch.ps1
```

اگر اسکریپت خارج از Root پروژه است:

```powershell
.\apply_accounting_v3_patch.ps1 -ProjectRoot "C:\path\to\accounting_cpanel_app"
```

سپس:

```powershell
git status
git add .
git commit -m "Upgrade accounting CRM to calendar V3"
git pull --rebase origin main
git push origin main
```

## cPanel

در Git Version Control روی Clone فعال:

1. Update from Remote
2. Deploy HEAD Commit

سپس بعد از ورود به سامانه:

```text
https://excel.bcsrp.ir/migrate_v2.php
```

نام `migrate_v2.php` برای سازگاری با Deploy قدیمی نگه داشته شده، ولی Migration نسخه ۳ را اجرا می‌کند.

## تغییرات دیتابیس

نیازی به تغییر دستی در phpMyAdmin نیست. Migration جداول/ستون‌های جدید را می‌سازد و اطلاعات قدیمی را حذف نمی‌کند.

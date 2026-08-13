# نصب/دیپلوی Accounting CRM V2 روی cPanel

## روش پیشنهادی برای پروژه فعلی شما

چون پروژه قبلی با Git به cPanel وصل است، بهتر است فایل‌های نسخه جدید را در همان repository جایگزین کنید، Commit بزنید و از cPanel Deploy بگیرید.

### مرحله ۱: اجرای پچ در ریشه پروژه محلی

```bash
bash accounting_v3_patch/apply_accounting_v2_patch.sh
```

### مرحله ۲: ارسال به Git

```bash
git add .
git commit -m "Accounting CRM v2 upgrade"
git push origin main
```

### مرحله ۳: دیپلوی در cPanel

در cPanel:

```text
Git Version Control > Manage > Pull or Deploy
```

ابتدا:

```text
Update from Remote
```

سپس:

```text
Deploy HEAD Commit
```

### مرحله ۴: اجرای مایگریشن دیتابیس

پس از باز شدن سایت، وارد شوید و از بخش تنظیمات دکمه اجرای مایگریشن را بزنید. نیازی نیست دستی در phpMyAdmin ستون اضافه کنید.

## مسیر Deploy

در `.cpanel.yml` مسیر فعلی شما تنظیم شده است:

```yaml
DEPLOYPATH=/home3/zzflgmfd/excel.bcsrp/
```

اگر Document Root ساب‌دامین تغییر کرد، فقط همین مسیر را عوض کنید.

## کشینگ و سرعت

روی هاست اشتراکی cPanel معمولاً Redis/Valky به‌صورت سرویس اختصاصی قابل اجرا نیست. این نسخه به جای آن از طراحی سبک، ایندکس دیتابیس، فیلترهای محدود و Auto-save سمت مرورگر استفاده می‌کند. برای آینده، جای اتصال سرویس جانبی/خانگی در تنظیمات گذاشته شده است.

# Accounting CRM API V1

این API برای اتصال Accounting CRM به سرویس‌های خارجی، Hermes، RAG، AI Agent و سرویس‌های خانگی/Edge طراحی شده است.

## Base URL

```text
https://YOUR-DOMAIN/api/v1
```

Fallback بدون Rewrite:

```text
https://YOUR-DOMAIN/api_v1.php?resource=companies
```

## Authentication

در تمام درخواست‌ها هدر زیر را ارسال کنید:

```http
Authorization: Bearer acct_live_...
```

توکن از صفحه **تنظیمات > API** ساخته می‌شود و متن کامل آن فقط یک بار نمایش داده می‌شود. دیتابیس فقط SHA-256 توکن را نگه می‌دارد.

Scopeها:

- `read`: مشاهده
- `write`: ایجاد، ویرایش و حذف
- `secrets`: مشاهده رمزهای سامانه‌ها و Export داده حساس
- `settings`: تنظیمات مدیریتی؛ علاوه بر آن نقش کاربر باید admin باشد

## Rate Limit

مقدار پیش‌فرض 120 درخواست در دقیقه برای هر Token است و از تنظیمات قابل تغییر است. این پیاده‌سازی روی MySQL است و به Redis/Valky وابسته نیست.

هدرهای پاسخ:

```text
X-RateLimit-Limit
X-RateLimit-Remaining
X-API-Version
```

## CORS

در تنظیمات API می‌توان Originهای مجاز را خط‌به‌خط یا با کاما وارد کرد. برای ارتباط Server-to-Server نیازی به CORS نیست.

## Response Format

موفق:

```json
{
  "ok": true,
  "data": {},
  "meta": {}
}
```

خطا:

```json
{
  "ok": false,
  "error": {
    "code": "bad_request",
    "message": "...",
    "details": {}
  }
}
```

## Endpoints

| بخش | Endpoint | Methods |
|---|---|---|
| Meta | `/meta` | GET |
| تقویم | `/calendar` | GET, POST |
| شرکت‌ها | `/companies`, `/companies/{id}` | GET, POST, PATCH, DELETE |
| سامانه‌ها | `/systems`, `/systems/{id}` | GET, POST, PATCH, DELETE |
| تعریف سامانه‌ها | `/portals` | GET |
| برنامه روزانه | `/daily-plans`, `/daily-plans/{id}` | GET, POST, PATCH, DELETE |
| برنامه ماهانه | `/monthly-plans`, `/monthly-plans/{id}` | GET, POST, PATCH, DELETE |
| کانبان | `/kanban`, `/kanban/{id}` | GET, PATCH |
| فیلدهای اضافه | `/custom-fields`, `/custom-fields/{id}` | GET, POST, PATCH, DELETE |
| تنظیمات | `/settings` | GET, PATCH |
| تنظیم ستون‌ها | `/table-preferences` | GET, PUT, DELETE |
| Import | `/imports` | GET, POST multipart |
| Export | `/exports` | GET |

## Examples

### Companies

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://YOUR-DOMAIN/api/v1/companies?page=1&per_page=50"
```

### Create daily plan

```bash
curl -X POST "https://YOUR-DOMAIN/api/v1/daily-plans" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"plan_date":"1405/05/23","company_id":1,"work_description":"کنترل اسناد","notes":"API"}'
```

### Calendar

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://YOUR-DOMAIN/api/v1/calendar?from=1405/05/01&to=1405/05/31"
```

### Kanban move

```bash
curl -X PATCH "https://YOUR-DOMAIN/api/v1/kanban/12" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"در حال انجام"}'
```

### Systems

به صورت پیش‌فرض Password برگردانده نمی‌شود:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://YOUR-DOMAIN/api/v1/systems?company_id=3"
```

برای دریافت Password باید Token دارای `secrets` باشد:

```bash
curl -H "Authorization: Bearer FULL_TOKEN" \
  "https://YOUR-DOMAIN/api/v1/systems?company_id=3&include_secrets=1"
```

### Import

```bash
curl -X POST "https://YOUR-DOMAIN/api/v1/imports" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "entity=companies" \
  -F "file=@companies.xlsx"
```

### Export

JSON:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://YOUR-DOMAIN/api/v1/exports?entity=companies&format=json"
```

Excel:

```bash
curl -L -H "Authorization: Bearer YOUR_TOKEN" \
  "https://YOUR-DOMAIN/api/v1/exports?entity=companies&format=xlsx" \
  -o companies.xlsx
```

## Pagination

پارامترهای عمومی:

- `page` پیش‌فرض 1
- `per_page` پیش‌فرض 50 و حداکثر 200

## Date formats

هر دو فرمت پذیرفته می‌شود:

```text
2026-08-14
1405/05/23
```

## Security notes

1. Token کامل را در Git ذخیره نکنید.
2. Token کامل با scope `secrets/settings` فقط برای سرویس‌های قابل اعتماد ساخته شود.
3. برای Hermes/RAG بهتر است یک Token اختصاصی با حداقل Scope لازم بسازید.
4. در صورت لو رفتن Token از تنظیمات آن را Revoke کنید.
5. در API سامانه‌ها Password فقط با Scope `secrets` قابل خواندن است.

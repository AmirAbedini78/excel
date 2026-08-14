# Accounting CRM SaaS API V2

API V2 از ابتدا برای Multi-Tenant طراحی شده است و هر Token فقط به یک Workspace متصل است.

## Base URL

```text
https://YOUR-DOMAIN/api/v2
```

## Authentication

```http
Authorization: Bearer acct_ws_xxxxxxxxx
```

Token را از صفحه `api_tokens.php` یا بخش «کاربران و دسترسی‌ها» بسازید. متن کامل Token فقط یک بار نمایش داده می‌شود و در دیتابیس فقط Hash آن نگهداری می‌شود.

## Scopeها

- `read`: خواندن داده‌ها
- `write`: ایجاد، ویرایش و حذف
- `secrets`: مشاهده Passwordهای سامانه‌ها و Export داده حساس

علاوه بر Scope، Permission خود User داخل Workspace هم بررسی می‌شود؛ بنابراین Token نمی‌تواند از دسترسی واقعی کاربر فراتر برود.

## پاسخ استاندارد

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
    "code": "request_failed",
    "message": "...",
    "details": {}
  }
}
```

## Endpointها

| بخش | Endpoint | Methods |
|---|---|---|
| Metadata | `/meta` | GET |
| شرکت‌ها | `/companies`, `/companies/{id}` | GET, POST, PATCH, PUT, DELETE |
| برنامه روزانه | `/daily-plans`, `/daily-plans/{id}` | GET, POST, PATCH, PUT, DELETE |
| برنامه ماهانه | `/monthly-plans`, `/monthly-plans/{id}` | GET, POST, PATCH, PUT, DELETE |
| تقویم | `/calendar` | GET |
| کانبان | `/kanban`, `/kanban/{id}` | GET, PATCH, PUT |
| سامانه‌ها | `/systems`, `/systems/{id}` | GET, POST, PATCH, PUT, DELETE |
| تعریف سامانه‌ها | `/portals` | GET |
| فیلدهای اضافه | `/custom-fields`, `/custom-fields/{id}` | GET, POST, PATCH, PUT, DELETE |
| نوت‌ها | `/notes`, `/notes/{id}` | GET, POST, PATCH, PUT, DELETE |
| فایل‌ها | `/files`, `/files/{id}` | GET, POST multipart, DELETE |
| Attachment | `/attachments` | GET, POST, DELETE |
| کاربران Workspace | `/members`, `/members/{id}` | GET, POST, PATCH, PUT, DELETE |
| Roleها | `/roles`, `/roles/{id}` | GET, POST, PATCH, PUT |
| Workspace | `/workspace` | GET, PATCH, PUT |
| Audit Log | `/audit-logs` | GET |
| تنظیم ستون‌ها | `/table-preferences` | GET, POST, PATCH, PUT, DELETE |
| Import | `/imports` | GET, POST multipart |
| Export | `/exports` | GET |

## نمونه‌ها

### لیست شرکت‌ها

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://YOUR-DOMAIN/api/v2/companies?page=1&per_page=50"
```

### ایجاد شرکت

```bash
curl -X POST "https://YOUR-DOMAIN/api/v2/companies" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"شرکت نمونه","company_type":"شرکت","legal_personality":"حقوقی"}'
```

### ایجاد نوت

```bash
curl -X POST "https://YOUR-DOMAIN/api/v2/notes" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"پیگیری مالیات","body":"فردا مدارک شرکت بررسی شود","pinned":true}'
```

### کانبان

```bash
curl -X PATCH "https://YOUR-DOMAIN/api/v2/kanban/12" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"در حال انجام"}'
```

### سامانه‌ها بدون رمز

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://YOUR-DOMAIN/api/v2/systems?company_id=3"
```

### سامانه‌ها همراه رمز

Token باید `secrets` داشته باشد و User نیز Permission مربوط به Secrets را داشته باشد:

```bash
curl -H "Authorization: Bearer YOUR_SECRET_TOKEN" \
  "https://YOUR-DOMAIN/api/v2/systems?company_id=3&include_secrets=1"
```

### Upload فایل

```bash
curl -X POST "https://YOUR-DOMAIN/api/v2/files" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@invoice.pdf"
```

### Attach فایل موجود

```bash
curl -X POST "https://YOUR-DOMAIN/api/v2/attachments" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"entity":"companies","record_id":12,"file_ids":[8,9]}'
```

### Import Excel/CSV

```bash
curl -X POST "https://YOUR-DOMAIN/api/v2/imports" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "entity=companies" \
  -F "file=@companies.xlsx"
```

### Export

JSON:

```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://YOUR-DOMAIN/api/v2/exports?entity=companies&format=json"
```

Excel:

```bash
curl -L -H "Authorization: Bearer YOUR_TOKEN" \
  "https://YOUR-DOMAIN/api/v2/exports?entity=companies&format=xlsx" \
  -o companies.xlsx
```

## تاریخ‌ها

هر دو فرمت پذیرفته می‌شود:

```text
2026-08-14
1405/05/23
```

## Pagination

```text
page=1
per_page=50
```

حداکثر `per_page=200` است.

## Rate Limit

به صورت پیش‌فرض از مقدار `api_rate_limit_per_minute` استفاده می‌شود و محدودیت برای هر Token به شکل جداگانه ثبت می‌شود.

هدرهای پاسخ:

```text
X-RateLimit-Limit
X-RateLimit-Remaining
X-API-Version: 2
```

## AI Agent / Hermes

برای Agent یک Token جدا بسازید. پیشنهاد:

- Agent تحلیل و ثبت Task: `read + write`
- Agent فقط تحلیل داده: `read`
- `secrets` فقط اگر واقعاً نیاز به Password سامانه‌ها دارد

نوت‌ها دارای فیلدهای `ai_status` و `ai_result_json` هستند تا در نسخه AI، خروجی تحلیل Note بتواند به برنامه روزانه/ماهانه یا سایر Entityها تبدیل شود.

## OpenAPI

```text
/openapi-v2.json
```

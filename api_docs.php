<?php
require __DIR__.'/app/bootstrap.php';
Auth::require();
$base=rtrim(base_url('api/v1'),'/');
?><!doctype html><html lang="fa" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>مستندات API - Accounting CRM</title>
<link rel="stylesheet" href="assets/style.css?v=3.3">
<style>
.api-doc{max-width:1100px;margin:auto}.api-doc h1{font-size:21px}.api-doc h2{margin-top:22px;font-size:15px}.api-doc h3{font-size:13px}
.api-doc table{min-width:700px}.api-doc pre{direction:ltr;text-align:left;overflow:auto;background:#111827;color:#e5e7eb;padding:12px;border-radius:9px;font-size:11px}
.api-doc code{direction:ltr}.method{display:inline-flex;min-width:48px;justify-content:center;border-radius:5px;padding:2px 5px;font-weight:800;background:#eef2ff;color:#334155}
.api-doc .note{background:#fffbea;border:1px solid #f2dfa3;padding:9px;border-radius:8px}
</style></head><body><main class="main api-doc">
<div class="topbar"><div><h1>Accounting CRM API V1</h1><p>REST API رسمی سامانه برای اتصال به Hermes، RAG، Agentها و سرویس‌های جانبی</p></div><a class="btn" href="index.php?page=settings">بازگشت به تنظیمات</a></div>
<section class="card"><b>Base URL</b><pre><?=h($base)?></pre><p>احراز هویت با Bearer Token انجام می‌شود. توکن را از تنظیمات سامانه بسازید.</p>
<pre>Authorization: Bearer acct_live_xxxxxxxxxxxxxxxxx</pre></section>
<section class="card"><h2>Endpointها</h2><div class="table-wrap"><table><thead><tr><th>بخش</th><th>مسیر</th><th>متدها</th><th>Scope</th></tr></thead><tbody>
<?php
$rows=[
['Meta','/meta','GET','read'],['تقویم','/calendar','GET, POST','read / write'],['شرکت‌ها','/companies , /companies/{id}','GET, POST, PATCH, DELETE','read / write'],
['سامانه‌ها','/systems , /systems/{id}','GET, POST, PATCH, DELETE','read / write؛ secrets برای مشاهده رمز'],['تعریف سامانه‌ها','/portals','GET','read'],
['برنامه روزانه','/daily-plans , /daily-plans/{id}','GET, POST, PATCH, DELETE','read / write'],['برنامه ماهانه','/monthly-plans , /monthly-plans/{id}','GET, POST, PATCH, DELETE','read / write'],
['کانبان','/kanban , /kanban/{id}','GET, PATCH','read / write'],['فیلدهای اضافه','/custom-fields , /custom-fields/{id}','GET, POST, PATCH, DELETE','read / write'],
['تنظیمات','/settings','GET, PATCH','settings + مدیر'],['چیدمان لیست','/table-preferences','GET, PUT, DELETE','read / write'],
['سوابق Import','/imports','GET, POST multipart','read / write'],['Export','/exports?entity=...&format=json|csv|xlsx','GET','read؛ secrets برای سامانه‌ها']
];
foreach($rows as $r)echo '<tr><td>'.h($r[0]).'</td><td dir="ltr">'.h($r[1]).'</td><td>'.h($r[2]).'</td><td>'.h($r[3]).'</td></tr>';
?>
</tbody></table></div></section>
<section class="card"><h2>نمونه درخواست</h2><h3>لیست شرکت‌ها</h3>
<pre>curl -H "Authorization: Bearer YOUR_TOKEN" "<?=h($base)?>/companies?page=1&amp;per_page=50"</pre>
<h3>ساخت برنامه روزانه</h3><pre>curl -X POST "<?=h($base)?>/daily-plans" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"plan_date":"1405/05/23","company_id":1,"work_description":"کنترل اسناد روز","notes":"ثبت از API"}'</pre>
<h3>جابه‌جایی کارت کانبان</h3><pre>curl -X PATCH "<?=h($base)?>/kanban/12" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"status":"در حال انجام"}'</pre></section>
<section class="card"><h2>Scopeها</h2><p><code>read</code> خواندن داده‌ها؛ <code>write</code> ایجاد/ویرایش/حذف؛ <code>secrets</code> مشاهده رمز سامانه‌ها و Export آن؛ <code>settings</code> دسترسی تنظیمات مدیریتی.</p>
<div class="note">توکن کامل را فقط برای سرویس قابل اعتماد بسازید. رمز توکن پس از ساخت فقط یک بار نمایش داده می‌شود و در دیتابیس فقط Hash آن ذخیره می‌شود.</div></section>
<section class="card"><h2>Pagination و تاریخ</h2><p>لیست‌ها از <code>page</code> و <code>per_page</code> (حداکثر ۲۰۰) پشتیبانی می‌کنند. تاریخ‌ها هم به شکل <code>YYYY-MM-DD</code> و هم تاریخ شمسی مثل <code>1405/05/23</code> پذیرفته می‌شوند.</p>
<p>OpenAPI: <a href="openapi.json" target="_blank">openapi.json</a></p></section>
</main></body></html>

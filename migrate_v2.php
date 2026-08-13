<?php
require __DIR__ . '/app/bootstrap.php';
Auth::require();
try {
    Schema::migrate(pdo());
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/style.css?v=3.0"><body class="main"><section class="card"><h1>مایگریشن دیتابیس انجام شد</h1><p>ساختار جدید تقویم، شرکت‌ها، سامانه‌ها و برنامه‌ها با دیتابیس هماهنگ شد.</p><a class="btn primary" href="index.php?page=dashboard">ورود به تقویم</a></section></body></html>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre style="direction:ltr;text-align:left;white-space:pre-wrap">'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</pre>';
}

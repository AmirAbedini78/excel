<?php
require __DIR__ . '/app/bootstrap.php';
Auth::require();
try {
    Schema::migrate(pdo());
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><link rel="stylesheet" href="assets/style.css?v=2.0"><body class="main"><section class="card"><h1>مایگریشن نسخه ۲ انجام شد</h1><p>دیتابیس با جدول‌ها و ستون‌های جدید هماهنگ شد.</p><a class="btn primary" href="index.php?page=settings">بازگشت به تنظیمات</a></section></body></html>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre style="direction:ltr;text-align:left">'.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</pre>';
}

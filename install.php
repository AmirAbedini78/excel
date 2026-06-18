<?php
require __DIR__ . '/app/bootstrap.php';
$installed = file_exists(__DIR__ . '/app/config.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($installed) { $error = 'سیستم قبلاً نصب شده است. برای نصب مجدد ابتدا app/config.php را حذف کنید.'; } else try {
        $base = rtrim(trim($_POST['base_url'] ?? ''), '/');
        $db = [
            'host' => trim($_POST['db_host'] ?? 'localhost'),
            'port' => (int)($_POST['db_port'] ?? 3306),
            'database' => trim($_POST['db_name'] ?? ''),
            'username' => trim($_POST['db_user'] ?? ''),
            'password' => (string)($_POST['db_pass'] ?? ''),
            'charset' => 'utf8mb4',
        ];
        $adminName = trim($_POST['admin_name'] ?? 'مدیر سیستم');
        $adminEmail = mb_strtolower(trim($_POST['admin_email'] ?? ''));
        $adminPass = (string)($_POST['admin_pass'] ?? '');
        if (!$base || !$db['database'] || !$db['username'] || !$adminEmail || strlen($adminPass) < 8) throw new RuntimeException('اطلاعات نصب کامل نیست. رمز مدیر حداقل ۸ کاراکتر باشد.');
        $pdo = DB::connect($db);
        Schema::migrate($pdo);
        Schema::createAdmin($pdo, $adminName, $adminEmail, $adminPass);
        Schema::seed($pdo);
        $cfg = [
            'app_name' => 'Accounting Manager 1405',
            'base_url' => $base,
            'timezone' => 'Asia/Tehran',
            'app_key' => bin2hex(random_bytes(32)),
            'db' => $db,
        ];
        $content = "<?php\nreturn " . var_export($cfg, true) . ";\n";
        file_put_contents(__DIR__ . '/app/config.php', $content);
        header('Location: index.php?page=login&installed=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>نصب سیستم مدیریت حسابداری</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="install-page"><main class="install-card">
<h1>نصب وب‌اپ مدیریت حسابداری چندشرکتی</h1>
<p>این نصب دیتابیس MySQL را آماده می‌کند، کاربر مدیر می‌سازد و داده‌های اولیه ۵ شرکت و سررسیدهای ۱۴۰۵ را وارد می‌کند.</p>
<?php if ($installed): ?><div class="alert warn">فایل تنظیمات قبلاً ساخته شده است. برای نصب مجدد، فایل <code>app/config.php</code> را حذف کنید.</div><?php endif; ?>
<?php if ($error): ?><div class="alert danger"><?=h($error)?></div><?php endif; ?>
<form method="post" class="grid-form">
<h2>آدرس و دیتابیس</h2>
<label>آدرس ساب‌دامین<input name="base_url" required value="<?=h((isset($_SERVER['HTTPS'])?'https':'http').'://'.($_SERVER['HTTP_HOST'] ?? ''))?>"></label>
<label>DB Host<input name="db_host" required value="localhost"></label>
<label>DB Port<input name="db_port" required value="3306"></label>
<label>DB Name<input name="db_name" required placeholder="مثلاً user_accounting"></label>
<label>DB User<input name="db_user" required placeholder="مثلاً user_accounting"></label>
<label>DB Password<input name="db_pass" type="password"></label>
<h2>مدیر سیستم</h2>
<label>نام مدیر<input name="admin_name" required value="مدیر حسابداری"></label>
<label>ایمیل مدیر<input name="admin_email" type="email" required placeholder="you@gmail.com"></label>
<label>رمز عبور مدیر<input name="admin_pass" type="password" required minlength="8"></label>
<button class="btn primary" type="submit">نصب و ساخت سیستم</button>
</form>
</main></body></html>

<?php
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function app_config(): array { global $CONFIG; return $CONFIG; }
function base_url(string $path=''): string { $b = rtrim(app_config()['base_url'] ?? '', '/'); return $b . '/' . ltrim($path,'/'); }
function redirect(string $url): never { header('Location: '.$url); exit; }
function csrf_token(): string { if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(24)); return $_SESSION['_csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'; }
function verify_csrf(): void { if (($_POST['csrf'] ?? '') !== ($_SESSION['_csrf'] ?? '')) { http_response_code(419); exit('CSRF token mismatch'); } }
function flash(string $msg, string $type='success'): void { $_SESSION['flash'][] = ['msg'=>$msg,'type'=>$type]; }
function flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
function pdo(): PDO { return DB::pdo(); }
function setting(string $key, $default='')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $st = pdo()->query("SELECT `key`,`value`,`encrypted` FROM settings");
            foreach ($st->fetchAll() as $row) $cache[$row['key']] = ((int)$row['encrypted']===1) ? decrypt_value($row['value']) : $row['value'];
        } catch (Throwable $e) { $cache = []; }
    }
    return $cache[$key] ?? $default;
}
function setting_set(string $key, ?string $value, int $encrypted=0): void
{
    $stored = $encrypted ? encrypt_value((string)$value) : (string)$value;
    $st = pdo()->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), encrypted=VALUES(encrypted), updated_at=NOW()");
    $st->execute([$key,$stored,$encrypted]);
}
function encrypt_value(string $plain): string
{
    if ($plain === '') return '';
    $key = hash('sha256', app_config()['app_key'] ?? 'x', true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return 'enc:'.base64_encode($iv.$cipher);
}
function decrypt_value(string $stored): string
{
    if (!str_starts_with($stored, 'enc:')) return $stored;
    $raw = base64_decode(substr($stored,4), true); if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw,0,16); $cipher = substr($raw,16);
    $key = hash('sha256', app_config()['app_key'] ?? 'x', true);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}
function status_badge(string $status): string
{
    $cls = match($status) { 'انجام شده'=>'done', 'در حال انجام'=>'progress', 'معوق'=>'overdue', default=>'open' };
    return '<span class="badge '.$cls.'">'.h($status).'</span>';
}
function due_status(?string $date, string $status): string
{
    if ($status === 'انجام شده') return 'انجام شده';
    if (!$date) return 'بدون تاریخ';
    $today = date('Y-m-d');
    $days = (int)floor((strtotime($date)-strtotime($today))/86400);
    if ($days < 0) return 'عقب افتاده';
    if ($days == 0) return 'امروز';
    if ($days <= 7) return 'تا ۷ روز';
    if ($days <= 15) return 'تا ۱۵ روز';
    return 'آینده';
}
function normalize_phone_list(string $s): array
{
    $s = Jalali::enDigits($s);
    return array_values(array_filter(array_map('trim', preg_split('/[,،\s]+/', $s))));
}
function table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SHOW TABLES LIKE ?"); $st->execute([$table]); return (bool)$st->fetchColumn();
}

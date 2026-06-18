<?php
class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        static $u = null;
        if ($u && (int)$u['id'] === (int)$_SESSION['user_id']) return $u;
        $st = pdo()->prepare("SELECT * FROM users WHERE id=? AND status='active' LIMIT 1");
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch() ?: null;
        return $u;
    }
    public static function check(): bool { return self::user() !== null; }
    public static function require(): void { if (!self::check()) redirect('index.php?page=login'); }
    public static function isAdmin(): bool { $u = self::user(); return $u && $u['role'] === 'admin'; }
    public static function attempt(string $email, string $password): bool
    {
        $st = pdo()->prepare("SELECT * FROM users WHERE email=? AND status='active' LIMIT 1");
        $st->execute([mb_strtolower(trim($email))]);
        $u = $st->fetch();
        if ($u && $u['password_hash'] && password_verify($password, $u['password_hash'])) { self::login((int)$u['id']); return true; }
        return false;
    }
    public static function login(int $id): void { session_regenerate_id(true); $_SESSION['user_id'] = $id; }
    public static function logout(): void { unset($_SESSION['user_id']); session_regenerate_id(true); }
}

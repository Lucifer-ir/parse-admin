<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/backend/config.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function app_config(): array
{
    global $config;
    return $config;
}

function app_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = app_config();
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['DB_HOST'], $c['DB_NAME']),
        $c['DB_USER'],
        $c['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]
    );
    return $pdo;
}

function app_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function app_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_https(): bool
{
    // Nginx sets HTTPS=on explicitly in the TLS FastCGI location; do not trust client-supplied proxy headers.
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // Never issue or accept the admin session cookie over plain HTTP.
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!app_https() && in_array($host, ['panel.aiparseh.ir', 'panel.aiparseh.ir:80'], true)) {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri === '' || $uri[0] !== '/') $uri = '/';
        header('Location: https://panel.aiparseh.ir' . $uri, true, 308);
        exit;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_name('PARSE_ADMIN_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => app_https(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['last_activity']) && $now - (int)$_SESSION['last_activity'] > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = $now;
}

function app_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function app_check_csrf(): void
{
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
    $known = (string)($_SESSION['csrf'] ?? '');
    if ($sent === '' || $known === '' || !hash_equals($known, $sent)) {
        app_json(419, ['error' => 'نشست فرم منقضی شده است. صفحه را تازه‌سازی کنید.']);
    }
}

function app_admin(): ?array
{
    if (empty($_SESSION['admin_id'])) return null;
    $stmt = app_db()->prepare("SELECT id,email,role,active FROM users WHERE id=? LIMIT 1");
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $user = $stmt->fetch();
    if (!$user || !in_array((string)$user['role'], ['admin','developer_admin','education_admin'], true) || !(bool)$user['active']) {
        unset($_SESSION['admin_id']);
        return null;
    }
    return $user;
}

function app_require_admin(): array
{
    $admin = app_admin();
    if (!$admin) app_json(401, ['error' => 'برای دسترسی به این بخش دوباره وارد شوید.']);
    return $admin;
}

function app_is_developer_admin(?array $admin): bool
{
    return $admin !== null && in_array((string)($admin['role'] ?? ''), ['admin','developer_admin'], true);
}

function app_require_developer_admin(): array
{
    $admin = app_require_admin();
    if (!app_is_developer_admin($admin)) app_json(403, ['error' => 'این بخش فقط برای ادمین برنامه‌نویس در دسترس است.']);
    return $admin;
}

function app_request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 16 * 1024 * 1024) {
        app_json(413, ['error' => 'حجم درخواست از ۱۶ مگابایت بیشتر است.']);
    }
    $decoded = json_decode($raw, true);
    if ($raw !== '' && !is_array($decoded)) {
        app_json(400, ['error' => 'بدنه JSON معتبر نیست.']);
    }
    return is_array($decoded) ? $decoded : [];
}

function app_setting_key(): string
{
    $encoded = (string)(app_config()['APP_ENCRYPTION_KEY'] ?? '');
    $key = base64_decode($encoded, true);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('کلید رمزنگاری تنظیمات در سرور آماده نیست.');
    }
    return $key;
}

function app_encrypt_secret(string $plain): array
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plain, $nonce, app_setting_key());
    return [base64_encode($cipher), base64_encode($nonce)];
}

function app_decrypt_secret(?string $cipher, ?string $nonce): string
{
    if (!$cipher || !$nonce) return '';
    $plain = sodium_crypto_secretbox_open(
        base64_decode($cipher, true) ?: '',
        base64_decode($nonce, true) ?: '',
        app_setting_key()
    );
    if (!is_string($plain)) throw new RuntimeException('رمزگشایی کلید سرویس ممکن نشد.');
    return $plain;
}

function app_paasta_key(): string
{
    $stmt = app_db()->query('SELECT api_key_ciphertext,api_key_nonce FROM ai_provider_settings WHERE id=1');
    $row = $stmt->fetch();
    if (!$row) return '';
    return app_decrypt_secret($row['api_key_ciphertext'] ?? null, $row['api_key_nonce'] ?? null);
}

app_session_start();

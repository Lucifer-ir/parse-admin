<?php
declare(strict_types=1);

$config = require '/opt/parse-admin/backend/config.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', '43200');

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['SERVER_PORT'] ?? '') === '443') ||
    (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

session_name('PARSE_ADMIN_SESSION');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['DB_HOST'], $config['DB_NAME']),
        $config['DB_USER'],
        $config['DB_PASS'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (Throwable $e) {
    error_log('Parse Admin DB connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('سرویس مدیریت موقتاً در دسترس نیست. تنظیمات اتصال پایگاه داده را بررسی کنید.');
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function admin_require_login(): array
{
    if (empty($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'نشست مدیر معتبر نیست.']);
        exit;
    }
    $signedIn = (int)($_SESSION['admin']['signed_in_at'] ?? 0);
    $lastActivity = (int)($_SESSION['admin']['last_activity'] ?? $signedIn);
    if ($signedIn < 1 || time() - $signedIn > 43200 || time() - $lastActivity > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'نشست مدیر منقضی شده است.']);
        exit;
    }
    $_SESSION['admin']['last_activity'] = time();
    return $_SESSION['admin'];
}

function admin_require_csrf(): void
{
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    if ($provided === '' || $sessionToken === '' || !hash_equals($sessionToken, $provided)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'نشست امنیتی منقضی شده است. صفحه را تازه‌سازی کنید.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function admin_json(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function admin_read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 2 * 1024 * 1024) {
        admin_json(413, ['ok' => false, 'message' => 'حجم درخواست بیش از حد مجاز است.']);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        admin_json(400, ['ok' => false, 'message' => 'بدنه درخواست JSON معتبر نیست.']);
    }
    return $data;
}

function admin_encryption_key(): string
{
    $hex = (string)($GLOBALS['config']['APP_ENCRYPTION_KEY'] ?? '');
    $key = ctype_xdigit($hex) ? hex2bin($hex) : false;
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('کلید رمزنگاری پنل در تنظیمات سرور تعریف نشده است.');
    }
    return $key;
}

function admin_encrypt_secret(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', admin_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('رمزنگاری کلید API ناموفق بود.');
    }
    return base64_encode($iv . $tag . $cipher);
}

function admin_decrypt_secret(?string $stored): string
{
    if (!$stored) return '';
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new RuntimeException('کلید API ذخیره‌شده قابل خواندن نیست.');
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', admin_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('رمزگشایی کلید API ممکن نیست؛ کلید رمزنگاری سرور را بررسی کنید.');
    }
    return $plain;
}

function admin_ai_settings(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM admin_ai_settings WHERE id = 1 LIMIT 1');
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->exec("INSERT IGNORE INTO admin_ai_settings (id, provider, base_url, enabled) VALUES (1, 'paasta', 'https://console.paasta.cloud/api/ai/v1', 0)");
        $stmt = $pdo->query('SELECT * FROM admin_ai_settings WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();
    }
    return $row ?: [];
}

function admin_ai_secret(PDO $pdo): string
{
    $settings = admin_ai_settings($pdo);
    return admin_decrypt_secret($settings['api_key_cipher'] ?? null);
}

function admin_ai_call(PDO $pdo, string $operation, string $method, string $path, ?array $body = null, int $timeout = 90): array
{
    $settings = admin_ai_settings($pdo);
    if (empty($settings['enabled']) && $operation !== 'provider.test') {
        throw new RuntimeException('اتصال هوش مصنوعی غیرفعال است. ابتدا آن را در تنظیمات فعال کنید.');
    }
    $apiKey = admin_decrypt_secret($settings['api_key_cipher'] ?? null);
    if ($apiKey === '') {
        throw new RuntimeException('کلید API ثبت نشده است.');
    }
    $base = rtrim((string)$settings['base_url'], '/');
    $url = $base . '/' . ltrim($path, '/');
    $idempotencyKey = bin2hex(random_bytes(16));
    $responseHeaders = [];
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: ' . (!empty($body['stream']) ? 'text/event-stream' : 'application/json'),
        'User-Agent: ParseAdmin/1.0',
    ];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('امکان ساخت درخواست HTTP وجود ندارد.');
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body !== null && $payload === false) throw new RuntimeException('تبدیل درخواست به JSON ناموفق بود.');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => max(15, min($timeout, 420)),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        },
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $started = microtime(true);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $latency = (int)round((microtime(true) - $started) * 1000);

    if ($raw === false) {
        admin_ai_log($pdo, $operation, $path, null, null, $idempotencyKey, 0, false, 0, 0, 0, null, $latency, 'upstream_connection_error');
        throw new RuntimeException('ارتباط با سرویس هوش مصنوعی برقرار نشد: ' . ($curlError ?: 'خطای شبکه'));
    }

    $decoded = json_decode((string)$raw, true);
    $isJson = is_array($decoded);
    $requestId = (string)($responseHeaders['x-paasta-request-id'] ?? $responseHeaders['x-request-id'] ?? ($decoded['id'] ?? ''));
    $usage = $decoded['usage'] ?? [];
    $inputTokens = (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
    $outputTokens = (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
    $totalTokens = (int)($usage['total_tokens'] ?? ($inputTokens + $outputTokens));
    $charged = $decoded['paasta']['charged_toman'] ?? $decoded['charged_toman'] ?? $responseHeaders['x-paasta-charged-toman'] ?? null;
    $model = is_array($body) ? (string)($body['model'] ?? '') : '';
    $errorCode = $isJson ? (string)($decoded['error']['code'] ?? $decoded['code'] ?? '') : '';

    admin_ai_log(
        $pdo, $operation, $path, $model, $requestId, $idempotencyKey,
        $status, $status >= 200 && $status < 300,
        $inputTokens, $outputTokens, $totalTokens,
        is_numeric($charged) ? (float)$charged : null,
        $latency, $errorCode ?: null
    );

    return [
        'status' => $status,
        'raw' => (string)$raw,
        'json' => $isJson ? $decoded : null,
        'content_type' => $contentType,
        'request_id' => $requestId,
        'idempotency_key' => $idempotencyKey,
        'latency_ms' => $latency,
        'ok' => $status >= 200 && $status < 300,
    ];
}

function admin_ai_log(
    PDO $pdo,
    string $operation,
    string $endpoint,
    ?string $model,
    ?string $requestId,
    ?string $idempotencyKey,
    int $status,
    bool $success,
    int $inputTokens,
    int $outputTokens,
    int $totalTokens,
    ?float $charged,
    int $latency,
    ?string $errorCode
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_ai_usage_logs
            (operation, endpoint, model, request_id, idempotency_key, http_status, is_success,
             prompt_tokens, completion_tokens, total_tokens, charged_toman, latency_ms, error_code)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $operation, $endpoint, $model ?: null, $requestId ?: null, $idempotencyKey,
            $status, $success ? 1 : 0, $inputTokens, $outputTokens, $totalTokens,
            $charged, $latency, $errorCode,
        ]);
    } catch (Throwable $e) {
        error_log('Parse Admin AI usage log failed: ' . $e->getMessage());
    }
}

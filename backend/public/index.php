<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && hash_equals((string)$config['CORS_ORIGIN'], $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 1_000_000) {
        respond(413, ['detail' => 'Request body is too large.']);
    }
    $decoded = json_decode($raw, true);
    if ($raw !== '' && !is_array($decoded)) {
        respond(400, ['detail' => 'Invalid JSON body.']);
    }
    return is_array($decoded) ? $decoded : [];
}

function client_ip(): string
{
    // Do not trust X-Forwarded-For unless a trusted reverse proxy is explicitly configured.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['DB_HOST'],
        $config['DB_NAME']
    );
    $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
} catch (Throwable $e) {
    error_log('Parse API database connection failed: ' . $e->getMessage());
    respond(503, ['detail' => 'Database service unavailable.']);
}

function require_admin(PDO $pdo): array
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/', $header, $matches)) {
        respond(401, ['detail' => 'Authentication required.']);
    }

    $tokenHash = hash('sha256', $matches[1]);
    $stmt = $pdo->prepare(
        'SELECT u.id, u.email, u.role, u.active, t.id AS token_id
         FROM auth_tokens t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.token_hash = ? AND t.expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $admin = $stmt->fetch();

    if (!$admin || !(bool)$admin['active'] || $admin['role'] !== 'admin') {
        respond(401, ['detail' => 'Invalid or expired admin session.']);
    }

    return $admin;
}

function audit(PDO $pdo, ?int $adminId, string $action, ?string $targetType = null, ?int $targetId = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO admin_audit_log (admin_user_id, action, target_type, target_id, ip_address)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$adminId, $action, $targetType, $targetId, client_ip()]);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/api(?=/|$)#', '', $path) ?: '/';

if ($method === 'GET' && $path === '/health') {
    try {
        $pdo->query('SELECT 1');
        respond(200, ['status' => 'ok', 'service' => 'parse-admin-api', 'version' => '1.0.0']);
    } catch (Throwable $e) {
        respond(503, ['status' => 'error']);
    }
}

if ($method === 'POST' && $path === '/auth/login') {
    $body = request_body();
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $password = (string)($body['password'] ?? '');
    $ip = client_ip();

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '' || strlen($password) > 4096) {
        respond(422, ['detail' => 'Enter a valid email and password.']);
    }

    $ipLimit = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE success = 0 AND ip_address = ?
           AND created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)'
    );
    $ipLimit->execute([$ip]);
    $pairLimit = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE success = 0 AND ip_address = ? AND email = ?
           AND created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)'
    );
    $pairLimit->execute([$ip, $email]);
    if ((int)$ipLimit->fetchColumn() >= 60 || (int)$pairLimit->fetchColumn() >= 10) {
        header('Retry-After: 900');
        respond(429, ['detail' => 'Too many login attempts. Try again later.']);
    }

    $stmt = $pdo->prepare('SELECT id, email, password_hash, role, active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $validPassword = $user && password_verify($password, (string)$user['password_hash']);
    $isAdmin = $validPassword && (bool)$user['active'] && $user['role'] === 'admin';

    $attempt = $pdo->prepare('INSERT INTO login_attempts (ip_address, email, success) VALUES (?, ?, ?)');
    $attempt->execute([$ip, $email, $isAdmin ? 1 : 0]);

    if (!$validPassword || !$isAdmin) {
        respond(401, ['detail' => 'Invalid email or password, or account is not an active administrator.']);
    }

    // Rehash passwords when PHP's password policy changes.
    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        $rehash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = gmdate('Y-m-d H:i:s', time() + 12 * 60 * 60);
    $insert = $pdo->prepare(
        'INSERT INTO auth_tokens (user_id, token_hash, expires_at, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $user['id'],
        $tokenHash,
        $expiresAt,
        $ip,
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
    audit($pdo, (int)$user['id'], 'admin.login', 'user', (int)$user['id']);

    respond(200, [
        'access_token' => $rawToken,
        'token_type' => 'bearer',
        'expires_in' => 43200,
    ]);
}

if ($method === 'POST' && $path === '/auth/logout') {
    $admin = require_admin($pdo);
    $stmt = $pdo->prepare('DELETE FROM auth_tokens WHERE id = ?');
    $stmt->execute([$admin['token_id']]);
    audit($pdo, (int)$admin['id'], 'admin.logout', 'user', (int)$admin['id']);
    respond(200, ['status' => 'ok']);
}

if ($method === 'GET' && $path === '/auth/me') {
    $admin = require_admin($pdo);
    respond(200, [
        'id' => (int)$admin['id'],
        'email' => $admin['email'],
        'role' => $admin['role'],
        'active' => (bool)$admin['active'],
    ]);
}

if (str_starts_with($path, '/admin/')) {
    $admin = require_admin($pdo);

    if ($method === 'GET' && $path === '/admin/stats') {
        $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $activeUsers = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn();
        $questions = (int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
        $sources = (int)$pdo->query('SELECT COUNT(*) FROM sources')->fetchColumn();
        respond(200, [
            'users' => $users,
            'active_users' => $activeUsers,
            'questions' => $questions,
            'sources' => $sources,
        ]);
    }

    if ($method === 'GET' && $path === '/admin/users') {
        $stmt = $pdo->query(
            'SELECT id, email, role, active, created_at
             FROM users ORDER BY id DESC LIMIT 1000'
        );
        respond(200, $stmt->fetchAll());
    }

    if ($method === 'GET' && $path === '/admin/questions') {
        $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
        $offset = max(0, min(1_000_000, (int)($_GET['offset'] ?? 0)));
        $stmt = $pdo->prepare(
            'SELECT id, question, subject, grade, source_type
             FROM questions ORDER BY id DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        respond(200, $stmt->fetchAll());
    }

    if ($method === 'DELETE' && preg_match('#^/admin/questions/([1-9][0-9]*)$#', $path, $matches)) {
        $questionId = (int)$matches[1];
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM questions WHERE id = ?');
            $stmt->execute([$questionId]);
            audit($pdo, (int)$admin['id'], 'question.delete', 'question', $questionId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        respond(200, ['deleted' => $stmt->rowCount()]);
    }
}

respond(404, ['detail' => 'Not found.']);

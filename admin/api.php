<?php
declare(strict_types=1);
require '/opt/parse-admin/admin/bootstrap.php';
$admin = admin_require_login();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') admin_require_csrf();

$action = (string)($_GET['action'] ?? '');
$input = $method === 'GET' ? $_GET : admin_read_json();

function provider_base_valid(string $url): string
{
    $url = rtrim(trim($url), '/');
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' ||
        strtolower((string)($parts['host'] ?? '')) !== 'console.paasta.cloud' ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) ||
        !in_array($parts['path'] ?? '', ['/api/ai/v1', '/api/ai'], true)) {
        admin_json(422, ['ok' => false, 'message' => 'برای اتصال مستندشده، آدرس باید https://console.paasta.cloud/api/ai/v1 یا https://console.paasta.cloud/api/ai باشد.']);
    }
    return $url;
}

function ensure_ai_http(array $result): array
{
    if (!$result['ok']) {
        $payload = $result['json'] ?? [];
        $message = (string)($payload['error']['message'] ?? $payload['detail'] ?? $payload['message'] ?? ('HTTP ' . $result['status']));
        admin_json(min(599, max(400, (int)$result['status'])), [
            'ok' => false,
            'message' => $message,
            'provider' => $payload,
            'request_id' => $result['request_id'],
            'latency_ms' => $result['latency_ms'],
        ]);
    }
    return $result;
}

function ai_model_catalog(PDO $pdo, string $kind): array
{
    $paths = [
        'chat' => 'models',
        'decision' => 'decisions/models',
        'image' => 'images',
        'audio' => 'audio/models',
        'embedding' => 'embeddings/models',
    ];
    if (!isset($paths[$kind])) admin_json(422, ['ok' => false, 'message' => 'نوع کاتالوگ معتبر نیست.']);
    $result = ensure_ai_http(admin_ai_call($pdo, 'catalog.' . $kind, 'GET', $paths[$kind], null, 45));
    return $result['json'] ?? [];
}

try {
    if ($method === 'GET' && $action === 'bootstrap') {
        $settings = admin_ai_settings($pdo);
        $summary = $pdo->query(
            'SELECT COUNT(*) AS requests,
                    SUM(is_success = 1) AS successful,
                    SUM(is_success = 0) AS failed,
                    COALESCE(SUM(total_tokens),0) AS tokens,
                    COALESCE(SUM(charged_toman),0) AS cost
             FROM admin_ai_usage_logs'
        )->fetch();
        $recent = $pdo->query(
            'SELECT operation, model, http_status, is_success, total_tokens, charged_toman,
                    latency_ms, request_id, created_at
             FROM admin_ai_usage_logs ORDER BY id DESC LIMIT 8'
        )->fetchAll();
        admin_json(200, [
            'ok' => true,
            'admin' => ['email' => $admin['email']],
            'provider' => [
                'base_url' => $settings['base_url'] ?? 'https://console.paasta.cloud/api/ai/v1',
                'enabled' => (bool)($settings['enabled'] ?? false),
                'key_configured' => !empty($settings['api_key_cipher']),
            ],
            'usage' => $summary,
            'recent' => $recent,
        ]);
    }

    if ($method === 'GET' && $action === 'catalog') {
        $kind = (string)($_GET['kind'] ?? 'chat');
        admin_json(200, ['ok' => true, 'kind' => $kind, 'data' => ai_model_catalog($pdo, $kind)]);
    }

    if ($method === 'GET' && $action === 'usage') {
        $summary = $pdo->query(
            'SELECT COUNT(*) AS requests,
                    SUM(is_success = 1) AS successful,
                    SUM(is_success = 0) AS failed,
                    COALESCE(SUM(total_tokens),0) AS tokens,
                    COALESCE(SUM(prompt_tokens),0) AS prompt_tokens,
                    COALESCE(SUM(completion_tokens),0) AS completion_tokens,
                    COALESCE(SUM(charged_toman),0) AS cost
             FROM admin_ai_usage_logs'
        )->fetch();
        $rows = $pdo->query(
            'SELECT id, operation, endpoint, model, request_id, http_status, is_success,
                    prompt_tokens, completion_tokens, total_tokens, charged_toman,
                    latency_ms, error_code, created_at
             FROM admin_ai_usage_logs ORDER BY id DESC LIMIT 500'
        )->fetchAll();
        admin_json(200, ['ok' => true, 'summary' => $summary, 'rows' => $rows]);
    }

    if ($method === 'POST' && $action === 'provider.save') {
        $base = provider_base_valid((string)($input['base_url'] ?? ''));
        $newKey = trim((string)($input['api_key'] ?? ''));
        if (strlen($newKey) > 1024) admin_json(422, ['ok' => false, 'message' => 'طول کلید API بیش از حد مجاز است.']);
        $current = admin_ai_settings($pdo);
        $cipher = $current['api_key_cipher'] ?? null;
        if ($newKey !== '') $cipher = admin_encrypt_secret($newKey);
        if (!$cipher) admin_json(422, ['ok' => false, 'message' => 'برای فعال‌سازی اتصال، ابتدا کلید API را وارد کن.']);
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $defaultChat = trim((string)($input['default_chat_model'] ?? '')) ?: null;
        $defaultImage = trim((string)($input['default_image_model'] ?? '')) ?: null;
        $defaultAudio = trim((string)($input['default_audio_model'] ?? '')) ?: null;

        $stmt = $pdo->prepare(
            'INSERT INTO admin_ai_settings
             (id, provider, base_url, api_key_cipher, enabled, default_chat_model, default_image_model, default_audio_model, updated_by)
             VALUES (1, "paasta", ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE base_url=VALUES(base_url), api_key_cipher=VALUES(api_key_cipher),
             enabled=VALUES(enabled), default_chat_model=VALUES(default_chat_model),
             default_image_model=VALUES(default_image_model), default_audio_model=VALUES(default_audio_model),
             updated_by=VALUES(updated_by)'
        );
        $stmt->execute([$base, $cipher, $enabled, $defaultChat, $defaultImage, $defaultAudio, (int)$admin['id']]);
        admin_json(200, ['ok' => true, 'message' => 'تنظیمات ارائه‌دهنده ذخیره شد.', 'enabled' => (bool)$enabled, 'key_configured' => true]);
    }

    if ($method === 'POST' && $action === 'provider.test') {
        $settings = admin_ai_settings($pdo);
        if (empty($settings['api_key_cipher'])) admin_json(422, ['ok' => false, 'message' => 'کلید API ثبت نشده است.']);
        $result = ensure_ai_http(admin_ai_call($pdo, 'provider.test', 'GET', 'models', null, 45));
        $data = $result['json'] ?? [];
        $models = $data['data'] ?? $data['models'] ?? $data;
        admin_json(200, ['ok' => true, 'message' => 'اتصال به API موفق بود.', 'model_count' => is_array($models) ? count($models) : 0, 'request_id' => $result['request_id']]);
    }

    if ($method === 'POST' && $action === 'ai.invoke') {
        $operation = (string)($input['operation'] ?? '');
        $body = $input['payload'] ?? null;
        if (!is_array($body)) admin_json(422, ['ok' => false, 'message' => 'پارامترهای درخواست معتبر نیستند.']);

        $routes = [
            'chat' => ['POST', 'chat/completions', 120],
            'responses' => ['POST', 'responses', 120],
            'messages' => ['POST', 'messages', 120],
            'decision' => ['POST', 'decisions', 120],
            'image_generate' => ['POST', 'images/generations', 390],
            'image_edit' => ['POST', 'images/edits', 390],
            'image_batch_create' => ['POST', 'images/batches', 120],
            'transcription' => ['POST', 'audio/transcriptions', 180],
            'speech' => ['POST', 'audio/speech', 180],
            'embeddings' => ['POST', 'embeddings', 120],
            'batch_create' => ['POST', 'batches', 120],
        ];
        if (!isset($routes[$operation])) admin_json(422, ['ok' => false, 'message' => 'این عملیات در فهرست endpointهای مجاز وجود ندارد.']);

        if (!empty($body['model']) && strlen((string)$body['model']) > 180) admin_json(422, ['ok' => false, 'message' => 'شناسه مدل معتبر نیست.']);
        if ($operation === 'speech' && mb_strlen((string)($body['input'] ?? '')) > 4000) {
            admin_json(422, ['ok' => false, 'message' => 'متن تبدیل گفتار حداکثر ۴۰۰۰ نویسه است.']);
        }
        if ($operation === 'transcription') {
            $audioData = (string)($body['input_audio']['data'] ?? '');
            if (strlen($audioData) > 12 * 1024 * 1024) admin_json(413, ['ok' => false, 'message' => 'فایل صوتی از سقف ۹ مگابایت base64 بیشتر است.']);
        }

        [$verb, $path, $timeout] = $routes[$operation];
        $result = admin_ai_call($pdo, $operation, $verb, $path, $body, $timeout);
        if (!$result['ok']) {
            $payload = $result['json'] ?? [];
            admin_json(min(599, max(400, $result['status'])), [
                'ok' => false,
                'message' => (string)($payload['error']['message'] ?? $payload['detail'] ?? $payload['message'] ?? ('خطای سرویس AI: HTTP ' . $result['status'])),
                'error_code' => $payload['error']['code'] ?? $payload['code'] ?? null,
                'provider' => $payload,
                'request_id' => $result['request_id'],
                'latency_ms' => $result['latency_ms'],
                'idempotency_key' => $result['idempotency_key'],
            ]);
        }
        if ($operation === 'speech') {
            header('Content-Type: ' . ($result['content_type'] ?: 'audio/mpeg'));
            header('Content-Disposition: inline; filename="parse-generated-speech"');
            header('X-AI-Request-ID: ' . preg_replace('/[^A-Za-z0-9._:-]/', '', $result['request_id']));
            header('X-AI-Latency-MS: ' . $result['latency_ms']);
            echo $result['raw'];
            exit;
        }
        admin_json(200, [
            'ok' => true,
            'data' => $result['json'] ?? ['raw' => $result['raw']],
            'request_id' => $result['request_id'],
            'idempotency_key' => $result['idempotency_key'],
            'latency_ms' => $result['latency_ms'],
        ]);
    }

    if ($method === 'POST' && $action === 'ai.lookup') {
        $type = (string)($input['type'] ?? '');
        $id = trim((string)($input['id'] ?? ''));
        if ($id === '' || strlen($id) > 160 || !preg_match('/^[A-Za-z0-9._:-]+$/', $id)) {
            admin_json(422, ['ok' => false, 'message' => 'شناسه معتبر نیست.']);
        }
        if ($type === 'generation') {
            $result = ensure_ai_http(admin_ai_call($pdo, 'generation.lookup', 'GET', 'generation?id=' . rawurlencode($id), null, 45));
        } elseif ($type === 'batch') {
            $result = ensure_ai_http(admin_ai_call($pdo, 'batch.lookup', 'GET', 'batches/' . rawurlencode($id), null, 45));
        } elseif ($type === 'image_batch') {
            $result = ensure_ai_http(admin_ai_call($pdo, 'image_batch.lookup', 'GET', 'images/batches/' . rawurlencode($id), null, 45));
        } else {
            admin_json(422, ['ok' => false, 'message' => 'نوع شناسه نامعتبر است.']);
        }
        admin_json(200, ['ok' => true, 'data' => $result['json'] ?? [], 'request_id' => $result['request_id']]);
    }

    if ($method === 'POST' && $action === 'ai.image_batch.cancel') {
        $id = trim((string)($input['id'] ?? ''));
        if ($id === '' || strlen($id) > 160 || !preg_match('/^[A-Za-z0-9._:-]+$/', $id)) {
            admin_json(422, ['ok' => false, 'message' => 'شناسه Batch معتبر نیست.']);
        }
        $result = ensure_ai_http(admin_ai_call($pdo, 'image_batch.cancel', 'DELETE', 'images/batches/' . rawurlencode($id), null, 45));
        admin_json(200, ['ok' => true, 'data' => $result['json'] ?? []]);
    }

    admin_json(404, ['ok' => false, 'message' => 'عملیات موردنظر پیدا نشد.']);
} catch (Throwable $e) {
    error_log('Parse Admin API action ' . $action . ' failed: ' . $e->getMessage());
    admin_json(500, ['ok' => false, 'message' => 'خطای داخلی پنل رخ داد. لاگ سرور را برای جزئیات بررسی کنید.']);
}

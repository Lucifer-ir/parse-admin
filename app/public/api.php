<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$admin = app_require_admin();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$path = preg_replace('#^/api(?=/|$)#', '', $path) ?: '/';

if ($method !== 'GET') app_check_csrf();

const PAASTA_AI_BASE = 'https://console.paasta.cloud/api/ai/v1';

function ai_json_decode(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function ai_record_usage(array $admin, string $operation, string $endpoint, ?string $model, int $status, array $data, array $headers, int $latency): void
{
    $usage = $data['usage'] ?? ($data['paasta']['usage'] ?? []);
    if (!is_array($usage)) $usage = [];
    $input = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
    $output = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? null;
    $total = $usage['total_tokens'] ?? ((is_numeric($input) && is_numeric($output)) ? (int)$input + (int)$output : null);
    $cached = $usage['input_tokens_details']['cached_tokens']
        ?? $usage['prompt_tokens_details']['cached_tokens']
        ?? $usage['cached_tokens']
        ?? null;
    $requestId = $headers['x-paasta-request-id'] ?? ($data['paasta']['request_id'] ?? null);
    $charged = $data['paasta']['charged_toman'] ?? ($headers['x-paasta-charged-toman'] ?? null);
    $errorCode = $data['error']['code'] ?? ($data['code'] ?? null);

    $stmt = app_db()->prepare(
        'INSERT INTO ai_usage_logs
        (admin_user_id,operation,endpoint,model,request_id,status_code,input_tokens,output_tokens,total_tokens,cached_input_tokens,charged_toman,latency_ms,error_code)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        (int)$admin['id'], $operation, $endpoint, $model,
        is_string($requestId) ? substr($requestId, 0, 190) : null,
        $status,
        is_numeric($input) ? (int)$input : null,
        is_numeric($output) ? (int)$output : null,
        is_numeric($total) ? (int)$total : null,
        is_numeric($cached) ? (int)$cached : null,
        is_numeric($charged) ? (string)$charged : null,
        max(0, $latency),
        is_string($errorCode) ? substr($errorCode, 0, 100) : null,
    ]);
}

function ai_paasta_call(
    array $admin,
    string $operation,
    string $method,
    string $endpoint,
    ?array $payload = null,
    ?array $multipart = null,
    int $timeout = 120,
    string $baseUrl = PAASTA_AI_BASE
): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('افزونه PHP cURL نصب نیست. php-curl را نصب کنید.');
    }
    $apiKey = app_paasta_key();
    if ($apiKey === '') {
        app_json(409, ['error' => 'ابتدا کلید API پاستا را در تنظیمات پنل ثبت کنید.']);
    }

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
    ];
    $idempotencyKey = bin2hex(random_bytes(18));
    if ($method !== 'GET') $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    if ($payload !== null && $multipart === null) $headers[] = 'Content-Type: application/json';

    $url = rtrim($baseUrl, '/') . $endpoint;
    $curl = curl_init($url);
    $responseHeaders = [];
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            return $length;
        },
    ];
    if ($multipart !== null) {
        $options[CURLOPT_POSTFIELDS] = $multipart;
    } elseif ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($curl, $options);

    $started = microtime(true);
    $raw = curl_exec($curl);
    $latency = (int)round((microtime(true) - $started) * 1000);
    $curlError = curl_error($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);

    if (!is_string($raw)) {
        error_log('Paasta API transport error: ' . $curlError);
        app_json(502, ['error' => 'ارتباط سرور با درگاه هوش مصنوعی برقرار نشد. جزئیات در لاگ سرور ثبت شد.']);
    }

    $data = ai_json_decode($raw);
    $model = is_array($payload) && isset($payload['model']) ? (string)$payload['model'] : null;
    ai_record_usage($admin, $operation, $endpoint, $model, $status, $data, $responseHeaders, $latency);

    if ($status < 200 || $status >= 300) {
        $code = $data['error']['code'] ?? $data['code'] ?? 'provider_error';
        $message = $data['error']['message'] ?? $data['detail'] ?? 'درخواست توسط سرویس هوش مصنوعی پذیرفته نشد.';
        app_json(502, [
            'error' => is_string($message) ? $message : 'خطای سرویس هوش مصنوعی',
            'code' => is_string($code) ? $code : 'provider_error',
            'provider_status' => $status,
            'request_id' => $responseHeaders['x-paasta-request-id'] ?? null,
        ]);
    }

    return [
        'data' => $data,
        'raw' => $raw,
        'headers' => $responseHeaders,
        'status' => $status,
        'content_type' => $contentType,
        'latency_ms' => $latency,
    ];
}

function ai_extract_text(array $data): string
{
    if (isset($data['choices'][0]['message']['content'])) {
        $content = $data['choices'][0]['message']['content'];
        if (is_string($content)) return $content;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text'])) $parts[] = (string)$part['text'];
            }
            if ($parts) return implode("\n", $parts);
        }
    }
    if (isset($data['output_text']) && is_string($data['output_text'])) return $data['output_text'];
    if (isset($data['content']) && is_array($data['content'])) {
        $parts = [];
        foreach ($data['content'] as $part) {
            if (is_array($part) && isset($part['text'])) $parts[] = (string)$part['text'];
        }
        if ($parts) return implode("\n", $parts);
    }
    if (isset($data['output']) && is_array($data['output'])) {
        $parts = [];
        foreach ($data['output'] as $item) {
            foreach (($item['content'] ?? []) as $part) {
                if (is_array($part) && isset($part['text'])) $parts[] = (string)$part['text'];
            }
        }
        if ($parts) return implode("\n", $parts);
    }
    return '';
}

function ai_save_history(array $admin, string $type, string $title, string $model, string $prompt, string $output, ?string $requestId): void
{
    $allowed = ['question','practice','review','chat','response'];
    if (!in_array($type, $allowed, true)) $type = 'chat';
    $stmt = app_db()->prepare(
        'INSERT INTO ai_generation_history (admin_user_id,generation_type,title,model,prompt,output,request_id)
         VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        (int)$admin['id'], $type, mb_substr($title, 0, 255), mb_substr($model, 0, 190),
        $prompt, $output, $requestId,
    ]);
}

function ai_catalog(array $admin, string $kind): never
{
    $routes = [
        'models' => '/models',
        'decisions' => '/decisions/models',
        'images' => '/images',
        'audio' => '/audio/models',
        'speech' => '/audio/speech',
        'embeddings' => '/embeddings/models',
    ];
    if (!isset($routes[$kind])) app_json(400, ['error' => 'نوع کاتالوگ معتبر نیست.']);
    $result = ai_paasta_call($admin, 'catalog_' . $kind, 'GET', $routes[$kind]);
    app_json(200, $result['data']);
}

if ($method === 'GET' && $path === '/bootstrap') {
    $stmt = app_db()->query('SELECT default_model,api_key_ciphertext,updated_at FROM ai_provider_settings WHERE id=1');
    $settings = $stmt->fetch() ?: [];
    app_json(200, [
        'admin' => ['id' => (int)$admin['id'], 'email' => $admin['email']],
        'csrf' => app_csrf(),
        'api_base' => PAASTA_AI_BASE,
        'provider_configured' => !empty($settings['api_key_ciphertext']),
        'provider_mask' => !empty($settings['api_key_ciphertext']) ? '••••••••••••••••' : '',
        'default_model' => $settings['default_model'] ?? '',
        'provider_updated_at' => $settings['updated_at'] ?? null,
    ]);
}

if ($method === 'GET' && $path === '/dashboard') {
    $pdo = app_db();
    $today = $pdo->query("SELECT COUNT(*) requests,COALESCE(SUM(charged_toman),0) cost,COALESCE(SUM(total_tokens),0) tokens FROM ai_usage_logs WHERE created_at >= CURDATE()")->fetch();
    $month = $pdo->query("SELECT COUNT(*) requests,COALESCE(SUM(charged_toman),0) cost,COALESCE(SUM(total_tokens),0) tokens FROM ai_usage_logs WHERE created_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetch();
    $totals = $pdo->query('SELECT COUNT(*) requests,COALESCE(SUM(charged_toman),0) cost,COALESCE(SUM(total_tokens),0) tokens FROM ai_usage_logs')->fetch();
    $models = $pdo->query('SELECT model,COUNT(*) requests,COALESCE(SUM(charged_toman),0) cost FROM ai_usage_logs WHERE model IS NOT NULL GROUP BY model ORDER BY requests DESC LIMIT 6')->fetchAll();
    $recent = $pdo->query('SELECT operation,model,status_code,total_tokens,charged_toman,latency_ms,request_id,created_at FROM ai_usage_logs ORDER BY id DESC LIMIT 12')->fetchAll();
    $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $questions = (int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
    app_json(200, ['today'=>$today,'month'=>$month,'totals'=>$totals,'models'=>$models,'recent'=>$recent,'users'=>$users,'questions'=>$questions]);
}

if ($method === 'GET' && preg_match('#^/catalog/([a-z]+)$#', $path, $m)) ai_catalog($admin, $m[1]);

if ($method === 'GET' && $path === '/settings') {
    $row = app_db()->query('SELECT default_model,api_key_ciphertext,updated_at FROM ai_provider_settings WHERE id=1')->fetch() ?: [];
    app_json(200, [
        'configured' => !empty($row['api_key_ciphertext']),
        'masked_key' => !empty($row['api_key_ciphertext']) ? '••••••••••••••••' : '',
        'default_model' => $row['default_model'] ?? '',
        'updated_at' => $row['updated_at'] ?? null,
        'api_base' => PAASTA_AI_BASE,
    ]);
}

if ($method === 'POST' && $path === '/settings') {
    $body = app_request_json();
    $key = trim((string)($body['api_key'] ?? ''));
    $model = trim((string)($body['default_model'] ?? ''));
    if ($key !== '' && (strlen($key) < 16 || strlen($key) > 500)) {
        app_json(422, ['error' => 'کلید API معتبر به نظر نمی‌رسد.']);
    }
    $pdo = app_db();
    if ($key !== '') {
        [$cipher,$nonce] = app_encrypt_secret($key);
        $stmt = $pdo->prepare('UPDATE ai_provider_settings SET api_key_ciphertext=?,api_key_nonce=?,default_model=?,updated_by=? WHERE id=1');
        $stmt->execute([$cipher,$nonce,$model !== '' ? $model : null,(int)$admin['id']]);
    } else {
        $stmt = $pdo->prepare('UPDATE ai_provider_settings SET default_model=?,updated_by=? WHERE id=1');
        $stmt->execute([$model !== '' ? $model : null,(int)$admin['id']]);
    }
    app_json(200, ['ok'=>true,'message'=>'تنظیمات ذخیره شد. کلید فقط به‌صورت رمز‌شده در سرور نگهداری می‌شود.']);
}

if ($method === 'POST' && $path === '/settings/test') {
    $result = ai_paasta_call($admin, 'connection_test', 'GET', '/models');
    app_json(200, ['ok'=>true,'message'=>'اتصال به کاتالوگ مدل‌های پاستا برقرار است.','models_count'=>count($result['data']['data'] ?? $result['data']['models'] ?? [])]);
}

if ($method === 'GET' && $path === '/usage') {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
    $stmt = app_db()->prepare(
        'SELECT id,operation,endpoint,model,request_id,status_code,input_tokens,output_tokens,total_tokens,cached_input_tokens,charged_toman,latency_ms,error_code,created_at
         FROM ai_usage_logs WHERE created_at >= ? AND created_at < DATE_ADD(?,INTERVAL 1 DAY)
         ORDER BY id DESC LIMIT 2000'
    );
    $stmt->execute([$from,$to]);
    $rows = $stmt->fetchAll();
    $sum = ['requests'=>count($rows),'cost'=>0.0,'tokens'=>0];
    foreach ($rows as $row) {
        $sum['cost'] += (float)($row['charged_toman'] ?? 0);
        $sum['tokens'] += (int)($row['total_tokens'] ?? 0);
    }
    app_json(200, ['summary'=>$sum,'rows'=>$rows,'from'=>$from,'to'=>$to]);
}

if ($method === 'GET' && $path === '/usage.csv') {
    $stmt = app_db()->query(
        'SELECT created_at,operation,endpoint,model,request_id,status_code,input_tokens,output_tokens,total_tokens,cached_input_tokens,charged_toman,latency_ms,error_code
         FROM ai_usage_logs ORDER BY id DESC LIMIT 10000'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parse-ai-usage.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['created_at','operation','endpoint','model','request_id','status_code','input_tokens','output_tokens','total_tokens','cached_input_tokens','charged_toman','latency_ms','error_code']);
    while ($row = $stmt->fetch()) fputcsv($out,$row);
    fclose($out);
    exit;
}

if ($method === 'GET' && $path === '/history') {
    $stmt = app_db()->prepare('SELECT id,generation_type,title,model,output,request_id,created_at FROM ai_generation_history WHERE admin_user_id=? ORDER BY id DESC LIMIT 100');
    $stmt->execute([(int)$admin['id']]);
    app_json(200, ['rows'=>$stmt->fetchAll()]);
}

if ($method === 'POST' && $path === '/generate') {
    $body = app_request_json();
    $type = (string)($body['type'] ?? '');
    $model = trim((string)($body['model'] ?? ''));
    if ($model === '') {
        $model = (string)(app_db()->query('SELECT default_model FROM ai_provider_settings WHERE id=1')->fetchColumn() ?: '');
    }
    if ($model === '') app_json(422, ['error'=>'ابتدا یک مدل را انتخاب یا در تنظیمات به‌عنوان مدل پیش‌فرض ثبت کنید.']);

    $topic = trim((string)($body['topic'] ?? ''));
    $grade = trim((string)($body['grade'] ?? ''));
    $difficulty = trim((string)($body['difficulty'] ?? 'متوسط'));
    $count = min(30,max(1,(int)($body['count'] ?? 5)));
    $text = trim((string)($body['text'] ?? ''));
    $extra = trim((string)($body['instructions'] ?? ''));

    $templates = [
        'question' => 'تو طراح حرفه‌ای سؤال آموزشی هستی. به فارسی دقیق و بدون ابهام سؤال بساز. برای هر سؤال پاسخ درست و توضیح کوتاه بده. از تکرار و ابهام پرهیز کن.',
        'practice' => 'تو طراح تمرین آموزشی هستی. تمرین‌های متنوع، مرحله‌ای و قابل ارزیابی طراحی کن. برای هر تمرین هدف، دستور کار، نمونه و معیار ارزیابی بده.',
        'review' => 'تو ویراستار و ارزیاب حرفه‌ای فارسی هستی. املا، دستور زبان، انسجام، وضوح، لحن و صحت ساختار را جداگانه بررسی کن؛ سپس نسخه بازنویسی‌شده را بده. اطلاعاتی را که از متن قابل اثبات نیست قطعی فرض نکن.',
    ];
    if (!isset($templates[$type])) app_json(422, ['error'=>'نوع تولید معتبر نیست.']);
    if ($type === 'review' && $text === '') app_json(422, ['error'=>'متنی برای بررسی وارد نشده است.']);
    if ($type !== 'review' && $topic === '') app_json(422, ['error'=>'موضوع را وارد کنید.']);

    $prompt = $type === 'review'
        ? "متن برای بررسی:\n" . mb_substr($text,0,30000) . "\n\nدستور تکمیلی: " . $extra
        : "موضوع: {$topic}\nپایه/مخاطب: {$grade}\nسطح دشواری: {$difficulty}\nتعداد: {$count}\nتوضیحات تکمیلی: {$extra}\n\nخروجی را با تیتر و شماره‌گذاری روشن به زبان فارسی ارائه کن.";
    $payload = [
        'model'=>$model,
        'messages'=>[
            ['role'=>'system','content'=>$templates[$type]],
            ['role'=>'user','content'=>$prompt],
        ],
        'max_tokens'=>min(8192,max(256,(int)($body['max_tokens'] ?? 2048))),
        'temperature'=>0.7,
    ];
    $call = ai_paasta_call($admin,'generate_'.$type,'POST','/chat/completions',$payload,null,180);
    $output = ai_extract_text($call['data']);
    $requestId = $call['headers']['x-paasta-request-id'] ?? ($call['data']['paasta']['request_id'] ?? null);
    if (!empty($body['save_history'])) {
        ai_save_history($admin,$type,($type==='question'?'تولید سؤال':($type==='practice'?'طراحی تمرین':'بررسی متن')),$model,$prompt,$output,(string)$requestId);
    }
    app_json(200,['output'=>$output,'usage'=>$call['data']['usage']??null,'paasta'=>$call['data']['paasta']??null,'request_id'=>$requestId]);
}

if ($method === 'POST' && $path === '/ai/chat/stream') {
    if (!function_exists('curl_init')) app_json(500,['error'=>'افزونه cURL نصب نیست.']);
    $body=app_request_json();
    $model=trim((string)($body['model']??''));
    $message=trim((string)($body['message']??''));
    if($model===''||$message==='') app_json(422,['error'=>'مدل و پیام الزامی هستند.']);
    $apiKey=app_paasta_key();
    if($apiKey==='') app_json(409,['error'=>'ابتدا کلید API پاستا را در تنظیمات ثبت کنید.']);

    $messages=$body['messages']??null;
    if(!is_array($messages)||count($messages)<1) {
        $messages=[];
        $system=trim((string)($body['system']??''));
        if($system!=='') $messages[]=['role'=>'system','content'=>mb_substr($system,0,10000)];
        $messages[]=['role'=>'user','content'=>mb_substr($message,0,50000)];
    }
    $payload=['model'=>$model,'messages'=>$messages,'max_tokens'=>min(8192,max(64,(int)($body['max_tokens']??1024))),'stream'=>true,'stream_options'=>['include_usage'=>true]];
    if(isset($body['temperature'])) $payload['temperature']=max(0,min(2,(float)$body['temperature']));
    if(isset($body['tools']) && is_array($body['tools'])) $payload['tools']=$body['tools'];
    if(isset($body['tool_choice'])) $payload['tool_choice']=$body['tool_choice'];
    if(isset($body['response_format']) && is_array($body['response_format'])) $payload['response_format']=$body['response_format'];
    if(isset($body['pdf_processing']) && is_array($body['pdf_processing'])) $payload['pdf_processing']=$body['pdf_processing'];
    if(isset($body['structured_outputs']) && is_array($body['structured_outputs'])) $payload['structured_outputs']=$body['structured_outputs'];

    $headers=['Authorization: Bearer '.$apiKey,'Content-Type: application/json','Accept: text/event-stream','Idempotency-Key: '.bin2hex(random_bytes(18))];
    $responseHeaders=[];
    $raw='';
    $curl=curl_init(PAASTA_AI_BASE.'/chat/completions');
    curl_setopt_array($curl,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_CONNECTTIMEOUT=>15,
        CURLOPT_TIMEOUT=>240,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HEADERFUNCTION=>static function($ch,string $line) use (&$responseHeaders):int{
            $length=strlen($line);$parts=explode(':',$line,2);
            if(count($parts)===2)$responseHeaders[strtolower(trim($parts[0]))]=trim($parts[1]);
            return $length;
        },
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$raw):int{
            $raw.=$chunk;
            echo $chunk;
            if(function_exists('ob_flush')) @ob_flush();
            flush();
            return strlen($chunk);
        },
    ]);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    header('Content-Security-Policy: default-src none');
    while(ob_get_level()>0) @ob_end_flush();
    $started=microtime(true);
    $ok=curl_exec($curl);
    $curlError=curl_error($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    $latency=(int)round((microtime(true)-$started)*1000);
    curl_close($curl);

    $usageData=[];
    $streamText='';
    foreach(preg_split('/\\r?\\n/',$raw) as $line){
        if(!str_starts_with($line,'data:')) continue;
        $chunk=trim(substr($line,5));
        if($chunk===''||$chunk==='[DONE]') continue;
        $decoded=json_decode($chunk,true);
        if(is_array($decoded)&&isset($decoded['usage'])) $usageData['usage']=$decoded['usage'];
        if(is_array($decoded)&&isset($decoded['paasta'])) $usageData['paasta']=$decoded['paasta'];
        $delta=$decoded['choices'][0]['delta']['content']??null;
        if(is_string($delta)) $streamText.=$delta;
    }
    if($ok===false){
        error_log('Paasta streaming transport error: '.$curlError);
        echo "event: error\ndata: ".json_encode(['error'=>'ارتباط جریانی با درگاه هوش مصنوعی قطع شد.'])."\n\n";
        exit;
    }
    ai_record_usage($admin,'chat_stream','/chat/completions',$model,$status,$usageData,$responseHeaders,$latency);
    $streamRequestId=$responseHeaders['x-paasta-request-id']??($usageData['paasta']['request_id']??null);
    if(!empty($body['save_history']) && $status>=200 && $status<300) {
        ai_save_history($admin,'chat','گفت‌وگوی جریانی',$model,$message,$streamText,(string)$streamRequestId);
    }
    if($status<200||$status>=300){
        error_log('Paasta streaming returned HTTP '.$status);
        echo "\nevent: error\ndata: ".json_encode(['error'=>'درخواست جریانی توسط درگاه رد شد.','provider_status'=>$status,'request_id'=>$responseHeaders['x-paasta-request-id']??null],JSON_UNESCAPED_UNICODE)."\n\n";
    }
    exit;
}

if ($method === 'POST' && $path === '/ai/chat') {
    $body=app_request_json();
    $model=trim((string)($body['model']??''));
    $message=trim((string)($body['message']??''));
    $messages=$body['messages']??null;
    if($model===''||($message===''&&(!is_array($messages)||count($messages)<1))) app_json(422,['error'=>'مدل و پیام یا ساختار Messages الزامی هستند.']);
    if(!is_array($messages)||count($messages)<1) {
        $messages=[];
        $system=trim((string)($body['system']??''));
        if($system!=='') $messages[]=['role'=>'system','content'=>mb_substr($system,0,10000)];
        $messages[]=['role'=>'user','content'=>mb_substr($message,0,50000)];
    }
    $payload=['model'=>$model,'messages'=>$messages,'max_tokens'=>min(8192,max(64,(int)($body['max_tokens']??1024))),'stream'=>false];
    if(isset($body['temperature'])) $payload['temperature']=max(0,min(2,(float)$body['temperature']));
    foreach(['tools','tool_choice','response_format','pdf_processing','parallel_tool_calls','top_p','presence_penalty','frequency_penalty','seed','stop'] as $field) {
        if(array_key_exists($field,$body)) $payload[$field]=$body[$field];
    }
    $call=ai_paasta_call($admin,'chat','POST','/chat/completions',$payload,null,180);
    $output=ai_extract_text($call['data']);
    $requestId=$call['headers']['x-paasta-request-id']??($call['data']['paasta']['request_id']??null);
    if(!empty($body['save_history'])) ai_save_history($admin,'chat','گفت‌وگوی هوش مصنوعی',$model,$message,$output,(string)$requestId);
    app_json(200,['output'=>$output,'usage'=>$call['data']['usage']??null,'paasta'=>$call['data']['paasta']??null,'request_id'=>$requestId]);
}

if ($method === 'POST' && $path === '/ai/messages') {
    $body=app_request_json();
    $model=trim((string)($body['model']??''));
    $message=trim((string)($body['message']??''));
    $messages=$body['messages']??null;
    if($model===''||($message===''&&(!is_array($messages)||count($messages)<1))) app_json(422,['error'=>'مدل و پیام یا ساختار Messages الزامی هستند.']);
    if(!is_array($messages)||count($messages)<1) $messages=[['role'=>'user','content'=>mb_substr($message,0,50000)]];
    $payload=['model'=>$model,'max_tokens'=>min(8192,max(64,(int)($body['max_tokens']??1024))),'messages'=>$messages];
    if(!empty($body['system'])) $payload['system']=mb_substr((string)$body['system'],0,10000);
    if(isset($body['pdf_processing'])) $payload['pdf_processing']=$body['pdf_processing'];
    $call=ai_paasta_call($admin,'anthropic_messages','POST','/messages',$payload,null,180,'https://console.paasta.cloud/api/ai');
    $output=ai_extract_text($call['data']);
    app_json(200,['output'=>$output,'data'=>$call['data'],'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && $path === '/ai/responses') {
    $body=app_request_json();
    $model=trim((string)($body['model']??''));
    $input=$body['input']??'';
    if(is_string($input)) $input=mb_substr(trim($input),0,50000);
    if($model===''||((is_string($input)&&$input==='')||(!is_string($input)&&(!is_array($input)||count($input)<1)))) app_json(422,['error'=>'مدل و ورودی معتبر الزامی هستند.']);
    $payload=['model'=>$model,'input'=>$input,'max_output_tokens'=>min(8192,max(64,(int)($body['max_output_tokens']??1024))),'store'=>false,'stream'=>false];
    if(!empty($body['instructions'])) $payload['instructions']=mb_substr((string)$body['instructions'],0,10000);
    if(!empty($body['web_search'])) {
        $payload['tools']=[['type'=>'web_search']];
        $payload['tool_choice']=['type'=>'web_search'];
        $payload['max_tool_calls']=1;
    }
    foreach(['tools','tool_choice','response_format','structured_outputs','pdf_processing','max_tool_calls'] as $field) {
        if(array_key_exists($field,$body)) $payload[$field]=$body[$field];
    }
    $call=ai_paasta_call($admin,'responses','POST','/responses',$payload,null,180);
    $output=ai_extract_text($call['data']);
    $requestId=$call['headers']['x-paasta-request-id']??($call['data']['paasta']['request_id']??null);
    if(!empty($body['save_history'])) {
        $historyPrompt=is_string($input)?$input:json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        ai_save_history($admin,'response','Responses API',$model,$historyPrompt,$output,(string)$requestId);
    }
    app_json(200,['output'=>$output,'data'=>$call['data'],'request_id'=>$requestId]);
}

if ($method === 'POST' && $path === '/ai/decisions') {
    $body=app_request_json();
    $model=trim((string)($body['model']??''));
    $state=$body['state']??null;
    $questions=$body['questions']??null;
    if($model===''||$state===null||!is_array($questions)) app_json(422,['error'=>'مدل، state و questions الزامی هستند.']);
    $payload=['model'=>$model,'state'=>$state,'questions'=>$questions];
    $call=ai_paasta_call($admin,'decisions','POST','/decisions',$payload,null,180);
    app_json(200,['data'=>$call['data'],'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && $path === '/ai/images') {
    $body=app_request_json();
    $model=trim((string)($body['model']??''));
    $prompt=trim((string)($body['prompt']??''));
    if($model===''||$prompt==='') app_json(422,['error'=>'مدل تصویر و شرح تصویر الزامی است.']);
    $payload=['model'=>$model,'prompt'=>mb_substr($prompt,0,10000),'n'=>1,'response_format'=>'b64_json'];
    if(!empty($body['size'])) $payload['size']=$body['size'];
    if(!empty($body['quality'])) $payload['quality']=$body['quality'];
    $call=ai_paasta_call($admin,'image_generation','POST','/images/generations',$payload,null,360);
    app_json(200,['data'=>$call['data'],'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && $path === '/ai/image-edit') {
    $body=$_POST;
    $model=trim((string)($body['model']??''));
    $prompt=trim((string)($body['prompt']??''));
    $imageUrls=array_values(array_filter(array_map('trim',preg_split('/\r?\n/',(string)($body['image_urls']??'')))));
    if($model===''||$prompt===''||count($imageUrls)<1||count($imageUrls)>4) app_json(422,['error'=>'مدل، شرح و ۱ تا ۴ آدرس HTTPS تصویر الزامی است.']);
    foreach($imageUrls as $url) if(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https:') app_json(422,['error'=>'فقط آدرس عمومی HTTPS مجاز است.']);
    $payload=['model'=>$model,'prompt'=>$prompt,'image_urls'=>json_encode($imageUrls,JSON_UNESCAPED_SLASHES)];
    if(!empty($body['mask_url'])) $payload['mask_url']=$body['mask_url'];
    $call=ai_paasta_call($admin,'image_edit','POST','/images/edits',null,$payload,360);
    app_json(200,['data'=>$call['data'],'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && $path === '/ai/transcription') {
    if(empty($_FILES['audio'])||$_FILES['audio']['error']!==UPLOAD_ERR_OK) app_json(422,['error'=>'فایل صوتی را انتخاب کنید.']);
    $file=$_FILES['audio'];
    if((int)$file['size']>20*1024*1024) app_json(413,['error'=>'فایل صوتی حداکثر ۲۰ مگابایت باشد.']);
    $model=trim((string)($_POST['model']??''));
    if($model==='') app_json(422,['error'=>'مدل تبدیل صوت را انتخاب کنید.']);
    $mime=function_exists('mime_content_type')?(mime_content_type($file['tmp_name'])?:'application/octet-stream'):'application/octet-stream';
    $payload=['model'=>$model,'file'=>new CURLFile($file['tmp_name'],$mime,basename((string)$file['name']))];
    if(!empty($_POST['language'])) $payload['language']=preg_replace('/[^a-z-]/i','',(string)$_POST['language']);
    $call=ai_paasta_call($admin,'audio_transcription','POST','/audio/transcriptions',null,$payload,360);
    app_json(200,['data'=>$call['data'],'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && $path === '/ai/speech') {
    $body=app_request_json();
    $model=trim((string)($body['model']??''));
    $input=trim((string)($body['input']??''));
    $voice=trim((string)($body['voice']??''));
    if($model===''||$input===''||mb_strlen($input)>4000) app_json(422,['error'=>'مدل و متن حداکثر ۴۰۰۰ نویسه الزامی هستند.']);
    $payload=['model'=>$model,'input'=>$input,'voice'=>$voice?:'alloy','response_format'=>$body['response_format']??'mp3'];
    $call=ai_paasta_call($admin,'audio_speech','POST','/audio/speech',$payload,null,360);
    app_json(200,['audio_base64'=>base64_encode($call['raw']),'content_type'=>$call['content_type']?:'audio/mpeg','request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && $path === '/ai/embeddings') {
    $body=app_request_json();
    $model=trim((string)($body['model']??''));
    $input=$body['input']??'';
    if($model===''||(!is_string($input)&&!is_array($input))) app_json(422,['error'=>'مدل و متن ورودی الزامی هستند.']);
    $call=ai_paasta_call($admin,'embeddings','POST','/embeddings',['model'=>$model,'input'=>$input],null,180);
    app_json(200,['data'=>$call['data'],'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && preg_match('#^/ai/knowledge/([a-zA-Z0-9_-]+)/search$#',$path,$m)) {
    $body=app_request_json();
    $query=trim((string)($body['query']??''));
    if($query==='') app_json(422,['error'=>'متن جست‌وجو الزامی است.']);
    $payload=['query'=>$query,'top_k'=>min(20,max(1,(int)($body['top_k']??5))),'retrieval_mode'=>$body['retrieval_mode']??'hybrid'];
    $call=ai_paasta_call($admin,'knowledge_search','POST','/knowledge-bases/'.rawurlencode($m[1]).'/search',$payload,null,180);
    app_json(200,['data'=>$call['data'],'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && $path === '/ai/batches') {
    $body=app_request_json();
    if(empty($body['model'])||empty($body['requests'])||!is_array($body['requests'])||count($body['requests'])<1||count($body['requests'])>20) app_json(422,['error'=>'مدل و ۱ تا ۲۰ درخواست Batch لازم است.']);
    foreach($body['requests'] as &$request) {
        if(empty($request['custom_id'])) $request['custom_id']=bin2hex(random_bytes(8));
    }
    unset($request);
    $call=ai_paasta_call($admin,'text_batch','POST','/batches',$body,null,180);
    app_json(200,['data'=>$call['data'],'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'GET' && preg_match('#^/ai/batches/([a-zA-Z0-9_-]+)$#',$path,$m)) {
    $call=ai_paasta_call($admin,'batch_status','GET','/batches/'.rawurlencode($m[1]));
    app_json(200,$call['data']);
}

if ($method === 'POST' && $path === '/ai/image-batches') {
    $body=app_request_json();
    if(empty($body['model'])||empty($body['requests'])||!is_array($body['requests'])||count($body['requests'])<1||count($body['requests'])>10) app_json(422,['error'=>'مدل و ۱ تا ۱۰ درخواست تصویر لازم است.']);
    $call=ai_paasta_call($admin,'image_batch','POST','/images/batches',$body,null,180);
    app_json(200,$call['data']);
}

if ($method === 'GET' && preg_match('#^/ai/image-batches/([a-zA-Z0-9_-]+)$#',$path,$m)) {
    $call=ai_paasta_call($admin,'image_batch_status','GET','/images/batches/'.rawurlencode($m[1]));
    app_json(200,$call['data']);
}

if ($method === 'DELETE' && preg_match('#^/ai/image-batches/([a-zA-Z0-9_-]+)$#',$path,$m)) {
    $call=ai_paasta_call($admin,'image_batch_cancel','DELETE','/images/batches/'.rawurlencode($m[1]));
    app_json(200,$call['data']);
}

if ($method === 'GET' && $path === '/generation') {
    $id=trim((string)($_GET['id']??''));
    if($id==='') app_json(422,['error'=>'شناسه درخواست الزامی است.']);
    $call=ai_paasta_call($admin,'generation_receipt','GET','/generation?id='.rawurlencode($id));
    app_json(200,$call['data']);
}

if ($method === 'POST' && $path === '/questions/save') {
    $body=app_request_json();
    $questions=$body['questions']??[];
    if(!is_array($questions)||count($questions)<1||count($questions)>100) app_json(422,['error'=>'فهرست سؤال معتبر نیست.']);
    $stmt=app_db()->prepare('INSERT INTO questions (question,subject,grade,source_type) VALUES (?,?,?,?)');
    $count=0;
    foreach($questions as $question) {
        $text=trim((string)(is_array($question)?($question['question']??''):$question));
        if($text==='') continue;
        $stmt->execute([mb_substr($text,0,60000),mb_substr((string)($body['subject']??''),0,120),mb_substr((string)($body['grade']??''),0,80),'ai']);
        $count++;
    }
    app_json(200,['saved'=>$count]);
}

app_json(404,['error'=>'مسیر API پنل یافت نشد.']);

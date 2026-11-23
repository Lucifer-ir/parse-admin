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
    // Keep raw billing/usage metadata for reconciliation, but never store prompt or completion text here.
    $providerMeta = [
        'paasta' => $data['paasta'] ?? null,
        'usage' => $usage,
        'headers' => array_intersect_key($headers, array_flip([
            'x-paasta-request-id','x-paasta-charged-toman','retry-after',
            'x-ratelimit-limit','x-ratelimit-remaining','x-ratelimit-reset'
        ])),
    ];
    foreach (['pricing_components','billing','cost_breakdown','usage_breakdown','cached_savings_toman','cache_savings_toman','charged_toman','rate_per_minute'] as $metaField) {
        if (array_key_exists($metaField, $data)) $providerMeta[$metaField] = $data[$metaField];
    }
    $providerMetaJson = json_encode($providerMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = app_db()->prepare(
        'INSERT INTO ai_usage_logs
        (admin_user_id,operation,endpoint,model,request_id,status_code,input_tokens,output_tokens,total_tokens,cached_input_tokens,charged_toman,latency_ms,error_code,provider_meta_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
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
        is_string($providerMetaJson) ? $providerMetaJson : null,
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
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
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
    $model = is_array($payload) && isset($payload['model'])
        ? (string)$payload['model']
        : (is_array($multipart) && isset($multipart['model']) ? (string)$multipart['model'] : null);
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


function platform_student_payload(array $row): array
{
    $row['id'] = (int)$row['id'];
    $row['active'] = ($row['status'] ?? 'active') === 'active';
    return $row;
}

function platform_question_type(string $type): string
{
    $allowed = ['multiple_choice','written','true_false','fill_blank','matching','ordering','listening','speaking'];
    return in_array($type, $allowed, true) ? $type : 'multiple_choice';
}

function platform_json_value(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}


if ($method === 'GET' && $path === '/platform/tracks') {
    $rows=app_db()->query('SELECT id,language_code,language_name,track_title,course_title,level_code,description,active,created_at FROM learning_tracks ORDER BY language_name,track_title,course_title,level_code')->fetchAll();
    app_json(200,['rows'=>$rows]);
}

if ($method === 'POST' && $path === '/platform/tracks') {
    $body=app_request_json();
    $languageCode=strtolower(trim((string)($body['language_code']??'')));
    $languageName=trim((string)($body['language_name']??''));
    $trackTitle=trim((string)($body['track_title']??''));
    $courseTitle=trim((string)($body['course_title']??''));
    $levelCode=trim((string)($body['level_code']??''));
    if(!preg_match('/^[a-z]{2,16}$/',$languageCode)||$languageName===''||$trackTitle===''||$courseTitle===''||$levelCode==='')app_json(422,['error'=>'زبان، رشته، دوره و سطح را کامل وارد کنید.']);
    try{
      $stmt=app_db()->prepare('INSERT INTO learning_tracks(language_code,language_name,track_title,course_title,level_code,description,active) VALUES(?,?,?,?,?,?,1)');
      $stmt->execute([$languageCode,$languageName,$trackTitle,$courseTitle,$levelCode,trim((string)($body['description']??''))?:null]);
    }catch(PDOException $e){if((string)$e->getCode()==='23000')app_json(409,['error'=>'این ترکیب زبان، رشته، دوره و سطح قبلاً تعریف شده است.']);throw $e;}
    app_json(201,['ok'=>true,'id'=>(int)app_db()->lastInsertId()]);
}

if ($method === 'POST' && preg_match('#^/platform/tracks/([1-9][0-9]*)/status$#',$path,$m)) {
    $id=(int)$m[1];$body=app_request_json();$active=!empty($body['active'])?1:0;
    app_db()->prepare('UPDATE learning_tracks SET active=? WHERE id=?')->execute([$active,$id]);
    app_json(200,['ok'=>true,'active'=>(bool)$active]);
}

if ($method === 'GET' && $path === '/platform/activities') {
    $rows=app_db()->query(
      'SELECT a.id,a.activity_type,a.title,a.subject,a.level,a.due_at,a.status,
       (SELECT COUNT(*) FROM activity_questions aq WHERE aq.activity_id=a.id) question_count,
       (SELECT COUNT(*) FROM student_activity_assignments saa WHERE saa.activity_id=a.id) student_count,
       (SELECT COUNT(*) FROM activity_submissions sub WHERE sub.activity_id=a.id AND sub.status IN (\'submitted\',\'reviewed\')) submitted_count
       FROM learning_activities a ORDER BY a.id DESC'
    )->fetchAll();
    app_json(200,['rows'=>$rows]);
}

if ($method === 'POST' && $path === '/platform/activities') {
    $body=app_request_json();
    $title=trim((string)($body['title']??''));
    $questionIds=array_values(array_unique(array_filter(array_map('intval',(array)($body['question_ids']??[])),static fn($id)=>$id>0)));
    if($title===''||count($questionIds)<1||count($questionIds)>200)app_json(422,['error'=>'عنوان و حداقل یک سؤال منتشرشده از بانک سؤال الزامی است.']);
    $type=($body['activity_type']??'practice')==='assignment'?'assignment':'practice';
    $due=trim((string)($body['due_at']??''))?:null;
    if($due!==null){$due=str_replace('T',' ',$due);if(strlen($due)===16)$due.=':00';}
    $status=in_array(($body['status']??'draft'),['draft','published','closed'],true)?$body['status']:'draft';
    $pdo=app_db();$pdo->beginTransaction();
    try{
      $pdo->prepare('INSERT INTO learning_activities(activity_type,title,description,subject,level,track_id,instructions,due_at,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)')
       ->execute([$type,$title,trim((string)($body['description']??''))?:null,trim((string)($body['subject']??''))?:null,trim((string)($body['level']??''))?:null,!empty($body['track_id'])?(int)$body['track_id']:null,trim((string)($body['instructions']??''))?:null,$due,$status,(int)$admin['id']]);
      $activityId=(int)$pdo->lastInsertId();
      $valid=$pdo->prepare('SELECT id FROM question_bank WHERE id=? AND status=\'published\'');
      $link=$pdo->prepare('INSERT INTO activity_questions(activity_id,question_id,position) VALUES(?,?,?)');
      foreach($questionIds as $index=>$qid){$valid->execute([$qid]);if(!$valid->fetchColumn())throw new InvalidArgumentException('تمام سؤال‌ها باید ابتدا در بانک سؤال منتشر شوند.');$link->execute([$activityId,$qid,$index+1]);}
      $targetIds=array_values(array_unique(array_filter(array_map('intval',(array)($body['target_student_ids']??[])),static fn($id)=>$id>0)));
      if(!empty($body['target_student_id']))$targetIds[]=(int)$body['target_student_id'];
      $targetIds=array_values(array_unique($targetIds));
      if(!$targetIds){
        $level=trim((string)($body['level']??''));
        $query=$level!==''?$pdo->prepare("SELECT id FROM students WHERE status='active' AND level=?"): $pdo->query("SELECT id FROM students WHERE status='active'");
        if($level!=='')$query->execute([$level]);
        if($level==='' && !empty($body['track_id'])) { $query=$pdo->prepare("SELECT id FROM students WHERE status='active' AND track_id=?"); $query->execute([(int)$body['track_id']]); }
        $targetIds=array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
      }
      if(count($targetIds)>10000)throw new InvalidArgumentException('تعداد دانش‌آموزان هدف بیش از حد مجاز است.');
      $assign=$pdo->prepare('INSERT IGNORE INTO student_activity_assignments(activity_id,student_id) VALUES(?,?)');
      $studentCheck=$pdo->prepare("SELECT id FROM students WHERE id=? AND status='active'");
      foreach($targetIds as $sid){$studentCheck->execute([$sid]);if($studentCheck->fetchColumn())$assign->execute([$activityId,$sid]);}
      $pdo->commit();
    }catch(Throwable $e){
      if($pdo->inTransaction())$pdo->rollBack();
      if($e instanceof InvalidArgumentException)app_json(422,['error'=>$e->getMessage()]);
      throw $e;
    }
    app_json(201,['ok'=>true,'id'=>$activityId,'assigned_students'=>count($targetIds)]);
}

if ($method === 'POST' && preg_match('#^/platform/activities/([1-9][0-9]*)/status$#',$path,$m)) {
    $id=(int)$m[1];$body=app_request_json();$status=(string)($body['status']??'');
    if(!in_array($status,['draft','published','closed'],true))app_json(422,['error'=>'وضعیت تمرین معتبر نیست.']);
    app_db()->prepare('UPDATE learning_activities SET status=? WHERE id=?')->execute([$status,$id]);
    app_json(200,['ok'=>true]);
}

if ($method === 'GET' && $path === '/platform/activity-submissions') {
    $rows=app_db()->query(
      'SELECT sub.id,sub.activity_id,sub.student_id,sub.submitted_at,sub.score,sub.total_points,sub.status,
       a.title activity_title,s.name student_name,s.username,
       (SELECT COUNT(*) FROM activity_answers aa WHERE aa.submission_id=sub.id) answered_count,
       (SELECT COUNT(*) FROM activity_questions aq WHERE aq.activity_id=sub.activity_id) question_count,
       (SELECT COUNT(*) FROM activity_answers aa WHERE aa.submission_id=sub.id AND aa.is_correct IS NULL) pending_review
       FROM activity_submissions sub JOIN learning_activities a ON a.id=sub.activity_id
       JOIN students s ON s.id=sub.student_id ORDER BY sub.id DESC LIMIT 500'
    )->fetchAll();
    app_json(200,['rows'=>$rows]);
}

if ($method === 'GET' && preg_match('#^/platform/activity-submissions/([1-9][0-9]*)$#',$path,$m)) {
    $id=(int)$m[1];
    $head=app_db()->prepare('SELECT sub.*,a.title activity_title,s.name student_name,s.username FROM activity_submissions sub JOIN learning_activities a ON a.id=sub.activity_id JOIN students s ON s.id=sub.student_id WHERE sub.id=?');
    $head->execute([$id]);$submission=$head->fetch();if(!$submission)app_json(404,['error'=>'پاسخ تمرین پیدا نشد.']);
    $rows=app_db()->prepare('SELECT aa.id,q.question_text,q.question_type,q.options_json,q.answer_json correct_answer,aa.answer_json student_answer,aa.is_correct,aa.awarded_points,aa.teacher_feedback,aq.points FROM activity_answers aa JOIN question_bank q ON q.id=aa.question_id JOIN activity_questions aq ON aq.question_id=q.id AND aq.activity_id=? WHERE aa.submission_id=? ORDER BY aq.position');
    $rows->execute([(int)$submission['activity_id'],$id]);
    app_json(200,['submission'=>$submission,'answers'=>$rows->fetchAll()]);
}

if ($method === 'POST' && preg_match('#^/platform/activity-answers/([1-9][0-9]*)/grade$#',$path,$m)) {
    $answerId=(int)$m[1];$body=app_request_json();$points=max(0,(float)($body['awarded_points']??0));$feedback=trim((string)($body['teacher_feedback']??''));
    $pdo=app_db();$stmt=$pdo->prepare('SELECT submission_id,question_id FROM activity_answers WHERE id=?');$stmt->execute([$answerId]);$answer=$stmt->fetch();if(!$answer)app_json(404,['error'=>'پاسخ سؤال پیدا نشد.']);
    $maxStmt=$pdo->prepare('SELECT aq.points,aq.activity_id FROM activity_questions aq JOIN activity_submissions sub ON sub.activity_id=aq.activity_id WHERE aq.question_id=? AND sub.id=?');
    $maxStmt->execute([(int)$answer['question_id'],(int)$answer['submission_id']]);$max=$maxStmt->fetch();
    if(!$max||$points>(float)$max['points'])app_json(422,['error'=>'نمره از بارم سؤال بیشتر است.']);
    $pdo->prepare('UPDATE activity_answers SET awarded_points=?,teacher_feedback=? WHERE id=?')->execute([$points,$feedback?:null,$answerId]);
    $sum=$pdo->prepare('SELECT COALESCE(SUM(awarded_points),0) FROM activity_answers WHERE submission_id=?');$sum->execute([(int)$answer['submission_id']]);$score=(float)$sum->fetchColumn();
    $total=$pdo->prepare('SELECT COALESCE(SUM(points),0) FROM activity_questions WHERE activity_id=?');$total->execute([(int)$max['activity_id']]);$totalPoints=(float)$total->fetchColumn();
    $pending=$pdo->prepare('SELECT COUNT(*) FROM activity_answers WHERE submission_id=? AND is_correct IS NULL AND awarded_points IS NULL');
    $pending->execute([(int)$answer['submission_id']]);
    $reviewStatus=(int)$pending->fetchColumn()===0?'reviewed':'submitted';
    $pdo->prepare('UPDATE activity_submissions SET score=?,total_points=?,status=? WHERE id=?')->execute([$score,$totalPoints,$reviewStatus,(int)$answer['submission_id']]);
    if($reviewStatus==='reviewed') {
      $assignment=$pdo->prepare('SELECT activity_id,student_id FROM activity_submissions WHERE id=?');
      $assignment->execute([(int)$answer['submission_id']]);
      $assignmentRow=$assignment->fetch();
      if($assignmentRow) app_db()->prepare("UPDATE student_activity_assignments SET status='reviewed' WHERE activity_id=? AND student_id=?")->execute([(int)$assignmentRow['activity_id'],(int)$assignmentRow['student_id']]);
    }
    app_json(200,['ok'=>true,'score'=>$score,'total_points'=>$totalPoints]);
}

if ($method === 'GET' && $path === '/platform/settings') {
    $row = app_db()->query('SELECT api_base_url,custom_font_filename,updated_at FROM admin_ui_settings WHERE id=1')->fetch() ?: [];
    app_json(200, [
        'api_base_url' => $row['api_base_url'] ?? 'https://api.aiparseh.ir/api',
        'custom_font_filename' => $row['custom_font_filename'] ?? null,
        'font_configured' => !empty($row['custom_font_filename']),
        'updated_at' => $row['updated_at'] ?? null,
    ]);
}

if ($method === 'POST' && $path === '/platform/settings') {
    $body = app_request_json();
    $url = rtrim(trim((string)($body['api_base_url'] ?? '')), '/');
    $parts = parse_url($url);
    if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) ||
        isset($parts['user']) || isset($parts['pass']) || strlen($url) > 500) {
        app_json(422, ['error' => 'آدرس API باید یک URL عمومی معتبر با HTTPS باشد.']);
    }
    $host = strtolower((string)$parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        app_json(422, ['error' => 'استفاده از میزبان محلی برای API مجاز نیست.']);
    }
    app_db()->prepare('UPDATE admin_ui_settings SET api_base_url=?,updated_by=? WHERE id=1')
        ->execute([$url, (int)$admin['id']]);
    app_json(200, ['ok' => true, 'api_base_url' => $url, 'message' => 'آدرس API ذخیره شد.']);
}

if ($method === 'POST' && $path === '/settings/font') {
    if (empty($_FILES['font']) || !is_array($_FILES['font']) || (int)$_FILES['font']['error'] !== UPLOAD_ERR_OK) {
        app_json(422, ['error' => 'فایل فونت را انتخاب کنید.']);
    }
    $file = $_FILES['font'];
    if ((int)$file['size'] < 1024 || (int)$file['size'] > 8 * 1024 * 1024) {
        app_json(413, ['error' => 'حجم فونت باید بین ۱ کیلوبایت تا ۸ مگابایت باشد.']);
    }
    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $formats = ['woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype'];
    if (!isset($formats[$extension]) || !is_uploaded_file((string)$file['tmp_name'])) {
        app_json(415, ['error' => 'فرمت مجاز فونت: WOFF2، WOFF، TTF یا OTF است.']);
    }
    $directory = __DIR__ . '/assets/fonts';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        app_json(500, ['error' => 'ساخت پوشه فونت روی سرور ممکن نشد.']);
    }
    $filename = 'custom-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        app_json(500, ['error' => 'ذخیره فونت روی سرور انجام نشد.']);
    }
    chmod($target, 0640);
    $fontUrl = '/assets/fonts/' . $filename;
    $format = $formats[$extension];
    $css = "@font-face{font-family:'ParseCustom';src:url('" . $fontUrl . "') format('" . $format . "');font-display:swap;font-style:normal;font-weight:100 900;} :root{font-family:'ParseCustom',Tahoma,sans-serif;} html,body,button,input,textarea,select{font-family:'ParseCustom',Tahoma,sans-serif!important;}";
    file_put_contents($directory . '/custom-font.css', $css, LOCK_EX);
    $pdo = app_db();
    $old = $pdo->query('SELECT custom_font_filename FROM admin_ui_settings WHERE id=1')->fetchColumn();
    $pdo->prepare('UPDATE admin_ui_settings SET custom_font_filename=?,updated_by=? WHERE id=1')
        ->execute([$filename, (int)$admin['id']]);
    if (is_string($old) && $old !== '' && $old !== $filename) {
        $oldPath = $directory . '/' . basename($old);
        if (is_file($oldPath)) @unlink($oldPath);
    }
    app_json(200, ['ok' => true, 'filename' => $filename, 'message' => 'فونت پنل با موفقیت ذخیره شد.']);
}

if ($method === 'GET' && $path === '/platform/overview') {
    $pdo = app_db();
    $students = (int)$pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
    $activeStudents = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status='active'")->fetchColumn();
    $onlineStudents = (int)$pdo->query('SELECT COUNT(*) FROM students WHERE last_seen_at >= (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)')->fetchColumn();
    $resources = (int)$pdo->query('SELECT COUNT(*) FROM learning_resources')->fetchColumn();
    $questions = (int)$pdo->query('SELECT COUNT(*) FROM question_bank')->fetchColumn();
    $exams = (int)$pdo->query('SELECT COUNT(*) FROM exams')->fetchColumn();
    $attempts = (int)$pdo->query('SELECT COUNT(*) FROM exam_attempts')->fetchColumn();
    $submitted = (int)$pdo->query("SELECT COUNT(*) FROM exam_attempts WHERE status IN ('submitted','reviewed')")->fetchColumn();
    app_json(200, compact('students','activeStudents','onlineStudents','resources','questions','exams','attempts','submitted'));
}

if ($method === 'GET' && $path === '/platform/students') {
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
    $offset = max(0, min(1000000, (int)($_GET['offset'] ?? 0)));
    $where = [];
    $params = [];
    if ($q !== '') {
        $where[] = '(s.username LIKE ? OR s.name LIKE ? OR s.phone LIKE ? OR s.student_code LIKE ? OR s.email LIKE ?)';
        $term = '%' . $q . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }
    if (in_array($status, ['active','disabled','pending'], true)) {
        $where[] = 's.status=?'; $params[] = $status;
    }
    $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $stmt = app_db()->prepare(
        'SELECT s.id,s.username,s.student_code,s.phone,s.name,s.email,s.status,s.level,s.group_name,s.track_id,t.track_title,t.course_title,t.level_code track_level,s.last_seen_at,s.last_seen_platform,s.created_at
         FROM students s LEFT JOIN learning_tracks t ON t.id=s.track_id' . $sqlWhere . ' ORDER BY s.id DESC LIMIT ? OFFSET ?'
    );
    foreach ($params as $i => $value) $stmt->bindValue($i + 1, $value);
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    app_json(200, ['rows' => $stmt->fetchAll(), 'limit' => $limit, 'offset' => $offset]);
}

if ($method === 'POST' && $path === '/platform/students') {
    $body=app_request_json();
    $username=trim((string)($body['username']??''));
    $name=trim((string)($body['name']??''));
    $password=(string)($body['password']??'');
    $phone=trim((string)($body['phone']??''));
    $code=trim((string)($body['student_code']??''));
    $email=trim((string)($body['email']??''));
    $trackId=!empty($body['track_id'])?(int)$body['track_id']:null;
    $studentStatus=(string)($body['status']??'active');
    if(!in_array($studentStatus,['active','disabled','pending'],true)) $studentStatus='active';

    if(!preg_match('/^[a-zA-Z0-9_.-]{3,100}$/',$username)||$name===''||strlen($password)<8||mb_strlen($name)>200)
        app_json(422,['error'=>'نام، نام کاربری معتبر و رمز عبور حداقل ۸ کاراکتری الزامی است.']);
    if($phone!==''&&!preg_match('/^[0-9+() -]{8,20}$/',$phone))
        app_json(422,['error'=>'شماره تماس معتبر نیست.']);

    $pdo=app_db();
    // Report conflicts before the insert so the 409 message accurately identifies the field.
    $checks=[
        ['username',$username,'نام کاربری'],
        ['phone',$phone,'شماره تماس'],
        ['student_code',$code,'کد دانش‌آموزی'],
    ];
    foreach($checks as [$column,$value,$label]){
        if($value==='') continue;
        $check=$pdo->prepare("SELECT id FROM students WHERE {$column}=? LIMIT 1");
        $check->execute([$value]);
        if($check->fetchColumn()) app_json(409,['error'=>"{$label} قبلاً ثبت شده است."]);
    }
    if($trackId!==null){
        $check=$pdo->prepare('SELECT id FROM learning_tracks WHERE id=? AND active=1');
        $check->execute([$trackId]);
        if(!$check->fetchColumn()) app_json(422,['error'=>'مسیر آموزشی انتخاب‌شده معتبر نیست یا غیرفعال است.']);
    }

    try{
      $pdo->beginTransaction();
      $hash=password_hash($password,PASSWORD_DEFAULT);
      $stmt=$pdo->prepare('INSERT INTO students(username,student_code,phone,name,email,password_hash,status,level,group_name,track_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
      $stmt->execute([
        $username,$code!==''?$code:null,$phone!==''?$phone:null,$name,$email!==''?$email:null,$hash,
        $studentStatus,
        trim((string)($body['level']??''))?:null,trim((string)($body['group_name']??''))?:null,$trackId
      ]);
      $studentId=(int)$pdo->lastInsertId();
      // Mobile authentication uses students + student_auth_tokens, not the admin users table.
      $pdo->commit();
    }catch(PDOException $e){
      if($pdo->inTransaction())$pdo->rollBack();
      error_log('Parse student create failed [SQLSTATE '.$e->getCode().']: '.$e->getMessage());
      if((string)$e->getCode()==='23000') app_json(409,['error'=>'اطلاعات دانش‌آموز با رکورد دیگری تداخل دارد. نام کاربری، شماره تماس یا کد دانش‌آموزی را بررسی کنید.']);
      throw $e;
    }catch(Throwable $e){
      if($pdo->inTransaction())$pdo->rollBack();
      error_log('Parse student create failed: '.$e->getMessage());
      throw $e;
    }
    $audit=$pdo->prepare('INSERT INTO admin_audit_log(admin_user_id,action,target_type,target_id,ip_address) VALUES(?,?,?,?,?)');
    $audit->execute([(int)$admin['id'],'student.create','student',$studentId,substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)]);
    app_json(201,['ok'=>true,'id'=>$studentId]);
}

if ($method === 'POST' && preg_match('#^/platform/students/([1-9][0-9]*)/status$#', $path, $m)) {
    $id = (int)$m[1]; $body = app_request_json();
    $status = (string)($body['status'] ?? '');
    if (!in_array($status, ['active','disabled','pending'], true)) app_json(422, ['error' => 'وضعیت انتخاب‌شده معتبر نیست.']);
    app_db()->prepare('UPDATE students SET status=? WHERE id=?')->execute([$status,$id]);
    if ($status !== 'active') app_db()->prepare('DELETE FROM student_auth_tokens WHERE student_id=?')->execute([$id]);
    app_json(200, ['ok' => true, 'status' => $status]);
}

if ($method === 'POST' && preg_match('#^/platform/students/([1-9][0-9]*)/password$#', $path, $m)) {
    $id = (int)$m[1]; $body = app_request_json(); $password = (string)($body['password'] ?? '');
    if (strlen($password) < 8) app_json(422, ['error' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.']);
    $pdo=app_db();$hash=password_hash($password,PASSWORD_DEFAULT);
    $stmt=$pdo->prepare('UPDATE students SET password_hash=? WHERE id=?');$stmt->execute([$hash,$id]);
    if ($stmt->rowCount() < 1) app_json(404, ['error' => 'دانش‌آموز پیدا نشد.']);
    app_db()->prepare('DELETE FROM student_auth_tokens WHERE student_id=?')->execute([$id]);
    app_json(200, ['ok' => true, 'message' => 'رمز دانش‌آموز تغییر کرد و نشست‌های قبلی باطل شدند.']);
}

if ($method === 'POST' && preg_match('#^/platform/students/([1-9][0-9]*)$#', $path, $m)) {
    $id = (int)$m[1]; $body = app_request_json();
    $allowed = ['name','phone','email','student_code','level','group_name','track_id'];
    $sets = []; $values = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $sets[] = $field . '=?';
            $values[] = trim((string)$body[$field]) !== '' ? trim((string)$body[$field]) : null;
        }
    }
    if (!$sets) app_json(422, ['error' => 'هیچ فیلد قابل تغییری ارسال نشده است.']);
    $values[] = $id;
    app_db()->prepare('UPDATE students SET ' . implode(',', $sets) . ' WHERE id=?')->execute($values);
    app_json(200, ['ok' => true]);
}

if ($method === 'GET' && $path === '/platform/resources') {
    $rows = app_db()->query(
        'SELECT r.id,r.resource_type,r.title,r.description,r.subject,r.level,r.track_id,t.track_title,t.course_title,r.content_text,r.file_url,r.original_filename,r.published,r.created_at,r.updated_at
         FROM learning_resources r LEFT JOIN learning_tracks t ON t.id=r.track_id ORDER BY r.id DESC'
    )->fetchAll();
    app_json(200, ['rows' => $rows]);
}

if ($method === 'POST' && $path === '/platform/resources') {
    $isUpload = isset($_FILES['resource_file']) && (int)($_FILES['resource_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    $body = $isUpload ? $_POST : app_request_json();
    $type = (string)($body['resource_type'] ?? 'handout');
    if (!in_array($type, ['book','handout','lesson','course','source'], true)) $type = 'handout';
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 255) app_json(422, ['error' => 'عنوان منبع آموزشی الزامی است.']);
    $content = trim((string)($body['content_text'] ?? ''));
    $fileUrl = trim((string)($body['file_url'] ?? '')) ?: null;
    $storedPath = null; $originalName = null;
    if ($isUpload) {
        $file = $_FILES['resource_file'];
        if ((int)$file['size'] > 20 * 1024 * 1024) app_json(413, ['error' => 'حداکثر حجم فایل ۲۰ مگابایت است.']);
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['txt','md','pdf'], true) || !is_uploaded_file((string)$file['tmp_name'])) {
            app_json(415, ['error' => 'فرمت مجاز فایل: TXT، MD یا PDF است.']);
        }
        $storage = '/var/lib/parse-admin/resources';
        if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) app_json(500, ['error' => 'پوشه منابع روی سرور ساخته نشد.']);        $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
        $storedPath = $storage . '/' . $safeName;
        if (!move_uploaded_file((string)$file['tmp_name'], $storedPath)) app_json(500, ['error' => 'ذخیره فایل روی سرور انجام نشد.']);
        chmod($storedPath, 0640);
        $originalName = basename((string)$file['name']);
        if (in_array($extension, ['txt','md'], true)) {
            $rawText = file_get_contents($storedPath);
            if (!is_string($rawText) || !mb_check_encoding($rawText, 'UTF-8')) app_json(422, ['error' => 'فایل متنی باید با UTF-8 ذخیره شده باشد.']);
            $content = mb_substr($rawText, 0, 600000);
        }
        $fileUrl = '/api/resources/0/download';
    }
    $stmt = app_db()->prepare(
        'INSERT INTO learning_resources(resource_type,title,description,subject,level,track_id,content_text,file_url,storage_path,original_filename,published,created_by)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $type,$title,trim((string)($body['description'] ?? '')) ?: null,
        trim((string)($body['subject'] ?? '')) ?: null,
        trim((string)($body['level'] ?? '')) ?: null,!empty($body['track_id']) ? (int)$body['track_id'] : null,
        $content !== '' ? $content : null,$fileUrl,$storedPath,$originalName,
        !empty($body['published']) ? 1 : 0,(int)$admin['id']
    ]);
    $id = (int)app_db()->lastInsertId();
    if ($storedPath) {
        app_db()->prepare('UPDATE learning_resources SET file_url=? WHERE id=?')->execute(['/api/resources/'.$id.'/download',$id]);
    }
    app_json(201, ['ok' => true, 'id' => $id, 'file_url' => $storedPath ? '/api/resources/'.$id.'/download' : $fileUrl]);
}

if ($method === 'POST' && preg_match('#^/platform/resources/([1-9][0-9]*)/publish$#', $path, $m)) {
    $id = (int)$m[1]; $body = app_request_json();
    $published = !empty($body['published']) ? 1 : 0;
    app_db()->prepare('UPDATE learning_resources SET published=? WHERE id=?')->execute([$published,$id]);
    app_json(200, ['ok' => true, 'published' => (bool)$published]);
}

if ($method === 'DELETE' && preg_match('#^/platform/resources/([1-9][0-9]*)$#', $path, $m)) {
    $id = (int)$m[1];
    $stmt = app_db()->prepare('SELECT storage_path FROM learning_resources WHERE id=?');
    $stmt->execute([$id]); $stored = $stmt->fetchColumn();
    app_db()->prepare('DELETE FROM learning_resources WHERE id=?')->execute([$id]);
    if (is_string($stored) && str_starts_with($stored, '/var/lib/parse-admin/resources/') && is_file($stored)) @unlink($stored);
    app_json(200, ['deleted' => 1]);
}

if ($method === 'GET' && $path === '/platform/questions') {
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));
    $stmt = app_db()->prepare(
        'SELECT q.id,q.resource_id,q.target_student_id,q.track_id,t.track_title,s.name AS student_name,q.subject,q.level,q.skill,q.question_type,
                q.question_text,q.options_json,q.answer_json,q.explanation,q.difficulty,q.points,q.status,q.created_at
         FROM question_bank q LEFT JOIN students s ON s.id=q.target_student_id LEFT JOIN learning_tracks t ON t.id=q.track_id
         WHERE (?=\'\' OR q.question_text LIKE ? OR q.subject LIKE ?)
         ORDER BY q.id DESC LIMIT ?'
    );
    $term = '%' . $q . '%';
    $stmt->bindValue(1,$q); $stmt->bindValue(2,$term); $stmt->bindValue(3,$term); $stmt->bindValue(4,$limit,PDO::PARAM_INT);
    $stmt->execute();
    app_json(200, ['rows' => $stmt->fetchAll()]);
}

if ($method === 'POST' && $path === '/platform/questions') {
    $body = app_request_json();
    $items = $body['questions'] ?? [$body];
    if (!is_array($items) || count($items) < 1 || count($items) > 100) app_json(422, ['error' => 'بین ۱ تا ۱۰۰ سؤال ارسال کنید.']);
    $subject = trim((string)($body['subject'] ?? 'عمومی'));
    $resourceId = !empty($body['resource_id']) ? (int)$body['resource_id'] : null;
    $studentId = !empty($body['target_student_id']) ? (int)$body['target_student_id'] : null;
    $skill = in_array(($body['skill'] ?? 'general'), ['listening','speaking','reading','writing','grammar','vocabulary','general'], true) ? $body['skill'] : 'general';
    $pdo = app_db(); $pdo->beginTransaction(); $saved = [];
    try {
        foreach ($items as $item) {
            if (!is_array($item) || trim((string)($item['question_text'] ?? '')) === '') continue;
            $qType = platform_question_type((string)($item['question_type'] ?? $body['question_type'] ?? 'multiple_choice'));
            $options = $item['options'] ?? null;
            if ($qType === 'multiple_choice') {
                if (!is_array($options) || count($options) < 2) throw new InvalidArgumentException('برای سؤال چندگزینه‌ای حداقل دو گزینه لازم است.');
            } else {
                $options = is_array($options) ? $options : null;
            }
            $difficulty = in_array(($item['difficulty'] ?? $body['difficulty'] ?? 'medium'), ['easy','medium','hard'], true)
                ? ($item['difficulty'] ?? $body['difficulty'] ?? 'medium') : 'medium';
            $stmt = $pdo->prepare(
                'INSERT INTO question_bank(resource_id,target_student_id,track_id,subject,level,skill,question_type,question_text,options_json,answer_json,explanation,difficulty,points,tags_json,status,created_by)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $resourceId,$studentId,!empty($body['track_id']) ? (int)$body['track_id'] : null,$subject,
                trim((string)($item['level'] ?? $body['level'] ?? '')) ?: null,$skill,$qType,
                trim((string)$item['question_text']),platform_json_value($options),
                platform_json_value($item['correct_answer'] ?? $item['answer'] ?? null),
                trim((string)($item['explanation'] ?? '')) ?: null,$difficulty,
                max(0.25,min(100,(float)($item['points'] ?? 1))),
                platform_json_value($item['tags'] ?? []),$body['status'] ?? 'draft',(int)$admin['id']
            ]);
            $saved[] = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof InvalidArgumentException) app_json(422, ['error' => $e->getMessage()]);
        throw $e;
    }
    app_json(201, ['saved' => count($saved), 'ids' => $saved]);
}

if ($method === 'POST' && $path === '/platform/questions/generate') {
    $body = app_request_json();
    $model = trim((string)($body['model'] ?? ''));
    $resourceId = (int)($body['resource_id'] ?? 0);
    $count = min(40, max(1, (int)($body['count'] ?? 10)));
    $qType = platform_question_type((string)($body['question_type'] ?? 'multiple_choice'));
    $subject = trim((string)($body['subject'] ?? 'عمومی'));
    $level = trim((string)($body['level'] ?? ''));
    $targetStudentId = !empty($body['target_student_id']) ? (int)$body['target_student_id'] : null;
    if ($model === '' || $resourceId < 1) app_json(422, ['error' => 'مدل و فایل منبع آموزشی را انتخاب کنید.']);
    $sourceStmt = app_db()->prepare('SELECT id,title,content_text FROM learning_resources WHERE id=? LIMIT 1');
    $sourceStmt->execute([$resourceId]); $source = $sourceStmt->fetch();
    if (!$source || trim((string)$source['content_text']) === '') app_json(422, ['error' => 'منبع انتخابی متن قابل پردازش ندارد؛ برای تولید AI فایل TXT یا MD بارگذاری کنید.']);
    $sourceText = mb_substr((string)$source['content_text'],0,60000);
    $typeRules = [
      'multiple_choice' => 'هر سؤال دقیقاً چهار گزینه مستقل با شناسه‌های A,B,C,D داشته باشد؛ correct_answer فقط شناسه گزینه صحیح باشد.',
      'written' => 'سؤال تشریحی باشد؛ options آرایه خالی و correct_answer پاسخ نمونه با معیار نمره‌دهی باشد.',
      'true_false' => 'گزاره درست/نادرست باشد؛ options شامل درست و نادرست و correct_answer یکی از همین دو مقدار باشد.',
      'fill_blank' => 'جای خالی در جمله قرار بده؛ options خالی و correct_answer متن کوتاه پاسخ باشد.',
      'matching' => 'دو ستون همتاسازی بساز؛ options شامل left و right و correct_answer آرایه تطبیق‌ها باشد.',
      'ordering' => 'مراحل را به‌هم‌ریخته نمایش بده؛ options آرایه آیتم‌ها و correct_answer ترتیب صحیح شناسه‌ها باشد.',
      'listening' => 'سؤال مهارت شنیداری و متناسب با متن منبع باشد؛ برای صوت، متن محرک و دستور اجرا را در question_text مشخص کن.',
      'speaking' => 'پرسش گفتاری و معیارهای ارزیابی را در explanation بنویس؛ options خالی باشد.'
    ];
    $prompt = "از محتوای منبع زیر فقط سؤال آموزشی بساز. موضوع: {$subject}. سطح: {$level}. دشواری: ".(string)($body['difficulty'] ?? 'medium').". نوع: {$qType}. تعداد: {$count}. مهارت: ".(string)($body['skill'] ?? 'general').".\n"
      .$typeRules[$qType]."\n"
      ."فقط JSON معتبر و بدون Markdown برگردان با ساختار {\\\"questions\\\":[{\\\"question_text\\\":\\\"...\\\",\\\"question_type\\\":\\\"{$qType}\\\",\\\"options\\\":[],\\\"correct_answer\\\":\\\"...\\\",\\\"explanation\\\":\\\"...\\\",\\\"difficulty\\\":\\\"easy|medium|hard\\\",\\\"points\\\":1}]}. متن سؤال و گزینه‌ها را فارسی و روشن بنویس. هیچ دانسته‌ای خارج از منبع را به منبع نسبت نده.\\n\\nمنبع: ".$sourceText;
    $system = 'You are an expert assessment designer. Return valid JSON only. Follow the requested question type exactly. Never add Markdown fences.';
    $call = ai_paasta_call($admin,'structured_question_generation','POST','/chat/completions',[
      'model'=>$model,'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$prompt]],
      'max_tokens'=>min(8192,max(1200,$count*500)),'temperature'=>0.35,
      'response_format'=>['type'=>'json_object']
    ],null,240);
    $output = ai_extract_text($call['data']);
    $decoded = json_decode($output,true);
    if (!is_array($decoded)) {
        $start = strpos($output,'{'); $end = strrpos($output,'}');
        if ($start !== false && $end > $start) $decoded = json_decode(substr($output,$start,$end-$start+1),true);
    }
    $items = is_array($decoded) ? ($decoded['questions'] ?? $decoded) : null;
    if (!is_array($items)) app_json(502,['error'=>'خروجی مدل JSON معتبر برای بانک سؤال نبود.','raw_output'=>mb_substr($output,0,4000)]);
    $saveBody = [
      'questions'=>array_slice($items,0,$count),'subject'=>$subject,'level'=>$level,
      'skill'=>$body['skill'] ?? 'general','resource_id'=>$resourceId,'target_student_id'=>$targetStudentId,'status'=>'draft'
    ];
    // Save with the same validation path used for manually authored questions.
    $fake = $saveBody;
    $pdo = app_db(); $pdo->beginTransaction(); $saved = [];
    try {
      foreach ($fake['questions'] as $item) {
        if (!is_array($item) || trim((string)($item['question_text'] ?? '')) === '') continue;
        $itemType = platform_question_type((string)($item['question_type'] ?? $qType));
        $options = $item['options'] ?? null;
        if ($itemType === 'multiple_choice' && (!is_array($options) || count($options) !== 4)) {
          throw new InvalidArgumentException('مدل برای سؤال چندگزینه‌ای دقیقاً چهار گزینه تولید نکرد.');
        }
        $stmt=$pdo->prepare('INSERT INTO question_bank(resource_id,target_student_id,track_id,subject,level,skill,question_type,question_text,options_json,answer_json,explanation,difficulty,points,tags_json,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([
          $resourceId,$targetStudentId,!empty($body['track_id']) ? (int)$body['track_id'] : null,$subject,$level,
          in_array(($body['skill'] ?? 'general'),['listening','speaking','reading','writing','grammar','vocabulary','general'],true)?$body['skill']:'general',
          $itemType,trim((string)$item['question_text']),platform_json_value($options),
          platform_json_value($item['correct_answer'] ?? null),trim((string)($item['explanation'] ?? '')) ?: null,
          in_array(($item['difficulty'] ?? ''),['easy','medium','hard'],true)?$item['difficulty']:'medium',
          max(0.25,min(100,(float)($item['points'] ?? 1))),platform_json_value($item['tags'] ?? []),'draft',(int)$admin['id']
        ]);
        $saved[] = (int)$pdo->lastInsertId();
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($e instanceof InvalidArgumentException) app_json(502,['error'=>$e->getMessage(),'raw_output'=>mb_substr($output,0,4000)]);
      throw $e;
    }
    app_json(200,['saved'=>count($saved),'ids'=>$saved,'questions'=>$items,'output'=>$output,'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && preg_match('#^/platform/questions/([1-9][0-9]*)/status$#', $path, $m)) {
    $id=(int)$m[1];$body=app_request_json();$status=(string)($body['status']??'');
    if(!in_array($status,['draft','published','archived'],true))app_json(422,['error'=>'وضعیت سؤال معتبر نیست.']);
    app_db()->prepare('UPDATE question_bank SET status=? WHERE id=?')->execute([$status,$id]);
    app_json(200,['ok'=>true,'status'=>$status]);
}

if ($method === 'DELETE' && preg_match('#^/platform/questions/([1-9][0-9]*)$#', $path, $m)) {
    app_db()->prepare('DELETE FROM question_bank WHERE id=?')->execute([(int)$m[1]]);
    app_json(200,['deleted'=>1]);
}

if ($method === 'GET' && $path === '/platform/exams') {
    $rows=app_db()->query(
      'SELECT e.id,e.title,e.subject,e.level,e.track_id,e.duration_minutes,e.starts_at,e.ends_at,e.status,e.passing_score,
              s.name AS student_name,
              (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id=e.id) AS question_count,
              (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id=e.id) AS attempt_count
       FROM exams e LEFT JOIN students s ON s.id=e.target_student_id
       ORDER BY e.id DESC'
    )->fetchAll();
    app_json(200,['rows'=>$rows]);
}

if ($method === 'POST' && $path === '/platform/exams') {
    $body=app_request_json();
    $title=trim((string)($body['title']??''));
    $questionIds=array_values(array_unique(array_filter(array_map('intval',(array)($body['question_ids']??[])),static fn($id)=>$id>0)));
    $duration=min(600,max(1,(int)($body['duration_minutes']??30)));
    if($title===''||count($questionIds)<1||count($questionIds)>200)app_json(422,['error'=>'عنوان آزمون و حداقل یک سؤال از بانک سؤال الزامی است.']);
    $start=trim((string)($body['starts_at']??''))?:null;$end=trim((string)($body['ends_at']??''))?:null;
    if($start!==null&&$end!==null&&strtotime($end)<=strtotime($start))app_json(422,['error'=>'زمان پایان باید بعد از زمان شروع باشد.']);
    $status=in_array(($body['status']??'draft'),['draft','published','closed'],true)?$body['status']:'draft';
    $pdo=app_db();$pdo->beginTransaction();
    try{
      $pdo->prepare('INSERT INTO exams(title,description,subject,level,track_id,duration_minutes,starts_at,ends_at,target_student_id,shuffle_questions,shuffle_options,show_result,passing_score,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$title,trim((string)($body['description']??''))?:null,trim((string)($body['subject']??''))?:null,trim((string)($body['level']??''))?:null,!empty($body['track_id'])?(int)$body['track_id']:null,$duration,$start,$end,!empty($body['target_student_id'])?(int)$body['target_student_id']:null,!empty($body['shuffle_questions'])?1:0,!empty($body['shuffle_options'])?1:0,!empty($body['show_result'])?1:0,max(0,min(100,(float)($body['passing_score']??50))),$status,(int)$admin['id']]);
      $examId=(int)$pdo->lastInsertId();
      $validStmt=$pdo->prepare('SELECT id FROM question_bank WHERE id=? AND status=\'published\'');
      $link=$pdo->prepare('INSERT INTO exam_questions(exam_id,question_id,position,points) VALUES(?,?,?,?)');
      foreach($questionIds as $position=>$qid){
        $validStmt->execute([$qid]);if(!$validStmt->fetchColumn())throw new InvalidArgumentException('یکی از سؤال‌های انتخاب‌شده در بانک سؤال وجود ندارد.');
        $link->execute([$examId,$qid,$position+1,max(0.25,(float)($body['points'][$qid]??1))]);
      }
      $pdo->commit();
    }catch(Throwable $e){
      if($pdo->inTransaction())$pdo->rollBack();
      if($e instanceof InvalidArgumentException)app_json(422,['error'=>$e->getMessage()]);
      throw $e;
    }
    app_json(201,['ok'=>true,'id'=>$examId]);
}

if ($method === 'POST' && preg_match('#^/platform/exams/([1-9][0-9]*)/status$#',$path,$m)) {
    $id=(int)$m[1];$body=app_request_json();$status=(string)($body['status']??'');
    if(!in_array($status,['draft','published','closed'],true))app_json(422,['error'=>'وضعیت آزمون معتبر نیست.']);
    app_db()->prepare('UPDATE exams SET status=? WHERE id=?')->execute([$status,$id]);
    app_json(200,['ok'=>true]);
}

if ($method === 'GET' && $path === '/platform/attempts') {
    $rows=app_db()->query(
      'SELECT a.id,a.exam_id,a.student_id,a.started_at,a.expires_at,a.submitted_at,a.score,a.total_points,a.status,
              e.title AS exam_title,s.name AS student_name,s.username,
              (SELECT COUNT(*) FROM exam_answers ans WHERE ans.attempt_id=a.id) AS answered_count,
              (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id=a.exam_id) AS question_count,
              (SELECT COUNT(*) FROM exam_answers ans WHERE ans.attempt_id=a.id AND ans.is_correct IS NULL AND ans.awarded_points IS NULL) AS pending_review
       FROM exam_attempts a INNER JOIN exams e ON e.id=a.exam_id INNER JOIN students s ON s.id=a.student_id
       ORDER BY a.id DESC LIMIT 500'
    )->fetchAll();
    app_json(200,['rows'=>$rows]);
}

if ($method === 'GET' && preg_match('#^/platform/attempts/([1-9][0-9]*)$#',$path,$m)) {
    $id=(int)$m[1];
    $head=app_db()->prepare(
      'SELECT a.*,e.title AS exam_title,s.name AS student_name,s.username
       FROM exam_attempts a JOIN exams e ON e.id=a.exam_id JOIN students s ON s.id=a.student_id WHERE a.id=?'
    );
    $head->execute([$id]);$attempt=$head->fetch();if(!$attempt)app_json(404,['error'=>'پاسخ‌نامه پیدا نشد.']);
    $answers=app_db()->prepare(
      'SELECT q.id AS question_id,q.question_text,q.question_type,q.options_json,q.answer_json AS correct_answer,
              a.answer_json AS student_answer,a.is_correct,a.awarded_points,a.teacher_feedback,eq.points
       FROM exam_answers a JOIN question_bank q ON q.id=a.question_id
       JOIN exam_questions eq ON eq.exam_id=? AND eq.question_id=q.id
       WHERE a.attempt_id=? ORDER BY eq.position'
    );
    $answers->execute([(int)$attempt['exam_id'],$id]);
    app_json(200,['attempt'=>$attempt,'answers'=>$answers->fetchAll()]);
}

if ($method === 'POST' && preg_match('#^/platform/answers/([1-9][0-9]*)/grade$#',$path,$m)) {
    $answerId=(int)$m[1];$body=app_request_json();
    $points=max(0,(float)($body['awarded_points']??0));
    $feedback=trim((string)($body['teacher_feedback']??''));
    $pdo=app_db();$stmt=$pdo->prepare('SELECT attempt_id,question_id FROM exam_answers WHERE id=?');$stmt->execute([$answerId]);$answer=$stmt->fetch();
    if(!$answer)app_json(404,['error'=>'پاسخ پیدا نشد.']);
    $maxStmt=$pdo->prepare('SELECT points,exam_id FROM exam_questions WHERE question_id=? AND exam_id=(SELECT exam_id FROM exam_attempts WHERE id=?)');
    $maxStmt->execute([(int)$answer['question_id'],(int)$answer['attempt_id']]);$max=$maxStmt->fetch();
    if(!$max||$points>(float)$max['points'])app_json(422,['error'=>'نمره واردشده از بارم سؤال بیشتر است.']);
    $pdo->prepare('UPDATE exam_answers SET is_correct=NULL,awarded_points=?,teacher_feedback=? WHERE id=?')->execute([$points,$feedback?:null,$answerId]);
    $sum=$pdo->prepare('SELECT COALESCE(SUM(awarded_points),0) score FROM exam_answers WHERE attempt_id=?');
    $sum->execute([(int)$answer['attempt_id']]);$score=(float)$sum->fetchColumn();
    $total=$pdo->prepare('SELECT COALESCE(SUM(points),0) FROM exam_questions WHERE exam_id=?');$total->execute([(int)$max['exam_id']]);$totalPoints=(float)$total->fetchColumn();
    $pending=$pdo->prepare('SELECT COUNT(*) FROM exam_answers WHERE attempt_id=? AND is_correct IS NULL AND awarded_points IS NULL');
    $pending->execute([(int)$answer['attempt_id']]);
    $reviewStatus=(int)$pending->fetchColumn()===0?'reviewed':'submitted';
    $pdo->prepare('UPDATE exam_attempts SET score=?,total_points=?,status=? WHERE id=?')->execute([$score,$totalPoints,$reviewStatus,(int)$answer['attempt_id']]);
    app_json(200,['ok'=>true,'score'=>$score,'total_points'=>$totalPoints]);
}

if ($method === 'GET' && $path === '/platform/monitoring') {
    $pdo=app_db();
    $online=$pdo->query(
      'SELECT id,username,name,level,group_name,last_seen_at,last_seen_platform
       FROM students WHERE last_seen_at >= (UTC_TIMESTAMP() - INTERVAL 5 MINUTE)
       ORDER BY last_seen_at DESC LIMIT 100'
    )->fetchAll();
    $events=$pdo->query(
      'SELECT e.id,e.student_id,s.name AS student_name,e.event_type,e.route,e.platform,e.created_at
       FROM student_activity_events e JOIN students s ON s.id=e.student_id
       ORDER BY e.id DESC LIMIT 100'
    )->fetchAll();
    app_json(200,['online'=>$online,'events'=>$events]);
}

if ($method === 'GET' && preg_match('#^/resources/([1-9][0-9]*)/download$#',$path,$m)) {
    $student=parse_require_student($pdo);$id=(int)$m[1];
    $stmt=$pdo->prepare('SELECT storage_path,original_filename,published FROM learning_resources WHERE id=?');
    $stmt->execute([$id]);$file=$stmt->fetch();
    if(!$file||!(bool)$file['published']||!is_string($file['storage_path'])||!is_file($file['storage_path']))respond(404,['message'=>'فایل در دسترس نیست.']);
    parse_store_event($pdo,(int)$student['id'],'resource.download','/resources/'.$id,['resource_id'=>$id]);
    $name=preg_replace('/[^a-zA-Z0-9._-]/','_', (string)$file['original_filename']) ?: 'resource';
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.filesize($file['storage_path']));
    header('Content-Disposition: attachment; filename="'.$name.'"');
    header('X-Content-Type-Options: nosniff');
    readfile($file['storage_path']);
    exit;
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
    $recent = $pdo->query('SELECT operation,model,status_code,input_tokens,output_tokens,total_tokens,cached_input_tokens,charged_toman,latency_ms,request_id,provider_meta_json,created_at FROM ai_usage_logs ORDER BY id DESC LIMIT 12')->fetchAll();
    $users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $questions = (int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
    app_json(200, ['today'=>$today,'month'=>$month,'totals'=>$totals,'models'=>$models,'recent'=>$recent,'users'=>$users,'questions'=>$questions]);
}

if ($method === 'GET' && preg_match('#^/catalog/([a-z]+)$#', $path, $m)) ai_catalog($admin, $m[1]);

if ($method === 'GET' && $path === '/prompts') {
    $rows=app_db()->query('SELECT slug,title,generation_type,system_prompt,enabled,updated_at FROM ai_prompt_templates ORDER BY id ASC')->fetchAll();
    app_json(200,['rows'=>$rows]);
}

if ($method === 'POST' && $path === '/prompts') {
    $body=app_request_json();
    $slug=trim((string)($body['slug']??''));
    $prompt=trim((string)($body['system_prompt']??''));
    $enabled=!empty($body['enabled'])?1:0;
    if(!preg_match('/^[a-z0-9-]{2,80}$/',$slug)||$prompt===''||mb_strlen($prompt)>20000) {
        app_json(422,['error'=>'شناسه قالب یا متن دستور معتبر نیست.']);
    }
    $stmt=app_db()->prepare('UPDATE ai_prompt_templates SET system_prompt=?,enabled=? WHERE slug=?');
    $stmt->execute([$prompt,$enabled,$slug]);
    $check=app_db()->prepare('SELECT id FROM ai_prompt_templates WHERE slug=?');
    $check->execute([$slug]);
    if(!$check->fetchColumn()) app_json(404,['error'=>'قالب موردنظر پیدا نشد.']);
    app_json(200,['ok'=>true,'message'=>'قالب دستور با موفقیت ذخیره شد.']);
}

if ($method === 'GET' && $path === '/users') {
    $q=trim((string)($_GET['q']??''));
    $limit=min(200,max(1,(int)($_GET['limit']??100)));
    $offset=max(0,min(1000000,(int)($_GET['offset']??0)));
    $stmt=app_db()->prepare(
        'SELECT id,email,role,active,created_at FROM users
         WHERE (? = "" OR email LIKE ?)
         ORDER BY id DESC LIMIT ? OFFSET ?'
    );
    $term='%'.$q.'%';
    $stmt->bindValue(1,$q);
    $stmt->bindValue(2,$term);
    $stmt->bindValue(3,$limit,PDO::PARAM_INT);
    $stmt->bindValue(4,$offset,PDO::PARAM_INT);
    $stmt->execute();
    app_json(200,['rows'=>$stmt->fetchAll(),'limit'=>$limit,'offset'=>$offset]);
}

if ($method === 'POST' && preg_match('#^/users/([1-9][0-9]*)/status$#',$path,$matches)) {
    $userId=(int)$matches[1];
    if($userId===(int)$admin['id']) app_json(409,['error'=>'برای جلوگیری از قفل‌شدن پنل، امکان غیرفعال‌کردن حساب مدیر فعلی وجود ندارد.']);
    $body=app_request_json();
    if(!array_key_exists('active',$body)) app_json(422,['error'=>'وضعیت جدید مشخص نشده است.']);
    $active=!empty($body['active'])?1:0;
    $stmt=app_db()->prepare('UPDATE users SET active=? WHERE id=?');
    $stmt->execute([$active,$userId]);
    if($stmt->rowCount()>0) {
        $audit=app_db()->prepare('INSERT INTO admin_audit_log (admin_user_id,action,target_type,target_id,ip_address) VALUES (?,?,?,?,?)');
        $audit->execute([(int)$admin['id'],$active?'user.enable':'user.disable','user',$userId,substr((string)($_SERVER['REMOTE_ADDR']??'unknown'),0,45)]);
    }
    app_json(200,['ok'=>true,'active'=>(bool)$active]);
}

if ($method === 'GET' && $path === '/questions') {
    $q=trim((string)($_GET['q']??''));
    $limit=min(200,max(1,(int)($_GET['limit']??100)));
    $offset=max(0,min(1000000,(int)($_GET['offset']??0)));
    $stmt=app_db()->prepare(
        'SELECT id,question,subject,grade,source_type,created_at FROM questions
         WHERE (? = "" OR question LIKE ? OR subject LIKE ? OR grade LIKE ?)
         ORDER BY id DESC LIMIT ? OFFSET ?'
    );
    $term='%'.$q.'%';
    $stmt->bindValue(1,$q);
    $stmt->bindValue(2,$term);
    $stmt->bindValue(3,$term);
    $stmt->bindValue(4,$term);
    $stmt->bindValue(5,$limit,PDO::PARAM_INT);
    $stmt->bindValue(6,$offset,PDO::PARAM_INT);
    $stmt->execute();
    app_json(200,['rows'=>$stmt->fetchAll(),'limit'=>$limit,'offset'=>$offset]);
}

if ($method === 'DELETE' && preg_match('#^/questions/([1-9][0-9]*)$#',$path,$matches)) {
    $questionId=(int)$matches[1];
    $pdo=app_db();
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('DELETE FROM questions WHERE id=?');
        $stmt->execute([$questionId]);
        if($stmt->rowCount()>0) {
            $audit=$pdo->prepare('INSERT INTO admin_audit_log (admin_user_id,action,target_type,target_id,ip_address) VALUES (?,?,?,?,?)');
            $audit->execute([(int)$admin['id'],'question.delete','question',$questionId,substr((string)($_SERVER['REMOTE_ADDR']??'unknown'),0,45)]);
        }
        $deleted=$stmt->rowCount();
        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    app_json(200,['deleted'=>$deleted]);
}

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
        'SELECT id,operation,endpoint,model,request_id,status_code,input_tokens,output_tokens,total_tokens,cached_input_tokens,charged_toman,latency_ms,error_code,provider_meta_json,created_at
         FROM ai_usage_logs WHERE created_at >= ? AND created_at < DATE_ADD(?,INTERVAL 1 DAY)
         ORDER BY id DESC LIMIT 2000'
    );
    $stmt->execute([$from,$to]);
    $rows = $stmt->fetchAll();
    $sum = ['requests'=>count($rows),'cost'=>0.0,'tokens'=>0,'cached_tokens'=>0];
    foreach ($rows as $row) {
        $sum['cost'] += (float)($row['charged_toman'] ?? 0);
        $sum['tokens'] += (int)($row['total_tokens'] ?? 0);
        $sum['cached_tokens'] += (int)($row['cached_input_tokens'] ?? 0);
    }
    app_json(200, ['summary'=>$sum,'rows'=>$rows,'from'=>$from,'to'=>$to]);
}

if ($method === 'GET' && $path === '/usage.csv') {
    $stmt = app_db()->query(
        'SELECT created_at,operation,endpoint,model,request_id,status_code,input_tokens,output_tokens,total_tokens,cached_input_tokens,charged_toman,latency_ms,error_code,provider_meta_json
         FROM ai_usage_logs ORDER BY id DESC LIMIT 10000'
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="parse-ai-usage.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['created_at','operation','endpoint','model','request_id','status_code','input_tokens','output_tokens','total_tokens','cached_input_tokens','charged_toman','latency_ms','error_code','provider_meta_json']);
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
    $templateSlug=['question'=>'question-fa','practice'=>'practice-fa','review'=>'review-fa'][$type];
    $templateQuery=app_db()->prepare('SELECT system_prompt FROM ai_prompt_templates WHERE slug=? AND enabled=1 LIMIT 1');
    $templateQuery->execute([$templateSlug]);
    $systemPrompt=(string)($templateQuery->fetchColumn()?:$templates[$type]);
    $payload = [
        'model'=>$model,
        'messages'=>[
            ['role'=>'system','content'=>$systemPrompt],
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
    $curl=curl_init(PAASTA_AI_BASE.'/chat/completions');    curl_setopt_array($curl,[
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
            $httpStatus=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            // Do not send provider error JSON as malformed SSE. Wait for status and return it as a proper SSE error event.
            if($httpStatus>=200 && $httpStatus<300) {
                echo $chunk;
                if(function_exists('ob_flush')) @ob_flush();
                flush();
            }
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
        $providerError=ai_json_decode($raw);
        $message=$providerError['error']['message']??$providerError['detail']??'درخواست جریانی توسط درگاه رد شد.';
        echo "\nevent: error\ndata: ".json_encode([
            'error'=>is_string($message)?$message:'خطای Provider',
            'provider_status'=>$status,
            'request_id'=>$responseHeaders['x-paasta-request-id']??null
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
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
    foreach(['pdf_processing','tools','tool_choice','thinking','stop_sequences','temperature','top_p','top_k','metadata'] as $field) {
        if(array_key_exists($field,$body)) $payload[$field]=$body[$field];
    }
    $call=ai_paasta_call($admin,'anthropic_messages','POST','/messages',$payload,null,180,'https://console.paasta.cloud/api/ai');
    $output=ai_extract_text($call['data']);
    app_json(200,['output'=>$output,'data'=>$call['data'],'request_id'=>$call['headers']['x-paasta-request-id']??null]);
}

if ($method === 'POST' && $path === '/ai/responses') {
    $body = app_request_json();
    $model = trim((string)($body['model'] ?? ''));
    $input = $body['input'] ?? '';
    if (is_string($input)) $input = trim($input);
    if ($model === '' || (is_string($input) && $input === '') ||
        (!is_string($input) && (!is_array($input) || count($input) < 1))) {
        app_json(422, ['error' => 'مدل و ورودی معتبر الزامی هستند.']);
    }

    $webSearch = !empty($body['web_search']);
    if ($webSearch && (!is_string($input) || mb_strlen($input) > 4000)) {
        app_json(422, ['error' => 'جست‌وجوی وب فقط ورودی متنی تا ۴۰۰۰ نویسه را می‌پذیرد.']);
    }
    if (is_string($input)) $input = mb_substr($input, 0, 50000);

    $payload = [
        'model' => $model,
        'input' => $input,
        'max_output_tokens' => min(8192, max(64, (int)($body['max_output_tokens'] ?? 1024))),
        'store' => false,
        'stream' => false,
    ];
    if (!empty($body['instructions'])) $payload['instructions'] = mb_substr((string)$body['instructions'], 0, 10000);

    foreach (['response_format','structured_outputs','pdf_processing'] as $field) {
        if (array_key_exists($field, $body)) $payload[$field] = $body[$field];
    }

    if ($webSearch) {
        // Web search has a separate billing component and must be explicit and non-streaming.
        $payload['tools'] = [['type' => 'web_search']];
        $payload['tool_choice'] = ['type' => 'web_search'];
        $payload['max_tool_calls'] = 1;
    } else {
        foreach (['tools','tool_choice','max_tool_calls'] as $field) {
            if (array_key_exists($field, $body)) $payload[$field] = $body[$field];
        }
    }

    $call = ai_paasta_call($admin, 'responses', 'POST', '/responses', $payload, null, 180);
    $output = ai_extract_text($call['data']);
    $requestId = $call['headers']['x-paasta-request-id'] ?? ($call['data']['paasta']['request_id'] ?? null);
    if (!empty($body['save_history'])) {
        $historyPrompt = is_string($input) ? $input : json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ai_save_history($admin, 'response', 'Responses API', $model, (string)$historyPrompt, $output, (string)$requestId);
    }
    app_json(200, ['output' => $output, 'data' => $call['data'], 'request_id' => $requestId]);
}

if ($method === 'POST' && $path === '/ai/responses/stream') {
    if(!function_exists('curl_init')) app_json(500,['error'=>'افزونه cURL نصب نیست.']);
    $body=app_request_json();
    $model=trim((string)($body['model']??''));
    $input=$body['input']??'';
    if(is_string($input)) $input=mb_substr(trim($input),0,50000);
    if($model===''||((is_string($input)&&$input==='')||(!is_string($input)&&(!is_array($input)||count($input)<1)))) {
        app_json(422,['error'=>'مدل و ورودی معتبر برای Responses Stream الزامی هستند.']);
    }
    $apiKey=app_paasta_key();
    if($apiKey==='') app_json(409,['error'=>'ابتدا کلید API پاستا را در تنظیمات ثبت کنید.']);
    $payload=[
        'model'=>$model,
        'input'=>$input,
        'max_output_tokens'=>min(8192,max(64,(int)($body['max_output_tokens']??1024))),
        'store'=>false,
        'stream'=>true
    ];
    if(!empty($body['instructions'])) $payload['instructions']=mb_substr((string)$body['instructions'],0,10000);
    foreach(['tools','tool_choice','response_format','structured_outputs','pdf_processing','max_tool_calls'] as $field) {
        if(array_key_exists($field,$body)) $payload[$field]=$body[$field];
    }
    $headers=['Authorization: Bearer '.$apiKey,'Content-Type: application/json','Accept: text/event-stream','Idempotency-Key: '.bin2hex(random_bytes(18))];
    $responseHeaders=[];$raw='';
    $curl=curl_init(PAASTA_AI_BASE.'/responses');
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
            $httpStatus=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            if($httpStatus>=200&&$httpStatus<300){
                echo $chunk;
                if(function_exists('ob_flush')) @ob_flush();
                flush();
            }
            return strlen($chunk);
        },
    ]);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    while(ob_get_level()>0) @ob_end_flush();
    $started=microtime(true);
    $ok=curl_exec($curl);
    $curlError=curl_error($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    $latency=(int)round((microtime(true)-$started)*1000);
    curl_close($curl);

    if($ok===false){
        error_log('Paasta Responses stream transport error: '.$curlError);
        echo "event: error\ndata: ".json_encode(['error'=>'ارتباط جریانی Responses با درگاه قطع شد.'])."\n\n";
        exit;
    }
    $usageData=[];$streamText='';
    foreach(preg_split('/\\r?\\n/',$raw) as $line){
        if(!str_starts_with($line,'data:')) continue;
        $chunk=trim(substr($line,5));
        if($chunk===''||$chunk==='[DONE]') continue;
        $event=json_decode($chunk,true);
        if(!is_array($event)) continue;
        $response=$event['response']??[];
        if(isset($response['usage'])) $usageData['usage']=$response['usage'];
        if(isset($event['usage'])) $usageData['usage']=$event['usage'];
        if(isset($response['paasta'])) $usageData['paasta']=$response['paasta'];
        if(isset($event['paasta'])) $usageData['paasta']=$event['paasta'];
        $delta=$event['delta']??$event['text']??null;
        if(is_string($delta)&&str_contains((string)($event['type']??''),'output_text')) $streamText.=$delta;
    }
    ai_record_usage($admin,'responses_stream','/responses',$model,$status,$usageData,$responseHeaders,$latency);
    $streamRequestId=$responseHeaders['x-paasta-request-id']??($usageData['paasta']['request_id']??null);
    if(!empty($body['save_history'])&&$status>=200&&$status<300){
        $historyPrompt=is_string($input)?$input:json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        ai_save_history($admin,'response','Responses API · Stream',$model,$historyPrompt,$streamText,(string)$streamRequestId);
    }
    if($status<200||$status>=300){
        $providerError=ai_json_decode($raw);
        $message=$providerError['error']['message']??$providerError['detail']??'درخواست Responses Stream رد شد.';
        echo "\nevent: error\ndata: ".json_encode(['error'=>is_string($message)?$message:'خطای Provider','provider_status'=>$status,'request_id'=>$streamRequestId],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n\n";
    }
    exit;
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

function ai_is_public_https_url(string $url): bool
{
    $parts = parse_url(trim($url));
    if (!$parts || strtolower((string)($parts['scheme'] ?? '')) !== 'https' ||
        empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === 'localhost' || str_ends_with($host, '.localhost') ||
        str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        return false;
    }

    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, $flags) !== false;
    }

    $records = dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records) || count($records) === 0) return false;
    $foundAddress = false;
    foreach ($records as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? null;
        if (!is_string($ip)) continue;
        $foundAddress = true;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false) return false;
    }
    return $foundAddress;
}

if ($method === 'POST' && $path === '/ai/image-edit') {
    $body = app_request_json();
    $model = trim((string)($body['model'] ?? ''));
    $prompt = trim((string)($body['prompt'] ?? ''));
    $hasSingle = array_key_exists('image_url', $body);
    $hasMultiple = array_key_exists('image_urls', $body);
    if ($hasSingle === $hasMultiple) {
        app_json(422, ['error' => 'دقیقاً یکی از image_url یا image_urls باید ارسال شود.']);
    }
    if ($model === '' || $prompt === '') {
        app_json(422, ['error' => 'مدل و شرح ویرایش الزامی هستند.']);
    }

    $payload = ['model' => $model, 'prompt' => mb_substr($prompt, 0, 10000)];
    if ($hasSingle) {
        $url = trim((string)$body['image_url']);
        if (!ai_is_public_https_url($url)) app_json(422, ['error' => 'image_url باید یک نشانی عمومی HTTPS باشد.']);
        $payload['image_url'] = $url;
    } else {
        $imageUrls = $body['image_urls'];
        if (!is_array($imageUrls)) app_json(422, ['error' => 'image_urls باید آرایه‌ای از ۱ تا ۴ نشانی HTTPS باشد.']);
        $imageUrls = array_values(array_filter(array_map(static fn($value) => is_string($value) ? trim($value) : '', $imageUrls)));
        if (count($imageUrls) < 1 || count($imageUrls) > 4) {
            app_json(422, ['error' => 'image_urls باید شامل ۱ تا ۴ نشانی عمومی HTTPS باشد.']);
        }
        foreach ($imageUrls as $url) {
            if (!ai_is_public_https_url($url)) app_json(422, ['error' => 'تمام نشانی‌های image_urls باید عمومی و HTTPS باشند.']);
        }
        $payload['image_urls'] = $imageUrls;
    }

    if (!empty($body['mask_url'])) {
        $maskUrl = trim((string)$body['mask_url']);
        if (!ai_is_public_https_url($maskUrl)) app_json(422, ['error' => 'mask_url باید یک نشانی عمومی HTTPS باشد.']);
        $payload['mask_url'] = $maskUrl;
    }

    // Paasta documents JSON URLs, not multipart uploads, for image edits.
    $call = ai_paasta_call($admin, 'image_edit', 'POST', '/images/edits', $payload, null, 360);
    app_json(200, ['data' => $call['data'], 'request_id' => $call['headers']['x-paasta-request-id'] ?? null]);
}

if ($method === 'POST' && $path === '/ai/transcription') {
    if (!function_exists('curl_init')) app_json(500,['error'=>'افزونه cURL نصب نیست.']);
    if (!empty($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        $file=$_FILES['audio'];
        if((int)$file['size']>15*1024*1024) app_json(413,['error'=>'حجم فایل صوتی از محدودیت امن این پنل بیشتر است.']);
        $model=trim((string)($_POST['model']??''));
        if($model==='') app_json(422,['error'=>'مدل تبدیل صوت را انتخاب کنید.']);
        $mime=function_exists('mime_content_type')?(mime_content_type($file['tmp_name'])?:'application/octet-stream'):'application/octet-stream';
        $payload=['model'=>$model,'file'=>new CURLFile($file['tmp_name'],$mime,basename((string)$file['name']))];
        if(!empty($_POST['language'])) $payload['language']=preg_replace('/[^a-z-]/i','',(string)$_POST['language']);
        $call=ai_paasta_call($admin,'audio_transcription','POST','/audio/transcriptions',null,$payload,360);
    } else {
        $body=app_request_json();
        $model=trim((string)($body['model']??''));
        $inputAudio=$body['input_audio']??null;
        if($model===''||!is_array($inputAudio)||empty($inputAudio['data'])||empty($inputAudio['format'])) {
            app_json(422,['error'=>'مدل و input_audio با فیلدهای data و format الزامی هستند.']);
        }
        $base64=(string)$inputAudio['data'];
        if(strlen($base64)>12*1024*1024||base64_decode($base64,true)===false) {
            app_json(422,['error'=>'داده صوتی Base64 معتبر نیست یا بیش از حد بزرگ است.']);
        }
        $payload=[
            'model'=>$model,
            'input_audio'=>[
                'data'=>$base64,
                'format'=>preg_replace('/[^a-z0-9]/i','',(string)$inputAudio['format'])
            ]
        ];
        if(!empty($body['language'])) $payload['language']=preg_replace('/[^a-z-]/i','',(string)$body['language']);
        $call=ai_paasta_call($admin,'audio_transcription','POST','/audio/transcriptions',$payload,null,360);
    }
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
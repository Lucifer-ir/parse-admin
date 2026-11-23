<?php
declare(strict_types=1);

/**
 * Student-facing Parse API routes.
 * This file is included after $pdo, $method and $path are initialized.
 * Admin/authoring operations are handled only by the authenticated PHP console.
 */
function parse_student_from_token(PDO $pdo): ?array
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer ([a-f0-9]{64})$/i', $header, $m)) return null;
    $stmt = $pdo->prepare(
        'SELECT s.id,s.username,s.student_code,s.phone,s.name,s.email,s.status,s.level,s.group_name,s.track_id,
                t.id AS token_id
         FROM student_auth_tokens t
         INNER JOIN students s ON s.id=t.student_id
         WHERE t.token_hash=? AND t.expires_at>UTC_TIMESTAMP() AND s.status=\'active\'
         LIMIT 1'
    );
    $stmt->execute([hash('sha256', $m[1])]);
    $student = $stmt->fetch();
    if (!$student) return null;
    $pdo->prepare('UPDATE student_auth_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?')->execute([(int)$student['token_id']]);
    $platform = substr((string)($_SERVER['HTTP_X_PARSE_PLATFORM'] ?? 'unknown'), 0, 40);
    $pdo->prepare('UPDATE students SET last_seen_at=UTC_TIMESTAMP(),last_seen_platform=? WHERE id=?')->execute([$platform,(int)$student['id']]);
    unset($student['token_id']);
    return $student;
}

function parse_require_student(PDO $pdo): array
{
    $student = parse_student_from_token($pdo);
    if (!$student) respond(401, ['message' => 'برای ادامه وارد حساب دانش‌آموزی شوید.']);
    return $student;
}

function parse_issue_student_token(PDO $pdo, int $studentId): string
{
    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO student_auth_tokens (student_id,token_hash,expires_at)
         VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY))'
    )->execute([$studentId, hash('sha256', $token)]);
    return $token;
}

function parse_store_event(PDO $pdo, int $studentId, string $event, ?string $route, array $metadata = []): void
{
    $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('UPDATE students SET last_seen_at=UTC_TIMESTAMP(),last_seen_platform=? WHERE id=?')
        ->execute([substr((string)($metadata['platform'] ?? ($_SERVER['HTTP_X_PARSE_PLATFORM'] ?? 'unknown')),0,40),$studentId]);
    $pdo->prepare(
        'INSERT INTO student_activity_events (student_id,event_type,route,metadata_json,ip_address,platform)
         VALUES (?,?,?,?,?,?)'
    )->execute([
        $studentId,
        substr($event, 0, 80),
        $route !== null ? substr($route, 0, 190) : null,
        $json ?: null,
        substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        substr((string)($metadata['platform'] ?? ($_SERVER['HTTP_X_PARSE_PLATFORM'] ?? 'unknown')), 0, 40),
    ]);
}

/* Student login uses username/phone. Admin login (email) remains backward compatible. */
if ($method === 'POST' && $path === '/auth/register') {
    $body = request_body();
    $username = trim((string)($body['username'] ?? ''));
    $phone = trim((string)($body['phone'] ?? ''));
    $name = trim((string)($body['name'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,100}$/', $username) || strlen($password) < 8 || mb_strlen($name) > 200) {
        respond(422, ['message' => 'نام کاربری معتبر و رمز عبور حداقل ۸ کاراکتری الزامی است.']);
    }
    if ($phone !== '' && !preg_match('/^[0-9+() -]{8,20}$/', $phone)) {
        respond(422, ['message' => 'شماره تماس معتبر نیست.']);
    }
    $check = $pdo->prepare('SELECT id FROM students WHERE username=? OR (?<>\'\' AND phone=?) LIMIT 1');
    $check->execute([$username, $phone, $phone]);
    if ($check->fetch()) respond(409, ['message' => 'نام کاربری یا شماره تماس قبلاً ثبت شده است.']);
    $insert = $pdo->prepare('INSERT INTO students (username,phone,name,password_hash,status) VALUES (?,?,?,?,\'active\')');
    $insert->execute([$username, $phone !== '' ? $phone : null, $name, password_hash($password, PASSWORD_DEFAULT)]);
    $studentId = (int)$pdo->lastInsertId();
    $token = parse_issue_student_token($pdo, $studentId);
    respond(201, ['token' => $token, 'user' => [
        'id' => $studentId, 'username' => $username, 'phone' => $phone,
        'name' => $name, 'role' => 'student', 'status' => 'active'
    ]]);
}

if ($method === 'POST' && $path === '/auth/login') {
    $body = request_body();
    if (array_key_exists('username', $body)) {
        $login = trim((string)$body['username']);
        $password = (string)($body['password'] ?? '');
        if ($login === '' || $password === '') respond(422, ['message' => 'نام کاربری و رمز عبور الزامی است.']);
        $stmt = $pdo->prepare(
            'SELECT id,username,phone,name,email,password_hash,status,level,group_name,track_id
             FROM students WHERE username=? OR phone=? LIMIT 1'
        );
        $stmt->execute([$login, $login]);
        $student = $stmt->fetch();
        if (!$student || $student['status'] !== 'active' || !password_verify($password, (string)$student['password_hash'])) {
            respond(401, ['message' => 'نام کاربری یا رمز عبور صحیح نیست.']);
        }
        if (password_needs_rehash((string)$student['password_hash'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE students SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), (int)$student['id']]);
        }
        unset($student['password_hash']);
        $student['role'] = 'student';
        $token = parse_issue_student_token($pdo, (int)$student['id']);
        parse_store_event($pdo, (int)$student['id'], 'login', '/auth/login', ['platform' => $body['platform'] ?? 'unknown']);
        respond(200, ['token' => $token, 'user' => $student]);
    }
}

if ($method === 'GET' && $path === '/auth/me') {
    $student = parse_student_from_token($pdo);
    if ($student) respond(200, ['user' => $student]);
}

if ($method === 'POST' && $path === '/auth/logout') {
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer ([a-f0-9]{64})$/i', $header, $m)) {
        $hash = hash('sha256', $m[1]);
        $lookup = $pdo->prepare('SELECT student_id FROM student_auth_tokens WHERE token_hash=? LIMIT 1');
        $lookup->execute([$hash]);
        $studentId = $lookup->fetchColumn();
        if ($studentId) {
            $pdo->prepare('DELETE FROM student_auth_tokens WHERE token_hash=?')->execute([$hash]);
            parse_store_event($pdo, (int)$studentId, 'logout', '/auth/logout');
            respond(200, ['message' => 'خروج انجام شد.']);
        }
    }
}

if ($method === 'POST' && $path === '/activity') {
    $student = parse_require_student($pdo);
    $body = request_body();
    $event = trim((string)($body['event'] ?? 'heartbeat'));
    if (!preg_match('/^[a-zA-Z0-9_.:-]{2,80}$/', $event)) respond(422, ['message' => 'نوع رویداد معتبر نیست.']);
    $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
    parse_store_event($pdo, (int)$student['id'], $event, isset($body['route']) ? (string)$body['route'] : null, $metadata);
    respond(200, ['ok' => true, 'recorded_at' => gmdate('c')]);
}

if ($method === 'GET' && $path === '/resources') {
    $student = parse_require_student($pdo);
    $stmt = $pdo->prepare(
        'SELECT r.id,r.resource_type,r.title,r.description,r.subject,r.level,r.track_id,r.file_url,r.original_filename,r.updated_at
         FROM learning_resources r
         WHERE r.published=1 AND (r.level IS NULL OR r.level=\'\' OR r.level=?) AND (r.track_id IS NULL OR r.track_id=?)
         ORDER BY r.resource_type,r.title'
    );
    $stmt->execute([(string)($student['level'] ?? ''),(int)($student['track_id'] ?? 0)]);
    respond(200, ['rows' => $stmt->fetchAll()]);
}


if ($method === 'GET' && $path === '/activities') {
    $student = parse_require_student($pdo);
    $stmt = $pdo->prepare(
        'SELECT a.id,a.activity_type,a.title,a.description,a.subject,a.level,a.track_id,a.due_at,a.status,
                saa.status AS assignment_status,saa.score,saa.completed_at,
                (SELECT COUNT(*) FROM activity_questions aq WHERE aq.activity_id=a.id) AS question_count
         FROM student_activity_assignments saa
         INNER JOIN learning_activities a ON a.id=saa.activity_id
         WHERE saa.student_id=? AND a.status=\'published\' AND (a.track_id IS NULL OR a.track_id=?) AND (a.level IS NULL OR a.level=\'\' OR a.level=?)
         ORDER BY a.due_at IS NULL,a.due_at,a.id DESC'
    );
    $stmt->execute([(int)$student['id'],(int)($student['track_id'] ?? 0),(string)($student['level'] ?? '')]);
    respond(200,['rows'=>$stmt->fetchAll()]);
}

if ($method === 'GET' && preg_match('#^/activities/([1-9][0-9]*)$#',$path,$m)) {
    $student = parse_require_student($pdo);
    $id=(int)$m[1];
    $head=$pdo->prepare(
      'SELECT a.id,a.activity_type,a.title,a.description,a.subject,a.level,a.instructions,a.due_at,a.status,
              saa.status AS assignment_status
       FROM learning_activities a JOIN student_activity_assignments saa ON saa.activity_id=a.id
       WHERE a.id=? AND saa.student_id=? AND a.status=\'published\' AND (a.track_id IS NULL OR a.track_id=?) AND (a.level IS NULL OR a.level=\'\' OR a.level=?) LIMIT 1'
    );
    $head->execute([$id,(int)$student['id'],(int)($student['track_id'] ?? 0),(string)($student['level'] ?? '')]);$activity=$head->fetch();
    if(!$activity)respond(404,['message'=>'این تمرین برای حساب شما در دسترس نیست.']);
    $questions=$pdo->prepare(
      'SELECT q.id,q.question_text,q.question_type,q.options_json,q.skill,q.difficulty,aq.position,q.points
       FROM activity_questions aq JOIN question_bank q ON q.id=aq.question_id
       WHERE aq.activity_id=? AND q.status=\'published\' ORDER BY aq.position'
    );
    $questions->execute([$id]);$activity['questions']=$questions->fetchAll();
    foreach($activity['questions'] as &$question){
      $question['options']=$question['options_json']?json_decode((string)$question['options_json'],true):null;
      unset($question['options_json']);
    }
    unset($question);
    respond(200,['activity'=>$activity]);
}

if ($method === 'POST' && preg_match('#^/activities/([1-9][0-9]*)/submit$#',$path,$m)) {
    $student=parse_require_student($pdo);$activityId=(int)$m[1];$body=request_body();
    $assignment=$pdo->prepare(
      'SELECT a.id,a.due_at FROM learning_activities a
       JOIN student_activity_assignments saa ON saa.activity_id=a.id
       WHERE a.id=? AND saa.student_id=? AND a.status=\'published\' AND (a.track_id IS NULL OR a.track_id=?) AND (a.level IS NULL OR a.level=\'\' OR a.level=?) LIMIT 1'
    );
    $assignment->execute([$activityId,(int)$student['id'],(int)($student['track_id'] ?? 0),(string)($student['level'] ?? '')]);$activity=$assignment->fetch();
    if(!$activity)respond(404,['message'=>'این تمرین برای حساب شما در دسترس نیست.']);
    if($activity['due_at'] && strtotime((string)$activity['due_at'])<time())respond(409,['message'=>'مهلت ارسال این تمرین به پایان رسیده است.']);
    $answers=$body['answers']??[];
    if(!is_array($answers)||count($answers)>200)respond(422,['message'=>'ساختار پاسخ‌ها معتبر نیست.']);
    $pdo->beginTransaction();
    try{
      $pdo->prepare(
        'INSERT INTO activity_submissions(activity_id,student_id,status)
         VALUES(?,?,\'in_progress\') ON DUPLICATE KEY UPDATE status=\'in_progress\''
      )->execute([$activityId,(int)$student['id']]);
      $find=$pdo->prepare('SELECT id FROM activity_submissions WHERE activity_id=? AND student_id=?');
      $find->execute([$activityId,(int)$student['id']]);$submissionId=(int)$find->fetchColumn();
      $allowed=$pdo->prepare('SELECT q.id,q.question_type,q.answer_json,aq.points FROM activity_questions aq JOIN question_bank q ON q.id=aq.question_id WHERE aq.activity_id=? AND q.id=? AND q.status=\'published\'');
      $save=$pdo->prepare(
        'INSERT INTO activity_answers(submission_id,question_id,answer_json,is_correct,awarded_points)
         VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE answer_json=VALUES(answer_json),is_correct=VALUES(is_correct),awarded_points=VALUES(awarded_points),answered_at=UTC_TIMESTAMP()'
      );
      $score=0.0;$total=0.0;$manual=0;
      $totalStmt=$pdo->prepare('SELECT COALESCE(SUM(points),0) FROM activity_questions WHERE activity_id=?');
      $totalStmt->execute([$activityId]);
      $total=(float)$totalStmt->fetchColumn();
      foreach($answers as $entry){
        if(!is_array($entry))continue;
        $questionId=(int)($entry['question_id']??0);
        if($questionId<1||!array_key_exists('answer',$entry))continue;
        $allowed->execute([$activityId,$questionId]);$q=$allowed->fetch();
        if(!$q)continue;
        $points=(float)$q['points'];$given=$entry['answer'];
        $objective=in_array($q['question_type'],['multiple_choice','true_false','fill_blank','ordering'],true);
        if($objective){
          $correct=json_decode((string)$q['answer_json'],true);
          $norm=static function($v){return is_array($v)?json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):mb_strtolower(trim(is_scalar($v)?(string)$v:''));};
          $isCorrect=$norm($given)===$norm($correct);$awarded=$isCorrect?$points:0.0;$score+=$awarded;
        }else{$isCorrect=null;$awarded=null;$manual++;}
        $save->execute([$submissionId,$questionId,json_encode($given, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),$isCorrect===null?null:($isCorrect?1:0),$awarded]);
      }
      $pdo->prepare(
        'UPDATE activity_submissions SET submitted_at=UTC_TIMESTAMP(),score=?,total_points=?,status=\'submitted\' WHERE id=?'
      )->execute([$score,$total,$submissionId]);
      $pdo->prepare(
        'UPDATE student_activity_assignments SET completed_at=UTC_TIMESTAMP(),score=?,status=\'submitted\' WHERE activity_id=? AND student_id=?'
      )->execute([$score,$activityId,(int)$student['id']]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    parse_store_event($pdo,(int)$student['id'],'activity.submit','/activities/'.$activityId,['submission_id'=>$submissionId]);
    respond(200,['submitted'=>true,'score'=>$score,'total_points'=>$total,'pending_manual_review'=>$manual]);
}

if ($method === 'GET' && $path === '/exams') {
    $student = parse_require_student($pdo);
    $stmt = $pdo->prepare(
        'SELECT e.id,e.title,e.description,e.subject,e.level,e.duration_minutes,e.starts_at,e.ends_at,e.show_result,
                (SELECT ea.status FROM exam_attempts ea WHERE ea.exam_id=e.id AND ea.student_id=? ORDER BY ea.id DESC LIMIT 1) AS attempt_status,
                COUNT(eq.question_id) AS question_count
         FROM exams e LEFT JOIN exam_questions eq ON eq.exam_id=e.id
         WHERE e.status=\'published\' AND (e.target_student_id IS NULL OR e.target_student_id=?) AND (e.track_id IS NULL OR e.track_id=?) AND (e.level IS NULL OR e.level=\'\' OR e.level=?)
           AND (e.starts_at IS NULL OR e.starts_at<=UTC_TIMESTAMP())
           AND (e.ends_at IS NULL OR e.ends_at>=UTC_TIMESTAMP())
         GROUP BY e.id ORDER BY e.starts_at,e.id DESC'
    );
    $stmt->execute([(int)$student['id'],(int)$student['id'],(int)($student['track_id'] ?? 0),(string)($student['level'] ?? '')]);
    respond(200, ['rows' => $stmt->fetchAll()]);
}

if ($method === 'GET' && preg_match('#^/exams/([1-9][0-9]*)$#', $path, $m)) {
    $student = parse_require_student($pdo);
    $examId = (int)$m[1];
    $stmt = $pdo->prepare(
        'SELECT id,title,description,subject,level,track_id,duration_minutes,starts_at,ends_at,show_result,status
         FROM exams WHERE id=? AND status=\'published\' AND (target_student_id IS NULL OR target_student_id=?) AND (track_id IS NULL OR track_id=?) AND (level IS NULL OR level=\'\' OR level=?) LIMIT 1'
    );
    $stmt->execute([$examId, (int)$student['id'],(int)($student['track_id'] ?? 0),(string)($student['level'] ?? '')]);
    $exam = $stmt->fetch();
    if (!$exam) respond(404, ['message' => 'آزمون پیدا نشد یا در دسترس نیست.']);
    $q = $pdo->prepare(
        'SELECT q.id,q.question_text,q.question_type,q.options_json,q.difficulty,q.skill,eq.points,eq.position
         FROM exam_questions eq INNER JOIN question_bank q ON q.id=eq.question_id
         WHERE eq.exam_id=? AND q.status=\'published\' ORDER BY eq.position,q.id'
    );
    $q->execute([$examId]);
    $exam['questions'] = $q->fetchAll();
    foreach ($exam['questions'] as &$question) {
        $question['options'] = $question['options_json'] ? json_decode((string)$question['options_json'], true) : null;
        unset($question['options_json']);
    }
    unset($question);
    respond(200, ['exam' => $exam]);
}

if ($method === 'POST' && preg_match('#^/exams/([1-9][0-9]*)/start$#', $path, $m)) {
    $student = parse_require_student($pdo);
    $examId = (int)$m[1];
    $check = $pdo->prepare(
        'SELECT id,duration_minutes FROM exams WHERE id=? AND status=\'published\'
         AND (target_student_id IS NULL OR target_student_id=?) AND (track_id IS NULL OR track_id=?) AND (level IS NULL OR level=\'\' OR level=?)
         AND (starts_at IS NULL OR starts_at<=UTC_TIMESTAMP())
         AND (ends_at IS NULL OR ends_at>=UTC_TIMESTAMP()) LIMIT 1'
    );
    $check->execute([$examId, (int)$student['id'],(int)($student['track_id'] ?? 0),(string)($student['level'] ?? '')]);
    $exam = $check->fetch();
    if (!$exam) respond(404, ['message' => 'آزمون در حال حاضر در دسترس نیست.']);
    $existing = $pdo->prepare(
        'SELECT id,started_at,expires_at,status FROM exam_attempts
         WHERE exam_id=? AND student_id=? AND status=\'in_progress\' ORDER BY id DESC LIMIT 1'
    );
    $existing->execute([$examId, (int)$student['id']]);
    $attempt = $existing->fetch();
    if (!$attempt) {
        $pdo->prepare(
            'INSERT INTO exam_attempts (exam_id,student_id,started_at,expires_at)
             VALUES (?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE))'
        )->execute([$examId, (int)$student['id'], (int)$exam['duration_minutes']]);
        $attemptId = (int)$pdo->lastInsertId();
        $reload = $pdo->prepare('SELECT id,started_at,expires_at,status FROM exam_attempts WHERE id=?');
        $reload->execute([$attemptId]);
        $attempt = $reload->fetch() ?: ['id' => $attemptId];
    }
    $answerStmt = $pdo->prepare('SELECT question_id,answer_json FROM exam_answers WHERE attempt_id=?');
    $answerStmt->execute([(int)$attempt['id']]);
    $savedAnswers = [];
    foreach ($answerStmt->fetchAll() as $saved) $savedAnswers[(string)$saved['question_id']] = json_decode((string)$saved['answer_json'], true);
    parse_store_event($pdo, (int)$student['id'], 'exam.start', '/exams/'.$examId, ['attempt_id' => (int)$attempt['id']]);
    respond(200, ['attempt' => $attempt, 'answers' => $savedAnswers]);
}

if ($method === 'POST' && preg_match('#^/exams/([1-9][0-9]*)/answers$#', $path, $m)) {
    $student = parse_require_student($pdo);
    $examId = (int)$m[1];
    $body = request_body();
    $questionId = (int)($body['question_id'] ?? 0);
    if ($questionId < 1 || !array_key_exists('answer', $body)) respond(422, ['message' => 'شناسه سؤال و پاسخ الزامی است.']);
    $attemptStmt = $pdo->prepare(
        'SELECT a.id,a.expires_at FROM exam_attempts a
         INNER JOIN exam_questions eq ON eq.exam_id=a.exam_id
         WHERE a.exam_id=? AND a.student_id=? AND a.status=\'in_progress\'
           AND a.expires_at>UTC_TIMESTAMP() AND eq.question_id=? ORDER BY a.id DESC LIMIT 1'
    );
    $attemptStmt->execute([$examId, (int)$student['id'], $questionId]);
    $attempt = $attemptStmt->fetch();
    if (!$attempt) respond(409, ['message' => 'زمان آزمون تمام شده یا پاسخ‌گویی مجاز نیست.']);
    $answerJson = json_encode($body['answer'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare(
        'INSERT INTO exam_answers (attempt_id,question_id,answer_json)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE answer_json=VALUES(answer_json),answered_at=UTC_TIMESTAMP()'
    )->execute([(int)$attempt['id'], $questionId, $answerJson]);
    parse_store_event($pdo, (int)$student['id'], 'exam.answer', '/exams/'.$examId, ['question_id' => $questionId]);
    respond(200, ['saved' => true, 'expires_at' => $attempt['expires_at']]);
}

if ($method === 'POST' && preg_match('#^/exams/([1-9][0-9]*)/submit$#', $path, $m)) {
    $student = parse_require_student($pdo);
    $examId = (int)$m[1];
    $attemptStmt = $pdo->prepare(
        'SELECT id,expires_at FROM exam_attempts WHERE exam_id=? AND student_id=? AND status=\'in_progress\'
         ORDER BY id DESC LIMIT 1'
    );
    $attemptStmt->execute([$examId, (int)$student['id']]);
    $attemptRow = $attemptStmt->fetch();
    $attemptId = (int)($attemptRow['id'] ?? 0);
    if ($attemptId < 1) respond(404, ['message' => 'آزمون فعالی برای ثبت پاسخ پیدا نشد.']);
    $rows = $pdo->prepare(
        'SELECT a.id,a.answer_json,q.question_type,q.answer_json AS correct_answer,eq.points
         FROM exam_answers a
         INNER JOIN question_bank q ON q.id=a.question_id
         INNER JOIN exam_questions eq ON eq.question_id=q.id AND eq.exam_id=?
         WHERE a.attempt_id=?'
    );
    $rows->execute([$examId, $attemptId]);
    $score = 0.0; $manual = 0;
    $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(points),0) FROM exam_questions WHERE exam_id=?');
    $totalStmt->execute([$examId]);
    $total = (float)$totalStmt->fetchColumn();
    foreach ($rows->fetchAll() as $row) {
        $points = (float)$row['points'];
        $given = json_decode((string)$row['answer_json'], true);
        $correct = json_decode((string)$row['correct_answer'], true);
        if (in_array($row['question_type'], ['multiple_choice','true_false','fill_blank','ordering'], true)) {
            $normalize = static function($value) { return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : mb_strtolower(trim(is_scalar($value) ? (string)$value : '')); };
            $isCorrect = $normalize($given) === $normalize($correct);
            $awarded = $isCorrect ? $points : 0.0; $score += $awarded;
            $pdo->prepare('UPDATE exam_answers SET is_correct=?,awarded_points=? WHERE id=?')
                ->execute([$isCorrect ? 1 : 0, $awarded, (int)$row['id']]);
        } else {
            $manual++;
        }
    }
    $finalStatus = strtotime((string)$attemptRow['expires_at'] . ' UTC') <= time() ? 'expired' : 'submitted';
    $pdo->prepare(
        'UPDATE exam_attempts SET submitted_at=UTC_TIMESTAMP(),score=?,total_points=?,status=? WHERE id=?'
    )->execute([$score, $total, $finalStatus, $attemptId]);
    parse_store_event($pdo, (int)$student['id'], 'exam.submit', '/exams/'.$examId, ['attempt_id' => $attemptId]);
    respond(200, ['submitted' => true, 'status' => $finalStatus, 'score' => $score, 'total_points' => $total, 'pending_manual_review' => $manual]);
}


if ($method === 'GET' && preg_match('#^/resources/([1-9][0-9]*)/download$#', $path, $m)) {
    $student = parse_require_student($pdo);
    $resourceId = (int)$m[1];
    $stmt = $pdo->prepare(
        'SELECT storage_path,original_filename,published,level,track_id
         FROM learning_resources WHERE id=? LIMIT 1'
    );
    $stmt->execute([$resourceId]);
    $file = $stmt->fetch();
    $allowedLevel = !$file || $file['level'] === null || $file['level'] === '' ||
        (string)$file['level'] === (string)($student['level'] ?? '');
    $allowedTrack = !$file || $file['track_id'] === null ||
        (int)$file['track_id'] === (int)($student['track_id'] ?? 0);
    if (!$file || !(bool)$file['published'] || !$allowedLevel || !$allowedTrack ||
        !is_string($file['storage_path']) || !str_starts_with($file['storage_path'], '/var/lib/parse-admin/resources/') ||
        !is_file($file['storage_path'])) {
        respond(404, ['message' => 'فایل در دسترس نیست.']);
    }
    parse_store_event($pdo, (int)$student['id'], 'resource.download', '/resources/'.$resourceId, [
        'resource_id' => $resourceId,
        'platform' => $_SERVER['HTTP_X_PARSE_PLATFORM'] ?? 'unknown',
    ]);
    $downloadName = basename((string)($file['original_filename'] ?: 'resource'));
    $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $downloadName) ?: 'resource';
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string)filesize($file['storage_path']));
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($file['storage_path']);
    exit;
}

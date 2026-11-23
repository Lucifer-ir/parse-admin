<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
$origin=$_SERVER['HTTP_ORIGIN']??'';
$allowed=getenv('CORS_ORIGIN')?:'https://panel.aiparseh.ir';
if($origin && hash_equals($allowed,$origin)){header("Access-Control-Allow-Origin: $origin");header('Vary: Origin');}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
if($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
function out(int $code,array $data):never{http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function body():array{$d=json_decode(file_get_contents('php://input'),true);return is_array($d)?$d:[];}
function envv(string $key,string $fallback=''):string{return getenv($key)?:$fallback;}
try{
 $pdo=new PDO('mysql:host='.envv('DB_HOST','localhost').';dbname='.envv('DB_NAME').' ;charset=utf8mb4',envv('DB_USER'),envv('DB_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}catch(Throwable $e){out(503,['detail'=>'Database unavailable']);}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$path=preg_replace('#^/api#','',$path);$method=$_SERVER['REQUEST_METHOD'];
if($path==='/health'&&$method==='GET')out(200,['status'=>'ok','version'=>'1.0.0']);
if($path==='/auth/login'&&$method==='POST'){
 $d=body();$email=strtolower(trim((string)($d['email']??'')));$pass=(string)($d['password']??'');$ip=$_SERVER['REMOTE_ADDR']??'unknown';
 $q=$pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND created_at>UTC_TIMESTAMP()-INTERVAL 15 MINUTE AND success=0");$q->execute([$ip]);if((int)$q->fetchColumn()>=12)out(429,['detail'=>'Too many login attempts. Try later.']);
 $q=$pdo->prepare('SELECT id,email,password_hash,role,active FROM users WHERE email=? LIMIT 1');$q->execute([$email]);$u=$q->fetch();
 $ok=$u&&$u['active']&&password_verify($pass,$u['password_hash']);
 $pdo->prepare('INSERT INTO login_attempts(ip,email,success) VALUES(?,?,?)')->execute([$ip,$email,$ok?1:0]);
 if(!$ok)out(401,['detail'=>'Invalid credentials']);
 if($u['role']!=='admin')out(403,['detail'=>'Admin access required']);
 $raw=bin2hex(random_bytes(32));$hash=hash('sha256',$raw);$expires=date('Y-m-d H:i:s',time()+86400);
 $pdo->prepare('INSERT INTO auth_tokens(user_id,token_hash,expires_at) VALUES(?,?,?)')->execute([$u['id'],$hash,$expires]);
 out(200,['access_token'=>$raw,'token_type'=>'bearer','expires_in'=>86400]);
}
function auth(PDO $pdo):array{$h=$_SERVER['HTTP_AUTHORIZATION']??'';if(!preg_match('/^Bearer ([a-f0-9]{64})$/',$h,$m))out(401,['detail'=>'Authentication required']);$q=$pdo->prepare('SELECT u.id,u.email,u.role,u.active,t.id token_id FROM auth_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.expires_at>UTC_TIMESTAMP()');$q->execute([hash('sha256',$m[1])]);$u=$q->fetch();if(!$u||!$u['active']||$u['role']!=='admin')out(401,['detail'=>'Invalid or expired token']);return $u;}
if($path==='/auth/me'&&$method==='GET'){$u=auth($pdo);out(200,['id'=>$u['id'],'email'=>$u['email'],'role'=>$u['role'],'active'=>true]);}
if(str_starts_with($path,'/admin/'))$u=auth($pdo);
if($path==='/admin/stats'&&$method==='GET'){$users=(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();$active=(int)$pdo->query('SELECT COUNT(*) FROM users WHERE active=1')->fetchColumn();$questions=(int)$pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();$sources=(int)$pdo->query('SELECT COUNT(*) FROM sources')->fetchColumn();out(200,['users'=>$users,'active_users'=>$active,'questions'=>$questions,'sources'=>$sources]);}
if($path==='/admin/users'&&$method==='GET'){$q=$pdo->query('SELECT id,email,role,active,created_at FROM users ORDER BY id DESC LIMIT 1000');out(200,$q->fetchAll());}
if($path==='/admin/questions'&&$method==='GET'){$limit=min(200,max(1,(int)($_GET['limit']??100)));$offset=max(0,(int)($_GET['offset']??0));$q=$pdo->prepare('SELECT id,question,subject,grade,source_type FROM questions ORDER BY id DESC LIMIT ? OFFSET ?');$q->bindValue(1,$limit,PDO::PARAM_INT);$q->bindValue(2,$offset,PDO::PARAM_INT);$q->execute();out(200,$q->fetchAll());}
if(preg_match('#^/admin/questions/(\d+)$#',$path,$m)&&$method==='DELETE'){$q=$pdo->prepare('DELETE FROM questions WHERE id=?');$q->execute([(int)$m[1]]);out(200,['deleted'=>$q->rowCount()]);}
if($path==='/auth/logout'&&$method==='POST'){$u=auth($pdo);$pdo->prepare('DELETE FROM auth_tokens WHERE id=?')->execute([$u['token_id']]);out(200,['status'=>'ok']);}
out(404,['detail'=>'Not found']);

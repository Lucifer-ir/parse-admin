<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    exit;
}

$loginError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $csrfSent = (string)($_POST['_csrf'] ?? '');
    $csrfKnown = (string)($_SESSION['csrf'] ?? '');
    if ($csrfSent === '' || $csrfKnown === '' || !hash_equals($csrfKnown, $csrfSent)) {
        $loginError = 'فرم ورود منقضی شده است. صفحه را تازه‌سازی کنید.';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $loginError = 'ایمیل و رمز عبور معتبر را وارد کنید.';
        } else {
            $pdo = app_db();
            $limit = $pdo->prepare(
                'SELECT COUNT(*) FROM login_attempts
                 WHERE success=0 AND created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
                   AND (ip_address=? OR (ip_address=? AND email=?))'
            );
            $limit->execute([$ip,$ip,$email]);
            if ((int)$limit->fetchColumn() >= 60) {
                $loginError = 'تعداد تلاش‌های ورود بیش از حد مجاز است. ۱۵ دقیقه دیگر امتحان کنید.';
            } else {
                $stmt = $pdo->prepare('SELECT id,email,password_hash,role,active FROM users WHERE email=? LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                $valid = $user && password_verify($password,(string)$user['password_hash'])
                    && in_array((string)$user['role'], ['admin','developer_admin','education_admin'], true) && (bool)$user['active'];

                $attempt = $pdo->prepare('INSERT INTO login_attempts (ip_address,email,success) VALUES (?,?,?)');
                $attempt->execute([$ip,$email,$valid?1:0]);

                if ($valid) {
                    if (password_needs_rehash((string)$user['password_hash'],PASSWORD_DEFAULT)) {
                        $rehash=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
                        $rehash->execute([password_hash($password,PASSWORD_DEFAULT),(int)$user['id']]);
                    }
                    session_regenerate_id(true);
                    $_SESSION['admin_id']=(int)$user['id'];
                    $_SESSION['last_activity']=time();
                    $_SESSION['csrf']=bin2hex(random_bytes(32));
                    header('Location: /',true,303);
                    exit;
                }
                $loginError='اطلاعات ورود صحیح نیست یا این حساب دسترسی مدیر فعال ندارد.';
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    $csrfSent = (string)($_POST['_csrf'] ?? '');
    $csrfKnown = (string)($_SESSION['csrf'] ?? '');
    if ($csrfSent !== '' && $csrfKnown !== '' && hash_equals($csrfKnown,$csrfSent)) {
        $_SESSION=[];
        if (ini_get('session.use_cookies')) {
            $params=session_get_cookie_params();
            setcookie(session_name(),'',time()-42000,$params['path'],$params['domain']??'',(bool)$params['secure'],(bool)$params['httponly']);
        }
        session_destroy();
    }
    header('Location: /',true,303);
    exit;
}

$admin=app_admin();
$isDeveloperAdmin = app_is_developer_admin($admin);
$allPageNames = ["dashboard","models","questions","practice","review","playground","multimodal","structured","batches","usage","history","question-bank","users","students","resources","exams","attempts","monitoring","tracks","activities","activity-submissions","prompts","settings"];
$educationPageNames = ['dashboard','questions','practice','review','question-bank','students','resources','exams','attempts','monitoring','tracks','activities','activity-submissions'];
$pageAccess = array_fill_keys($allPageNames, $isDeveloperAdmin);
$pageAccess['dashboard'] = true;
if (!$isDeveloperAdmin) { foreach ($educationPageNames as $pageName) $pageAccess[$pageName] = true; }
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://cdnjs.cloudflare.com; style-src-attr 'unsafe-inline'; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
$csrf=app_csrf();
$assetVersion = max(
    (int)(@filemtime(__DIR__ . '/assets/admin.css') ?: 0),
    (int)(@filemtime(__DIR__ . '/assets/admin.js') ?: 0)
);
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0a1222">
<meta name="csrf-token" content="<?= app_e($csrf) ?>">
<meta name="admin-role" content="<?= $isDeveloperAdmin ? 'developer_admin' : 'education_admin' ?>">
<title><?= $admin ? 'کنسول مدیریت Parse' : 'ورود مدیر | Parse Admin' ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"><link rel="stylesheet" href="/assets/admin.css?v=<?= app_e((string)$assetVersion) ?>">

</head>
<body class="<?= $admin ? 'app-page' : 'login-page' ?>">
<div id="parseStartup"><div class="mark">P</div><div class="spin"></div><p>در حال آماده‌سازی Parse Admin</p></div>
<?php if (!$admin): ?>
<div class="login-shell">
  <div class="login-decoration decor-one"></div><div class="login-decoration decor-two"></div>
  <section class="login-card">
    <div class="login-brand"><div class="brand-symbol">P<span></span></div><div><strong>PARSE<span>ADMIN</span></strong><small>AI PLATFORM CONTROL CENTER</small></div></div>
    <div class="login-overline"><i></i> SECURE ADMINISTRATOR ACCESS</div>
    <h1>به مرکز مدیریت<br><span>Parse خوش آمدید</span></h1>
    <p class="login-description">مدیریت هوشمند کاربران، محتوای آموزشی، مدل‌های هوش مصنوعی و هزینه‌های پلتفرم در یک فضای یکپارچه.</p>
    <?php if ($loginError !== ''): ?><div class="login-alert" role="alert"><?= app_e($loginError) ?></div><?php endif; ?>
    <form method="post" action="/" class="login-form" autocomplete="on">
      <input type="hidden" name="action" value="login"><input type="hidden" name="_csrf" value="<?= app_e($csrf) ?>">
      <label for="email">ایمیل مدیر</label><div class="field-wrap"><span class="field-icon">✉</span><input id="email" name="email" type="email" autocomplete="username" placeholder="admin@yourdomain.com" required autofocus value="<?= app_e($_POST['email'] ?? '') ?>"></div>
      <label for="password">رمز عبور</label><div class="field-wrap"><span class="field-icon">⌑</span><input id="password" name="password" type="password" autocomplete="current-password" placeholder="رمز عبور حساب مدیر" required></div>
      <button class="login-submit" type="submit"><span>ورود امن به پنل</span><b>←</b></button>
    </form>
    <div class="login-foot"><span class="shield">◆</span> ارتباط امن با نشست سمت سرور · محافظت‌شده با HTTPS</div>
  </section>
  <aside class="login-side">
    <div class="orb orb-a"></div><div class="orb orb-b"></div>
    <div class="side-content">
      <div class="side-tag">PARSE INTELLIGENCE</div>
      <h2>از داده تا تصمیم،<br><span>با قدرت هوش مصنوعی.</span></h2>
      <p>همه ابزارهای ساخت سؤال، طراحی تمرین، ارزیابی متن، تولید محتوا و تحلیل مصرف API در یک پنل مدیریتی.</p>
      <div class="feature-stack">
        <div><i>✦</i><span><b>AI Workspace</b><small>مدیریت مدل‌ها و جریان‌های هوشمند</small></span></div>
        <div><i>◷</i><span><b>Usage Intelligence</b><small>پایش توکن، هزینه و عملکرد درخواست‌ها</small></span></div>
        <div><i>◇</i><span><b>Learning Studio</b><small>ساخت سؤال و تمرین و بررسی متن</small></span></div>
      </div>
    </div>
    <div class="side-footer"><span>PARSE ADMIN</span><span>PRIVATE SYSTEM · 2026</span></div>
  </aside>
</div>
<?php else: ?>
<div class="app-layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand"><div class="brand-symbol">P<span></span></div><div><strong>PARSE<span>ADMIN</span></strong><small>CONTROL CENTER</small></div></div>
    <div class="workspace-switch"><span class="workspace-dot"></span><span><b>Parse Workspace</b><small>فضای کاری اصلی</small></span><span class="chevron">⌄</span></div>
    <nav class="side-nav">
<?php if ($isDeveloperAdmin): ?>
      <div class="nav-caption">نمای کلی</div><button class="nav-item active" data-page="dashboard"><i>▦</i><span>داشبورد</span></button><button class="nav-item" data-page="models"><i>◈</i><span>کاتالوگ مدل‌ها</span></button>
      <div class="nav-caption">استودیوی هوش مصنوعی</div><button class="nav-item" data-page="questions"><i>✧</i><span>تولید سؤال</span></button><button class="nav-item" data-page="practice"><i>▤</i><span>طراحی تمرین</span></button><button class="nav-item" data-page="review"><i>≋</i><span>بررسی و ویرایش متن</span></button><button class="nav-item" data-page="playground"><i>⌘</i><span>AI Playground</span></button>
      <div class="nav-caption">ابزارهای پیشرفته</div><button class="nav-item" data-page="multimodal"><i>✺</i><span>تصویر و صوت</span></button><button class="nav-item" data-page="structured"><i>⌗</i><span>تصمیم و Embedding</span></button><button class="nav-item" data-page="batches"><i>⇶</i><span>Batch و رسید درخواست</span></button>
      <div class="nav-caption">گزارش و پیکربندی</div><button class="nav-item" data-page="usage"><i>◷</i><span>مصرف و هزینه‌ها</span></button><button class="nav-item" data-page="history"><i>▧</i><span>تاریخچه تولید</span></button>
      <div class="nav-caption">مدیریت کاربران و محتوا</div><button class="nav-item" data-page="question-bank"><i>▤</i><span>بانک هوشمند سؤال</span></button><button class="nav-item" data-page="users"><i>♙</i><span>مدیریت مدیران</span></button>
<?php else: ?>
      <div class="nav-caption">نمای کلی</div><button class="nav-item active" data-page="dashboard"><i>▦</i><span>داشبورد آموزشی</span></button>
      <div class="nav-caption">استودیوی آموزشی</div><button class="nav-item" data-page="questions"><i>✧</i><span>تولید سؤال</span></button><button class="nav-item" data-page="practice"><i>▤</i><span>طراحی تمرین</span></button><button class="nav-item" data-page="review"><i>≋</i><span>بررسی و ویرایش متن</span></button><button class="nav-item" data-page="question-bank"><i>▤</i><span>بانک هوشمند سؤال</span></button><div class="nav-caption">مدیریت اپلیکیشن آموزشی</div>
<?php endif; ?>
      <button class="nav-item" data-page="students"><i>♙</i><span>دانش‌آموزان</span></button><button class="nav-item" data-page="resources"><i>▣</i><span>کتاب‌ها و جزوه‌ها</span></button><button class="nav-item" data-page="exams"><i>◷</i><span>آزمون‌ها و زمان‌بندی</span></button><button class="nav-item" data-page="attempts"><i>✓</i><span>پاسخ‌نامه‌ها و نمرات</span></button><button class="nav-item" data-page="monitoring"><i>⌁</i><span>مانیتورینگ اپ</span></button><button class="nav-item" data-page="tracks"><i>⌘</i><span>زبان و مسیر آموزشی</span></button><button class="nav-item" data-page="activities"><i>▤</i><span>تمرین‌ها و تکالیف</span></button><button class="nav-item" data-page="activity-submissions"><i>✓</i><span>پاسخ تمرین‌ها</span></button>
<?php if ($isDeveloperAdmin): ?><div class="nav-caption">پیکربندی سیستم</div><button class="nav-item" data-page="prompts"><i>✎</i><span>قالب‌های دستور AI</span></button><button class="nav-item" data-page="settings"><i>⚙</i><span>تنظیمات اتصال</span></button><?php endif; ?>
</nav>
    <div class="sidebar-bottom"><div class="admin-mini"><div class="avatar"><?= app_e(mb_strtoupper(mb_substr((string)$admin['email'],0,1))) ?></div><div><b><?= app_e($admin['email']) ?></b><small><?= app_e($isDeveloperAdmin ? 'ادمین برنامه‌نویس' : 'ادمین آموزشی') ?></small></div></div><form method="post" action="/"><input type="hidden" name="action" value="logout"><input type="hidden" name="_csrf" value="<?= app_e($csrf) ?>"><button class="logout-button" type="submit">↪ خروج امن</button></form></div>
  </aside>

  <main class="main-area">
    <header class="topbar"><div class="topbar-left"><button class="mobile-menu" id="mobileMenu">☰</button><div><div class="breadcrumb">Parse <span>/</span> <b id="breadcrumbTitle">داشبورد</b></div><div class="topbar-subtitle">مدیریت و پایش پلتفرم آموزشی</div></div></div><div class="topbar-right"><div class="system-status"><i></i><span>پنل فعال</span></div><button class="icon-button" id="globalRefresh" title="به‌روزرسانی">↻</button><div class="top-avatar"><?= app_e(mb_strtoupper(mb_substr((string)$admin['email'],0,1))) ?></div></div></header>

    <?php if (!empty($pageAccess['dashboard'])) { $pageLocal = __DIR__ . '/pages/dashboard.local.php'; $pageDefault = __DIR__ . '/pages/dashboard.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['models'])) { $pageLocal = __DIR__ . '/pages/models.local.php'; $pageDefault = __DIR__ . '/pages/models.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['questions'])) { $pageLocal = __DIR__ . '/pages/questions.local.php'; $pageDefault = __DIR__ . '/pages/questions.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['practice'])) { $pageLocal = __DIR__ . '/pages/practice.local.php'; $pageDefault = __DIR__ . '/pages/practice.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['review'])) { $pageLocal = __DIR__ . '/pages/review.local.php'; $pageDefault = __DIR__ . '/pages/review.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['playground'])) { $pageLocal = __DIR__ . '/pages/playground.local.php'; $pageDefault = __DIR__ . '/pages/playground.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['multimodal'])) { $pageLocal = __DIR__ . '/pages/multimodal.local.php'; $pageDefault = __DIR__ . '/pages/multimodal.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['structured'])) { $pageLocal = __DIR__ . '/pages/structured.local.php'; $pageDefault = __DIR__ . '/pages/structured.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['batches'])) { $pageLocal = __DIR__ . '/pages/batches.local.php'; $pageDefault = __DIR__ . '/pages/batches.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['usage'])) { $pageLocal = __DIR__ . '/pages/usage.local.php'; $pageDefault = __DIR__ . '/pages/usage.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['history'])) { $pageLocal = __DIR__ . '/pages/history.local.php'; $pageDefault = __DIR__ . '/pages/history.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['question-bank'])) { $pageLocal = __DIR__ . '/pages/question-bank.local.php'; $pageDefault = __DIR__ . '/pages/question-bank.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['users'])) { $pageLocal = __DIR__ . '/pages/users.local.php'; $pageDefault = __DIR__ . '/pages/users.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['students'])) { $pageLocal = __DIR__ . '/pages/students.local.php'; $pageDefault = __DIR__ . '/pages/students.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['resources'])) { $pageLocal = __DIR__ . '/pages/resources.local.php'; $pageDefault = __DIR__ . '/pages/resources.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['exams'])) { $pageLocal = __DIR__ . '/pages/exams.local.php'; $pageDefault = __DIR__ . '/pages/exams.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['attempts'])) { $pageLocal = __DIR__ . '/pages/attempts.local.php'; $pageDefault = __DIR__ . '/pages/attempts.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['monitoring'])) { $pageLocal = __DIR__ . '/pages/monitoring.local.php'; $pageDefault = __DIR__ . '/pages/monitoring.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['tracks'])) { $pageLocal = __DIR__ . '/pages/tracks.local.php'; $pageDefault = __DIR__ . '/pages/tracks.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['activities'])) { $pageLocal = __DIR__ . '/pages/activities.local.php'; $pageDefault = __DIR__ . '/pages/activities.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['activity-submissions'])) { $pageLocal = __DIR__ . '/pages/activity-submissions.local.php'; $pageDefault = __DIR__ . '/pages/activity-submissions.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['prompts'])) { $pageLocal = __DIR__ . '/pages/prompts.local.php'; $pageDefault = __DIR__ . '/pages/prompts.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>

    <?php if (!empty($pageAccess['settings'])) { $pageLocal = __DIR__ . '/pages/settings.local.php'; $pageDefault = __DIR__ . '/pages/settings.php'; require is_file($pageLocal) ? $pageLocal : $pageDefault; } ?>
    <footer class="app-footer"><span>PARSE ADMIN</span><span>Secure server-side console · <?= date('Y') ?></span></footer>
  </main>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="/assets/admin.js?v=<?= app_e((string)$assetVersion) ?>" defer></script>
<?php endif; ?>
</body></html>

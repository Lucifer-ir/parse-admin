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
                    && $user['role']==='admin' && (bool)$user['active'];

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
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
$csrf=app_csrf();
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0a1222">
<meta name="csrf-token" content="<?= app_e($csrf) ?>">
<title><?= $admin ? 'کنسول مدیریت Parse' : 'ورود مدیر | Parse Admin' ?></title>
<link rel="stylesheet" href="/assets/admin.css?v=20261123-php-console-1">
</head>
<body class="<?= $admin ? 'app-page' : 'login-page' ?>">
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
      <div class="nav-caption">نمای کلی</div>
      <button class="nav-item active" data-page="dashboard"><i>▦</i><span>داشبورد</span></button>
      <button class="nav-item" data-page="models"><i>◈</i><span>کاتالوگ مدل‌ها</span></button>
      <div class="nav-caption">استودیوی هوش مصنوعی</div>
      <button class="nav-item" data-page="questions"><i>✧</i><span>تولید سؤال</span></button>
      <button class="nav-item" data-page="practice"><i>▤</i><span>طراحی تمرین</span></button>
      <button class="nav-item" data-page="review"><i>≋</i><span>بررسی و ویرایش متن</span></button>
      <button class="nav-item" data-page="playground"><i>⌘</i><span>AI Playground</span></button>
      <div class="nav-caption">ابزارهای پیشرفته</div>
      <button class="nav-item" data-page="multimodal"><i>✺</i><span>تصویر و صوت</span></button>
      <button class="nav-item" data-page="structured"><i>⌗</i><span>تصمیم و Embedding</span></button>
      <button class="nav-item" data-page="batches"><i>⇶</i><span>Batch و رسید درخواست</span></button>
      <div class="nav-caption">گزارش و پیکربندی</div>
      <button class="nav-item" data-page="usage"><i>◷</i><span>مصرف و هزینه‌ها</span></button>
      <button class="nav-item" data-page="history"><i>▧</i><span>تاریخچه تولید</span></button>
      <div class="nav-caption">مدیریت محتوا و کاربران</div>
      <button class="nav-item" data-page="question-bank"><i>▤</i><span>بانک سؤال‌ها</span></button>
      <button class="nav-item" data-page="users"><i>♙</i><span>مدیریت کاربران</span></button>
      <button class="nav-item" data-page="prompts"><i>✎</i><span>قالب‌های دستور AI</span></button>
      <button class="nav-item" data-page="settings"><i>⚙</i><span>تنظیمات اتصال</span></button>
    </nav>
    <div class="sidebar-bottom"><div class="admin-mini"><div class="avatar"><?= app_e(mb_strtoupper(mb_substr((string)$admin['email'],0,1))) ?></div><div><b><?= app_e($admin['email']) ?></b><small>مدیر سیستم</small></div></div><form method="post" action="/"><input type="hidden" name="action" value="logout"><input type="hidden" name="_csrf" value="<?= app_e($csrf) ?>"><button class="logout-button" type="submit">↪ خروج امن</button></form></div>
  </aside>

  <main class="main-area">
    <header class="topbar"><div class="topbar-left"><button class="mobile-menu" id="mobileMenu">☰</button><div><div class="breadcrumb">Parse <span>/</span> <b id="breadcrumbTitle">داشبورد</b></div><div class="topbar-subtitle">مدیریت و پایش پلتفرم آموزشی</div></div></div><div class="topbar-right"><div class="system-status"><i></i><span>پنل فعال</span></div><button class="icon-button" id="globalRefresh" title="به‌روزرسانی">↻</button><div class="top-avatar"><?= app_e(mb_strtoupper(mb_substr((string)$admin['email'],0,1))) ?></div></div></header>

    <section class="page-view active" id="page-dashboard">
      <div class="page-heading"><div><div class="eyebrow">OVERVIEW / CONTROL CENTER</div><h1>داشبورد مدیریتی</h1><p>نمای جامع عملکرد سرویس، درخواست‌های هوش مصنوعی و هزینه‌ها.</p></div><div class="heading-actions"><span class="date-pill" id="todayLabel"></span><button class="button button-ghost" id="refreshDashboard">↻ به‌روزرسانی</button></div></div>
      <div class="metric-grid">
        <article class="metric-card accent-cyan"><div class="metric-top"><span>هزینه امروز</span><i>₮</i></div><strong id="todayCost">—</strong><small>تومان · مجموع درخواست‌های ثبت‌شده</small><div class="metric-foot"><span id="todayRequests">— درخواست</span><span>امروز</span></div></article>
        <article class="metric-card accent-violet"><div class="metric-top"><span>هزینه ماه جاری</span><i>◷</i></div><strong id="monthCost">—</strong><small>تومان · از ابتدای ماه</small><div class="metric-foot"><span id="monthRequests">— درخواست</span><span>این ماه</span></div></article>
        <article class="metric-card accent-green"><div class="metric-top"><span>توکن مصرف‌شده</span><i>✧</i></div><strong id="monthTokens">—</strong><small>توکن ورودی و خروجی ثبت‌شده</small><div class="metric-foot"><span id="allRequests">— درخواست کل</span><span>تجمیعی</span></div></article>
        <article class="metric-card accent-orange"><div class="metric-top"><span>مدل‌های استفاده‌شده</span><i>◈</i></div><strong id="modelCount">—</strong><small>مدل‌های متمایز در پنل</small><div class="metric-foot"><span id="userCount">— کاربر</span><span>سامانه</span></div></article>
      </div>
      <div class="dashboard-grid"><section class="panel-card"><div class="panel-heading"><div><h3>مصرف و هزینه مدل‌ها</h3><p>مقایسه درخواست‌ها در مدل‌های پرکاربرد</p></div><button class="link-button" data-go="usage">گزارش کامل ←</button></div><div id="modelUsage" class="model-usage"><div class="empty-state">در حال دریافت اطلاعات...</div></div></section><section class="panel-card"><div class="panel-heading"><div><h3>وضعیت اتصال AI</h3><p>وضعیت پیکربندی سرویس</p></div><span class="status-badge" id="providerBadge">در حال بررسی</span></div><div class="provider-state"><div class="provider-logo">✳</div><div><b>Paasta AI Gateway</b><small id="providerStateText">بررسی تنظیمات کلید API</small></div></div><div class="provider-meta"><span>آدرس سرویس</span><b dir="ltr">console.paasta.cloud/api/ai/v1</b></div><div class="provider-meta"><span>کلید API</span><b id="providerMask">پیکربندی نشده</b></div><button class="button button-secondary full-button" data-go="settings">مدیریت اتصال و کلید ←</button></section></div>
      <section class="panel-card table-panel"><div class="panel-heading"><div><h3>آخرین درخواست‌ها</h3><p>عملیات‌های اخیر ثبت‌شده در درگاه هوش مصنوعی</p></div><button class="link-button" data-go="usage">مشاهده همه ←</button></div><div class="table-scroll"><table><thead><tr><th>عملیات</th><th>مدل</th><th>وضعیت</th><th>توکن</th><th>هزینه (تومان)</th><th>زمان</th></tr></thead><tbody id="recentUsage"><tr><td colspan="6" class="empty-cell">در انتظار اطلاعات</td></tr></tbody></table></div></section>
    </section>

    <section class="page-view" id="page-models">
      <div class="page-heading"><div><div class="eyebrow">MODEL REGISTRY</div><h1>کاتالوگ مدل‌ها</h1><p>دریافت مدل‌ها و قابلیت‌ها مستقیماً از API پاستا.</p></div><button class="button button-ghost" id="refreshModels">↻ دریافت کاتالوگ</button></div>
      <div class="catalog-tabs"><button class="catalog-tab active" data-catalog="models">مدل‌های متنی</button><button class="catalog-tab" data-catalog="decisions">تصمیم‌گیری</button><button class="catalog-tab" data-catalog="images">تصویر</button><button class="catalog-tab" data-catalog="audio">مدل‌های صوت</button><button class="catalog-tab" data-catalog="speech">صداها و Voice</button><button class="catalog-tab" data-catalog="embeddings">Embedding</button></div>
      <div class="filter-row"><input id="modelSearch" class="input" placeholder="جست‌وجوی مدل یا شناسه..."><span id="modelCountLabel" class="muted-label"></span></div><div id="modelGrid" class="model-grid"><div class="empty-state">برای دریافت کاتالوگ، اتصال API را در تنظیمات ثبت کنید.</div></div>
    </section>

    <section class="page-view" id="page-questions">
      <div class="page-heading"><div><div class="eyebrow">LEARNING STUDIO / QUESTION BUILDER</div><h1>تولید هوشمند سؤال</h1><p>ساخت سؤال‌های آموزشی متناسب با پایه، موضوع و سطح دشواری.</p></div><span class="tag tag-ai">AI POWERED</span></div>
      <div class="workspace-grid"><form class="panel-card form-panel" id="questionForm"><div class="panel-heading"><div><h3>مشخصات سؤال‌ها</h3><p>پارامترهای تولید را مشخص کن.</p></div><span class="step-number">01</span></div>
        <label>مدل هوش مصنوعی<select class="input model-select" name="model" data-kind="models" required><option value="">ابتدا کاتالوگ را دریافت کن</option></select></label>
        <div class="form-two"><label>درس / موضوع<input class="input" name="topic" placeholder="مثلاً ریاضی، کسرها" required></label><label>پایه / مخاطب<input class="input" name="grade" placeholder="مثلاً پایه هفتم"></label></div>
        <div class="form-two"><label>تعداد سؤال<select class="input" name="count"><option>5</option><option>10</option><option>15</option><option>20</option></select></label><label>سطح دشواری<select class="input" name="difficulty"><option>آسان</option><option selected>متوسط</option><option>سخت</option><option>ترکیبی</option></select></label></div>
        <label>دستورالعمل تکمیلی<textarea class="input textarea" name="instructions" rows="3" placeholder="نوع سؤال، بارم‌بندی، فرمت پاسخ و..."></textarea></label>
        <label class="check-row"><input type="checkbox" name="save_history" checked><span>ذخیره خروجی در تاریخچه تولید</span></label>
        <button class="button button-primary full-button" type="submit">✧ تولید سؤال‌ها</button><div class="form-message" id="questionMessage"></div>
      </form><section class="panel-card result-panel"><div class="panel-heading"><div><h3>خروجی تولید</h3><p>نتیجه مدل در این بخش نمایش داده می‌شود.</p></div><span class="result-live" id="questionResultStatus">آماده</span></div><div id="questionOutput" class="output-area"><div class="output-placeholder"><div>✧</div><b>هنوز سؤالی تولید نشده</b><span>پارامترها را وارد کن و روی تولید سؤال‌ها بزن.</span></div></div><div class="result-actions"><button class="button button-secondary" data-copy="questionOutput">کپی خروجی</button><button class="button button-secondary" id="saveQuestions">ذخیره در بانک سؤال</button></div><div class="usage-inline" id="questionUsage"></div></section></div>
    </section>

    <section class="page-view" id="page-practice">
      <div class="page-heading"><div><div class="eyebrow">LEARNING STUDIO / PRACTICE DESIGNER</div><h1>طراحی تمرین آموزشی</h1><p>تمرین مرحله‌ای، تکلیف، فعالیت کلاسی و معیار ارزیابی بساز.</p></div><span class="tag tag-ai">AI POWERED</span></div>
      <div class="workspace-grid"><form class="panel-card form-panel" id="practiceForm"><div class="panel-heading"><div><h3>تنظیمات تمرین</h3><p>چارچوب محتوای آموزشی را تعیین کن.</p></div><span class="step-number">02</span></div><label>مدل هوش مصنوعی<select class="input model-select" name="model" data-kind="models"><option value="">انتخاب مدل</option></select></label><div class="form-two"><label>موضوع<input class="input" name="topic" placeholder="موضوع تمرین" required></label><label>پایه / سطح<input class="input" name="grade" placeholder="مثلاً متوسطه اول"></label></div><div class="form-two"><label>تعداد فعالیت<select class="input" name="count"><option>3</option><option selected>5</option><option>8</option><option>10</option></select></label><label>سطح<select class="input" name="difficulty"><option>آسان</option><option selected>متوسط</option><option>سخت</option><option>ترکیبی</option></select></label></div><label>هدف یا توضیح تکمیلی<textarea class="input textarea" rows="4" name="instructions" placeholder="هدف یادگیری، زمان، نوع فعالیت و..."></textarea></label><label class="check-row"><input type="checkbox" name="save_history" checked><span>ذخیره در تاریخچه تولید</span></label><button class="button button-primary full-button" type="submit">✧ طراحی تمرین</button><div class="form-message" id="practiceMessage"></div></form><section class="panel-card result-panel"><div class="panel-heading"><div><h3>تمرین‌های تولیدشده</h3><p>خروجی آماده بازبینی و استفاده</p></div><span class="result-live">خروجی هوش مصنوعی</span></div><div id="practiceOutput" class="output-area"><div class="output-placeholder"><div>▤</div><b>فضای طراحی تمرین</b><span>موضوع و سطح تمرین را مشخص کن.</span></div></div><div class="result-actions"><button class="button button-secondary" data-copy="practiceOutput">کپی خروجی</button></div><div class="usage-inline" id="practiceUsage"></div></section></div>
    </section>

    <section class="page-view" id="page-review">
      <div class="page-heading"><div><div class="eyebrow">LEARNING STUDIO / TEXT ANALYSIS</div><h1>بررسی و ویرایش متن</h1><p>تحلیل املا، دستور زبان، انسجام، وضوح و بازنویسی متن.</p></div><span class="tag tag-ai">AI POWERED</span></div>
      <div class="workspace-grid review-grid"><form class="panel-card form-panel" id="reviewForm"><div class="panel-heading"><div><h3>متن برای بررسی</h3><p>متن ورودی را وارد کن.</p></div></div><label>مدل بررسی<select class="input model-select" name="model" data-kind="models"><option value="">انتخاب مدل</option></select></label><label>متن<textarea class="input textarea review-text" name="text" rows="12" placeholder="متن موردنظر برای بررسی را اینجا وارد کن..." required></textarea></label><label>نوع بررسی<select class="input" name="instructions"><option value="اشتباهات را دسته‌بندی کن، دلیل هر اصلاح را توضیح بده و در پایان نسخه اصلاح‌شده را ارائه کن.">بررسی کامل و اصلاح</option><option value="فقط غلط‌های املایی و نگارشی را مشخص و اصلاح کن.">املا و نگارش</option><option value="ساختار، انسجام، لحن و وضوح متن را ارزیابی و بازنویسی کن.">ساختار و کیفیت نگارش</option><option value="سطح متن، تناسب با مخاطب و کیفیت آموزشی را ارزیابی کن.">ارزیابی آموزشی</option></select></label><label class="check-row"><input type="checkbox" name="save_history" checked><span>ذخیره نتیجه در تاریخچه</span></label><button class="button button-primary full-button" type="submit">✧ بررسی و اصلاح متن</button><div class="form-message" id="reviewMessage"></div></form><section class="panel-card result-panel"><div class="panel-heading"><div><h3>گزارش بررسی</h3><p>ایرادها و نسخه پیشنهادی</p></div></div><div id="reviewOutput" class="output-area"><div class="output-placeholder"><div>≋</div><b>متن هنوز بررسی نشده</b><span>پس از اجرا، گزارش در این بخش قرار می‌گیرد.</span></div></div><div class="result-actions"><button class="button button-secondary" data-copy="reviewOutput">کپی گزارش</button></div><div class="usage-inline" id="reviewUsage"></div></section></div>
    </section>

    <section class="page-view" id="page-playground">
      <div class="page-heading"><div><div class="eyebrow">MODEL PLAYGROUND</div><h1>محیط آزمایش مدل‌ها</h1><p>ارسال مستقیم درخواست Chat Completions و Responses از سرور Parse.</p></div><span class="tag tag-ai">SERVER SIDE</span></div>
      <div class="workspace-grid playground-grid"><form class="panel-card form-panel" id="chatForm"><div class="panel-heading"><div><h3>تنظیم درخواست</h3><p>پارامترهای مدل را انتخاب کن.</p></div></div><label>مدل<select class="input model-select" name="model" data-kind="models"><option value="">انتخاب مدل</option></select></label><label>System Prompt<textarea class="input textarea" name="system" rows="3" placeholder="نقش و دستور مدل..."></textarea></label><label>پیام کاربر<textarea class="input textarea" name="message" rows="6" placeholder="پیام خود را بنویس..." required></textarea></label>
        <details class="advanced-settings"><summary>پارامترهای پیشرفته · Tools و JSON Schema</summary>
          <label>Messages JSON (چندبخشی، فایل و PDF)<textarea class="input textarea code-input" name="messages_json" rows="4" placeholder='[{"role":"user","content":[{"type":"text","text":"متن را بررسی کن"}]}]'></textarea></label>
          <label>Responses Input JSON (برای فایل و ورودی ساختاریافته)<textarea class="input textarea code-input" name="responses_input_json" rows="4" placeholder='[{"role":"user","content":[{"type":"input_text","text":"این فایل را خلاصه کن"}]}]'></textarea></label>
          <label>Function Tools JSON<textarea class="input textarea code-input" name="tools_json" rows="5" placeholder='[{"type":"function","function":{"name":"lookup","description":"...","parameters":{"type":"object","properties":{},"required":[],"additionalProperties":false},"strict":true}}]'></textarea></label>
          <label>Tool Choice<input class="input" name="tool_choice" dir="ltr" placeholder="auto / none / required"></label>
          <label>Response Format / JSON Schema<textarea class="input textarea code-input" name="response_format_json" rows="5" placeholder='{"type":"json_schema","json_schema":{"name":"result","strict":true,"schema":{"type":"object","properties":{},"required":[],"additionalProperties":false}}}'></textarea></label>
          <label>سایر گزینه‌های API (JSON) · مانند pdf_processing<textarea class="input textarea code-input" name="advanced_options_json" rows="3" placeholder='{"pdf_processing":{"allow_external":false}}'></textarea></label>
          <label class="check-row"><input type="checkbox" name="web_search"><span>درخواست جست‌وجوی وب از طریق Responses API</span></label>
        </details>
        <div class="form-two"><label>حداکثر توکن خروجی<input class="input" name="max_tokens" type="number" min="64" max="8192" value="1024"></label><label>Temperature<input class="input" name="temperature" type="number" min="0" max="2" step="0.1" value="0.7"></label></div><label class="check-row"><input type="checkbox" name="save_history"><span>ذخیره گفت‌وگو در تاریخچه</span></label><button class="button button-primary full-button" type="submit">ارسال درخواست</button><div class="form-two"><button class="button button-secondary full-button" id="runResponses" type="button">اجرا با Responses API</button><button class="button button-secondary full-button" id="runMessages" type="button">اجرا با Anthropic Messages</button></div><button class="button button-secondary full-button" id="runStream" type="button">اجرای جریانی (SSE)</button><div class="form-message" id="chatMessage"></div></form><section class="panel-card result-panel"><div class="panel-heading"><div><h3>پاسخ مدل</h3><p>خروجی درخواست و شناسه رسید</p></div><span class="result-live" id="chatState">آماده</span></div><div id="chatOutput" class="output-area"><div class="output-placeholder"><div>⌘</div><b>منتظر پیام شما</b><span>یک مدل انتخاب و درخواست را ارسال کن.</span></div></div><div class="result-actions"><button class="button button-secondary" data-copy="chatOutput">کپی پاسخ</button></div><div class="usage-inline" id="chatUsage"></div></section></div>
    </section>

    <section class="page-view" id="page-multimodal">
      <div class="page-heading"><div><div class="eyebrow">MULTIMODAL TOOLKIT</div><h1>ابزارهای تصویر و صوت</h1><p>تولید تصویر، ویرایش، تبدیل گفتار به متن و تولید گفتار.</p></div><span class="tag tag-ai">MULTIMODAL</span></div>
      <div class="tool-tabs"><button class="tool-tab active" data-tool="imageTool">تولید تصویر</button><button class="tool-tab" data-tool="imageEditTool">ویرایش تصویر</button><button class="tool-tab" data-tool="transcribeTool">گفتار به متن</button><button class="tool-tab" data-tool="speechTool">تولید گفتار</button></div>
      <div class="tool-pane active" id="imageTool"><div class="workspace-grid"><form class="panel-card form-panel" id="imageForm"><h3>تولید تصویر</h3><label>مدل تصویر<select class="input model-select" name="model" data-kind="images"><option value="">انتخاب مدل تصویر</option></select></label><label>شرح تصویر<textarea class="input textarea" name="prompt" rows="5" placeholder="تصویر موردنظر را با جزئیات توصیف کن..." required></textarea></label><label>اندازه<select class="input" name="size"><option value="1024x1024">1024 × 1024</option><option value="1024x1536">1024 × 1536</option><option value="1536x1024">1536 × 1024</option></select></label><button class="button button-primary full-button" type="submit">ساخت تصویر</button><div class="form-message" id="imageMessage"></div></form><section class="panel-card result-panel"><h3>خروجی تصویر</h3><div id="imageOutput" class="media-output">پس از تولید تصویر، نتیجه اینجا نمایش داده می‌شود.</div></section></div></div>
      <div class="tool-pane" id="imageEditTool"><div class="workspace-grid"><form class="panel-card form-panel" id="imageEditForm"><h3>ویرایش تصویر با URL</h3><label>مدل ویرایش<select class="input model-select" name="model" data-kind="images"><option value="">مدل دارای supports_edit</option></select></label><label>شرح ویرایش<textarea class="input textarea" rows="4" name="prompt" required placeholder="چه تغییری در تصویر اعمال شود؟"></textarea></label><label>آدرس‌های عمومی HTTPS تصویر (هر خط یک آدرس)<textarea class="input textarea" rows="3" name="image_urls" required placeholder="https://example.com/image.png"></textarea></label><label>آدرس ماسک (اختیاری)<input class="input" name="mask_url" type="url" placeholder="https://..."></label><button class="button button-primary full-button" type="submit">ارسال ویرایش</button><div class="form-message" id="imageEditMessage"></div></form><section class="panel-card result-panel"><h3>نتیجه ویرایش</h3><pre id="imageEditOutput" class="output-area"></pre></section></div></div>
      <div class="tool-pane" id="transcribeTool"><div class="workspace-grid"><form class="panel-card form-panel" id="transcribeForm"><h3>تبدیل صوت به متن</h3><label>مدل صوت<select class="input model-select" name="model" data-kind="audio"><option value="">انتخاب مدل صوت</option></select></label><label>فایل صوتی<input class="input file-input" type="file" name="audio" accept="audio/*" required></label><label>زبان<select class="input" name="language"><option value="fa">فارسی</option><option value="en">انگلیسی</option><option value="ar">عربی</option></select></label><button class="button button-primary full-button" type="submit">تبدیل به متن</button><div class="form-message" id="transcribeMessage"></div></form><section class="panel-card result-panel"><h3>متن استخراج‌شده</h3><div id="transcribeOutput" class="output-area"></div><button class="button button-secondary" data-copy="transcribeOutput">کپی متن</button></section></div></div>
      <div class="tool-pane" id="speechTool"><div class="workspace-grid"><form class="panel-card form-panel" id="speechForm"><h3>تبدیل متن به گفتار</h3><label>مدل گفتار<select class="input model-select" name="model" data-kind="audio"><option value="">مدل TTS را انتخاب کنید</option></select></label><label>متن (حداکثر ۴۰۰۰ نویسه)<textarea class="input textarea" name="input" rows="5" maxlength="4000" required></textarea></label><div class="form-two"><label>Voice<select class="input model-select" name="voice" data-kind="speech"><option value="coral">coral · پیش‌فرض</option></select></label><label>قالب خروجی<select class="input" name="response_format"><option>mp3</option><option>pcm</option></select></label></div><button class="button button-primary full-button" type="submit">تولید صدا</button><div class="form-message" id="speechMessage"></div></form><section class="panel-card result-panel"><h3>پخش خروجی صوتی</h3><div id="speechOutput" class="media-output">فایل صوتی بعد از تولید نمایش داده می‌شود.</div></section></div></div>
    </section>

    <section class="page-view" id="page-structured">
      <div class="page-heading"><div><div class="eyebrow">STRUCTURED INTELLIGENCE</div><h1>تصمیم‌گیری و Embedding</h1><p>اجرای مدل‌های تصمیم ساختاریافته و بردارسازی متن.</p></div></div>
      <div class="tool-tabs"><button class="tool-tab active" data-tool="decisionTool">Decision API</button><button class="tool-tab" data-tool="embeddingTool">Embeddings</button><button class="tool-tab" data-tool="knowledgeTool">جست‌وجوی پایگاه دانش</button></div>
      <div class="tool-pane active" id="decisionTool"><div class="workspace-grid"><form class="panel-card form-panel" id="decisionForm"><h3>تصمیم‌گیری ساختاریافته</h3><label>مدل تصمیم<select class="input model-select" name="model" data-kind="decisions"><option value="">انتخاب مدل Decision</option></select></label><label>State<textarea class="input textarea" name="state" rows="4" required placeholder="شرح وضعیت یا داده ورودی"></textarea></label><label>Questions JSON<textarea class="input textarea code-input" name="questions" rows="7" required>{"is_bug":{"type":"noul","instructions":"آیا این مورد یک باگ است؟","criteria":{"true":"رفتار خراب یا غیرمنتظره","false":"درخواست ویژگی یا پرسش"}}}</textarea></label><button class="button button-primary full-button" type="submit">اجرای Decision</button><div class="form-message" id="decisionMessage"></div></form><section class="panel-card result-panel"><h3>نتیجه تصمیم</h3><pre class="output-area" id="decisionOutput"></pre></section></div></div>
      <div class="tool-pane" id="embeddingTool"><div class="workspace-grid"><form class="panel-card form-panel" id="embeddingForm"><h3>تولید Embedding</h3><label>مدل Embedding<select class="input model-select" name="model" data-kind="embeddings"><option value="">انتخاب مدل</option></select></label><label>متن یا لیست متن (هر خط یک ورودی)<textarea class="input textarea" name="input" rows="6" required></textarea></label><button class="button button-primary full-button" type="submit">ساخت بردارها</button><div class="form-message" id="embeddingMessage"></div></form><section class="panel-card result-panel"><h3>بردار خروجی</h3><pre id="embeddingOutput" class="output-area"></pre></section></div></div>
      <div class="tool-pane" id="knowledgeTool"><div class="workspace-grid"><form class="panel-card form-panel" id="knowledgeForm"><h3>جست‌وجوی پایگاه دانش</h3><label>شناسه Knowledge Base<input class="input" name="kb_id" required></label><label>پرس‌وجو<textarea class="input textarea" name="query" rows="4" required></textarea></label><div class="form-two"><label>Top K<input class="input" type="number" name="top_k" value="5" min="1" max="20"></label><label>روش بازیابی<select class="input" name="retrieval_mode"><option>hybrid</option><option>semantic</option><option>keyword</option></select></label></div><button class="button button-primary full-button" type="submit">جست‌وجوی دانش</button><div class="form-message" id="knowledgeMessage"></div></form><section class="panel-card result-panel"><h3>نتایج بازیابی</h3><pre id="knowledgeOutput" class="output-area"></pre></section></div></div>
    </section>

    <section class="page-view" id="page-batches">
      <div class="page-heading"><div><div class="eyebrow">ASYNC JOBS / RECEIPTS</div><h1>Batch و رسید درخواست</h1><p>ثبت Batch متنی/تصویری و بررسی وضعیت یا رسید مصرف.</p></div></div>
      <div class="tool-tabs"><button class="tool-tab active" data-tool="batchTool">Batch متنی</button><button class="tool-tab" data-tool="imageBatchTool">Batch تصویر</button><button class="tool-tab" data-tool="receiptTool">رسید و تطبیق مصرف</button></div>
      <div class="tool-pane active" id="batchTool"><div class="workspace-grid"><form class="panel-card form-panel" id="batchForm"><h3>ارسال Batch متنی</h3><label>مدل<select class="input model-select" name="model" data-kind="models"><option value="">انتخاب مدل</option></select></label><label>درخواست‌ها (هر خط یک درخواست)<textarea class="input textarea" name="requests" rows="6" placeholder="یک خلاصه کوتاه بنویس&#10;این متن را تحلیل کن..." required></textarea></label><label>حداکثر توکن هر ردیف<input class="input" name="max_tokens" type="number" value="256" min="16" max="8192"></label><button class="button button-primary full-button" type="submit">ثبت Batch</button><div class="form-message" id="batchMessage"></div></form><section class="panel-card result-panel"><h3>وضعیت Batch</h3><pre id="batchOutput" class="output-area"></pre></section></div></div>
      <div class="tool-pane" id="imageBatchTool"><div class="workspace-grid"><form class="panel-card form-panel" id="imageBatchForm"><h3>Batch تصویر</h3><label>مدل تصویر<select class="input model-select" name="model" data-kind="images"><option value="">انتخاب مدل تصویر</option></select></label><label>شرح تصاویر (هر خط یک درخواست)<textarea class="input textarea" name="prompts" rows="5" required></textarea></label><button class="button button-primary full-button" type="submit">ثبت Batch تصویر</button><div class="form-message" id="imageBatchMessage"></div></form><section class="panel-card result-panel"><h3>پاسخ Batch تصویر</h3><pre id="imageBatchOutput" class="output-area"></pre></section></div></div>
      <div class="tool-pane" id="receiptTool"><div class="workspace-grid"><form class="panel-card form-panel" id="receiptForm"><h3>استعلام رسید تولید</h3><label>شناسه درخواست x-paasta-request-id<input class="input" name="id" required></label><button class="button button-primary full-button" type="submit">دریافت رسید</button><div class="form-message" id="receiptMessage"></div></form><section class="panel-card result-panel"><h3>رسید مصرف</h3><pre id="receiptOutput" class="output-area"></pre></section></div></div>
    </section>

    <section class="page-view" id="page-usage">
      <div class="page-heading"><div><div class="eyebrow">BILLING & ANALYTICS</div><h1>گزارش مصرف و هزینه</h1><p>آمار درخواست‌های ارسال‌شده از همین پنل؛ بدون ذخیره متن پیام‌ها در گزارش مصرف.</p></div><a class="button button-secondary" href="/api/usage.csv">↓ خروجی CSV</a></div>
      <section class="panel-card"><div class="filter-row"><label>از تاریخ<input class="input date-input" type="date" id="usageFrom"></label><label>تا تاریخ<input class="input date-input" type="date" id="usageTo"></label><button class="button button-primary" id="loadUsage">اعمال فیلتر</button></div><div class="usage-summary"><div><small>تعداد درخواست</small><b id="usageRequests">—</b></div><div><small>مجموع توکن</small><b id="usageTokens">—</b></div><div><small>هزینه (تومان)</small><b id="usageCost">—</b></div></div><div class="table-scroll"><table><thead><tr><th>زمان</th><th>عملیات</th><th>مدل</th><th>وضعیت</th><th>ورودی</th><th>خروجی</th><th>هزینه تومان</th><th>تاخیر</th></tr></thead><tbody id="usageRows"><tr><td colspan="8" class="empty-cell">در انتظار گزارش</td></tr></tbody></table></div></section>
      <p class="notice">توجه: گزارش داخلی فقط درخواست‌هایی را شامل می‌شود که از این پنل به درگاه پاستا فرستاده شده‌اند. برای مصرف کلید در اپلیکیشن‌ها یا سرویس‌های دیگر، گزارش پنل پاستا را نیز بررسی کنید.</p>
    </section>

    <section class="page-view" id="page-history">
      <div class="page-heading"><div><div class="eyebrow">GENERATION LIBRARY</div><h1>تاریخچه تولید</h1><p>خروجی‌های ذخیره‌شده سؤال، تمرین، بررسی متن و گفت‌وگو.</p></div><button class="button button-ghost" id="refreshHistory">↻ به‌روزرسانی</button></div>
      <div id="historyList" class="history-list"><div class="empty-state">در حال دریافت تاریخچه...</div></div>
    </section>

    <section class="page-view" id="page-question-bank">
      <div class="page-heading"><div><div class="eyebrow">CONTENT MANAGEMENT</div><h1>بانک سؤال‌ها</h1><p>جست‌وجو و مدیریت سؤال‌های ذخیره‌شده از تولید هوش مصنوعی.</p></div><button class="button button-ghost" id="refreshQuestionBank">↻ به‌روزرسانی</button></div>
      <section class="panel-card table-panel">
        <div class="filter-row"><input id="bankQuestionSearch" class="input" placeholder="جست‌وجوی سؤال، درس یا پایه..."><span id="bankQuestionCount" class="muted-label"></span></div>
        <div class="table-scroll"><table><thead><tr><th>شناسه</th><th>متن سؤال</th><th>درس</th><th>پایه</th><th>منبع</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody id="bankQuestionRows"><tr><td colspan="7" class="empty-cell">در حال دریافت...</td></tr></tbody></table></div>
      </section>
    </section>

    <section class="page-view" id="page-users">
      <div class="page-heading"><div><div class="eyebrow">USER & ACCESS MANAGEMENT</div><h1>مدیریت کاربران</h1><p>مشاهده حساب‌ها و کنترل وضعیت فعال یا غیرفعال آن‌ها.</p></div><button class="button button-ghost" id="refreshUsers">↻ به‌روزرسانی</button></div>
      <section class="panel-card table-panel">
        <div class="filter-row"><input id="userSearch" class="input" placeholder="جست‌وجوی ایمیل کاربر..."><span id="userCount" class="muted-label"></span></div>
        <div class="table-scroll"><table><thead><tr><th>شناسه</th><th>ایمیل</th><th>نقش</th><th>وضعیت</th><th>تاریخ عضویت</th><th>عملیات</th></tr></thead><tbody id="userRows"><tr><td colspan="6" class="empty-cell">در حال دریافت...</td></tr></tbody></table></div>
      </section>
      <p class="notice">برای جلوگیری از قفل‌شدن پنل، غیرفعال‌کردن حساب مدیر فعلی مجاز نیست.</p>
    </section>

    <section class="page-view" id="page-prompts">
      <div class="page-heading"><div><div class="eyebrow">AI PROMPT MANAGEMENT</div><h1>قالب‌های دستور هوش مصنوعی</h1><p>دستور سیستم تولید سؤال، تمرین و بررسی متن را بدون تغییر کد مدیریت کن.</p></div></div>
      <div class="workspace-grid">
        <section class="panel-card form-panel">
          <label>قالب موردنظر<select class="input" id="promptSelect"></select></label>
          <div class="security-note" id="promptMeta">قالب دستور انتخاب نشده است.</div>
          <form id="promptForm">
            <input type="hidden" name="slug" id="promptSlug">
            <label>System Prompt<textarea class="input textarea" name="system_prompt" id="promptBody" rows="17" required></textarea></label>
            <label class="check-row"><input type="checkbox" name="enabled" id="promptEnabled" checked><span>استفاده از این قالب در تولید جدید فعال باشد</span></label>
            <button class="button button-primary full-button" type="submit">ذخیره قالب دستور</button>
            <div class="form-message" id="promptMessage"></div>
          </form>
        </section>
        <section class="panel-card result-panel">
          <div class="panel-heading"><div><h3>راهنمای قالب‌ها</h3><p>این دستور به‌عنوان System Prompt درخواست AI ارسال می‌شود.</p></div></div>
          <div class="security-row"><i>✧</i><span><b>Question</b><small>تولید سؤال با پاسخ صحیح و توضیح</small></span></div>
          <div class="security-row"><i>▤</i><span><b>Practice</b><small>طراحی تمرین مرحله‌ای و قابل ارزیابی</small></span></div>
          <div class="security-row"><i>≋</i><span><b>Review</b><small>تحلیل و بازنویسی متن</small></span></div>
          <div class="notice">تغییر قالب فقط روی درخواست‌های جدید اثر دارد. خروجی‌های قبلی در تاریخچه ثابت می‌مانند.</div>
        </section>
      </div>
    </section>

    <section class="page-view" id="page-settings">
      <div class="page-heading"><div><div class="eyebrow">SYSTEM CONFIGURATION</div><h1>تنظیمات اتصال و امنیت</h1><p>مدیریت امن کلید درگاه هوش مصنوعی و مدل پیش‌فرض.</p></div></div>
      <div class="settings-grid"><form class="panel-card form-panel" id="settingsForm"><div class="panel-heading"><div><h3>درگاه Paasta AI</h3><p>کلید فقط روی سرور Parse ذخیره و رمزگذاری می‌شود.</p></div><span class="status-badge" id="settingsStatus">در حال بررسی</span></div><div class="provider-large"><div class="provider-logo">✳</div><div><b>Paasta AI Gateway</b><small dir="ltr">https://console.paasta.cloud/api/ai/v1</small></div></div><label>کلید API پاستا<input class="input secret-input" type="password" name="api_key" autocomplete="new-password" placeholder="برای ثبت کلید جدید وارد کنید"></label><small class="field-hint">کلید فعلی هرگز به مرورگر بازگردانده نمی‌شود. خالی گذاشتن این قسمت، کلید ذخیره‌شده را تغییر نمی‌دهد.</small><label>مدل پیش‌فرض<select class="input model-select" name="default_model" data-kind="models"><option value="">انتخاب از کاتالوگ یا خالی</option></select></label><div class="inline-actions"><button class="button button-primary" type="submit">ذخیره امن تنظیمات</button><button class="button button-secondary" id="testProvider" type="button">تست کلید و اتصال</button></div><div class="form-message" id="settingsMessage"></div></form>
      <section class="panel-card security-panel"><h3>وضعیت امنیت</h3><div class="security-row"><i>✓</i><span><b>ارتباط سمت سرور</b><small>کلید API در درخواست مرورگر نمایش داده نمی‌شود.</small></span></div><div class="security-row"><i>✓</i><span><b>رمزگذاری کلید</b><small>ذخیره با رمزنگاری Secretbox و کلید خارج از web root.</small></span></div><div class="security-row"><i>✓</i><span><b>نشست مدیر</b><small>کوکی HttpOnly و SameSite Strict با پایان نشست خودکار.</small></span></div><div class="security-row"><i>✓</i><span><b>محافظت CSRF</b><small>درخواست‌های تغییردهنده نیازمند توکن نشست هستند.</small></span></div><div class="security-row"><i>✓</i><span><b>ثبت مصرف</b><small>هزینه، توکن، مدل، وضعیت و رسید؛ بدون محتوای پیام.</small></span></div><div class="notice">کلید معتبر پاستا را از کنسول سرویس بسازید. کلید را در چت یا کد سمت مرورگر قرار ندهید.</div></section></div>
    </section>
    <footer class="app-footer"><span>PARSE ADMIN</span><span>Secure server-side console · <?= date('Y') ?></span></footer>
  </main>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="/assets/admin.js?v=20261123-php-console-1" defer></script>
<?php endif; ?>
</body></html>

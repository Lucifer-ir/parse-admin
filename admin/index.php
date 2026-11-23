<?php
declare(strict_types=1);
require '/opt/parse-admin/admin/bootstrap.php';

$ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
$flash = '';
if (!empty($_SESSION['admin']['signed_in_at']) &&
    (time() - (int)$_SESSION['admin']['signed_in_at'] > 43200 ||
     time() - (int)($_SESSION['admin']['last_activity'] ?? $_SESSION['admin']['signed_in_at']) > 1800)) {
    $_SESSION = [];
    session_regenerate_id(true);
}
if (!empty($_SESSION['admin']['id'])) $_SESSION['admin']['last_activity'] = time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    $provided = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals(admin_csrf_token(), $provided)) {
        http_response_code(419);
        exit('درخواست نامعتبر است. صفحه را دوباره بارگذاری کنید.');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
    header('Location: /', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!hash_equals(admin_csrf_token(), (string)($_POST['csrf_token'] ?? ''))) {
        $flash = 'نشست امنیتی نامعتبر است. صفحه را تازه‌سازی کنید.';
    } else {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $flash = 'ایمیل و رمز عبور معتبر را وارد کنید.';
        } else {
            try {
                $limit = $pdo->prepare(
                    'SELECT COUNT(*) FROM login_attempts
                     WHERE success = 0 AND ip_address = ?
                       AND created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)'
                );
                $limit->execute([$ip]);
                if ((int)$limit->fetchColumn() >= 30) {
                    $flash = 'تعداد تلاش‌های ورود بیش از حد مجاز است. ۱۵ دقیقه دیگر تلاش کنید.';
                } else {
                    $stmt = $pdo->prepare('SELECT id, email, password_hash, role, active FROM users WHERE email = ? LIMIT 1');
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();
                    $valid = $user && password_verify($password, (string)$user['password_hash']);
                    $allowed = $valid && (bool)$user['active'] && $user['role'] === 'admin';

                    $attempt = $pdo->prepare('INSERT INTO login_attempts (ip_address, email, success) VALUES (?, ?, ?)');
                    $attempt->execute([$ip, $email, $allowed ? 1 : 0]);

                    if (!$allowed) {
                        $flash = 'ایمیل یا رمز عبور نادرست است، یا این حساب دسترسی مدیر فعال ندارد.';
                    } else {
                        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
                            $rehash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
                            $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
                        }
                        session_regenerate_id(true);
                        $_SESSION['admin'] = [
                            'id' => (int)$user['id'],
                            'email' => (string)$user['email'],
                            'role' => 'admin',
                            'signed_in_at' => time(),
                            'last_activity' => time(),
                        ];
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $pdo->prepare(
                            "INSERT INTO admin_audit_log (admin_user_id, action, target_type, target_id, ip_address)
                             VALUES (?, 'admin.login', 'user', ?, ?)"
                        )->execute([(int)$user['id'], (int)$user['id'], $ip]);
                        header('Location: /', true, 303);
                        exit;
                    }
                }
            } catch (Throwable $e) {
                error_log('Parse Admin login failed: ' . $e->getMessage());
                $flash = 'ورود در حال حاضر انجام نشد. وضعیت پایگاه داده و لاگ سرور را بررسی کنید.';
            }
        }
    }
}

$loggedIn = !empty($_SESSION['admin']['id']) && ($_SESSION['admin']['role'] ?? '') === 'admin';
$csrf = admin_csrf_token();
$aiSettings = $loggedIn ? admin_ai_settings($pdo) : [];
$aiKeyConfigured = $loggedIn && !empty($aiSettings['api_key_cipher']);
$aiEnabled = $loggedIn && !empty($aiSettings['enabled']);
$adminEmail = $loggedIn ? (string)$_SESSION['admin']['email'] : '';

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#101827">
<meta name="csrf-token" content="<?= h($csrf) ?>">
<title><?= $loggedIn ? 'کنسول مدیریت هوش مصنوعی | Parse' : 'ورود مدیر | Parse Admin' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;450;500;550;600;650;700;750;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/admin.css?v=<?= (int)@filemtime('/var/www/parse-admin/assets/admin.css') ?>">
<script defer src="/assets/admin.js?v=<?= (int)@filemtime('/var/www/parse-admin/assets/admin.js') ?>"></script>
</head>
<body class="<?= $loggedIn ? 'app-mode' : 'auth-mode' ?>">
<?php if (!$loggedIn): ?>
<div class="auth-layout">
  <div class="auth-backdrop"></div>
  <section class="auth-brand-panel">
    <div class="auth-brand"><span class="brand-symbol">P</span><span>PARSE<span class="brand-muted">/ADMIN</span></span></div>
    <div class="auth-brand-copy">
      <div class="eyebrow"><span class="eyebrow-dot"></span> CONTROL PLANE · AI OPERATIONS</div>
      <h1>همه‌چیز برای مدیریت هوش مصنوعی،<br><em>در یک کنسول.</em></h1>
      <p>مدل‌ها، کلیدها، تولید محتوا، کیفیت خروجی و هزینه‌ها را از یک نقطه کنترل کنید.</p>
      <div class="auth-feature-list">
        <div><span class="feature-icon">✳</span><span><b>مدیریت مدل‌ها</b><small>کاتالوگ، قابلیت‌ها و تعرفه‌ها</small></span></div>
        <div><span class="feature-icon">⌘</span><span><b>عملیات هوشمند</b><small>سؤال، تمرین، بازبینی متن و رسانه</small></span></div>
        <div><span class="feature-icon">⌁</span><span><b>ردیابی مصرف</b><small>توکن، هزینه، زمان و شناسه درخواست</small></span></div>
      </div>
    </div>
    <div class="auth-foot"><span>PARSE PLATFORM</span><span>SECURE ADMIN ACCESS</span></div>
  </section>
  <section class="auth-form-panel">
    <div class="auth-form-wrap">
      <div class="mobile-brand"><span class="brand-symbol">P</span><b>PARSE ADMIN</b></div>
      <div class="form-kicker">ADMINISTRATOR ACCESS</div>
      <h2>خوش برگشتی</h2>
      <p class="form-subtitle">برای ورود به کنسول مدیریتی اطلاعات حساب مدیر را وارد کن.</p>
      <?php if ($flash !== ''): ?><div class="alert alert-error" role="alert"><?= h($flash) ?></div><?php endif; ?>
      <form method="post" action="/" class="auth-form" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <label for="email">ایمیل مدیر</label>
        <div class="input-wrap"><span class="input-icon">＠</span><input id="email" name="email" type="email" autocomplete="username" placeholder="admin@example.com" required autofocus value="<?= h((string)($_POST['email'] ?? '')) ?>"></div>
        <div class="label-row"><label for="password">رمز عبور</label><span class="label-hint">دسترسی مدیر سیستم</span></div>
        <div class="input-wrap"><span class="input-icon">⌑</span><input id="password" name="password" type="password" autocomplete="current-password" placeholder="رمز عبور حساب مدیر" required><button type="button" class="password-toggle" data-toggle-password="password" aria-label="نمایش رمز">◉</button></div>
        <button class="btn btn-primary btn-login" type="submit" name="login" value="1"><span>ورود امن به پنل</span><span class="btn-arrow">←</span></button>
      </form>
      <div class="auth-security"><span class="security-lock">◆</span><span>اتصال امن · نشست PHP سمت سرور · کنترل دسترسی مدیر</span></div>
      <div class="auth-form-footer">با ورود، فعالیت‌های مدیریتی ثبت و قابل پیگیری خواهند بود.</div>
    </div>
  </section>
</div>
<?php else: ?>
<div class="app-shell" id="adminApp">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand"><span class="brand-symbol">P</span><div><b>PARSE<span>/ADMIN</span></b><small>AI CONTROL CENTER</small></div><button class="sidebar-close" id="sidebarClose" aria-label="بستن منو">×</button></div>
    <div class="workspace-switch"><span class="workspace-avatar">P</span><span><b>Parse Workspace</b><small>فضای مدیریتی اصلی</small></span><span class="switch-chevron">⌄</span></div>
    <div class="nav-label">WORKSPACE</div>
    <nav class="side-nav">
      <button class="nav-item active" data-page="overview"><span class="nav-icon">▦</span><span>نمای کلی</span></button>
      <button class="nav-item" data-page="models"><span class="nav-icon">◈</span><span>کاتالوگ مدل‌ها</span><span class="nav-count" id="modelCount">—</span></button>
      <button class="nav-item" data-page="playground"><span class="nav-icon">✳</span><span>محیط آزمایش AI</span></button>
      <button class="nav-item" data-page="questions"><span class="nav-icon">☷</span><span>سازنده سؤال</span></button>
      <button class="nav-item" data-page="exercises"><span class="nav-icon">⊞</span><span>سازنده تمرین</span></button>
      <button class="nav-item" data-page="review"><span class="nav-icon">⌕</span><span>بررسی و اصلاح متن</span></button>
    </nav>
    <div class="nav-label">AI CAPABILITIES</div>
    <nav class="side-nav">
      <button class="nav-item" data-page="images"><span class="nav-icon">▧</span><span>تصویر و ویرایش</span></button>
      <button class="nav-item" data-page="audio"><span class="nav-icon">♫</span><span>صوت و گفتار</span></button>
      <button class="nav-item" data-page="embeddings"><span class="nav-icon">⌗</span><span>Embedding و RAG</span></button>
      <button class="nav-item" data-page="decisions"><span class="nav-icon">◇</span><span>تصمیم‌گیری ساختاریافته</span></button>
      <button class="nav-item" data-page="batches"><span class="nav-icon">▤</span><span>درخواست‌های Batch</span></button>
    </nav>
    <div class="nav-label">OPERATIONS</div>
    <nav class="side-nav">
      <button class="nav-item" data-page="usage"><span class="nav-icon">◷</span><span>مصرف و هزینه</span></button>
      <button class="nav-item" data-page="settings"><span class="nav-icon">⚙</span><span>تنظیمات و کلید API</span><span class="nav-status <?= $aiEnabled ? 'status-on' : 'status-off' ?>" id="providerStatus"></span></button>
    </nav>
    <div class="sidebar-bottom">
      <div class="sidebar-user"><span class="user-avatar"><?= h(mb_strtoupper(mb_substr($adminEmail, 0, 1))) ?></span><span><b><?= h($adminEmail) ?></b><small>مدیر سیستم</small></span></div>
      <form method="post" action="/" class="logout-form"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><button name="logout" value="1" class="logout-button"><span>↪</span> خروج امن</button></form>
      <div class="sidebar-version">PARSE ADMIN <span>v2.0</span></div>
    </div>
  </aside>
  <main class="main-area">
    <header class="topbar">
      <div class="topbar-leading"><button class="mobile-menu" id="mobileMenu" aria-label="باز کردن منو">☰</button><div class="breadcrumbs"><span>Parse Admin</span><b>/</b><strong id="pageCrumb">نمای کلی</strong></div></div>
      <div class="topbar-actions"><span class="live-indicator"><i></i> AI Gateway</span><button class="icon-button" id="refreshAll" title="به‌روزرسانی">↻</button><span class="top-avatar">P</span></div>
    </header>
    <div class="page-container">
      <section class="page-view active" id="page-overview">
        <div class="page-heading-row"><div><div class="eyebrow">SYSTEM OVERVIEW</div><h1>مرکز کنترل هوش مصنوعی</h1><p>وضعیت سرویس‌ها، فعالیت مدل‌ها و هزینه‌های پردازش را یکجا ببین.</p></div><div class="heading-actions"><span class="date-chip" id="currentDate">—</span><button class="btn btn-secondary" data-go="settings">تنظیم اتصال <span>⚙</span></button></div></div>
        <div class="welcome-strip"><div><span class="welcome-label">PARSE AI OPERATIONS</span><h2>مدیریت هوشمند، از یک نقطه</h2><p>از انتخاب مدل تا ساخت محتوا و بررسی مصرف، تمام عملیات در یک محیط امن اجرا می‌شوند.</p></div><div class="welcome-orb">✳<span></span></div></div>
        <div class="stat-grid">
          <article class="metric-card"><div class="metric-top"><span>کل درخواست‌ها</span><span class="metric-symbol blue-symbol">↗</span></div><strong id="statRequests">—</strong><small>درخواست‌های اجراشده از پنل</small></article>
          <article class="metric-card"><div class="metric-top"><span>هزینه ثبت‌شده</span><span class="metric-symbol green-symbol">₮</span></div><strong id="statCost">—</strong><small>تومان · بر اساس رسید API</small></article>
          <article class="metric-card"><div class="metric-top"><span>توکن مصرفی</span><span class="metric-symbol purple-symbol">◫</span></div><strong id="statTokens">—</strong><small>مجموع ورودی و خروجی</small></article>
          <article class="metric-card"><div class="metric-top"><span>نرخ موفقیت</span><span class="metric-symbol orange-symbol">✓</span></div><strong id="statSuccess">—</strong><small>درخواست‌های موفق پنل</small></article>
        </div>
        <div class="dashboard-grid">
          <section class="panel-card"><div class="panel-heading"><div><h3>آخرین فعالیت‌ها</h3><p>درخواست‌های اخیر ثبت‌شده در پنل</p></div><button class="text-link" data-go="usage">مشاهده همه ←</button></div><div class="table-scroll"><table class="data-table"><thead><tr><th>عملیات</th><th>مدل</th><th>وضعیت</th><th>هزینه</th><th>زمان</th></tr></thead><tbody id="recentUsage"><tr><td colspan="5" class="empty-state">هنوز درخواستی ثبت نشده است.</td></tr></tbody></table></div></section>
          <section class="panel-card quick-panel"><div class="panel-heading"><div><h3>شروع سریع</h3><p>مستقیم وارد ابزار موردنیاز شو</p></div></div><div class="quick-actions">
            <button data-go="questions"><span class="quick-icon q-icon">☷</span><span><b>ساخت سؤال</b><small>تولید سؤال با پاسخ و تحلیل</small></span><i>←</i></button>
            <button data-go="exercises"><span class="quick-icon e-icon">⊞</span><span><b>ساخت تمرین</b><small>تمرین متنوع و سطح‌بندی‌شده</small></span><i>←</i></button>
            <button data-go="review"><span class="quick-icon r-icon">⌕</span><span><b>بررسی متن</b><small>تصحیح و امتیازدهی متن</small></span><i>←</i></button>
            <button data-go="playground"><span class="quick-icon p-icon">✳</span><span><b>محیط آزمایش</b><small>اجرای مستقیم درخواست مدل</small></span><i>←</i></button>
          </div></section>
        </div>
      </section>

      <section class="page-view" id="page-settings">
        <div class="page-heading-row"><div><div class="eyebrow">CONNECTION & SECURITY</div><h1>تنظیمات اتصال هوش مصنوعی</h1><p>کلید سرویس را امن ذخیره کن و اتصال را از سمت سرور بررسی کن.</p></div><span class="badge <?= $aiEnabled ? 'badge-success' : 'badge-neutral' ?>" id="aiStatusBadge"><?= $aiEnabled ? 'اتصال فعال' : 'اتصال غیرفعال' ?></span></div>
        <div class="settings-layout">
          <div class="panel-card form-panel"><div class="panel-heading"><div><h3>درگاه ارائه‌دهنده</h3><p>در این نسخه اتصال سازگار با API پاستا پیکربندی می‌شود.</p></div><span class="provider-logo">P</span></div>
            <form id="providerForm" class="form-grid">
              <label class="field full-field">آدرس پایه API<input id="providerBase" type="url" value="<?= h((string)($aiSettings['base_url'] ?? 'https://console.paasta.cloud/api/ai/v1')) ?>" required><small>Base URL مستندات: https://console.paasta.cloud/api/ai/v1</small></label>
              <label class="field full-field">کلید API پاستا<input id="providerKey" type="password" autocomplete="new-password" placeholder="<?= $aiKeyConfigured ? 'کلید ذخیره شده است؛ برای تغییر، کلید جدید وارد کن' : 'PAASTA_AI_KEY' ?>"><small>کلید در مرورگر نگهداری نمی‌شود؛ با AES-256-GCM روی سرور رمزنگاری می‌شود.</small></label>
              <div class="field inline-check"><label class="switch"><input id="providerEnabled" type="checkbox" <?= $aiEnabled ? 'checked' : '' ?>><span></span></label><div><b>فعال‌سازی درگاه</b><small>پس از ثبت کلید و تست اتصال فعال کن.</small></div></div>
              <div class="form-actions"><button class="btn btn-primary" type="submit">ذخیره تنظیمات</button><button class="btn btn-secondary" id="testProvider" type="button">تست اتصال و دریافت مدل‌ها</button></div>
              <p class="form-feedback full-field" id="providerFeedback"></p>
            </form>
          </div>
          <aside class="panel-card security-panel"><div class="security-icon">⌑</div><h3>امنیت کلید API</h3><ul><li>کلید فقط در بک‌اند PHP استفاده می‌شود.</li><li>مقدار خام کلید هرگز به مرورگر بازگردانده نمی‌شود.</li><li>کلید با AES-256-GCM و کلید رمزنگاری خارج از Web Root نگهداری می‌شود.</li><li>در گزارش مصرف، متن درخواست و پاسخ ذخیره نمی‌شود.</li></ul><div class="security-warning">کلید API را در کد JavaScript، HTML یا مخزن Git قرار نده.</div></aside>
        </div>
        <div class="panel-card mt-20"><div class="panel-heading"><div><h3>مدل‌های پیش‌فرض</h3><p>بعد از تست اتصال، مدل‌های موجود را بارگذاری کن.</p></div><button class="btn btn-secondary" id="loadCatalogs" type="button">بارگذاری کاتالوگ‌ها</button></div><div class="catalog-mini-grid"><div><b>Chat / Responses</b><span id="chatModelCount">—</span></div><div><b>Decision</b><span id="decisionModelCount">—</span></div><div><b>Image</b><span id="imageModelCount">—</span></div><div><b>Audio</b><span id="audioModelCount">—</span></div><div><b>Embeddings</b><span id="embeddingModelCount">—</span></div></div></div>
      </section>

      <section class="page-view" id="page-models">
        <div class="page-heading-row"><div><div class="eyebrow">MODEL CATALOG</div><h1>کاتالوگ مدل‌ها</h1><p>شناسه، قیمت، ظرفیت زمینه و قابلیت هر مدل را از API دریافت کن.</p></div><button class="btn btn-primary" id="reloadModels">↻ دریافت کاتالوگ زنده</button></div>
        <div class="filter-row"><div class="segmented" id="modelKinds"><button class="selected" data-kind="chat">Chat</button><button data-kind="decision">Decision</button><button data-kind="image">Image</button><button data-kind="audio">Audio</button><button data-kind="embedding">Embedding</button></div><input id="modelSearch" class="search-input" placeholder="جست‌وجوی مدل یا قابلیت..."></div>
        <div class="panel-card"><div class="table-scroll"><table class="data-table"><thead><tr><th>شناسه مدل</th><th>قیمت</th><th>زمینه / خروجی</th><th>قابلیت‌ها</th><th>وضعیت</th></tr></thead><tbody id="modelsTable"><tr><td colspan="5" class="empty-state">برای دریافت مدل‌ها، کاتالوگ زنده را بارگذاری کن.</td></tr></tbody></table></div></div>
      </section>

      <section class="page-view" id="page-playground">
        <div class="page-heading-row"><div><div class="eyebrow">MODEL PLAYGROUND</div><h1>محیط آزمایش AI</h1><p>درخواست مستقیم Chat Completions و Responses را با مدل انتخابی اجرا کن.</p></div><span class="badge badge-success">Server-side proxy</span></div>
        <div class="playground-grid"><div class="panel-card form-panel"><div class="form-grid">
          <label class="field">نوع API<select id="playgroundApi"><option value="chat">Chat Completions</option><option value="responses">Responses API</option><option value="messages">Anthropic Messages</option></select></label>
          <label class="field">مدل<select id="playgroundModel" class="model-select"><option value="">ابتدا کاتالوگ را بارگذاری کن</option></select></label>
          <label class="field full-field" id="systemPromptField">System Prompt<textarea id="playgroundSystem" rows="3" placeholder="نقش و دستورالعمل مدل..."></textarea></label>
          <label class="field full-field">ورودی کاربر<textarea id="playgroundInput" rows="7" placeholder="درخواست خود را بنویس..."></textarea></label>
          <label class="field">حداکثر خروجی<input id="playgroundMax" type="number" min="16" max="32768" value="1024"></label>
          <label class="field">دما (Temperature)<input id="playgroundTemp" type="number" min="0" max="2" step="0.1" value="0.7"></label>
          <details class="advanced-options full-field">
            <summary>تنظیمات پیشرفته درخواست</summary>
            <div class="advanced-grid">
              <label class="advanced-check"><input id="playgroundStream" type="checkbox"> پاسخ جریانی SSE</label>
              <label class="advanced-check"><input id="playgroundWebSearch" type="checkbox"> جست‌وجوی وب (فقط Responses)</label>
              <label class="field full-field">Function Tools JSON<textarea id="playgroundTools" rows="4" placeholder='[{"type":"function","function":{"name":"lookup","description":"...","parameters":{"type":"object","properties":{},"additionalProperties":false},"strict":true}}]'></textarea><small>حداکثر ۱۲۸ تابع؛ اجرای تابع باید توسط برنامه و با کنترل دسترسی انجام شود.</small></label>
              <label class="field full-field">Structured Output / response_format JSON<textarea id="playgroundStructured" rows="4" placeholder='{"type":"json_schema","json_schema":{"name":"result","strict":true,"schema":{"type":"object","properties":{},"required":[],"additionalProperties":false}}}'></textarea></label>
            </div>
          </details>
          <div class="form-actions full-field"><button id="runPlayground" class="btn btn-primary" type="button">اجرای درخواست <span>✳</span></button><button id="clearPlayground" class="btn btn-secondary" type="button">پاک‌سازی</button></div>
        </div></div><div class="panel-card result-panel"><div class="panel-heading"><div><h3>خروجی مدل</h3><p>پاسخ، زمان و شناسه درخواست</p></div><span class="result-status" id="playgroundStatus">آماده</span></div><div id="playgroundResult" class="result-content placeholder-result">نتیجه اجرای درخواست اینجا نمایش داده می‌شود.</div><div class="result-meta" id="playgroundMeta"></div></div></div>
      </section>

      <section class="page-view" id="page-questions">
        <div class="page-heading-row"><div><div class="eyebrow">CONTENT GENERATOR</div><h1>سازنده سؤال</h1><p>سؤال استاندارد همراه پاسخ صحیح، گزینه‌ها، توضیح و سطح دشواری تولید کن.</p></div><span class="badge badge-neutral">Structured generation</span></div>
        <div class="tool-layout"><div class="panel-card form-panel"><div class="form-grid">
          <label class="field">مدل<select id="questionModel" class="model-select"></select></label><label class="field">تعداد سؤال<input id="questionCount" type="number" min="1" max="30" value="5"></label>
          <label class="field">موضوع / درس<input id="questionSubject" placeholder="مثلاً زیست‌شناسی"></label><label class="field">پایه / سطح<input id="questionGrade" placeholder="مثلاً دوازدهم"></label>
          <label class="field">نوع سؤال<select id="questionType"><option value="mixed">ترکیبی</option><option value="multiple_choice">چهارگزینه‌ای</option><option value="short_answer">پاسخ کوتاه</option><option value="true_false">درست / نادرست</option><option value="essay">تشریحی</option></select></label>
          <label class="field">سختی<select id="questionDifficulty"><option value="mixed">ترکیبی</option><option value="easy">آسان</option><option value="medium">متوسط</option><option value="hard">سخت</option></select></label>
          <label class="field full-field">سرفصل و توضیحات<textarea id="questionTopic" rows="4" placeholder="سرفصل، نکات مهم، محدوده آموزشی و هر محدودیتی که باید رعایت شود..."></textarea></label>
          <div class="form-actions full-field"><button id="generateQuestions" class="btn btn-primary">تولید سؤال</button><button class="btn btn-secondary" data-export="questionResult">خروجی JSON</button></div>
        </div></div><div class="panel-card output-panel"><div class="panel-heading"><div><h3>سؤالات تولیدشده</h3><p>خروجی ساختاریافته برای ذخیره یا انتقال به اپلیکیشن</p></div><span class="result-status" id="questionStatus">آماده</span></div><div id="questionResult" class="generated-output placeholder-result">با تکمیل فرم، سؤالات اینجا نمایش داده می‌شوند.</div></div></div>
      </section>

      <section class="page-view" id="page-exercises">
        <div class="page-heading-row"><div><div class="eyebrow">PRACTICE BUILDER</div><h1>سازنده تمرین</h1><p>مجموعه تمرین آموزشی با تنوع سؤال، راهنمایی و پاسخ تشریحی بساز.</p></div></div>
        <div class="tool-layout"><div class="panel-card form-panel"><div class="form-grid">
          <label class="field">مدل<select id="exerciseModel" class="model-select"></select></label><label class="field">تعداد تمرین<input id="exerciseCount" type="number" min="1" max="30" value="8"></label>
          <label class="field">نوع تمرین<select id="exerciseType"><option value="mixed">ترکیبی</option><option value="quiz">آزمون کوتاه</option><option value="flashcards">فلش‌کارت</option><option value="fill_blank">جای خالی</option><option value="problem_solving">حل مسئله</option><option value="reading">درک مطلب</option></select></label>
          <label class="field">سطح<select id="exerciseLevel"><option value="beginner">مبتدی</option><option value="intermediate">متوسط</option><option value="advanced">پیشرفته</option></select></label>
          <label class="field">موضوع<input id="exerciseSubject" placeholder="موضوع تمرین"></label><label class="field">زمان پیشنهادی (دقیقه)<input id="exerciseDuration" type="number" min="1" max="180" value="20"></label>
          <label class="field full-field">هدف یادگیری<textarea id="exerciseGoal" rows="4" placeholder="دانش‌آموز بعد از انجام تمرین چه چیزی باید یاد بگیرد؟"></textarea></label>
          <div class="form-actions full-field"><button id="generateExercises" class="btn btn-primary">ساخت تمرین</button><button class="btn btn-secondary" data-export="exerciseResult">خروجی JSON</button></div>
        </div></div><div class="panel-card output-panel"><div class="panel-heading"><div><h3>تمرین تولیدشده</h3><p>مجموعه تمرین، کلید پاسخ و توضیحات</p></div><span class="result-status" id="exerciseStatus">آماده</span></div><div id="exerciseResult" class="generated-output placeholder-result">تمرین‌های ساخته‌شده اینجا نمایش داده می‌شوند.</div></div></div>
      </section>

      <section class="page-view" id="page-review">
        <div class="page-heading-row"><div><div class="eyebrow">TEXT INTELLIGENCE</div><h1>بررسی و اصلاح متن</h1><p>متن را از نظر نگارش، املا، دستور زبان، انسجام و معیار دلخواه بررسی کن.</p></div></div>
        <div class="tool-layout"><div class="panel-card form-panel"><div class="form-grid">
          <label class="field">مدل<select id="reviewModel" class="model-select"></select></label><label class="field">زبان متن<select id="reviewLanguage"><option value="fa">فارسی</option><option value="en">انگلیسی</option><option value="auto">تشخیص خودکار</option></select></label>
          <label class="field">نوع بررسی<select id="reviewType"><option value="comprehensive">بررسی کامل</option><option value="grammar">دستور زبان و املا</option><option value="academic">نگارش علمی</option><option value="readability">خوانایی و روانی</option><option value="factual">صحت محتوایی (با ذکر عدم قطعیت)</option><option value="seo">بهینه‌سازی سئو</option></select></label>
          <label class="field">لحن پیشنهادی<select id="reviewTone"><option value="preserve">حفظ لحن اصلی</option><option value="formal">رسمی</option><option value="friendly">صمیمی</option><option value="academic">آکادمیک</option></select></label>
          <label class="field full-field">متن برای بررسی<textarea id="reviewText" rows="10" placeholder="متن خود را اینجا وارد کن..." required></textarea></label>
          <label class="field full-field">معیارهای اختصاصی (اختیاری)<textarea id="reviewCriteria" rows="3" placeholder="مثلاً لحن رسمی، محدودیت واژگان یا راهنمای ارزیابی..."></textarea></label>
          <div class="form-actions full-field"><button id="reviewTextBtn" class="btn btn-primary">بررسی متن</button><button class="btn btn-secondary" data-export="reviewResult">خروجی JSON</button></div>
        </div></div><div class="panel-card output-panel"><div class="panel-heading"><div><h3>گزارش بررسی</h3><p>امتیاز، ایرادها، نسخه اصلاح‌شده و پیشنهادها</p></div><span class="result-status" id="reviewStatus">آماده</span></div><div id="reviewResult" class="generated-output placeholder-result">گزارش بررسی اینجا نمایش داده می‌شود.</div></div></div>
      </section>

      <section class="page-view" id="page-images">
        <div class="page-heading-row"><div><div class="eyebrow">IMAGE INTELLIGENCE</div><h1>تولید و ویرایش تصویر</h1><p>مدل‌های تصویر، تولید تکی، ویرایش و پردازش Batch را مدیریت کن.</p></div></div>
        <div class="subtabs"><button class="selected" data-image-tab="generate">تولید تصویر</button><button data-image-tab="edit">ویرایش تصویر</button><button data-image-tab="batch">Batch تصویر</button></div>
        <div class="panel-card form-panel image-tool" id="imageGeneratePanel"><div class="form-grid"><label class="field">مدل تصویر<select id="imageModel" class="model-select"></select></label><label class="field">تعداد<input id="imageCount" type="number" min="1" max="4" value="1"></label><label class="field full-field">Prompt<textarea id="imagePrompt" rows="4" placeholder="تصویر موردنظر را با جزئیات توصیف کن..."></textarea></label><label class="field">اندازه<select id="imageSize"><option>1024x1024</option><option>1024x1536</option><option>1536x1024</option></select></label><label class="field">کیفیت<select id="imageQuality"><option value="auto">Auto</option><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></label><div class="form-actions full-field"><button id="generateImage" class="btn btn-primary">تولید تصویر</button></div></div><div id="imageResult" class="media-result"></div></div>
        <div class="panel-card form-panel image-tool" id="imageEditPanel" hidden><div class="form-grid"><label class="field">مدل تصویر<select id="imageEditModel" class="model-select"></select></label><label class="field">Prompt ویرایش<input id="imageEditPrompt" placeholder="چه تغییری اعمال شود؟"></label><label class="field full-field">Image URL یا URLها<textarea id="imageUrls" rows="3" placeholder="https://... (هر URL در یک خط؛ ۱ تا ۴ نشانی عمومی HTTPS)"></textarea></label><label class="field full-field">Mask URL (اختیاری)<input id="imageMaskUrl" type="url" placeholder="https://..."></label><div class="form-actions full-field"><button id="editImage" class="btn btn-primary">ارسال برای ویرایش</button></div></div><div id="imageEditResult" class="media-result"></div></div>
        <div class="panel-card form-panel image-tool" id="imageBatchPanel" hidden><div class="form-grid"><label class="field">مدل تصویر<select id="imageBatchModel" class="model-select"></select></label><label class="field">عملیات<select id="imageBatchAction"><option value="generation">تولید</option><option value="edit">ویرایش</option></select></label><label class="field full-field">آیتم‌ها (JSON)<textarea id="imageBatchItems" rows="6" placeholder='[{"prompt":"...","n":1}]'></textarea></label><div class="form-actions full-field"><button id="submitImageBatch" class="btn btn-primary">ساخت Batch</button></div><label class="field">شناسه Batch<input id="imageBatchId" placeholder="batch id"></label><div class="form-actions"><button id="getImageBatch" class="btn btn-secondary">بررسی وضعیت</button><button id="cancelImageBatch" class="btn btn-danger">توقف صف</button></div><pre id="imageBatchResult" class="code-output full-field"></pre></div></div>
      </section>

      <section class="page-view" id="page-audio">
        <div class="page-heading-row"><div><div class="eyebrow">AUDIO STUDIO</div><h1>صوت و گفتار</h1><p>تبدیل گفتار به متن و تولید گفتار با مدل و صدای انتخابی.</p></div></div>
        <div class="subtabs"><button class="selected" data-audio-tab="transcribe">گفتار به متن</button><button data-audio-tab="speech">تولید گفتار</button></div>
        <div class="panel-card form-panel audio-tool" id="transcribePanel"><div class="form-grid"><label class="field">مدل تبدیل صوت<select id="transcribeModel" class="model-select"></select></label><label class="field">زبان<select id="transcribeLanguage"><option value="fa">فارسی</option><option value="en">English</option><option value="auto">خودکار</option></select></label><label class="field full-field">فایل صوتی<input id="audioFile" type="file" accept="audio/*"></label><div class="form-actions full-field"><button id="transcribeAudio" class="btn btn-primary">تبدیل به متن</button></div></div><pre class="code-output" id="transcriptionResult">نتیجه تبدیل صوت اینجا نمایش داده می‌شود.</pre></div>
        <div class="panel-card form-panel audio-tool" id="speechPanel" hidden><div class="form-grid"><label class="field">مدل تولید صوت<select id="speechModel" class="model-select"></select></label><label class="field">شناسه صدا<input id="speechVoice" value="coral"></label><label class="field">فرمت<select id="speechFormat"><option value="mp3">MP3</option><option value="pcm">PCM</option><option value="wav">WAV (در مدل‌های پشتیبان)</option></select></label><label class="field full-field">متن<input id="speechText" maxlength="4000" placeholder="متن برای تبدیل به گفتار (حداکثر ۴۰۰۰ نویسه)"></label><div class="form-actions full-field"><button id="generateSpeech" class="btn btn-primary">تولید گفتار</button></div></div><audio id="speechPlayer" controls hidden></audio></div>
      </section>

      <section class="page-view" id="page-embeddings">
        <div class="page-heading-row"><div><div class="eyebrow">VECTOR & RETRIEVAL</div><h1>Embedding و RAG</h1><p>بردارسازی متن و جست‌وجوی دانش در پایگاه دانش.</p></div></div>
        <div class="panel-card form-panel"><div class="form-grid"><label class="field">مدل Embedding<select id="embeddingModel" class="model-select"></select></label><label class="field">ورودی<select id="embeddingInputType"><option value="single">یک متن</option><option value="list">چند متن</option></select></label><label class="field full-field">متن‌ها<textarea id="embeddingInput" rows="5" placeholder="متن یا چند متن برای بردارسازی..."></textarea></label><div class="form-actions full-field"><button id="runEmbedding" class="btn btn-primary">ایجاد Embedding</button></div></div><pre id="embeddingResult" class="code-output"></pre></div>
        <div class="panel-card form-panel mt-20"><div class="panel-heading"><div><h3>جست‌وجوی پایگاه دانش</h3><p>با ID پایگاه دانش ساخته‌شده در پنل پاستا</p></div></div><div class="form-grid"><label class="field">Knowledge Base ID<input id="knowledgeBaseId" placeholder="KNOWLEDGE_BASE_ID"></label><label class="field">Top K<input id="knowledgeTopK" type="number" value="5" min="1" max="20"></label><label class="field">Query<input id="knowledgeQuery" placeholder="پرسش جست‌وجو"></label><label class="field">Retrieval Mode<select id="knowledgeMode"><option value="hybrid">Hybrid</option><option value="semantic">Semantic</option><option value="keyword">Keyword</option></select></label><div class="form-actions full-field"><button id="searchKnowledge" class="btn btn-primary">جست‌وجو در دانش</button></div></div><pre id="knowledgeResult" class="code-output"></pre></div>
      </section>

      <section class="page-view" id="page-decisions">
        <div class="page-heading-row"><div><div class="eyebrow">STRUCTURED DECISIONS</div><h1>تصمیم‌گیری ساختاریافته</h1><p>اجرای endpoint اختصاصی Decisions با پاسخ ساختاریافته.</p></div></div>
        <div class="tool-layout"><div class="panel-card form-panel"><div class="form-grid"><label class="field full-field">مدل Decision<select id="decisionModel" class="model-select"></select></label><label class="field full-field">State<textarea id="decisionState" rows="5" placeholder="وضعیت، متن یا داده ورودی..."></textarea></label><label class="field full-field">Questions JSON<textarea id="decisionQuestions" rows="7">{
  "is_relevant": {
    "type": "noul",
    "instructions": "آیا متن با موضوع موردنظر مرتبط است؟",
    "criteria": {
      "true": "مرتبط و در محدوده موضوع",
      "false": "نامرتبط یا خارج از موضوع"
    }
  }
}</textarea></label><div class="form-actions full-field"><button id="runDecision" class="btn btn-primary">اجرای تصمیم‌گیری</button></div></div></div><div class="panel-card output-panel"><div class="panel-heading"><div><h3>نتیجه Decision</h3><p>answers و usage</p></div></div><pre id="decisionResult" class="code-output"></pre></div></div>
      </section>

      <section class="page-view" id="page-batches">
        <div class="page-heading-row"><div><div class="eyebrow">ASYNC PROCESSING</div><h1>درخواست‌های Batch</h1><p>ارسال یک تا ۲۰ درخواست متنی با مدل مشترک و بررسی وضعیت Batch.</p></div></div>
        <div class="panel-card form-panel"><div class="form-grid"><label class="field">مدل<select id="batchModel" class="model-select"></select></label><label class="field">Endpoint<select id="batchEndpoint"><option value="/v1/chat/completions">Chat Completions</option><option value="/v1/responses">Responses</option></select></label><label class="field full-field">Requests JSON<textarea id="batchRequests" rows="9">[
  {
    "custom_id": "item-1",
    "body": {
      "messages": [{"role":"user","content":"یک خلاصه کوتاه بنویس"}],
      "max_tokens": 256
    }
  }
]</textarea></label><div class="form-actions full-field"><button id="createBatch" class="btn btn-primary">ارسال Batch</button></div><label class="field">Batch ID<input id="batchId" placeholder="BATCH_ID"></label><div class="form-actions"><button id="getBatch" class="btn btn-secondary">دریافت نتیجه</button></div><pre id="batchResult" class="code-output full-field"></pre></div></div>
      </section>

      <section class="page-view" id="page-usage">
        <div class="page-heading-row"><div><div class="eyebrow">USAGE & BILLING</div><h1>مصرف و صورتحساب</h1><p>گزارش درخواست‌های عبوری از پنل بر اساس رسید API؛ بدون ذخیره متن ورودی یا خروجی.</p></div><button class="btn btn-secondary" id="refreshUsage">↻ به‌روزرسانی</button></div>
        <div class="stat-grid usage-stats"><article class="metric-card"><div class="metric-top"><span>هزینه کل</span><span class="metric-symbol green-symbol">₮</span></div><strong id="usageCost">—</strong><small>تومان ثبت‌شده</small></article><article class="metric-card"><div class="metric-top"><span>توکن کل</span><span class="metric-symbol purple-symbol">◫</span></div><strong id="usageTokens">—</strong><small>ورودی و خروجی</small></article><article class="metric-card"><div class="metric-top"><span>موفق</span><span class="metric-symbol blue-symbol">✓</span></div><strong id="usageSuccess">—</strong><small>درخواست موفق</small></article><article class="metric-card"><div class="metric-top"><span>ناموفق</span><span class="metric-symbol orange-symbol">!</span></div><strong id="usageFailed">—</strong><small>درخواست ناموفق</small></article></div>
        <div class="panel-card lookup-panel"><div class="panel-heading"><div><h3>تطبیق یک درخواست با رسید ارائه‌دهنده</h3><p>با شناسه x-paasta-request-id وضعیت و مصرف همان درخواست را دریافت کن.</p></div></div><div class="request-id-input"><input id="usageRequestId" placeholder="REQUEST_ID"><button class="btn btn-secondary" id="lookupGeneration">بررسی درخواست</button></div><pre class="code-output" id="generationLookupResult">شناسه درخواست را وارد کن.</pre></div>
        <div class="panel-card mt-20"><div class="panel-heading"><div><h3>دفتر درخواست‌ها</h3><p>شناسه درخواست، endpoint، مدل، هزینه و زمان پاسخ</p></div><button class="btn btn-secondary" id="exportUsage">خروجی CSV</button></div><div class="table-scroll"><table class="data-table"><thead><tr><th>زمان</th><th>عملیات</th><th>مدل</th><th>Request ID</th><th>توکن</th><th>هزینه</th><th>HTTP</th><th>زمان</th></tr></thead><tbody id="usageTable"><tr><td colspan="8" class="empty-state">در حال دریافت...</td></tr></tbody></table></div></div>
        <p class="note-text">این گزارش مصرف درخواست‌هایی را نشان می‌دهد که از طریق این پنل ارسال شده‌اند. برای مصرف کل کلید در تمام سرویس‌ها باید گزارش حساب/کلید از کنسول ارائه‌دهنده دریافت شود؛ مستند ارسال‌شده endpoint عمومی فهرست کامل مصرف کل حساب را مشخص نمی‌کند.</p>
      </section>
    </div>
  </main>
</div>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<div class="mobile-scrim" id="mobileScrim"></div>
<script>window.PARSE_ADMIN = { csrf: <?= json_encode($csrf) ?>, aiEnabled: <?= $aiEnabled ? 'true' : 'false' ?>, hasApiKey: <?= $aiKeyConfigured ? 'true' : 'false' ?>, adminEmail: <?= json_encode($adminEmail, JSON_UNESCAPED_UNICODE) ?> };</script>
<?php endif; ?>
</body>
</html>

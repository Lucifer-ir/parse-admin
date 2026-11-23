'use strict';

(() => {
  const $ = (selector) => document.querySelector(selector);
  const loginView = $('#login');
  const dashboard = $('#dashboard');
  const loginForm = $('#loginForm');
  const loginError = $('#loginError');

  const API_KEY = 'parse_admin_api';
  const TOKEN_KEY = 'parse_admin_token';
  const DEFAULT_API = 'https://api.aiparseh.ir';

  function normalizeBase(value) {
    return String(value || '').trim().replace(/\/+$/, '');
  }

  function validateApiUrl(value) {
    const normalized = normalizeBase(value);
    if (!normalized) throw new Error('آدرس API خالی است.');
    let parsed;
    try {
      parsed = new URL(normalized);
    } catch {
      throw new Error('آدرس API معتبر نیست. نمونه: https://api.aiparseh.ir');
    }
    const localHost = ['localhost', '127.0.0.1'].includes(parsed.hostname);
    if (parsed.protocol !== 'https:' && !(localHost && parsed.protocol === 'http:')) {
      throw new Error('آدرس API باید HTTPS باشد.');
    }
    if (parsed.username || parsed.password || parsed.search || parsed.hash) {
      throw new Error('آدرس API نباید شامل نام کاربری، رمز، Query یا Fragment باشد.');
    }
    return normalized;
  }

  function loadSavedApi() {
    const saved = normalizeBase(localStorage.getItem(API_KEY) || '');
    if (!saved || /(?:example[.]com|example[.]org)/i.test(saved)) return DEFAULT_API;
    try {
      return validateApiUrl(saved);
    } catch {
      return DEFAULT_API;
    }
  }

  let apiBase = loadSavedApi();
  let token = sessionStorage.getItem(TOKEN_KEY) || '';
  let usersCache = [];
  let questionsCache = [];
  let toastTimer = null;

  function setLoginError(message) {
    loginError.textContent = message || '';
    loginError.hidden = !message;
  }

  function notify(message) {
    const toast = $('#toast');
    toast.textContent = message;
    toast.classList.add('visible');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('visible'), 4500);
  }

  function formatNumber(value) {
    return Number(value || 0).toLocaleString('fa-IR');
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('fa-IR');
  }

  function safeText(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[char]));
  }

  function extractMessage(payload, fallback) {
    if (!payload || typeof payload !== 'object') return fallback;
    const detail = payload.detail ?? payload.message ?? payload.error;
    if (typeof detail === 'string' && detail.trim()) return detail;
    if (Array.isArray(detail)) {
      const messages = detail.map(item => item && typeof item.msg === 'string' ? item.msg : '').filter(Boolean);
      return messages.join('، ') || fallback;
    }
    return fallback;
  }

  async function request(path, options = {}, base = apiBase) {
    if (!base) throw new Error('آدرس API تنظیم نشده است.');

    const { auth = true, ...fetchOptions } = options;
    const headers = new Headers(fetchOptions.headers || {});
    if (token && auth) headers.set('Authorization', 'Bearer ' + token);
    if (fetchOptions.body && !headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json');
    }

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 20000);
    let response;

    try {
      response = await fetch(base + path, {
        ...fetchOptions,
        headers,
        signal: controller.signal,
        cache: 'no-store',
        credentials: 'omit'
      });
    } catch (error) {
      if (error && error.name === 'AbortError') {
        throw new Error('سرور API در مهلت تعیین‌شده پاسخ نداد. وضعیت سرویس را بررسی کنید.');
      }
      throw new Error('ارتباط با API برقرار نشد. DNS، گواهی SSL، فایروال و CORS دامنه پنل را بررسی کنید.');
    } finally {
      clearTimeout(timeoutId);
    }

    const raw = await response.text();
    let payload = {};
    if (raw) {
      try {
        payload = JSON.parse(raw);
      } catch {
        if (!response.ok) throw new Error('خطای HTTP ' + response.status + ' از API دریافت شد.');
        throw new Error('پاسخ API قالب JSON ندارد. مسیر Nginx و اجرای PHP را بررسی کنید.');
      }
    }

    if (!response.ok) {
      throw new Error(extractMessage(payload, 'خطای HTTP ' + response.status));
    }
    return payload;
  }

  function switchToLogin(message = '') {
    dashboard.hidden = true;
    loginView.hidden = false;
    if (message) setLoginError(message);
  }

  function switchToDashboard(user) {
    loginView.hidden = true;
    dashboard.hidden = false;
    $('#adminName').textContent = user?.email || 'مدیر سیستم';
    $('#apiSettings').value = apiBase;
    $('#apiOrigin').textContent = new URL(apiBase).host;
  }

  function clearSession() {
    token = '';
    sessionStorage.removeItem(TOKEN_KEY);
  }

  async function loadUsers() {
    const result = await request('/admin/users');
    usersCache = Array.isArray(result) ? result : (Array.isArray(result.users) ? result.users : []);
    renderUsers();
  }

  async function loadQuestions() {
    const result = await request('/admin/questions?limit=200&offset=0');
    questionsCache = Array.isArray(result) ? result : (Array.isArray(result.questions) ? result.questions : []);
    renderQuestions();
  }

  function renderUsers() {
    const term = $('#userSearch').value.trim().toLowerCase();
    const rows = usersCache.filter(user => String(user.email || '').toLowerCase().includes(term));
    $('#usersTable').innerHTML = rows.length
      ? rows.map(user => '<tr><td>' + formatNumber(user.id) + '</td><td dir="ltr">' + safeText(user.email) +
        '</td><td><span class="role-tag">' + safeText(user.role) + '</span></td><td><span class="state ' +
        (user.active ? 'enabled' : 'disabled') + '">' + (user.active ? 'فعال' : 'غیرفعال') +
        '</span></td><td>' + formatDate(user.created_at) + '</td></tr>').join('')
      : '<tr><td colspan="5" class="empty">کاربری پیدا نشد.</td></tr>';
  }

  function renderQuestions() {
    const term = $('#questionSearch').value.trim().toLowerCase();
    const rows = questionsCache.filter(question => String(question.question || '').toLowerCase().includes(term));
    $('#questionsTable').innerHTML = rows.length
      ? rows.map(question => '<tr><td>' + formatNumber(question.id) + '</td><td class="question-cell">' +
        safeText(question.question) + '</td><td>' + safeText(question.subject || '—') + '</td><td>' +
        safeText(question.grade || '—') + '</td><td>' + safeText(question.source_type || '—') +
        '</td><td><button class="danger-button" data-delete-question="' + Number(question.id) + '">حذف</button></td></tr>').join('')
      : '<tr><td colspan="6" class="empty">سؤالی پیدا نشد.</td></tr>';
  }

  async function loadDashboardData() {
    $('#connection').textContent = 'در حال دریافت اطلاعات...';
    const stats = await request('/admin/stats');
    $('#users').textContent = formatNumber(stats.users);
    $('#activeUsers').textContent = formatNumber(stats.active_users) + ' کاربر فعال';
    $('#sources').textContent = formatNumber(stats.sources);
    $('#questions').textContent = formatNumber(stats.questions);
    $('#serviceStatus').textContent = 'متصل';
    $('#connection').textContent = 'اتصال به سرویس برقرار است';

    const results = await Promise.allSettled([loadUsers(), loadQuestions()]);
    const failed = results.find(result => result.status === 'rejected');
    if (failed) notify('ورود موفق بود؛ اما بخشی از اطلاعات دریافت نشد: ' + failed.reason.message);
  }

  async function verifyCurrentSession() {
    const me = await request('/auth/me');
    if (!me || me.role !== 'admin' || me.active === false) {
      throw new Error('این حساب دسترسی مدیر فعال ندارد.');
    }
    return me;
  }

  async function refreshDashboard(showSuccess = true) {
    $('#connection').textContent = 'در حال بررسی نشست...';
    try {
      const me = await verifyCurrentSession();
      switchToDashboard(me);
      await loadDashboardData();
      if (showSuccess) notify('اطلاعات پنل به‌روزرسانی شد.');
    } catch (error) {
      $('#connection').textContent = error.message;
      $('#serviceStatus').textContent = 'خطا';
      if (/401|403|invalid|expired|authentication|required/i.test(error.message)) {
        clearSession();
        switchToLogin('نشست ورود معتبر نیست یا سطح دسترسی مدیر ندارید. دوباره وارد شوید.');
      } else if (dashboard.hidden) {
        switchToLogin(error.message);
      } else {
        notify(error.message);
      }
    }
  }

  // Login deliberately calls /auth/login directly. /health is only a diagnostic
  // action in Settings and must not block a valid login.
  loginForm.addEventListener('submit', async event => {
    event.preventDefault();
    setLoginError('');

    const email = $('#username').value.trim().toLowerCase();
    const password = $('#password').value;
    if (!email || !password) {
      setLoginError('ایمیل و رمز عبور را وارد کنید.');
      return;
    }

    const submit = loginForm.querySelector('button[type="submit"]');
    submit.disabled = true;
    submit.textContent = 'در حال ورود...';
    setLoginError('در حال ارسال درخواست ورود به سرور...');

    try {
      const result = await request('/auth/login', {
        method: 'POST',
        auth: false,
        body: JSON.stringify({ email, password })
      }, apiBase);

      if (!result || typeof result.access_token !== 'string' || !result.access_token) {
        throw new Error('پاسخ ورود معتبر نیست؛ توکن از API دریافت نشد.');
      }

      token = result.access_token;
      sessionStorage.setItem(TOKEN_KEY, token);

      let me;
      try {
        me = await verifyCurrentSession();
      } catch (error) {
        clearSession();
        throw new Error('رمز عبور پذیرفته شد اما تأیید نشست ناموفق بود: ' + error.message);
      }

      setLoginError('');
      switchToDashboard(me);
      $('#password').value = '';

      // Auth success is independent from dashboard widgets. A stats endpoint
      // failure must not make the login appear to have failed.
      try {
        await loadDashboardData();
      } catch (error) {
        $('#serviceStatus').textContent = 'خطا';
        $('#connection').textContent = error.message;
        notify('ورود موفق بود؛ خطا در دریافت آمار پنل: ' + error.message);
      }
      notify('با موفقیت وارد پنل شدید.');
    } catch (error) {
      clearSession();
      switchToLogin();
      setLoginError(error.message || 'ورود انجام نشد. خطای نامشخصی رخ داد.');
    } finally {
      submit.disabled = false;
      submit.textContent = 'ورود به پنل ←';
    }
  });

  // API address is only presented in the authenticated Settings area.
  $('#saveDashboardApi').addEventListener('click', () => {
    const message = $('#dashboardSettingsMessage');
    try {
      const nextApi = validateApiUrl($('#apiSettings').value);
      const changed = nextApi !== apiBase;
      apiBase = nextApi;
      localStorage.setItem(API_KEY, apiBase);
      $('#apiOrigin').textContent = new URL(apiBase).host;
      message.textContent = 'آدرس API ذخیره شد.';
      if (changed) {
        clearSession();
        switchToLogin('آدرس API تغییر کرد. برای ورود به سرویس جدید دوباره وارد شوید.');
      }
    } catch (error) {
      message.textContent = error.message;
    }
  });

  $('#checkDashboardApi').addEventListener('click', async () => {
    const message = $('#dashboardSettingsMessage');
    message.textContent = 'در حال بررسی اتصال...';
    try {
      const candidate = validateApiUrl($('#apiSettings').value);
      const health = await request('/health', { method: 'GET', auth: false }, candidate);
      if (!health || health.status !== 'ok') throw new Error('پاسخ سلامت API معتبر نیست.');
      const changed = candidate !== apiBase;
      apiBase = candidate;
      localStorage.setItem(API_KEY, apiBase);
      $('#apiOrigin').textContent = new URL(apiBase).host;
      message.textContent = 'اتصال موفق · ' + (health.service || 'API') + ' · نسخه ' + (health.version || 'نامشخص');
      if (changed) {
        clearSession();
        switchToLogin('آدرس جدید تست و ذخیره شد. برای ادامه دوباره وارد شوید.');
      }
    } catch (error) {
      message.textContent = error.message;
    }
  });

  $('#refresh').addEventListener('click', () => refreshDashboard(true));

  $('#logout').addEventListener('click', async () => {
    try {
      if (token) await request('/auth/logout', { method: 'POST' });
    } catch {
      // Always clear local session even if the API cannot be reached.
    }
    clearSession();
    switchToLogin('از حساب خارج شدید.');
  });

  $('#userSearch').addEventListener('input', renderUsers);
  $('#questionSearch').addEventListener('input', renderQuestions);

  $('#questionsTable').addEventListener('click', async event => {
    const button = event.target.closest('[data-delete-question]');
    if (!button) return;
    const id = Number(button.dataset.deleteQuestion);
    if (!Number.isSafeInteger(id) || id < 1 || !confirm('این سؤال حذف شود؟ این عملیات قابل بازگشت نیست.')) return;
    button.disabled = true;
    try {
      await request('/admin/questions/' + id, { method: 'DELETE' });
      questionsCache = questionsCache.filter(question => Number(question.id) !== id);
      renderQuestions();
      notify('سؤال حذف شد.');
    } catch (error) {
      notify(error.message);
      button.disabled = false;
    }
  });

  document.querySelectorAll('.tab').forEach(button => {
    button.addEventListener('click', () => {
      document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.tab === button.dataset.tab);
      });
      ['usersPanel', 'questionsPanel', 'settingsPanel'].forEach(id => {
        $('#' + id).hidden = id !== button.dataset.tab;
      });
    });
  });

  // Surface script/runtime failures visibly instead of silently leaving the form inert.
  window.addEventListener('error', event => {
    if (loginView && !loginView.hidden) {
      setLoginError('خطای اجرای پنل: ' + (event.message || 'JavaScript error'));
    }
  });
  window.addEventListener('unhandledrejection', event => {
    const reason = event.reason && event.reason.message ? event.reason.message : String(event.reason || 'Unknown error');
    if (loginView && !loginView.hidden) setLoginError('خطای غیرمنتظره: ' + reason);
  });

  $('#apiSettings').value = apiBase;
  $('#apiOrigin').textContent = new URL(apiBase).host;

  if (token) refreshDashboard(false);
  else switchToLogin();
})();

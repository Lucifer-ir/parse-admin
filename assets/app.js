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

  let apiBase = normalizeBase(localStorage.getItem(API_KEY) || DEFAULT_API);
  let token = sessionStorage.getItem(TOKEN_KEY) || '';
  let usersCache = [];
  let questionsCache = [];
  let toastTimer = null;

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
      throw new Error('فرمت آدرس API معتبر نیست. نمونه: https://api.aiparseh.ir');
    }
    const isLocal = ['localhost', '127.0.0.1'].includes(parsed.hostname);
    if (parsed.protocol !== 'https:' && !(isLocal && parsed.protocol === 'http:')) {
      throw new Error('برای امنیت ورود، آدرس API باید HTTPS باشد.');
    }
    if (parsed.username || parsed.password || parsed.search || parsed.hash) {
      throw new Error('آدرس API نباید شامل نام کاربری، رمز، Query یا Fragment باشد.');
    }
    return normalized;
  }

  function setApiBase(value) {
    apiBase = validateApiUrl(value);
    localStorage.setItem(API_KEY, apiBase);
    $('#apiSettings').value = apiBase;
    $('#apiOrigin').textContent = new URL(apiBase).host;
  }

  function setLoginError(message, kind = 'error') {
    loginError.textContent = message || '';
    loginError.dataset.kind = kind;
  }

  function notify(message) {
    const toast = $('#toast');
    toast.textContent = message;
    toast.classList.add('visible');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('visible'), 3500);
  }

  function formatNumber(value) {
    return Number(value || 0).toLocaleString('fa-IR');
  }

  function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('fa-IR');
  }

  function extractMessage(payload, fallback) {
    if (!payload || typeof payload !== 'object') return fallback;
    const detail = payload.detail ?? payload.message ?? payload.error;
    if (typeof detail === 'string' && detail.trim()) return detail;
    if (Array.isArray(detail)) {
      return detail.map(item => item && typeof item.msg === 'string' ? item.msg : '').filter(Boolean).join('، ') || fallback;
    }
    return fallback;
  }

  async function request(path, options = {}, base = apiBase) {
    if (!base) throw new Error('آدرس API تنظیم نشده است.');

    const headers = new Headers(options.headers || {});
    if (token && options.auth !== false) headers.set('Authorization', 'Bearer ' + token);
    if (options.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    let response;

    try {
      response = await fetch(base + path, {
        ...options,
        headers,
        signal: controller.signal,
        cache: 'no-store',
        credentials: 'omit'
      });
    } catch (error) {
      if (error && error.name === 'AbortError') {
        throw new Error('زمان پاسخ‌گویی API تمام شد. وضعیت سرور را بررسی کنید.');
      }
      throw new Error('ارتباط با API برقرار نشد. DNS، SSL و CORS دامنه panel.aiparseh.ir را بررسی کنید.');
    } finally {
      clearTimeout(timeout);
    }

    const raw = await response.text();
    let payload = {};
    if (raw) {
      try {
        payload = JSON.parse(raw);
      } catch {
        if (!response.ok) {
          throw new Error('API خطای HTTP ' + response.status + ' برگرداند و پاسخ آن JSON معتبر نیست.');
        }
        throw new Error('پاسخ API معتبر نیست؛ احتمالاً مسیر API به‌درستی به PHP متصل نشده است.');
      }
    }

    if (!response.ok) {
      const fallback = 'خطای HTTP ' + response.status;
      throw new Error(extractMessage(payload, fallback));
    }
    return payload;
  }

  async function checkHealth(base = apiBase) {
    const result = await request('/health', { method: 'GET', auth: false }, base);
    if (!result || result.status !== 'ok') {
      throw new Error('API پاسخ سلامت معتبر برنگرداند.');
    }
    return result;
  }

  function switchToLogin(message = '') {
    dashboard.hidden = true;
    loginView.hidden = false;
    if (message) setLoginError(message);
  }

  function switchToDashboard(user) {
    loginView.hidden = true;
    dashboard.hidden = false;
    $('#adminName').textContent = user && user.email ? user.email : 'مدیر سیستم';
    $('#apiSettings').value = apiBase;
    $('#apiOrigin').textContent = new URL(apiBase).host;
  }

  function safeText(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char]));
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

  async function loadDashboard() {
    const me = await request('/auth/me');
    if (!me || me.role !== 'admin' || me.active === false) {
      throw new Error('این حساب دسترسی مدیر فعال ندارد.');
    }

    const stats = await request('/admin/stats');
    $('#users').textContent = formatNumber(stats.users);
    $('#activeUsers').textContent = formatNumber(stats.active_users) + ' کاربر فعال';
    $('#sources').textContent = formatNumber(stats.sources);
    $('#questions').textContent = formatNumber(stats.questions);
    $('#serviceStatus').textContent = 'متصل';
    $('#connection').textContent = 'اتصال برقرار است';
    switchToDashboard(me);

    const results = await Promise.allSettled([loadUsers(), loadQuestions()]);
    if (results.some(result => result.status === 'rejected')) {
      const failed = results.find(result => result.status === 'rejected');
      notify('ورود موفق بود، اما بخشی از اطلاعات بارگذاری نشد: ' + failed.reason.message);
    }
  }

  async function refreshDashboard(showSuccess = true) {
    $('#connection').textContent = 'در حال دریافت اطلاعات...';
    try {
      await loadDashboard();
      if (showSuccess) notify('اطلاعات پنل به‌روزرسانی شد.');
    } catch (error) {
      $('#connection').textContent = error.message;
      $('#serviceStatus').textContent = 'خطا';

      if (/401|403|unauthorized|invalid or expired/i.test(error.message)) {
        token = '';
        sessionStorage.removeItem(TOKEN_KEY);
        switchToLogin('نشست ورود معتبر نیست یا دسترسی مدیر ندارید. دوباره وارد شوید.');
      } else {
        notify(error.message);
        if (dashboard.hidden) switchToLogin(error.message);
      }
    }
  }

  loginForm.addEventListener('submit', async event => {
    event.preventDefault();
    setLoginError('');

    let base;
    try {
      base = validateApiUrl(apiBase);
    } catch (error) {
      setLoginError(error.message);
      return;
    }

    const submit = loginForm.querySelector('button[type="submit"]');
    const originalLabel = 'ورود به پنل';
    submit.disabled = true;
    submit.textContent = 'در حال بررسی اطلاعات...';

    try {
      await checkHealth(base);
      const result = await request('/auth/login', {
        method: 'POST',
        auth: false,
        body: JSON.stringify({
          email: $('#username').value.trim().toLowerCase(),
          password: $('#password').value
        })
      }, base);

      if (!result || typeof result.access_token !== 'string' || !result.access_token) {
        throw new Error('ورود انجام نشد: API توکن معتبر برنگرداند.');
      }

      setApiBase(base);
      token = result.access_token;
      sessionStorage.setItem(TOKEN_KEY, token);
      $('#password').value = '';

      await loadDashboard();
      setLoginError('');
      notify('با موفقیت وارد پنل شدید.');
    } catch (error) {
      token = '';
      sessionStorage.removeItem(TOKEN_KEY);
      switchToLogin();
      setLoginError(error.message || 'ورود انجام نشد. پاسخ مشخصی از سرور دریافت نشد.');
    } finally {
      submit.disabled = false;
      submit.textContent = originalLabel;
    }
  });

  $('#saveDashboardApi').addEventListener('click', () => {
    const oldBase = apiBase;
    try {
      const newBase = validateApiUrl($('#apiSettings').value);
      if (newBase !== oldBase) {
        apiBase = newBase;
        localStorage.setItem(API_KEY, apiBase);
        token = '';
        sessionStorage.removeItem(TOKEN_KEY);
        $('#apiOrigin').textContent = new URL(apiBase).host;
        switchToLogin('آدرس API ذخیره شد. برای ورود به سرویس جدید، دوباره وارد شوید.');
        notify('آدرس API تغییر کرد؛ لطفاً دوباره وارد شوید.');
        return;
      }
      localStorage.setItem(API_KEY, apiBase);
      $('#dashboardSettingsMessage').textContent = 'آدرس API ذخیره شد.';
      notify('تنظیمات ذخیره شد.');
    } catch (error) {
      $('#dashboardSettingsMessage').textContent = error.message;
    }
  });

  $('#checkDashboardApi').addEventListener('click', async () => {
    const message = $('#dashboardSettingsMessage');
    message.textContent = 'در حال بررسی اتصال...';
    try {
      const candidate = validateApiUrl($('#apiSettings').value);
      const health = await checkHealth(candidate);
      const changed = candidate !== apiBase;
      apiBase = candidate;
      localStorage.setItem(API_KEY, apiBase);
      $('#apiOrigin').textContent = new URL(apiBase).host;
      message.textContent = 'اتصال موفق است · نسخه ' + (health.version || 'نامشخص');
      if (changed) {
        token = '';
        sessionStorage.removeItem(TOKEN_KEY);
        switchToLogin('آدرس API جدید تست و ذخیره شد. برای ادامه دوباره وارد شوید.');
        notify('آدرس API ذخیره شد؛ دوباره وارد شوید.');
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
      // Local logout must still succeed when the API is unavailable.
    }
    token = '';
    sessionStorage.removeItem(TOKEN_KEY);
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

  $('#apiSettings').value = apiBase;
  $('#apiOrigin').textContent = new URL(apiBase).host;

  if (token) {
    refreshDashboard(false);
  } else {
    switchToLogin();
  }
})();

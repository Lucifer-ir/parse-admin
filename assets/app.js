'use strict';
const $ = (selector) => document.querySelector(selector);
const loginView = $('#login');
const dashboard = $('#dashboard');
const loginForm = $('#loginForm');
const apiInput = $('#api');
const loginError = $('#loginError');
const API_KEY = 'parse_admin_api';
let apiBase = localStorage.getItem(API_KEY) || '';
let token = sessionStorage.getItem('parse_admin_token') || '';
let currentUser = null;
let usersCache = [];
let questionsCache = [];
let toastTimer;

apiInput.value = apiBase;
$('#apiSettings').value = apiBase;

function normalizedApi(value) { return String(value || '').trim().replace(/\/+$/, ''); }
function saveApi(value, messageSelector = '#settingsMessage') {
  const valueToSave = normalizedApi(value);
  if (!valueToSave) { $(messageSelector).textContent = 'ابتدا آدرس API را وارد کنید.'; return false; }
  try { const parsed = new URL(valueToSave); if (!['https:', 'http:'].includes(parsed.protocol)) throw new Error(); }
  catch { $(messageSelector).textContent = 'آدرس واردشده معتبر نیست.'; return false; }
  const apiChanged = apiBase !== '' && apiBase !== valueToSave;
  apiBase = valueToSave;
  localStorage.setItem(API_KEY, apiBase);
  if (apiChanged && token) {
    token = '';
    sessionStorage.removeItem('parse_admin_token');
    switchToLogin();
  }
  apiInput.value = apiBase;
  $('#apiSettings').value = apiBase;
  $('#apiOrigin').textContent = new URL(apiBase).host;
  $(messageSelector).textContent = apiChanged
    ? 'آدرس API تغییر کرد؛ برای دسترسی مدیریتی دوباره وارد شوید.'
    : 'آدرس API ذخیره شد.';
  return true;
}
function notify(message) {
  const toast = $('#toast'); toast.textContent = message; toast.classList.add('visible');
  clearTimeout(toastTimer); toastTimer = setTimeout(() => toast.classList.remove('visible'), 2800);
}
function formatNumber(value) { return Number(value || 0).toLocaleString('fa-IR'); }
function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('fa-IR');
}
async function request(path, options = {}) {
  if (!apiBase) throw new Error('آدرس API تنظیم نشده است.');
  const headers = new Headers(options.headers || {});
  if (token) headers.set('Authorization', 'Bearer ' + token);
  if (options.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
  let response;
  try { response = await fetch(apiBase + path, { ...options, headers }); }
  catch { throw new Error('اتصال به API برقرار نشد. آدرس، DNS، HTTPS و تنظیمات CORS را بررسی کنید.'); }
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(typeof payload.detail === 'string' ? payload.detail : 'خطای HTTP ' + response.status);
  return payload;
}
async function checkHealth() {
  const result = await request('/health');
  if (result.status !== 'ok') throw new Error('پاسخ سلامت API معتبر نیست.');
  return result;
}
async function testApi(messageSelector = '#settingsMessage') {
  if (!saveApi(apiInput.value, messageSelector)) return;
  $(messageSelector).textContent = 'در حال بررسی اتصال...';
  try { const health = await checkHealth(); $(messageSelector).textContent = 'اتصال موفق · نسخه ' + (health.version || 'نامشخص'); }
  catch (error) { $(messageSelector).textContent = error.message; }
}
function switchToDashboard(user) {
  currentUser = user; loginView.hidden = true; dashboard.hidden = false;
  $('#adminName').textContent = user?.email || 'مدیر سیستم';
  $('#apiOrigin').textContent = apiBase ? new URL(apiBase).host : '';
  $('#apiSettings').value = apiBase;
}
function switchToLogin() { dashboard.hidden = true; loginView.hidden = false; }
function safeText(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
}
function renderUsers() {
  const term = $('#userSearch').value.trim().toLowerCase();
  const rows = usersCache.filter(user => String(user.email || '').toLowerCase().includes(term));
  $('#usersTable').innerHTML = rows.length ? rows.map(user => `<tr><td>${formatNumber(user.id)}</td><td dir="ltr">${safeText(user.email)}</td><td><span class="role-tag">${safeText(user.role)}</span></td><td><span class="state ${user.active ? 'enabled' : 'disabled'}">${user.active ? 'فعال' : 'غیرفعال'}</span></td><td>${formatDate(user.created_at)}</td></tr>`).join('') : '<tr><td colspan="5" class="empty">کاربری پیدا نشد.</td></tr>';
}
function renderQuestions() {
  const term = $('#questionSearch').value.trim().toLowerCase();
  const rows = questionsCache.filter(question => String(question.question || '').toLowerCase().includes(term));
  $('#questionsTable').innerHTML = rows.length ? rows.map(question => `<tr><td>${formatNumber(question.id)}</td><td class="question-cell">${safeText(question.question)}</td><td>${safeText(question.subject || '—')}</td><td>${safeText(question.grade || '—')}</td><td>${safeText(question.source_type || '—')}</td><td><button class="danger-button" data-delete-question="${Number(question.id)}">حذف</button></td></tr>`).join('') : '<tr><td colspan="6" class="empty">سؤالی پیدا نشد.</td></tr>';
}
async function loadUsers() { usersCache = await request('/admin/users'); renderUsers(); }
async function loadQuestions() { questionsCache = await request('/admin/questions?limit=200&offset=0'); renderQuestions(); }
async function loadDashboard() {
  const me = await request('/auth/me');
  if (me.role !== 'admin' || me.active === false) throw new Error('این حساب دسترسی مدیر ندارد.');
  currentUser = me;
  const stats = await request('/admin/stats');
  $('#users').textContent = formatNumber(stats.users);
  $('#activeUsers').textContent = formatNumber(stats.active_users) + ' کاربر فعال';
  $('#sources').textContent = formatNumber(stats.sources);
  $('#questions').textContent = formatNumber(stats.questions);
  $('#serviceStatus').textContent = 'متصل';
  $('#connection').textContent = 'دریافت اطلاعات با موفقیت انجام شد';
  switchToDashboard(me);
  const results = await Promise.allSettled([loadUsers(), loadQuestions()]);
  if (results.some(result => result.status === 'rejected')) notify('بخشی از اطلاعات بارگذاری نشد؛ تنظیمات API را بررسی کنید.');
}
async function refreshDashboard() {
  $('#connection').textContent = 'در حال دریافت اطلاعات...';
  try { await loadDashboard(); notify('اطلاعات پنل به‌روزرسانی شد.'); }
  catch (error) {
    $('#connection').textContent = error.message;
    $('#serviceStatus').textContent = 'خطا';
    if (/دسترسی مدیر|توکن|expired|401|403/i.test(error.message)) {
      token = '';
      sessionStorage.removeItem('parse_admin_token');
      switchToLogin();
    }
    if (dashboard.hidden) {
      switchToLogin();
      loginError.textContent = error.message;
    } else {
      notify(error.message);
    }
  }
}
loginForm.addEventListener('submit', async event => {
  event.preventDefault(); loginError.textContent = '';
  if (!saveApi(apiInput.value)) { loginError.textContent = 'ابتدا آدرس API را تنظیم کنید.'; return; }
  const submit = loginForm.querySelector('button[type="submit"]');
  submit.disabled = true; submit.textContent = 'در حال ورود...';
  try {
    const result = await request('/auth/login', { method: 'POST', body: JSON.stringify({ email: $('#username').value.trim(), password: $('#password').value }) });
    if (!result.access_token) throw new Error('پاسخ ورود از API معتبر نیست.');
    token = result.access_token; sessionStorage.setItem('parse_admin_token', token);
    await refreshDashboard();
    if (!dashboard.hidden) notify('با موفقیت وارد شدید.');
  } catch (error) {
    token = ''; sessionStorage.removeItem('parse_admin_token'); loginError.textContent = error.message;
  } finally { submit.disabled = false; submit.innerHTML = 'ورود به پنل <span>←</span>'; }
});
$('#saveSettings').addEventListener('click', () => saveApi(apiInput.value));
$('#testApi').addEventListener('click', () => testApi());
$('#saveDashboardApi').addEventListener('click', () => { if (saveApi($('#apiSettings').value, '#dashboardSettingsMessage')) notify('تنظیمات ذخیره شد.'); });
$('#checkDashboardApi').addEventListener('click', async () => {
  if (!saveApi($('#apiSettings').value, '#dashboardSettingsMessage')) return;
  $('#dashboardSettingsMessage').textContent = 'در حال بررسی...';
  try { const health = await checkHealth(); $('#dashboardSettingsMessage').textContent = 'سرویس فعال است · نسخه ' + (health.version || 'نامشخص'); }
  catch (error) { $('#dashboardSettingsMessage').textContent = error.message; }
});
$('#refresh').addEventListener('click', refreshDashboard);
$('#logout').addEventListener('click', () => { token = ''; sessionStorage.removeItem('parse_admin_token'); switchToLogin(); });
$('#userSearch').addEventListener('input', renderUsers);
$('#questionSearch').addEventListener('input', renderQuestions);
$('#questionsTable').addEventListener('click', async event => {
  const button = event.target.closest('[data-delete-question]');
  if (!button) return;
  const id = Number(button.dataset.deleteQuestion);
  if (!Number.isInteger(id) || !confirm('این سؤال حذف شود؟ این عملیات قابل بازگشت نیست.')) return;
  button.disabled = true;
  try { await request('/admin/questions/' + id, { method: 'DELETE' }); questionsCache = questionsCache.filter(question => Number(question.id) !== id); renderQuestions(); notify('سؤال حذف شد.'); }
  catch (error) { notify(error.message); button.disabled = false; }
});
document.querySelectorAll('.tab').forEach(button => button.addEventListener('click', () => {
  document.querySelectorAll('.tab').forEach(tab => tab.classList.toggle('active', tab.dataset.tab === button.dataset.tab));
  ['usersPanel', 'questionsPanel', 'settingsPanel'].forEach(id => { $('#' + id).hidden = id !== button.dataset.tab; });
}));
$('#apiOrigin').textContent = apiBase ? new URL(apiBase).host : '';
if (token && apiBase) refreshDashboard(); else switchToLogin();

'use strict';

(() => {
  const cfg = window.PARSE_ADMIN || {};
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || cfg.csrf || '';
  const $ = (selector) => document.querySelector(selector);
  const $$ = (selector) => Array.from(document.querySelectorAll(selector));
  const catalogs = { chat: [], decision: [], image: [], audio: [], embedding: [] };
  const pageTitles = {
    overview: 'نمای کلی', models: 'کاتالوگ مدل‌ها', playground: 'محیط آزمایش AI',
    questions: 'سازنده سؤال', exercises: 'سازنده تمرین', review: 'بررسی و اصلاح متن',
    images: 'تصویر و ویرایش', audio: 'صوت و گفتار', embeddings: 'Embedding و RAG',
    decisions: 'تصمیم‌گیری ساختاریافته', batches: 'درخواست‌های Batch',
    usage: 'مصرف و هزینه', settings: 'تنظیمات و کلید API'
  };
  const state = { currentPage: 'overview', modelKind: 'chat', lastOutputs: {}, usageRows: [] };
  let speechObjectUrl = null;

  const money = value => Number(value || 0).toLocaleString('fa-IR', { maximumFractionDigits: 2 });
  const number = value => Number(value || 0).toLocaleString('fa-IR');
  const safe = value => String(value ?? '').replace(/[&<>"']/g, ch => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ch]));
  const shortDate = value => {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T') + (String(value).endsWith('Z') ? '' : 'Z'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('fa-IR');
  };

  function toast(message, kind = 'info') {
    const node = $('#toast');
    node.textContent = message;
    node.dataset.kind = kind;
    node.classList.add('visible');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(() => node.classList.remove('visible'), 5000);
  }

  async function api(action, method = 'GET', payload = null, params = {}) {
    const url = new URL('/api.php', location.origin);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    const headers = new Headers({ 'Accept': 'application/json' });
    const options = { method, credentials: 'same-origin', cache: 'no-store', headers };
    if (method !== 'GET') {
      headers.set('Content-Type', 'application/json');
      headers.set('X-CSRF-Token', csrf);
      options.body = JSON.stringify(payload || {});
    }
    let response;
    try {
      response = await fetch(url, options);
    } catch {
      throw new Error('ارتباط با پنل PHP برقرار نشد. اتصال سرور را بررسی کن.');
    }
    const raw = await response.text();
    let data = {};
    if (raw) {
      try { data = JSON.parse(raw); }
      catch { throw new Error('پاسخ سرور JSON معتبر نیست.'); }
    }
    if (response.status === 401) {
      location.href = '/';
      throw new Error('نشست مدیر منقضی شده است.');
    }
    if (!response.ok || data.ok === false) {
      if (response.status === 419) throw new Error('نشست امنیتی منقضی شده؛ صفحه را تازه‌سازی کن.');
      throw new Error(data.message || data.detail || 'خطای HTTP ' + response.status);
    }
    return data;
  }

  async function binaryApi(action, payload) {
    const response = await fetch('/api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'audio/*, application/json' },
      body: JSON.stringify(payload || {})
    });
    if (!response.ok) {
      const data = await response.json().catch(() => ({}));
      throw new Error(data.message || data.detail || 'خطای HTTP ' + response.status);
    }
    return {
      blob: await response.blob(),
      requestId: response.headers.get('X-AI-Request-ID') || '',
      latency: response.headers.get('X-AI-Latency-MS') || ''
    };
  }

  function showPage(page) {
    if (!pageTitles[page]) return;
    state.currentPage = page;
    $$('.page-view').forEach(node => node.classList.toggle('active', node.id === 'page-' + page));
    $$('.nav-item').forEach(node => node.classList.toggle('active', node.dataset.page === page));
    $('#pageCrumb').textContent = pageTitles[page];
    $('#sidebar').classList.remove('open');
    $('#mobileScrim').classList.remove('show');
    if (page === 'usage') loadUsage();
    if (page === 'models') loadCatalog(state.modelKind).catch(error => toast(error.message, 'error'));
    if (page === 'settings') loadCatalogCounts();
  }

  function setBusy(button, busy, label) {
    if (!button) return;
    if (busy) {
      button.dataset.originalLabel = button.innerHTML;
      button.disabled = true;
      button.textContent = label || 'در حال اجرا...';
    } else {
      button.disabled = false;
      if (button.dataset.originalLabel) button.innerHTML = button.dataset.originalLabel;
      delete button.dataset.originalLabel;
    }
  }

  function unwrapList(data) {
    if (Array.isArray(data)) return data;
    if (!data || typeof data !== 'object') return [];
    for (const key of ['data', 'models', 'items', 'results']) {
      if (Array.isArray(data[key])) return data[key];
    }
    return [];
  }

  function modelId(model) {
    return String(model.id || model.model || model.name || model.slug || '');
  }

  function modelLabel(model) {
    const id = modelId(model);
    const display = model.display_name || model.name || model.label || '';
    return display && display !== id ? display + ' — ' + id : id;
  }

  function modelPrice(model) {
    const pricing = model.pricing || model.prices || {};
    const input = pricing.prompt ?? pricing.input ?? model.input_price ?? model.prompt_price ?? model.price_per_million ?? null;
    const output = pricing.completion ?? pricing.output ?? model.output_price ?? model.completion_price ?? null;
    if (input === null && output === null) return 'طبق تعرفه مدل';
    return 'ورودی: ' + money(input) + ' · خروجی: ' + money(output);
  }

  function capabilities(model) {
    const bits = [];
    const bools = [
      ['supports_vision','تصویر'],['supports_tools','ابزار'],['supports_edit','ویرایش'],
      ['supports_streaming','استریم'],['supports_json','JSON'],['supports_audio','صوت'],
      ['supports_pdf','PDF'],['supports_batch','Batch']
    ];
    bools.forEach(([key, label]) => { if (model[key] === true) bits.push(label); });
    const modalities = model.modalities || model.input_modalities;
    if (Array.isArray(modalities)) modalities.forEach(item => bits.push(String(item)));
    if (model.hosted_in_iran || model.hosting_country === 'IR') bits.push('میزبانی ایران');
    if (model.free || String(model.id || '').endsWith(':free')) bits.push('رایگان');
    return [...new Set(bits)].slice(0, 7);
  }

  function selectModels(kind, selectors) {
    const list = catalogs[kind] || [];
    const html = list.length
      ? '<option value="">انتخاب مدل...</option>' + list.map(model => '<option value="' + safe(modelId(model)) + '">' + safe(modelLabel(model)) + '</option>').join('')
      : '<option value="">ابتدا کاتالوگ مدل را بارگذاری کن</option>';
    selectors.forEach(selector => {
      const node = $(selector);
      if (!node) return;
      const previous = node.value;
      node.innerHTML = html;
      if (list.some(model => modelId(model) === previous)) node.value = previous;
      if (!node.value && list.length) node.value = modelId(list[0]);
    });
  }

  async function loadCatalog(kind) {
    const result = await api('catalog', 'GET', null, { kind });
    const list = unwrapList(result.data);
    catalogs[kind] = list;
    if (kind === 'chat') {
      selectModels('chat', ['#playgroundModel','#questionModel','#exerciseModel','#reviewModel','#batchModel']);
      $('#modelCount').textContent = number(list.length);
      $('#chatModelCount').textContent = number(list.length);
    }
    if (kind === 'decision') {
      selectModels('decision', ['#decisionModel']);
      $('#decisionModelCount').textContent = number(list.length);
    }
    if (kind === 'image') {
      selectModels('image', ['#imageModel','#imageEditModel','#imageBatchModel']);
      $('#imageModelCount').textContent = number(list.length);
    }
    if (kind === 'audio') {
      selectModels('audio', ['#transcribeModel','#speechModel']);
      $('#audioModelCount').textContent = number(list.length);
    }
    if (kind === 'embedding') {
      selectModels('embedding', ['#embeddingModel']);
      $('#embeddingModelCount').textContent = number(list.length);
    }
    if (state.currentPage === 'models' && state.modelKind === kind) renderModels(kind);
    return list;
  }

  async function loadCatalogs() {
    const btn = $('#loadCatalogs');
    setBusy(btn, true, 'دریافت کاتالوگ‌ها...');
    const outcomes = await Promise.allSettled(['chat','decision','image','audio','embedding'].map(loadCatalog));
    setBusy(btn, false);
    const ok = outcomes.filter(x => x.status === 'fulfilled').length;
    const errors = outcomes.filter(x => x.status === 'rejected').map(x => x.reason.message);
    if (ok) toast(ok + ' کاتالوگ از ۵ کاتالوگ دریافت شد.');
    if (errors.length) toast(errors.join(' | '), 'error');
  }

  function loadCatalogCounts() {
    const cached = Object.entries(catalogs).some(([, list]) => list.length);
    if (!cached && cfg.aiEnabled) loadCatalogs().catch(error => toast(error.message, 'error'));
  }

  function renderModels(kind) {
    const term = ($('#modelSearch')?.value || '').trim().toLowerCase();
    const list = (catalogs[kind] || []).filter(model => {
      const data = JSON.stringify(model).toLowerCase();
      return data.includes(term);
    });
    $('#modelsTable').innerHTML = list.length ? list.map(model => {
      const id = modelId(model);
      const context = model.context_length ?? model.context_window ?? model.max_context_tokens ?? '—';
      const output = model.max_output_tokens ?? model.max_completion_tokens ?? model.max_tokens ?? '—';
      const tags = capabilities(model);
      const isIran = model.hosted_in_iran || model.hosting_country === 'IR';
      return '<tr><td><span class="model-id">' + safe(id) + '</span><br><small>' + safe(model.display_name || model.name || '') + '</small></td>' +
        '<td>' + safe(modelPrice(model)) + '</td><td>زمینه: ' + safe(context) + '<br>خروجی: ' + safe(output) +
        '</td><td><div class="model-tags">' + (tags.length ? tags.map(tag => '<span class="model-tag">' + safe(tag) + '</span>').join('') : '—') +
        '</div></td><td><span class="state-pill">' + (isIran ? 'میزبانی ایران' : 'در دسترس') + '</span></td></tr>';
    }).join('') : '<tr><td colspan="5" class="empty-state">مدلی مطابق جست‌وجو پیدا نشد یا کاتالوگ هنوز بارگذاری نشده است.</td></tr>';
  }

  function renderRecent(rows) {
    $('#recentUsage').innerHTML = rows.length ? rows.map(row =>
      '<tr><td>' + safe(row.operation) + '</td><td><span class="model-id">' + safe(row.model || '—') + '</span></td>' +
      '<td><span class="state-pill" style="' + (row.is_success ? '' : 'background:#fff0ef;color:#c04d52') + '">' + (row.is_success ? 'موفق' : 'ناموفق') +
      '</span></td><td>' + money(row.charged_toman) + ' تومان</td><td>' + safe(shortDate(row.created_at)) + '</td></tr>'
    ).join('') : '<tr><td colspan="5" class="empty-state">هنوز درخواستی از این پنل اجرا نشده است.</td></tr>';
  }

  async function loadBootstrap() {
    const result = await api('bootstrap');
    const usage = result.usage || {};
    $('#statRequests').textContent = number(usage.requests);
    $('#statCost').textContent = money(usage.cost);
    $('#statTokens').textContent = number(usage.tokens);
    const ratio = Number(usage.requests || 0) > 0 ? 100 * Number(usage.successful || 0) / Number(usage.requests) : 0;
    $('#statSuccess').textContent = ratio.toLocaleString('fa-IR', { maximumFractionDigits: 1 }) + '%';
    renderRecent(result.recent || []);
    $('#providerStatus').classList.toggle('status-on', !!result.provider?.enabled);
    $('#providerStatus').classList.toggle('status-off', !result.provider?.enabled);
    $('#aiStatusBadge').textContent = result.provider?.enabled ? 'اتصال فعال' : 'اتصال غیرفعال';
    $('#aiStatusBadge').className = 'badge ' + (result.provider?.enabled ? 'badge-success' : 'badge-neutral');
    $('#providerBase').value = result.provider?.base_url || 'https://console.paasta.cloud/api/ai/v1';
    $('#providerEnabled').checked = !!result.provider?.enabled;
    cfg.aiEnabled = !!result.provider?.enabled;
    cfg.hasApiKey = !!result.provider?.key_configured;
    if (cfg.hasApiKey) $('#providerKey').placeholder = 'کلید ذخیره شده است؛ برای تغییر، کلید جدید وارد کن';
    $('#currentDate').textContent = new Date().toLocaleDateString('fa-IR', { year:'numeric', month:'long', day:'numeric' });
  }

  async function saveProvider(event) {
    event?.preventDefault();
    const btn = $('#providerForm').querySelector('button[type="submit"]');
    const feedback = $('#providerFeedback');
    setBusy(btn, true, 'در حال ذخیره...');
    feedback.textContent = '';
    try {
      const result = await api('provider.save', 'POST', {
        base_url: $('#providerBase').value.trim(),
        api_key: $('#providerKey').value,
        enabled: $('#providerEnabled').checked ? 1 : 0
      });
      $('#providerKey').value = '';
      cfg.aiEnabled = !!result.enabled;
      cfg.hasApiKey = true;
      feedback.textContent = 'تنظیمات با موفقیت ذخیره شد. اکنون اتصال را تست کن.';
      toast(result.message || 'تنظیمات ذخیره شد.');
      await loadBootstrap();
    } catch (error) {
      feedback.textContent = error.message;
      toast(error.message, 'error');
    } finally {
      setBusy(btn, false);
    }
  }

  async function testProvider() {
    const btn = $('#testProvider');
    const feedback = $('#providerFeedback');
    setBusy(btn, true, 'در حال بررسی...');
    feedback.textContent = 'در حال ارسال درخواست آزمایشی به GET /models...';
    try {
      const result = await api('provider.test', 'POST', {});
      feedback.textContent = result.message + ' · ' + number(result.model_count) + ' مدل · Request ID: ' + (result.request_id || '—');
      toast('اتصال موفق بود؛ ' + number(result.model_count) + ' مدل شناسایی شد.');
      await loadCatalogs();
      await loadBootstrap();
    } catch (error) {
      feedback.textContent = error.message;
      toast(error.message, 'error');
    } finally {
      setBusy(btn, false);
    }
  }

  async function invoke(operation, payload) {
    const result = await api('ai.invoke', 'POST', { operation, payload });
    return result;
  }

  function displayResult(target, data, meta = null) {
    const node = $(target);
    if (!node) return;
    node.classList.remove('placeholder-result');
    node.textContent = typeof data === 'string' ? data : JSON.stringify(data, null, 2);
    if (meta) {
      const metaNode = $(meta);
      if (metaNode) metaNode.textContent = [
        meta.request_id ? 'Request ID: ' + meta.request_id : '',
        meta.latency_ms !== undefined ? 'زمان: ' + number(meta.latency_ms) + ' ms' : ''
      ].filter(Boolean).join(' · ');
    }
  }

  function extractSseText(raw) {
    const lines = String(raw || '').split(/\\r?\\n/);
    const pieces = [];
    let structured = [];
    for (const line of lines) {
      if (!line.startsWith('data:')) continue;
      const chunk = line.slice(5).trim();
      if (!chunk || chunk === '[DONE]') continue;
      try {
        const item = JSON.parse(chunk);
        const chatDelta = item.choices?.[0]?.delta?.content;
        if (typeof chatDelta === 'string') pieces.push(chatDelta);
        else if (Array.isArray(chatDelta)) pieces.push(chatDelta.map(part => part.text || '').join(''));
        else if (item.type === 'response.output_text.delta' && typeof item.delta === 'string') pieces.push(item.delta);
        else if (item.type === 'content_block_delta' && item.delta?.type === 'text_delta') pieces.push(item.delta.text || '');
        else if (item.choices?.[0]?.text) pieces.push(item.choices[0].text);
        else if (item.error) structured.push(item.error.message || JSON.stringify(item.error));
      } catch {
        // Ignore non-JSON keep-alive/event lines.
      }
    }
    return pieces.length ? pieces.join('') : (structured.join('\\n') || String(raw || ''));
  }

  function extractText(data, apiKind = 'chat') {
    if (!data || typeof data !== 'object') return String(data ?? '');
    if (typeof data.raw === 'string' && data.raw.includes('data:')) return extractSseText(data.raw);
    if (typeof data.output_text === 'string') return data.output_text;
    if (apiKind === 'messages' && Array.isArray(data.content)) {
      return data.content.map(part => part.text || '').filter(Boolean).join('\n');
    }
    const choiceContent = data.choices?.[0]?.message?.content ?? data.choices?.[0]?.text;
    if (typeof choiceContent === 'string') return choiceContent;
    if (Array.isArray(choiceContent)) return choiceContent.map(item => item.text || '').filter(Boolean).join('\n');
    if (Array.isArray(data.output)) {
      const texts = [];
      data.output.forEach(item => (item.content || []).forEach(part => { if (part.text) texts.push(part.text); }));
      if (texts.length) return texts.join('\n');
    }
    return JSON.stringify(data, null, 2);
  }

  function parseGenerated(text) {
    let clean = String(text || '').trim();
    clean = clean.replace(/^\u0060\u0060\u0060(?:json)?\s*/i, '').replace(/\s*\u0060\u0060\u0060$/i, '');
    const first = clean.indexOf('{');
    const last = clean.lastIndexOf('}');
    const arrayStart = clean.indexOf('[');
    const arrayEnd = clean.lastIndexOf(']');
    if (arrayStart >= 0 && arrayEnd > arrayStart && (first < 0 || arrayStart < first)) {
      clean = clean.slice(arrayStart, arrayEnd + 1);
    } else if (first >= 0 && last > first) {
      clean = clean.slice(first, last + 1);
    }
    try { return JSON.parse(clean); } catch { return null; }
  }

  function renderGenerated(target, data) {
    const node = $(target);
    node.classList.remove('placeholder-result');
    node.textContent = '';
    if (!data || typeof data !== 'object') {
      node.textContent = String(data ?? '');
      return;
    }
    const title = data.title || data.topic || data.summary || 'نتیجه تولید';
    const h = document.createElement('h4');
    h.textContent = title;
    node.append(h);
    Object.entries(data).forEach(([key, value]) => {
      if (key === 'title' || key === 'topic' || key === 'summary') {
        if (key === 'summary') {
          const p = document.createElement('p'); p.textContent = value; node.append(p);
        }
        return;
      }
      const section = document.createElement('section');
      const heading = document.createElement('h4'); heading.textContent = key;
      const pre = document.createElement('pre'); pre.textContent = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
      section.append(heading, pre); node.append(section);
    });
  }

  async function runGenerator(kind, buttonSelector, statusSelector, resultSelector, payloadBuilder, systemText) {
    const btn = $(buttonSelector);
    const status = $(statusSelector);
    setBusy(btn, true, 'در حال تولید...');
    status.textContent = 'در حال اجرا';
    try {
      const { model, userText, maxTokens = 4096, temperature = 0.5 } = payloadBuilder();
      if (!model) throw new Error('ابتدا کاتالوگ مدل‌های Chat را بارگذاری کن و یک مدل انتخاب کن.');
      const payload = {
        model,
        messages: [
          { role: 'system', content: systemText },
          { role: 'user', content: userText }
        ],
        max_tokens: Math.min(12000, Math.max(128, Number(maxTokens))),
        temperature
      };
      const result = await invoke('chat', payload);
      const answer = extractText(result.data, 'chat');
      const parsed = parseGenerated(answer);
      const output = parsed || { raw_output: answer, warning: 'مدل پاسخ را به شکل JSON معتبر برنگرداند.' };
      state.lastOutputs[kind] = output;
      renderGenerated(resultSelector, output);
      status.textContent = 'تکمیل شد';
      toast('عملیات با موفقیت انجام شد.');
      if (result.request_id) toast('شناسه درخواست: ' + result.request_id);
      await loadBootstrap();
    } catch (error) {
      status.textContent = 'ناموفق';
      toast(error.message, 'error');
      const node = $(resultSelector); node.classList.remove('placeholder-result'); node.textContent = error.message;
    } finally {
      setBusy(btn, false);
    }
  }

  async function runQuestionGenerator() {
    const count = Math.min(30, Math.max(1, Number($('#questionCount').value || 5)));
    const subject = $('#questionSubject').value.trim();
    const grade = $('#questionGrade').value.trim();
    const type = $('#questionType').value;
    const difficulty = $('#questionDifficulty').value;
    const topic = $('#questionTopic').value.trim();
    const userText = [
      'برای درس و مشخصات زیر سؤال آموزشی تولید کن.',
      'موضوع: ' + subject, 'پایه: ' + grade, 'نوع: ' + type, 'سختی: ' + difficulty,
      'تعداد دقیق: ' + count, 'محدوده و سرفصل: ' + topic,
      'خروجی فقط JSON معتبر فارسی باشد، بدون Markdown و توضیح بیرون JSON.',
      'ساختار: {"title":"...","questions":[{"id":1,"question":"...","type":"multiple_choice|short_answer|true_false|essay","options":["..."],"correct_answer":"...","explanation":"...","difficulty":"easy|medium|hard","topic":"..."}]}',
      'گزینه‌ها فقط برای سؤال چندگزینه‌ای. پاسخ صحیح و توضیح آموزشی برای هر سؤال الزامی است. از تکرار سؤال و ابهام دوری کن.'
    ].join('\n');
    return runGenerator('questions','#generateQuestions','#questionStatus','#questionResult',
      () => ({ model: $('#questionModel').value, userText, maxTokens: 7000 }), 
      'تو طراح حرفه‌ای سؤال آموزشی هستی. سؤال‌های دقیق، متناسب با پایه و سطح درخواست‌شده و با پاسخ صحیح تولید کن.');
  }

  async function runExerciseGenerator() {
    const count = Math.min(30, Math.max(1, Number($('#exerciseCount').value || 8)));
    const userText = [
      'یک مجموعه تمرین آموزشی تولید کن. تعداد دقیق: ' + count,
      'موضوع: ' + $('#exerciseSubject').value.trim(),
      'نوع تمرین: ' + $('#exerciseType').value,
      'سطح: ' + $('#exerciseLevel').value,
      'مدت پیشنهادی (دقیقه): ' + $('#exerciseDuration').value,
      'هدف یادگیری: ' + $('#exerciseGoal').value.trim(),
      'فقط JSON معتبر و فارسی برگردان، بدون Markdown.',
      'ساختار: {"title":"...","learning_objectives":["..."],"estimated_minutes":20,"exercises":[{"id":1,"type":"...","instruction":"...","question":"...","options":[],"answer":"...","hint":"...","explanation":"...","points":1}],"answer_key":[{"id":1,"answer":"...","explanation":"..."}]}',
      'تمرین‌ها متنوع، قابل ارزیابی و متناسب با سطح باشند؛ پاسخ و راهنمایی ارائه شود.'
    ].join('\n');
    return runGenerator('exercises','#generateExercises','#exerciseStatus','#exerciseResult',
      () => ({ model: $('#exerciseModel').value, userText, maxTokens: 8000 }),
      'تو طراح برنامه درسی و تمرین آموزشی هستی. تمرین‌های باکیفیت و قابل سنجش طراحی کن.');
  }

  async function runTextReview() {
    const text = $('#reviewText').value.trim();
    if (!text) { toast('متن برای بررسی را وارد کن.', 'error'); return; }
    const userText = [
      'متن زیر را دقیق بررسی کن.',
      'زبان: ' + $('#reviewLanguage').value,
      'نوع بررسی: ' + $('#reviewType').value,
      'لحن خروجی: ' + $('#reviewTone').value,
      'معیارهای اختصاصی: ' + $('#reviewCriteria').value.trim(),
      'متن برای بررسی:\n' + text,
      'فقط JSON معتبر برگردان با ساختار: {"overall_score":0,"summary":"...","corrected_text":"...","issues":[{"category":"grammar|spelling|style|coherence|fact","original":"...","suggestion":"...","explanation":"...","severity":"low|medium|high"}],"strengths":["..."],"improvements":["..."]}',
      'امتیاز از ۰ تا ۱۰۰ باشد. واقعیت‌های بیرونی را بدون منبع قطعی فرض نکن و عدم قطعیت را توضیح بده.'
    ].join('\n');
    return runGenerator('review','#reviewTextBtn','#reviewStatus','#reviewResult',
      () => ({ model: $('#reviewModel').value, userText, maxTokens: 6000 }),
      'تو ویراستار حرفه‌ای و ارزیاب متن هستی. خطاها را دقیق و قابل اصلاح گزارش کن.');
  }

  async function runPlayground() {
    const btn = $('#runPlayground');
    setBusy(btn, true, 'در حال ارسال...');
    $('#playgroundStatus').textContent = 'در حال اجرا';
    $('#playgroundResult').classList.remove('placeholder-result');
    $('#playgroundResult').textContent = 'درخواست در حال پردازش است...';
    try {
      const model = $('#playgroundModel').value;
      const text = $('#playgroundInput').value.trim();
      if (!model || !text) throw new Error('مدل و ورودی را انتخاب و تکمیل کن.');
      const max = Math.min(32768, Math.max(16, Number($('#playgroundMax').value || 1024)));
      const apiType = $('#playgroundApi').value;
      const system = $('#playgroundSystem').value.trim();
      let operation = 'chat';
      let payload;
      const stream = $('#playgroundStream').checked;
      const webSearch = $('#playgroundWebSearch').checked;
      if (webSearch && apiType !== 'responses') throw new Error('جست‌وجوی وب طبق مستندات فقط از Responses API اجرا می‌شود.');
      if (stream && apiType === 'messages') throw new Error('در این پنل استریم برای Chat Completions و Responses فعال است.');
      let tools = null;
      let responseFormat = null;
      if ($('#playgroundTools').value.trim()) {
        try { tools = JSON.parse($('#playgroundTools').value); } catch { throw new Error('Function Tools JSON معتبر نیست.'); }
        if (!Array.isArray(tools) || tools.length > 128) throw new Error('tools باید آرایه‌ای با حداکثر ۱۲۸ تابع باشد.');
      }
      if ($('#playgroundStructured').value.trim()) {
        try { responseFormat = JSON.parse($('#playgroundStructured').value); } catch { throw new Error('Structured Output JSON معتبر نیست.'); }
      }
      if (apiType === 'responses') {
        operation = 'responses';
        payload = { model, instructions: system, input: text, max_output_tokens: max, store: false, stream };
        if (webSearch) {
          payload.tools = [{ type: 'web_search' }];
          payload.tool_choice = { type: 'web_search' };
          payload.max_tool_calls = 1;
        }
        if (tools) payload.tools = [...(payload.tools || []), ...tools];
      } else if (apiType === 'messages') {
        operation = 'messages';
        payload = { model, max_tokens: max, system, messages: [{ role: 'user', content: text }] };
        if (tools) payload.tools = tools;
      } else {
        payload = { model, messages: [...(system ? [{ role:'system', content:system }] : []), { role:'user', content:text }], max_tokens: max, temperature: Math.min(2, Math.max(0, Number($('#playgroundTemp').value || 0.7))), stream };
        if (tools) payload.tools = tools;
        if (responseFormat) payload.response_format = responseFormat;
      }
      const result = await invoke(operation, payload);
      const answer = extractText(result.data, apiType);
      displayResult('#playgroundResult', answer, '#playgroundMeta');
      $('#playgroundMeta').textContent = 'Request ID: ' + (result.request_id || '—') + ' · زمان: ' + number(result.latency_ms) + ' ms';
      $('#playgroundStatus').textContent = 'موفق';
      await loadBootstrap();
    } catch (error) {
      $('#playgroundResult').textContent = error.message;
      $('#playgroundStatus').textContent = 'خطا';
      toast(error.message, 'error');
    } finally {
      setBusy(btn, false);
    }
  }

  async function runImageGeneration() {
    const btn = $('#generateImage');
    setBusy(btn, true, 'در حال تولید تصویر...');
    $('#imageResult').textContent = '';
    try {
      const model = $('#imageModel').value;
      const prompt = $('#imagePrompt').value.trim();
      if (!model || !prompt) throw new Error('مدل و Prompt تصویر را تکمیل کن.');
      const result = await invoke('image_generate', {
        model, prompt, n: Math.min(4, Math.max(1, Number($('#imageCount').value || 1))),
        size: $('#imageSize').value, quality: $('#imageQuality').value, response_format: 'b64_json'
      });
      const data = result.data;
      const items = data.data || data.images || [];
      if (!Array.isArray(items) || !items.length) throw new Error('در پاسخ API تصویر قابل نمایش برنگشت. پاسخ خام: ' + JSON.stringify(data).slice(0, 800));
      items.forEach((item, index) => {
        const src = item.b64_json ? 'data:image/png;base64,' + item.b64_json : item.url;
        if (!src) return;
        const card = document.createElement('div'); card.className = 'media-card';
        const img = document.createElement('img'); img.src = src; img.alt = item.revised_prompt || 'Generated image ' + (index + 1);
        const caption = document.createElement('p'); caption.textContent = item.revised_prompt || 'تصویر شماره ' + (index + 1);
        card.append(img, caption); $('#imageResult').append(card);
      });
      toast('درخواست تولید تصویر تکمیل شد. شناسه: ' + (result.request_id || '—'));
      await loadBootstrap();
    } catch (error) { toast(error.message, 'error'); $('#imageResult').textContent = error.message; }
    finally { setBusy(btn, false); }
  }

  async function runImageEdit() {
    const btn = $('#editImage'); setBusy(btn, true, 'در حال ارسال...');
    try {
      const urls = $('#imageUrls').value.split(/\r?\n/).map(x => x.trim()).filter(Boolean);
      if (!urls.length || urls.length > 4 || urls.some(url => !/^https:\/\//i.test(url))) {
        throw new Error('۱ تا ۴ نشانی عمومی HTTPS برای تصویر وارد کن.');
      }
      const payload = { model: $('#imageEditModel').value, prompt: $('#imageEditPrompt').value.trim() };
      if (!payload.model || !payload.prompt) throw new Error('مدل و توضیح ویرایش الزامی است.');
      if (urls.length === 1) payload.image_url = urls[0]; else payload.image_urls = urls;
      if ($('#imageMaskUrl').value.trim()) payload.mask_url = $('#imageMaskUrl').value.trim();
      const result = await invoke('image_edit', payload);
      displayResult('#imageEditResult', result.data);
      toast('درخواست ویرایش تصویر ارسال شد.');
      await loadBootstrap();
    } catch (error) { toast(error.message, 'error'); }
    finally { setBusy(btn, false); }
  }

  async function runImageBatch() {
    const btn = $('#submitImageBatch'); setBusy(btn, true, 'در حال ساخت Batch...');
    try {
      const requests = JSON.parse($('#imageBatchItems').value);
      if (!Array.isArray(requests) || requests.length < 1 || requests.length > 10) throw new Error('Batch تصویر باید ۱ تا ۱۰ مورد داشته باشد.');
      const result = await invoke('image_batch_create', { model: $('#imageBatchModel').value, requests });
      $('#imageBatchResult').textContent = JSON.stringify(result.data, null, 2);
      const id = result.data.id || result.data.batch_id;
      if (id) $('#imageBatchId').value = id;
      toast('Batch تصویر ایجاد شد.');
    } catch (error) { toast(error.message, 'error'); }
    finally { setBusy(btn, false); }
  }

  async function imageBatchAction(action) {
    const id = $('#imageBatchId').value.trim();
    if (!id) { toast('شناسه Batch را وارد کن.', 'error'); return; }
    try {
      if (action === 'cancel') {
        const result = await api('ai.image_batch.cancel', 'POST', { id });
        $('#imageBatchResult').textContent = JSON.stringify(result.data, null, 2);
      } else {
        const result = await api('ai.lookup', 'POST', { type: 'image_batch', id });
        $('#imageBatchResult').textContent = JSON.stringify(result.data, null, 2);
      }
    } catch (error) { toast(error.message, 'error'); }
  }

  async function readFileBase64(file) {
    const buffer = await file.arrayBuffer();
    let binary = '';
    const bytes = new Uint8Array(buffer);
    const chunk = 0x8000;
    for (let i = 0; i < bytes.length; i += chunk) binary += String.fromCharCode(...bytes.subarray(i, i + chunk));
    return btoa(binary);
  }

  async function runTranscription() {
    const btn = $('#transcribeAudio'); setBusy(btn, true, 'در حال تبدیل صوت...');
    try {
      const file = $('#audioFile').files[0];
      if (!file) throw new Error('ابتدا فایل صوتی را انتخاب کن.');
      if (file.size > 8 * 1024 * 1024) throw new Error('برای این پنل حجم فایل صوتی حداکثر ۸ مگابایت است.');
      const base64 = await readFileBase64(file);
      const ext = (file.name.split('.').pop() || 'wav').toLowerCase();
      const result = await invoke('transcription', {
        model: $('#transcribeModel').value,
        input_audio: { data: base64, format: ext },
        language: $('#transcribeLanguage').value === 'auto' ? undefined : $('#transcribeLanguage').value
      });
      displayResult('#transcriptionResult', result.data);
      toast('تبدیل گفتار به متن انجام شد.');
      await loadBootstrap();
    } catch (error) { $('#transcriptionResult').textContent = error.message; toast(error.message, 'error'); }
    finally { setBusy(btn, false); }
  }

  async function runSpeech() {
    const btn = $('#generateSpeech'); setBusy(btn, true, 'در حال تولید صوت...');
    try {
      const text = $('#speechText').value.trim();
      if (!text || text.length > 4000) throw new Error('متن را وارد کن؛ حداکثر ۴۰۰۰ نویسه.');
      const result = await binaryApi('ai.invoke', {
        operation: 'speech',
        payload: { model: $('#speechModel').value, input: text, voice: $('#speechVoice').value.trim(), response_format: $('#speechFormat').value }
      });
      if (speechObjectUrl) URL.revokeObjectURL(speechObjectUrl);
      speechObjectUrl = URL.createObjectURL(result.blob);
      const player = $('#speechPlayer'); player.src = speechObjectUrl; player.hidden = false;
      toast('فایل صوتی تولید شد · Request ID: ' + (result.requestId || '—'));
      await loadBootstrap();
    } catch (error) { toast(error.message, 'error'); }
    finally { setBusy(btn, false); }
  }

  async function runEmbeddings() {
    const btn = $('#runEmbedding'); setBusy(btn, true, 'در حال ساخت بردار...');
    try {
      const text = $('#embeddingInput').value.trim();
      if (!text) throw new Error('متن ورودی را وارد کن.');
      const input = $('#embeddingInputType').value === 'list' ? text.split(/\r?\n/).map(x => x.trim()).filter(Boolean) : text;
      const result = await invoke('embeddings', { model: $('#embeddingModel').value, input });
      displayResult('#embeddingResult', result.data);
      toast('Embedding ساخته شد.');
      await loadBootstrap();
    } catch (error) { $('#embeddingResult').textContent = error.message; toast(error.message, 'error'); }
    finally { setBusy(btn, false); }
  }

  async function runKnowledgeSearch() {
    const btn = $('#searchKnowledge'); setBusy(btn, true, 'در حال جست‌وجو...');
    try {
      const result = await api('knowledge.search', 'POST', {
        knowledge_base_id: $('#knowledgeBaseId').value.trim(),
        query: $('#knowledgeQuery').value.trim(),
        top_k: Math.min(20, Math.max(1, Number($('#knowledgeTopK').value || 5))),
        retrieval_mode: $('#knowledgeMode').value
      });
      displayResult('#knowledgeResult', result.data);
      await loadBootstrap();
    } catch (error) { $('#knowledgeResult').textContent = error.message; toast(error.message, 'error'); }
    finally { setBusy(btn, false); }
  }

  async function runDecision() {
    const btn = $('#runDecision'); setBusy(btn, true, 'در حال تصمیم‌گیری...');
    try {
      let questions;
      try { questions = JSON.parse($('#decisionQuestions').value); }
      catch { throw new Error('ساختار Questions JSON معتبر نیست.'); }
      const result = await invoke('decision', {
        model: $('#decisionModel').value,
        state: $('#decisionState').value,
        questions
      });
      displayResult('#decisionResult', result.data);
      await loadBootstrap();
    } catch (error) { $('#decisionResult').textContent = error.message; toast(error.message, 'error'); }
    finally { setBusy(btn, false); }
  }

  async function createBatch() {
    const btn = $('#createBatch'); setBusy(btn, true, 'در حال ارسال Batch...');
    try {
      const requests = JSON.parse($('#batchRequests').value);
      if (!Array.isArray(requests) || requests.length < 1 || requests.length > 20) throw new Error('Batch متنی باید ۱ تا ۲۰ درخواست داشته باشد.');
      const result = await invoke('batch_create', {
        model: $('#batchModel').value,
        endpoint: $('#batchEndpoint').value,
        requests
      });
      $('#batchResult').textContent = JSON.stringify(result.data, null, 2);
      const id = result.data.id || result.data.batch_id;
      if (id) $('#batchId').value = id;
      await loadBootstrap();
    } catch (error) { $('#batchResult').textContent = error.message; toast(error.message, 'error'); }
    finally { setBusy(btn, false); }
  }

  async function lookupBatch() {
    const id = $('#batchId').value.trim();
    if (!id) { toast('شناسه Batch را وارد کن.', 'error'); return; }
    try {
      const result = await api('ai.lookup', 'POST', { type: 'batch', id });
      $('#batchResult').textContent = JSON.stringify(result.data, null, 2);
    } catch (error) { $('#batchResult').textContent = error.message; toast(error.message, 'error'); }
  }

  async function loadUsage() {
    try {
      const result = await api('usage');
      const s = result.summary || {};
      $('#usageCost').textContent = money(s.cost);
      $('#usageTokens').textContent = number(s.tokens);
      $('#usageSuccess').textContent = number(s.successful);
      $('#usageFailed').textContent = number(s.failed);
      state.usageRows = result.rows || [];
      renderUsage();
    } catch (error) { toast(error.message, 'error'); }
  }

  function renderUsage() {
    const rows = state.usageRows;
    $('#usageTable').innerHTML = rows.length ? rows.map(row =>
      '<tr><td>' + safe(shortDate(row.created_at)) + '</td><td>' + safe(row.operation) + '<br><small>' + safe(row.endpoint) + '</small></td>' +
      '<td><span class="model-id">' + safe(row.model || '—') + '</span></td><td><span class="model-id">' + safe(row.request_id || '—') +
      '</span></td><td>' + number(row.total_tokens) + '</td><td>' + (row.charged_toman === null ? '—' : money(row.charged_toman) + ' تومان') +
      '</td><td>' + safe(row.http_status) + '</td><td>' + number(row.latency_ms) + ' ms</td></tr>'
    ).join('') : '<tr><td colspan="8" class="empty-state">هنوز گزارشی ثبت نشده است.</td></tr>';
  }

  function exportUsageCsv() {
    const rows = state.usageRows;
    const headers = ['created_at','operation','endpoint','model','request_id','http_status','is_success','prompt_tokens','completion_tokens','total_tokens','charged_toman','latency_ms'];
    const quote = value => '"' + String(value ?? '').replace(/"/g, '""') + '"';
    const csv = [headers.join(','), ...rows.map(row => headers.map(key => quote(row[key])).join(','))].join('\r\n');
    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = 'parse-ai-usage.csv'; a.click();
    URL.revokeObjectURL(url);
  }

  function exportJson(id) {
    const keys = { questionResult: 'questions', exerciseResult: 'exercises', reviewResult: 'review' };
    const data = state.lastOutputs[keys[id] || id.replace('Result','')] || null;
    if (!data) { toast('خروجی‌ای برای دانلود وجود ندارد.', 'error'); return; }
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = id.replace('Result','') + '.json'; a.click();
    URL.revokeObjectURL(url);
  }

  function setSubtab(buttonSelector, panelSelector, dataAttr, panelPrefix) {
    $$(buttonSelector).forEach(button => button.addEventListener('click', () => {
      $$(buttonSelector).forEach(x => x.classList.toggle('selected', x === button));
      const selected = button.dataset[dataAttr];
      $(panelSelector).forEach(panel => { panel.hidden = panel.id !== panelPrefix + selected + 'Panel'; });
    }));
  }

  function initEvents() {
    $$('.nav-item').forEach(button => button.addEventListener('click', () => showPage(button.dataset.page)));
    $$('[data-go]').forEach(button => button.addEventListener('click', () => showPage(button.dataset.go)));
    $('#mobileMenu')?.addEventListener('click', () => { $('#sidebar').classList.add('open'); $('#mobileScrim').classList.add('show'); });
    $('#sidebarClose')?.addEventListener('click', () => { $('#sidebar').classList.remove('open'); $('#mobileScrim').classList.remove('show'); });
    $('#mobileScrim')?.addEventListener('click', () => { $('#sidebar').classList.remove('open'); $('#mobileScrim').classList.remove('show'); });

    $('#providerForm').addEventListener('submit', saveProvider);
    $('#testProvider').addEventListener('click', testProvider);
    $('#loadCatalogs').addEventListener('click', loadCatalogs);
    $('#reloadModels').addEventListener('click', () => loadCatalog(state.modelKind).catch(error => toast(error.message, 'error')));
    $('#modelKinds').addEventListener('click', event => {
      const button = event.target.closest('[data-kind]');
      if (!button) return;
      $$('#modelKinds button').forEach(node => node.classList.toggle('selected', node === button));
      state.modelKind = button.dataset.kind;
      loadCatalog(state.modelKind).catch(error => toast(error.message, 'error'));
    });
    $('#modelSearch').addEventListener('input', () => renderModels(state.modelKind));
    $('#runPlayground').addEventListener('click', runPlayground);
    $('#clearPlayground').addEventListener('click', () => {
      $('#playgroundInput').value = ''; $('#playgroundSystem').value = '';
      $('#playgroundResult').textContent = 'نتیجه اجرای درخواست اینجا نمایش داده می‌شود.';
      $('#playgroundResult').classList.add('placeholder-result');
      $('#playgroundMeta').textContent = '';
    });
    $('#playgroundApi').addEventListener('change', event => {
      const showSystem = event.target.value !== 'messages';
      $('#systemPromptField').hidden = !showSystem;
    });
    $('#generateQuestions').addEventListener('click', runQuestionGenerator);
    $('#generateExercises').addEventListener('click', runExerciseGenerator);
    $('#reviewTextBtn').addEventListener('click', runTextReview);
    $('#generateImage').addEventListener('click', runImageGeneration);
    $('#editImage').addEventListener('click', runImageEdit);
    $('#submitImageBatch').addEventListener('click', runImageBatch);
    $('#getImageBatch').addEventListener('click', () => imageBatchAction('get'));
    $('#cancelImageBatch').addEventListener('click', () => imageBatchAction('cancel'));
    $('#transcribeAudio').addEventListener('click', runTranscription);
    $('#generateSpeech').addEventListener('click', runSpeech);
    $('#runEmbedding').addEventListener('click', runEmbeddings);
    $('#searchKnowledge').addEventListener('click', runKnowledgeSearch);
    $('#runDecision').addEventListener('click', runDecision);
    $('#createBatch').addEventListener('click', createBatch);
    $('#getBatch').addEventListener('click', lookupBatch);
    $('#refreshUsage').addEventListener('click', loadUsage);
    $('#exportUsage').addEventListener('click', exportUsageCsv);
    $('#refreshAll').addEventListener('click', async () => {
      try { await loadBootstrap(); toast('اطلاعات به‌روزرسانی شد.'); } catch (error) { toast(error.message, 'error'); }
    });
    $$('[data-export]').forEach(button => button.addEventListener('click', () => exportJson(button.dataset.export)));
    setSubtab('[data-image-tab]','.image-tool','imageTab','image');
    setSubtab('[data-audio-tab]','.audio-tool','audioTab','');
    // Password visibility toggle on the PHP login page.
    $$('[data-toggle-password]').forEach(button => button.addEventListener('click', () => {
      const input = $('#' + button.dataset.togglePassword);
      input.type = input.type === 'password' ? 'text' : 'password';
    }));
  }

  async function init() {
    if (!$('#adminApp')) return;
    initEvents();
    await loadBootstrap();
    if (cfg.aiEnabled) {
      loadCatalog('chat').catch(() => {});
    }
  }

  init().catch(error => toast(error.message, 'error'));
})();

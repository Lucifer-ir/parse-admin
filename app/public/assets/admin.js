'use strict';

(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const csrf = $('meta[name="csrf-token"]')?.content || '';
  // Independent splash timeout; deliberately does not depend on init or API.
  const splashAtBoot = document.getElementById('parseStartup');
  if (splashAtBoot) {
    setTimeout(() => {
      splashAtBoot.classList.add('done');
      splashAtBoot.style.opacity = '0';
      splashAtBoot.style.visibility = 'hidden';
      splashAtBoot.style.pointerEvents = 'none';
      splashAtBoot.remove();
    }, 5000);
  }

  const isDeveloperAdmin = ['developer_admin','admin'].includes($('meta[name="admin-role"]')?.content || '');
  const state = { catalogs: {}, lastQuestionOutput: '', bootstrap: null, currentPage: 'dashboard', users: [], questions: [], prompts: [], students: [], resources: [], exams: [], attempts: [], tracks: [], activities: [], activitySubmissions: [], platformSettings: {} };
  const number = value => Number(value || 0).toLocaleString('fa-IR');
  const money = value => Number(value || 0).toLocaleString('fa-IR', { maximumFractionDigits: 2 });
  const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let toastTimer;

  function toast(message, type = 'info') {
    const node = $('#toast');
    if (!node) return;
    node.textContent = message;
    node.dataset.type = type;
    node.classList.add('visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => node.classList.remove('visible'), 4200);
  }

  function setMessage(selector, message, type = 'info') {
    const node = $(selector);
    if (!node) return;
    node.textContent = message || '';
    node.dataset.type = type;
  }

  async function api(path, options = {}) {
    const headers = new Headers(options.headers || {});
    const isForm = options.body instanceof FormData;
    if (!isForm && options.body && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');
    if (options.method && !['GET','HEAD'].includes(options.method.toUpperCase())) headers.set('X-CSRF-Token', csrf);
    let response;
    try {
      response = await fetch('/api' + path, {
        ...options,
        headers,
        credentials: 'same-origin',
        cache: 'no-store'
      });
    } catch {
      throw new Error('ارتباط با سرور پنل برقرار نشد. اتصال اینترنت و وضعیت PHP-FPM را بررسی کنید.');
    }
    const contentType = response.headers.get('content-type') || '';
    let payload;
    if (contentType.includes('application/json')) {
      payload = await response.json().catch(() => ({}));
    } else {
      const raw = await response.text();
      if (!response.ok) throw new Error('خطای سرور: HTTP ' + response.status);
      throw new Error(raw || 'پاسخ سرور قابل پردازش نیست.');
    }
    if (!response.ok) {
      if (response.status === 401) {
        toast('نشست شما پایان یافته است. دوباره وارد شوید.', 'error');
        setTimeout(() => location.reload(), 800);
      }
      throw new Error(payload.error || payload.message || 'خطای HTTP ' + response.status);
    }
    return payload;
  }

  function json(data) { return JSON.stringify(data); }

  function showPage(page, label, updateUrl = true) { if(updateUrl){const url=new URL(location.href);url.searchParams.set('page',page);history.pushState({page},'',url);}
    const titles = {
      dashboard:'داشبورد', models:'کاتالوگ مدل‌ها', questions:'تولید سؤال', practice:'طراحی تمرین',
      review:'بررسی و ویرایش متن', playground:'AI Playground', multimodal:'تصویر و صوت',
      structured:'تصمیم و Embedding', batches:'Batch و رسید درخواست',
      usage:'مصرف و هزینه‌ها', history:'تاریخچه تولید', settings:'تنظیمات اتصال',
      'question-bank':'بانک هوشمند سؤال', users:'مدیریت مدیران', prompts:'قالب‌های دستور AI', students:'دانش‌آموزان', resources:'کتاب‌ها و جزوه‌ها', exams:'آزمون‌ها و زمان‌بندی', attempts:'پاسخ‌نامه‌ها و نمرات', monitoring:'مانیتورینگ اپ', tracks:'زبان و مسیر آموزشی', activities:'تمرین‌ها و تکالیف', 'activity-submissions':'پاسخ تمرین‌ها'
    };
    state.currentPage = page;
    $$('.page-view').forEach(node => node.classList.toggle('active', node.id === 'page-' + page));
    $$('.nav-item').forEach(node => node.classList.toggle('active', node.dataset.page === page));
    $('#breadcrumbTitle').textContent = label || titles[page] || page;
    $('#sidebar').classList.remove('open');
    if (page === 'dashboard') loadDashboard();
    if (page === 'models') loadCatalog('models');
    if (page === 'usage') loadUsage();
    if (page === 'history') loadHistory();
    if (page === 'settings') { loadSettings(); loadPlatformSettings(); }
    if (page === 'users') loadUsers();
    if (page === 'prompts') loadPrompts();
    if (page === 'students') { loadStudents(); loadTracks(); }
    if (page === 'resources') { loadResources(); loadTracks(); }
    if (page === 'exams') { loadExams(); loadStudents(); loadTracks(); }
    if (page === 'attempts') loadAttempts();
    if (page === 'monitoring') loadMonitoring();
    if (page === 'tracks') loadTracks();
    if (page === 'activities') { loadActivities(); loadTracks(); loadStudents(); loadPlatformQuestionBank(); }
    if (page === 'activity-submissions') loadActivitySubmissions();
    if (page === 'question-bank') { loadPlatformQuestionBank(); loadResources(); loadStudents(); loadTracks(); }
    if (isDeveloperAdmin && (page === 'questions' || page === 'practice' || page === 'review' || page === 'playground')) {
      if (!state.catalogs.models) loadCatalog('models');
    }
  }

  function setBusy(form, busy, busyText) {
    const button = $('button[type="submit"]', form);
    if (!button) return;
    if (busy) {
      button.dataset.originalText = button.textContent;
      button.disabled = true;
      button.textContent = busyText || 'در حال پردازش...';
    } else {
      button.disabled = false;
      button.textContent = button.dataset.originalText || 'اجرا';
    }
  }

  function payloadFromForm(form) {
    const data = new FormData(form);
    const result = {};
    for (const [key, value] of data.entries()) {
      if (value instanceof File) continue;
      if (key in result) {
        if (!Array.isArray(result[key])) result[key] = [result[key]];
        result[key].push(value);
      } else {
        result[key] = value;
      }
    }
    $$('input[type="checkbox"]', form).forEach(input => {
      if (input.name) result[input.name] = input.checked;
    });
    return result;
  }

  function formatObject(value) {
    return JSON.stringify(value, null, 2);
  }

  function responseModels(payload) {
    if (Array.isArray(payload)) return payload;
    for (const candidate of [payload?.data, payload?.models, payload?.items, payload?.results, payload?.voices]) {
      if (Array.isArray(candidate)) return candidate;
    }
    return [];
  }

  function modelId(model) {
    return String(model.id || model.model || model.slug || model.name || model.voice || '');
  }

  function modelLabel(model) {
    const id = modelId(model);
    const title = model.name || model.display_name || model.title;
    return title && title !== id ? title + ' · ' + id : id || 'مدل بدون شناسه';
  }

  function modelOptionHtml(models, includeEmpty = true) {
    const options = models.map(model => {
      const id = modelId(model);
      return '<option value="' + escapeHtml(id) + '">' + escapeHtml(modelLabel(model)) + '</option>';
    }).join('');
    return (includeEmpty ? '<option value="">انتخاب مدل</option>' : '') + options;
  }

  function fillModelSelects(kind, models) {
    $$('.model-select[data-kind="' + kind + '"]').forEach(select => {
      const current = select.value;
      select.innerHTML = modelOptionHtml(models);
      if (current && models.some(model => modelId(model) === current)) select.value = current;
      else if (state.bootstrap?.default_model && models.some(model => modelId(model) === state.bootstrap.default_model)) {
        select.value = state.bootstrap.default_model;
      } else if (models.length === 1) {
        select.value = modelId(models[0]);
      }
    });
  }

  function displayModelCard(model, kind) {
    const id = modelId(model);
    const price = model.pricing || model.prices || model.pricing_components || {};
    const priceText = typeof model.price === 'string' || typeof model.price === 'number'
      ? String(model.price)
      : Object.keys(price).slice(0, 2).map(key => key.replaceAll('_',' ') + ': ' + price[key]).join(' · ');
    const capabilities = [];
    if (model.context_length || model.context_window) capabilities.push('Context ' + number(model.context_length || model.context_window));
    if (model.max_output_tokens || model.max_tokens) capabilities.push('Output ' + number(model.max_output_tokens || model.max_tokens));
    if (model.supports_edit) capabilities.push('ویرایش تصویر');
    if (model.supports_vision) capabilities.push('Vision');
    if (model.supports_tools) capabilities.push('Tools');
    if (model.hosted_in_iran || model.region === 'iran') capabilities.push('میزبانی ایران');
    const status = model.available === false || model.enabled === false ? 'غیرفعال' : 'در دسترس';
    return '<article class="model-card"><div class="model-card-top"><span class="model-kind">' + escapeHtml(kind) + '</span><span class="availability ' + (status === 'در دسترس' ? 'ok' : 'off') + '">' + status + '</span></div><h3>' + escapeHtml(model.name || model.display_name || id) + '</h3><code>' + escapeHtml(id) + '</code><p>' + escapeHtml(model.description || model.provider || 'مدل قابل انتخاب از کاتالوگ درگاه پاستا') + '</p><div class="capability-row">' + (capabilities.length ? capabilities.map(x => '<span>' + escapeHtml(x) + '</span>').join('') : '<span>قابلیت‌ها تابع کاتالوگ مدل</span>') + '</div><div class="model-price">' + escapeHtml(priceText || 'تعرفه از کاتالوگ سرویس') + '</div><button class="text-action" data-select-model="' + escapeHtml(id) + '" data-select-kind="' + escapeHtml(kind) + '">انتخاب مدل برای ابزارها ←</button></article>';
  }

  async function loadCatalog(kind = 'models', force = false) {
    if (state.catalogs[kind] && !force) {
      renderCatalog(kind);
      return state.catalogs[kind];
    }
    const routes = {models:'models',decisions:'decisions',images:'images',audio:'audio',speech:'speech',embeddings:'embeddings'};
    try {
      const payload = await api('/catalog/' + routes[kind]);
      const models = responseModels(payload);
      state.catalogs[kind] = models;
      fillModelSelects(kind, models);
      if (kind === 'models') {
        $$('.model-select[data-kind="models"]').forEach(select => {
          if (!select.value && state.bootstrap?.default_model) select.value = state.bootstrap.default_model;
        });
        fillModelSelects('models', models);
        const settingsSelect = $('#settingsForm select[name="default_model"]');
        if (settingsSelect && state.bootstrap?.default_model) settingsSelect.value = state.bootstrap.default_model;
      }
      if (state.currentPage === 'models') renderCatalog($('#page-models .catalog-tab.active')?.dataset.catalog || kind);
      return models;
    } catch (error) {
      if (state.currentPage === 'models') $('#modelGrid').innerHTML = '<div class="empty-state error-state">' + escapeHtml(error.message) + '</div>';
      else toast('دریافت کاتالوگ ' + kind + ' ناموفق بود: ' + error.message, 'error');
      throw error;
    }
  }

  function renderCatalog(kind) {
    const models = state.catalogs[kind] || [];
    const term = ($('#modelSearch')?.value || '').trim().toLowerCase();
    const filtered = models.filter(model => (modelId(model) + ' ' + (model.name || '') + ' ' + (model.description || '')).toLowerCase().includes(term));
    $('#modelGrid').innerHTML = filtered.length
      ? filtered.map(model => displayModelCard(model, kind)).join('')
      : '<div class="empty-state">مدلی مطابق جست‌وجو پیدا نشد یا کاتالوگ خالی است.</div>';
    $('#modelCountLabel').textContent = number(filtered.length) + ' مدل';
  }

  async function loadDashboard() {
    if (!isDeveloperAdmin) {
      try { const data=await api('/platform/overview');
        $('#educationStudents').textContent=number(data.students); $('#educationActiveStudents').textContent=number(data.activeStudents);
        $('#educationResources').textContent=number(data.resources); $('#educationQuestions').textContent=number(data.questions);
        $('#educationExams').textContent=number(data.exams); $('#educationSubmitted').textContent=number(data.submitted);
      } catch(error) { toast('خطا در دریافت داشبورد آموزشی: '+error.message,'error'); }
      return;
    }
    try {
      const [data,boot]=await Promise.all([api('/dashboard'),api('/bootstrap')]); state.bootstrap=boot;
      $('#todayCost').textContent=money(data.today?.cost); $('#todayRequests').textContent=number(data.today?.requests)+' درخواست';
      $('#monthCost').textContent=money(data.month?.cost); $('#monthRequests').textContent=number(data.month?.requests)+' درخواست';
      $('#monthTokens').textContent=number(data.month?.tokens); $('#allRequests').textContent=number(data.totals?.requests)+' درخواست کل';
      $('#modelCount').textContent=number((data.models||[]).length); $('#userCount').textContent=number(data.users)+' کاربر';
      $('#providerBadge').textContent=boot.provider_configured?'کلید ثبت شده':'نیازمند تنظیم'; $('#providerBadge').classList.toggle('success',!!boot.provider_configured);
      $('#providerStateText').textContent=boot.provider_configured?'کلید API رمزگذاری‌شده در سرور ثبت شده است.':'برای فعال‌سازی قابلیت‌های هوش مصنوعی، کلید را ثبت کنید.';
      $('#providerMask').textContent=boot.provider_configured?'••••••••••••••••':'پیکربندی نشده';
      const models=data.models||[];
      $('#modelUsage').innerHTML=models.length?models.map(model=>{const total=Math.max(1,...models.map(x=>Number(x.requests||0)));const percent=Math.max(3,Math.round(Number(model.requests||0)/total*100));return '<div class="usage-model-row"><div><b>'+escapeHtml(model.model||'مدل نامشخص')+'</b><span>'+number(model.requests)+' درخواست · '+money(model.cost)+' تومان</span></div><div class="usage-bar"><i class="usage-bar-fill usage-width-'+percent+'"></i></div></div>';}).join(''):'<div class="empty-state compact-empty">هنوز درخواست ثبت نشده است.</div>';
      $('#recentUsage').innerHTML=(data.recent||[]).length?data.recent.map(row=>'<tr><td>'+escapeHtml(row.operation)+'</td><td dir="ltr">'+escapeHtml(row.model||'—')+'</td><td><span class="table-status '+(Number(row.status_code)<400?'ok':'bad')+'">'+number(row.status_code)+'</span></td><td>'+number(row.total_tokens)+'</td><td>'+money(row.charged_toman)+'</td><td>'+escapeHtml(new Date(row.created_at).toLocaleString('fa-IR'))+'</td></tr>').join(''):'<tr><td colspan="6" class="empty-cell">هیچ درخواست هوش مصنوعی از این پنل ثبت نشده است.</td></tr>';
    } catch(error) { toast('خطا در دریافت داشبورد: '+error.message,'error'); }
  }
  function displayText(selector, text) {
    const node = $(selector);
    node.textContent = text || 'پاسخی از مدل دریافت نشد.';
  }

  function renderUsageDetails(selector, result) {
    if (!isDeveloperAdmin) { const node = $(selector); if (node) node.textContent = ''; return; }
    const charged = result.paasta?.charged_toman;
    const usage = result.usage || {};
    const line = [
      'شناسه درخواست: ' + (result.request_id || '—'),
      'توکن ورودی: ' + number(usage.input_tokens ?? usage.prompt_tokens),
      'توکن خروجی: ' + number(usage.output_tokens ?? usage.completion_tokens),
      'هزینه: ' + (charged !== undefined ? money(charged) + ' تومان' : 'در انتظار رسید مصرف')
    ].join('  |  ');
    $(selector).textContent = line;
  }

  async function handleGenerate(form, type, outputSelector, messageSelector, usageSelector, title) {
    const payload = payloadFromForm(form);
    if (type === 'review') payload.text = String(payload.text || '');
    setBusy(form, true, 'در حال تولید با هوش مصنوعی...');
    setMessage(messageSelector, 'درخواست از طریق بک‌اند PHP به درگاه پاستا ارسال شد...');
    try {
      const result = await api('/generate', {method:'POST',body:json({...payload,type})});
      state.lastQuestionOutput = type === 'question' ? result.output : state.lastQuestionOutput;
      displayText(outputSelector, result.output);
      renderUsageDetails(usageSelector, result);
      setMessage(messageSelector, 'تولید با موفقیت انجام شد.', 'success');
      toast('خروجی با موفقیت تولید شد.', 'success');
    } catch (error) {
      setMessage(messageSelector, error.message, 'error');
      toast(error.message, 'error');
    } finally {
      setBusy(form, false);
    }
  }

  function attachGenerate(formId, type, output, message, usage) {
    const form = $('#' + formId);
    form.addEventListener('submit', event => {
      event.preventDefault();
      handleGenerate(form,type,output,message,usage,type);
    });
  }

  function initForms() {
    attachGenerate('questionForm','question','#questionOutput','#questionMessage','#questionUsage','تولید سؤال');
    attachGenerate('practiceForm','practice','#practiceOutput','#practiceMessage','#practiceUsage','طراحی تمرین');
    attachGenerate('reviewForm','review','#reviewOutput','#reviewMessage','#reviewUsage','بررسی متن');

    $('#saveQuestions').addEventListener('click', async () => {
      const text = $('#questionOutput').textContent.trim();
      if (!text || text.includes('هنوز سؤالی تولید نشده')) return toast('ابتدا سؤال تولید کنید.', 'error');
      const form = $('#questionForm');
      const meta = payloadFromForm(form);
      const rows = text.split(/\n+/).map(line => line.replace(/^\s*(?:\d+[.、)\-]|[-•*])\s*/, '').trim()).filter(line => line.length > 12);
      const questions = rows.length > 1 ? rows : [text];
      try {
        const result = await api('/questions/save',{method:'POST',body:json({questions,subject:meta.topic,grade:meta.grade})});
        toast(number(result.saved) + ' مورد به بانک سؤال اضافه شد.', 'success');
      } catch(error) { toast(error.message,'error'); }
    });

    if (!isDeveloperAdmin) return;

    function parseAdvancedOptions(data) {
      const parseJsonField = (field, target) => {
        const raw = String(data[field] || '').trim();
        delete data[field];
        if (raw) data[target] = JSON.parse(raw);
      };
      parseJsonField('tools_json','tools');
      parseJsonField('response_format_json','response_format');
      parseJsonField('messages_json','messages');
      parseJsonField('responses_input_json','responses_input');
      parseJsonField('advanced_options_json','advanced');
      if (data.tool_choice) {
        const choice = String(data.tool_choice).trim();
        try { data.tool_choice = JSON.parse(choice); } catch { data.tool_choice = choice; }
      } else {
        delete data.tool_choice;
      }
      delete data.web_search;
      if (data.advanced && typeof data.advanced === 'object') {
        for (const [key,value] of Object.entries(data.advanced)) {
          if (!Object.prototype.hasOwnProperty.call(data,key)) data[key]=value;
        }
        delete data.advanced;
      }
      return data;
    }

    $('#chatForm').addEventListener('submit', async event => {
      event.preventDefault();
      const form=$('#chatForm');
      let data;
      try { data=parseAdvancedOptions(payloadFromForm(form)); }
      catch { return setMessage('#chatMessage','ساختار JSON در تنظیمات پیشرفته معتبر نیست.','error'); }
      setBusy(form,true,'در حال دریافت پاسخ...');
      setMessage('#chatMessage','درخواست از بک‌اند PHP به درگاه هوش مصنوعی ارسال شد...');
      $('#chatState').textContent='در حال اجرا';
      try {
        const result=await api('/ai/chat',{method:'POST',body:json(data)});
        displayText('#chatOutput',result.output || formatObject(result.data));
        renderUsageDetails('#chatUsage',result);
        $('#chatState').textContent='تکمیل شد';
        setMessage('#chatMessage','پاسخ دریافت شد.','success');
      } catch(error) { $('#chatState').textContent='خطا';setMessage('#chatMessage',error.message,'error'); }
      finally { setBusy(form,false); }
    });

    $('#runResponses').addEventListener('click',async()=>{
      let data;
      try { data=parseAdvancedOptions(payloadFromForm($('#chatForm'))); }
      catch { return setMessage('#chatMessage','ساختار JSON در تنظیمات پیشرفته معتبر نیست.','error'); }
      const responseInput=data.responses_input || data.message;
      const webSearch=!!payloadFromForm($('#chatForm')).web_search;
      if(!data.model||(!responseInput)) return toast('مدل و ورودی Responses را وارد کنید.','error');
      if(webSearch && typeof responseInput!=='string') return setMessage('#chatMessage','برای Web Search ورودی باید متن ساده باشد.','error');
      $('#chatState').textContent='Responses API';
      setMessage('#chatMessage','در حال ارسال به Responses API...');
      try {
        const result=await api('/ai/responses',{method:'POST',body:json({
          model:data.model,input:responseInput,instructions:data.system,
          max_output_tokens:Number(data.max_tokens||1024),
          save_history:data.save_history,web_search:webSearch,
          tools:data.tools,tool_choice:data.tool_choice,response_format:data.response_format,
          pdf_processing:data.pdf_processing,structured_outputs:data.structured_outputs,
          max_tool_calls:data.max_tool_calls
        })});
        displayText('#chatOutput',result.output||formatObject(result.data));
        $('#chatUsage').textContent='شناسه درخواست: '+(result.request_id||'—');
        setMessage('#chatMessage','درخواست Responses با موفقیت انجام شد.','success');
      } catch(error) { setMessage('#chatMessage',error.message,'error'); }
    });

    $('#runMessages').addEventListener('click',async()=>{
      let data;
      try { data=parseAdvancedOptions(payloadFromForm($('#chatForm'))); }
      catch { return setMessage('#chatMessage','ساختار JSON در تنظیمات پیشرفته معتبر نیست.','error'); }
      if(!data.model||(!data.message&&!data.messages)) return toast('مدل و پیام یا ساختار Messages را وارد کنید.','error');
      $('#chatState').textContent='Anthropic Messages';
      setMessage('#chatMessage','در حال ارسال به مسیر Anthropic Messages...');
      try {
        const payload = {
          model: data.model,
          message: data.message,
          messages: data.messages,
          system: data.system,
          max_tokens: Number(data.max_tokens || 1024)
        };
        [
          'pdf_processing','tools','tool_choice','thinking','stop_sequences',
          'temperature','top_p','top_k','metadata'
        ].forEach(key => {
          if (Object.prototype.hasOwnProperty.call(data, key)) payload[key] = data[key];
        });
        const result=await api('/ai/messages',{method:'POST',body:json(payload)});
        displayText('#chatOutput',result.output||formatObject(result.data));
        $('#chatUsage').textContent='شناسه درخواست: '+(result.request_id||'—');
        setMessage('#chatMessage','درخواست Messages با موفقیت انجام شد.','success');
      } catch(error) { setMessage('#chatMessage',error.message,'error'); }
    });

    $('#runStream').addEventListener('click',async()=>{
      const form=$('#chatForm');
      let data;
      try { data=parseAdvancedOptions(payloadFromForm(form)); }
      catch { return setMessage('#chatMessage','ساختار JSON در تنظیمات پیشرفته معتبر نیست.','error'); }
      if(!data.model||!data.message) return toast('مدل و پیام را وارد کنید.','error');
      $('#chatState').textContent='جریان زنده';
      $('#chatOutput').textContent='';
      setMessage('#chatMessage','در حال دریافت خروجی جریانی SSE...');
      const controller=new AbortController();
      try {
        const response=await fetch('/api/ai/chat/stream',{
          method:'POST',
          credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
          body:json(data),
          signal:controller.signal
        });
        if(!response.ok || !response.body) {
          const err=await response.json().catch(()=>({}));
          throw new Error(err.error||'خطای HTTP '+response.status);
        }
        const reader=response.body.getReader();
        const decoder=new TextDecoder();
        let buffer='';
        let finalError='';
        while(true) {
          const {value,done}=await reader.read();
          if(done) break;
          buffer+=decoder.decode(value,{stream:true});
          const lines=buffer.split(/\r?\n/);
          buffer=lines.pop()||'';
          for(const line of lines) {
            if(line.startsWith('event: error')) continue;
            if(!line.startsWith('data:')) continue;
            const raw=line.slice(5).trim();
            if(!raw||raw==='[DONE]') continue;
            try {
              const chunk=JSON.parse(raw);
              if(chunk.error) { finalError=chunk.error; continue; }
              const delta=chunk.choices?.[0]?.delta?.content;
              if(typeof delta==='string') {
                $('#chatOutput').textContent += delta;
              }
            } catch { /* Ignore incomplete or non-JSON SSE lines. */ }
          }
        }
        if(finalError) throw new Error(finalError);
        $('#chatState').textContent='تکمیل شد';
        setMessage('#chatMessage','جریان پاسخ به پایان رسید. جزئیات مصرف در گزارش ثبت شد.','success');
      } catch(error) {
        $('#chatState').textContent='خطا';
        setMessage('#chatMessage',error.message,'error');
      }
    });

    $('#runResponsesStream').addEventListener('click',async()=>{
      let data;
      try { data=parseAdvancedOptions(payloadFromForm($('#chatForm'))); }
      catch { return setMessage('#chatMessage','ساختار JSON در تنظیمات پیشرفته معتبر نیست.','error'); }
      if(!data.model||(!data.message&&!data.responses_input)) return toast('مدل و ورودی را وارد کنید.','error');
      if(!!payloadFromForm($('#chatForm')).web_search) return setMessage('#chatMessage','طبق قرارداد API، Web Search فقط در درخواست غیرجریانی Responses اجرا می‌شود.','error');
      const payload={
        model:data.model,
        input:data.responses_input||data.message,
        instructions:data.system,
        max_output_tokens:Number(data.max_tokens||1024),
        save_history:data.save_history,
        tools:data.tools,
        tool_choice:data.tool_choice,
        response_format:data.response_format,
        pdf_processing:data.pdf_processing,
        structured_outputs:data.structured_outputs,
        max_tool_calls:data.max_tool_calls
      };
      if(payload.tools===undefined) delete payload.tools;
      if(payload.tool_choice===undefined) delete payload.tool_choice;
      if(payload.response_format===undefined) delete payload.response_format;
      if(payload.pdf_processing===undefined) delete payload.pdf_processing;
      if(payload.structured_outputs===undefined) delete payload.structured_outputs;
      if(payload.max_tool_calls===undefined) delete payload.max_tool_calls;
      $('#chatState').textContent='Responses Stream';
      $('#chatOutput').textContent='';
      setMessage('#chatMessage','در حال دریافت خروجی جریانی Responses...');
      try {
        const response=await fetch('/api/ai/responses/stream',{
          method:'POST',
          credentials:'same-origin',
          headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
          body:json(payload)
        });
        if(!response.ok||!response.body) {
          const err=await response.json().catch(()=>({}));
          throw new Error(err.error||'خطای HTTP '+response.status);
        }
        const reader=response.body.getReader();
        const decoder=new TextDecoder();
        let buffer='',eventName='',finalError='';
        while(true) {
          const {value,done}=await reader.read();
          if(done)break;
          buffer+=decoder.decode(value,{stream:true});
          const lines=buffer.split(/\r?\n/);
          buffer=lines.pop()||'';
          for(const line of lines) {
            if(line.startsWith('event:')) { eventName=line.slice(6).trim(); continue; }
            if(!line.startsWith('data:')) continue;
            const raw=line.slice(5).trim();
            if(!raw||raw==='[DONE]')continue;
            try {
              const event=JSON.parse(raw);
              if(event.error||eventName==='error') { finalError=event.error||'خطای جریانی Provider'; continue; }
              const type=String(event.type||eventName||'');
              const delta=event.delta??event.output_text_delta??null;
              if(typeof delta==='string'&&type.includes('output_text')) $('#chatOutput').textContent+=delta;
            } catch { /* Ignore incomplete SSE JSON frames. */ }
            eventName='';
          }
        }
        if(finalError)throw new Error(finalError);
        $('#chatState').textContent='تکمیل شد';
        setMessage('#chatMessage','جریان Responses پایان یافت؛ مصرف و هزینه ثبت شد.','success');
      } catch(error) {
        $('#chatState').textContent='خطا';
        setMessage('#chatMessage',error.message,'error');
      }
    });

    $('#imageForm').addEventListener('submit',async event=>{
      event.preventDefault();const form=$('#imageForm');const data=payloadFromForm(form);setBusy(form,true,'در حال ساخت تصویر...');
      setMessage('#imageMessage','تصویر در حال تولید است؛ ممکن است زمان‌بر باشد.');
      try {
        const result=await api('/ai/images',{method:'POST',body:json(data)});
        const image=result.data?.data?.[0]?.b64_json||result.data?.data?.[0]?.url;
        const holder=$('#imageOutput');holder.innerHTML='';
        if(image){const img=new Image();img.src=image.startsWith('http')?image:'data:image/png;base64,'+image;img.alt='تصویر تولیدشده';holder.append(img);}
        else holder.textContent=formatObject(result.data);
        setMessage('#imageMessage','تولید تصویر تکمیل شد. شناسه: '+(result.request_id||'—'),'success');
      } catch(error){setMessage('#imageMessage',error.message,'error');}
      finally{setBusy(form,false);}
    });

    $('#imageEditForm').addEventListener('submit',async event=>{
      event.preventDefault();
      const form=$('#imageEditForm');
      const data=payloadFromForm(form);
      const urls=String(data.image_urls||'').split(/\r?\n/).map(value=>value.trim()).filter(Boolean);
      setBusy(form,true,'در حال ویرایش...');
      try {
        if(urls.length<1||urls.length>4) throw new Error('بین ۱ تا ۴ نشانی عمومی HTTPS وارد کنید.');
        if(urls.some(url=>!url.toLowerCase().startsWith('https://'))) throw new Error('فقط نشانی عمومی HTTPS پذیرفته می‌شود.');
        const payload={model:data.model,prompt:data.prompt};
        if(urls.length===1) payload.image_url=urls[0];
        else payload.image_urls=urls;
        if(data.mask_url) payload.mask_url=data.mask_url;
        const result=await api('/ai/image-edit',{method:'POST',body:json(payload)});
        $('#imageEditOutput').textContent=formatObject(result.data);
        setMessage('#imageEditMessage','ویرایش تصویر با موفقیت انجام شد.','success');
      } catch(error) {
        setMessage('#imageEditMessage',error.message,'error');
      } finally {
        setBusy(form,false);
      }
    });

    $('#transcribeForm').addEventListener('submit',async event=>{
      event.preventDefault();const form=$('#transcribeForm');const body=new FormData(form);setBusy(form,true,'در حال تبدیل صوت...');
      try{const result=await api('/ai/transcription',{method:'POST',body});const data=result.data||{};displayText('#transcribeOutput',data.text||data.transcription||formatObject(data));setMessage('#transcribeMessage','تبدیل صوت انجام شد.','success');}
      catch(error){setMessage('#transcribeMessage',error.message,'error');}
      finally{setBusy(form,false);}
    });

    $('#speechForm').addEventListener('submit',async event=>{
      event.preventDefault();const form=$('#speechForm');const data=payloadFromForm(form);setBusy(form,true,'در حال تولید صدا...');
      try{const result=await api('/ai/speech',{method:'POST',body:json(data)});const bytes=Uint8Array.from(atob(result.audio_base64),c=>c.charCodeAt(0));const blob=new Blob([bytes],{type:result.content_type||'audio/mpeg'});const url=URL.createObjectURL(blob);const holder=$('#speechOutput');holder.innerHTML='';const audio=new Audio(url);audio.controls=true;audio.src=url;holder.append(audio);const a=document.createElement('a');a.href=url;a.download='parse-speech.'+(data.response_format||'mp3');a.textContent='دریافت فایل صوتی';holder.append(a);setMessage('#speechMessage','تولید صدا انجام شد.','success');}
      catch(error){setMessage('#speechMessage',error.message,'error');}
      finally{setBusy(form,false);}
    });

    $('#decisionForm').addEventListener('submit',async event=>{
      event.preventDefault();const data=payloadFromForm($('#decisionForm'));setBusy($('#decisionForm'),true,'در حال تصمیم‌گیری...');
      try{data.questions=JSON.parse(data.questions);data.state=data.state;const result=await api('/ai/decisions',{method:'POST',body:json(data)});$('#decisionOutput').textContent=formatObject(result.data);}
      catch(error){setMessage('#decisionMessage',error instanceof SyntaxError?'ساختار Questions JSON معتبر نیست.':error.message,'error');}
      finally{setBusy($('#decisionForm'),false);}
    });

    $('#embeddingForm').addEventListener('submit',async event=>{
      event.preventDefault();const data=payloadFromForm($('#embeddingForm'));data.input=String(data.input||'').split(/\n+/).map(x=>x.trim()).filter(Boolean);setBusy($('#embeddingForm'),true,'در حال ساخت بردار...');
      try{const result=await api('/ai/embeddings',{method:'POST',body:json(data)});$('#embeddingOutput').textContent=formatObject(result.data);}
      catch(error){setMessage('#embeddingMessage',error.message,'error');}
      finally{setBusy($('#embeddingForm'),false);}
    });

    $('#knowledgeForm').addEventListener('submit',async event=>{
      event.preventDefault();const data=payloadFromForm($('#knowledgeForm'));setBusy($('#knowledgeForm'),true,'در حال جست‌وجو...');
      try{const id=encodeURIComponent(data.kb_id);delete data.kb_id;const result=await api('/ai/knowledge/'+id+'/search',{method:'POST',body:json(data)});$('#knowledgeOutput').textContent=formatObject(result.data);}
      catch(error){setMessage('#knowledgeMessage',error.message,'error');}
      finally{setBusy($('#knowledgeForm'),false);}
    });

    $('#batchForm').addEventListener('submit',async event=>{
      event.preventDefault();const data=payloadFromForm($('#batchForm'));const prompts=String(data.requests||'').split(/\n+/).map(x=>x.trim()).filter(Boolean);if(!prompts.length||prompts.length>20)return setMessage('#batchMessage','بین ۱ تا ۲۰ درخواست وارد کنید.','error');
      data.endpoint='/v1/chat/completions';data.requests=prompts.map((prompt,index)=>({custom_id:'parse-'+Date.now()+'-'+(index+1),body:{messages:[{role:'user',content:prompt}],max_tokens:Number(data.max_tokens||256)}}));delete data.max_tokens;setBusy($('#batchForm'),true,'در حال ثبت Batch...');
      try{
        const result=await api('/ai/batches',{method:'POST',body:json(data)});
        $('#batchOutput').textContent=formatObject(result.data);
        const id=result.data?.id||result.data?.batch_id||result.data?.batch?.id||'';
        if(id) $('#batchLookupId').value=id;
        setMessage('#batchMessage','Batch ثبت شد. شناسه: '+(id||'در پاسخ Provider موجود نیست'),'success');
      }
      catch(error){setMessage('#batchMessage',error.message,'error');}
      finally{setBusy($('#batchForm'),false);}
    });

    $('#imageBatchForm').addEventListener('submit',async event=>{
      event.preventDefault();const data=payloadFromForm($('#imageBatchForm'));const prompts=String(data.prompts||'').split(/\n+/).map(x=>x.trim()).filter(Boolean);if(!prompts.length||prompts.length>10)return setMessage('#imageBatchMessage','بین ۱ تا ۱۰ شرح تصویر وارد کنید.','error');
      data.requests=prompts.map((prompt,index)=>({custom_id:'img-'+Date.now()+'-'+(index+1),type:'generation',body:{prompt}}));setBusy($('#imageBatchForm'),true,'در حال ثبت...');
      try{
        const result=await api('/ai/image-batches',{method:'POST',body:json(data)});
        $('#imageBatchOutput').textContent=formatObject(result.data);
        const id=result.data?.id||result.data?.batch_id||result.data?.batch?.id||'';
        if(id) $('#imageBatchLookupId').value=id;
        setMessage('#imageBatchMessage','Batch تصویر ثبت شد. شناسه: '+(id||'در پاسخ Provider موجود نیست'),'success');
      }
      catch(error){setMessage('#imageBatchMessage',error.message,'error');}
      finally{setBusy($('#imageBatchForm'),false);}
    });

    $('#checkBatchStatus').addEventListener('click',async()=>{
      const id=$('#batchLookupId').value.trim();
      if(!id) return setMessage('#batchMessage','شناسه Batch را وارد کنید.','error');
      const button=$('#checkBatchStatus');button.disabled=true;button.textContent='در حال بررسی...';
      try{
        const result=await api('/ai/batches/'+encodeURIComponent(id));
        $('#batchOutput').textContent=formatObject(result);
        setMessage('#batchMessage','وضعیت Batch دریافت شد.','success');
      } catch(error) {
        setMessage('#batchMessage',error.message,'error');
      } finally {
        button.disabled=false;button.textContent='بررسی وضعیت';
      }
    });

    $('#checkImageBatchStatus').addEventListener('click',async()=>{
      const id=$('#imageBatchLookupId').value.trim();
      if(!id) return setMessage('#imageBatchMessage','شناسه Batch تصویر را وارد کنید.','error');
      const button=$('#checkImageBatchStatus');button.disabled=true;button.textContent='در حال بررسی...';
      try{
        const result=await api('/ai/image-batches/'+encodeURIComponent(id));
        $('#imageBatchOutput').textContent=formatObject(result);
        setMessage('#imageBatchMessage','وضعیت Batch تصویر دریافت شد.','success');
      } catch(error) {
        setMessage('#imageBatchMessage',error.message,'error');
      } finally {
        button.disabled=false;button.textContent='بررسی وضعیت';
      }
    });

    $('#cancelImageBatch').addEventListener('click',async()=>{
      const id=$('#imageBatchLookupId').value.trim();
      if(!id) return setMessage('#imageBatchMessage','شناسه Batch تصویر را وارد کنید.','error');
      if(!confirm('صف این Batch تصویر متوقف شود؟')) return;
      const button=$('#cancelImageBatch');button.disabled=true;button.textContent='در حال توقف...';
      try{
        const result=await api('/ai/image-batches/'+encodeURIComponent(id),{method:'DELETE',body:'{}'});
        $('#imageBatchOutput').textContent=formatObject(result);
        setMessage('#imageBatchMessage','درخواست توقف صف ارسال شد.','success');
      } catch(error) {
        setMessage('#imageBatchMessage',error.message,'error');
      } finally {
        button.disabled=false;button.textContent='توقف صف';
      }
    });

    $('#receiptForm').addEventListener('submit',async event=>{
      event.preventDefault();const id=$('#receiptForm [name="id"]').value.trim();setBusy($('#receiptForm'),true,'در حال دریافت رسید...');
      try{const result=await api('/generation?id='+encodeURIComponent(id));$('#receiptOutput').textContent=formatObject(result);}
      catch(error){setMessage('#receiptMessage',error.message,'error');}
      finally{setBusy($('#receiptForm'),false);}
    });

    $('#settingsForm').addEventListener('submit',async event=>{
      event.preventDefault();const data=payloadFromForm($('#settingsForm'));setBusy($('#settingsForm'),true,'در حال ذخیره...');
      try{const result=await api('/settings',{method:'POST',body:json(data)});$('#settingsForm [name="api_key"]').value='';setMessage('#settingsMessage',result.message,'success');await loadSettings();await loadDashboard();}
      catch(error){setMessage('#settingsMessage',error.message,'error');}
      finally{setBusy($('#settingsForm'),false);}
    });

    $('#testProvider').addEventListener('click',async()=>{
      setMessage('#settingsMessage','در حال آزمایش کلید و دریافت کاتالوگ...');
      try{const result=await api('/settings/test',{method:'POST',body:'{}'});setMessage('#settingsMessage',result.message+' تعداد مدل‌ها: '+number(result.models_count),'success');}
      catch(error){setMessage('#settingsMessage',error.message,'error');}
    });
  }

  async function loadSettings() {
    try {
      const [settings, boot] = await Promise.all([api('/settings'),api('/bootstrap')]);
      state.bootstrap = boot;
      $('#settingsStatus').textContent = settings.configured ? 'کلید ثبت شده' : 'نیازمند کلید';
      $('#settingsStatus').classList.toggle('success',!!settings.configured);
      $('#providerBadge').textContent = settings.configured ? 'کلید ثبت شده' : 'نیازمند تنظیم';
      $('#providerStateText').textContent = settings.configured ? 'اتصال پاستا آماده تست است.' : 'کلید API هنوز ثبت نشده است.';
      $('#providerMask').textContent = settings.configured ? settings.masked_key : 'پیکربندی نشده';
      $('#settingsForm [name="default_model"]').value = settings.default_model || '';
    } catch(error) {
      setMessage('#settingsMessage',error.message,'error');
    }
  }

  function localDateInput(date) {
    const y=date.getFullYear();
    const m=String(date.getMonth()+1).padStart(2,'0');
    const d=String(date.getDate()).padStart(2,'0');
    return y+'-'+m+'-'+d;
  }

  async function loadUsage() {
    const now=new Date();const first=new Date(now.getFullYear(),now.getMonth(),1);
    if(!$('#usageFrom').value) $('#usageFrom').value=localDateInput(first);
    if(!$('#usageTo').value) $('#usageTo').value=localDateInput(now);
    const from=$('#usageFrom').value,to=$('#usageTo').value;
    try{
      const result=await api('/usage?from='+encodeURIComponent(from)+'&to='+encodeURIComponent(to));
      $('#usageRequests').textContent=number(result.summary.requests);
      $('#usageTokens').textContent=number(result.summary.tokens);
      $('#usageCachedTokens').textContent=number(result.summary.cached_tokens);
      $('#usageCost').textContent=money(result.summary.cost);
      $('#usageRows').innerHTML=result.rows.length?result.rows.map(row=>{
        let meta='—';
        try {
          const decoded=JSON.parse(row.provider_meta_json||'{}');
          if(decoded&&Object.keys(decoded).length) meta='<details class="usage-detail"><summary>جزئیات</summary><pre>'+escapeHtml(formatObject(decoded))+'</pre></details>';
        } catch { meta='—'; }
        return '<tr><td>'+escapeHtml(new Date(row.created_at).toLocaleString('fa-IR'))+'</td><td>'+escapeHtml(row.operation)+'</td><td dir="ltr">'+escapeHtml(row.model||'—')+'</td><td><span class="table-status '+(Number(row.status_code)<400?'ok':'bad')+'">'+number(row.status_code)+'</span></td><td>'+number(row.input_tokens)+'</td><td>'+number(row.output_tokens)+'</td><td>'+number(row.cached_input_tokens)+'</td><td>'+money(row.charged_toman)+'</td><td>'+number(row.latency_ms)+' ms</td><td>'+meta+'</td></tr>';
      }).join(''):'<tr><td colspan="10" class="empty-cell">در این بازه گزارشی ثبت نشده است.</td></tr>';
    }catch(error){toast(error.message,'error');}
  }

  async function loadQuestionBank() {
    const term=$('#bankQuestionSearch').value.trim();
    try {
      const result=await api('/questions?limit=100&offset=0&q='+encodeURIComponent(term));
      state.questions=result.rows||[];
      $('#bankQuestionCount').textContent=number(state.questions.length)+' سؤال';
      $('#bankQuestionRows').innerHTML=state.questions.length?state.questions.map(row=>'<tr><td>'+number(row.id)+'</td><td class="question-cell">'+escapeHtml(row.question)+'</td><td>'+escapeHtml(row.subject||'—')+'</td><td>'+escapeHtml(row.grade||'—')+'</td><td>'+escapeHtml(row.source_type||'—')+'</td><td>'+escapeHtml(new Date(row.created_at).toLocaleDateString('fa-IR'))+'</td><td><button class="danger-button" data-delete-bank-question="'+Number(row.id)+'">حذف</button></td></tr>').join(''):'<tr><td colspan="7" class="empty-cell">سؤالی در بانک پیدا نشد.</td></tr>';
    } catch(error) {
      $('#bankQuestionRows').innerHTML='<tr><td colspan="7" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>';
      toast(error.message,'error');
    }
  }

  async function loadUsers() {
    const term=$('#userSearch').value.trim();
    try {
      const result=await api('/users?limit=200&offset=0&q='+encodeURIComponent(term));
      state.users=result.rows||[];
      $('#userCount').textContent=number(state.users.length)+' حساب';
      $('#userRows').innerHTML=state.users.length?state.users.map(row=>{
        const active=!!Number(row.active);
        const roleLabel=({developer_admin:'ادمین برنامه‌نویس',education_admin:'ادمین آموزشی',admin:'ادمین برنامه‌نویس',user:'کاربر'})[row.role]||row.role;
        return '<tr><td>'+number(row.id)+'</td><td dir="ltr">'+escapeHtml(row.email)+'</td><td>'+escapeHtml(roleLabel)+'</td><td><span class="table-status '+(active?'ok':'bad')+'">'+(active?'فعال':'غیرفعال')+'</span></td><td>'+escapeHtml(new Date(row.created_at).toLocaleDateString('fa-IR'))+'</td><td><button class="button button-secondary" data-user-status="'+Number(row.id)+'" data-active="'+(active?'1':'0')+'">'+(active?'غیرفعال‌سازی':'فعال‌سازی')+'</button></td></tr>';
      }).join(''):'<tr><td colspan="6" class="empty-cell">کاربری پیدا نشد.</td></tr>';
    } catch(error) {
      $('#userRows').innerHTML='<tr><td colspan="6" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>';
      toast(error.message,'error');
    }
  }


  function faDate(value, withTime = true) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T') + (String(value).endsWith('Z') ? '' : 'Z'));
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('fa-IR', withTime ? {} : {dateStyle:'medium'});
  }

  function statusLabel(value) {
    return ({active:'فعال',disabled:'غیرفعال',pending:'در انتظار',draft:'پیش‌نویس',published:'منتشرشده',closed:'بسته',assigned:'اختصاص‌یافته',in_progress:'در حال انجام',submitted:'ارسال‌شده',reviewed:'بررسی‌شده',expired:'منقضی'})[value] || value || '—';
  }

  function questionTypeLabel(type) {
    return ({multiple_choice:'چهارگزینه‌ای',written:'تشریحی',true_false:'درست / نادرست',fill_blank:'جای خالی',matching:'وصل‌کردنی',ordering:'مرتب‌سازی',listening:'شنیداری',speaking:'گفتاری'})[type] || type || '—';
  }


  async function loadTracks() {
    try {
      const [trackResult,catalog] = await Promise.all([api('/platform/tracks'),api('/platform/education-catalog')]);
      state.tracks = trackResult.rows || [];
      state.educationCatalog = catalog;
      const classTypes=(catalog.class_types||[]);
      const activeTypes=classTypes.filter(type=>Number(type.active));
      const activeLanguages=(catalog.languages||[]);
      const allLevels=(catalog.levels||[]);
      const activeLevels=allLevels.filter(level=>Number(level.active));
      if ($('#trackRows')) {
        $('#trackRows').innerHTML = state.tracks.length ? state.tracks.map(track =>
          '<tr><td>'+escapeHtml(track.language_name)+' <small dir="ltr">'+escapeHtml(track.language_code)+'</small></td><td>'+escapeHtml(track.track_title)+'</td><td>'+escapeHtml(track.course_title)+'</td><td>'+escapeHtml(track.level_code)+'</td><td>'+escapeHtml(track.class_type_title||'—')+'</td><td><span class="table-status '+(Number(track.active)?'ok':'bad')+'">'+(Number(track.active)?'فعال':'غیرفعال')+'</span></td><td><button class="button button-secondary" data-track-status="'+Number(track.id)+'" data-active="'+Number(track.active)+'">'+(Number(track.active)?'غیرفعال‌سازی':'فعال‌سازی')+'</button></td></tr>'
        ).join('') : '<tr><td colspan="7" class="empty-cell">هنوز مسیر آموزشی تعریف نشده است.</td></tr>';
      }
      if ($('#trackLanguage')) {
        const selected=$('#trackLanguage').value||'en';
        $('#trackLanguage').innerHTML=activeLanguages.map(l=>'<option value="'+escapeHtml(l.language_code)+'">'+escapeHtml(l.native_name||l.language_name)+'</option>').join('');
        $('#trackLanguage').value=activeLanguages.some(l=>l.language_code===selected)?selected:(activeLanguages[0]?.language_code||'en');
      }
      const languageOptions=activeLanguages.map(l=>'<option value="'+escapeHtml(l.language_code)+'">'+escapeHtml(l.native_name||l.language_name)+'</option>').join('');
      if($('#levelLanguage')){const previous=$('#levelLanguage').value||($('#trackLanguage')?.value||'en');$('#levelLanguage').innerHTML=languageOptions;$('#levelLanguage').value=activeLanguages.some(l=>l.language_code===previous)?previous:(activeLanguages[0]?.language_code||'en');}
      const refreshTrackLevels=()=>{
        if(!$('#trackLevel'))return;
        const lang=$('#trackLanguage')?.value||'en', current=$('#trackLevel').value;
        const levels=activeLevels.filter(level=>level.language_code===lang);
        $('#trackLevel').innerHTML=levels.map(level=>'<option value="'+escapeHtml(level.level_code)+'">'+escapeHtml(level.level_code+' · '+level.level_name)+'</option>').join('');
        if(levels.some(level=>level.level_code===current))$('#trackLevel').value=current;
      };
      refreshTrackLevels();
      if($('#trackLanguage')&&!$('#trackLanguage').dataset.bound){
        $('#trackLanguage').dataset.bound='1';
        $('#trackLanguage').addEventListener('change',refreshTrackLevels);
      }
      const typeOptions='<option value="">نوع کلاس انتخاب نشده</option>'+activeTypes.map(t=>'<option value="'+Number(t.id)+'">'+escapeHtml(t.title)+'</option>').join('');
      ['#trackClassType','#studentEditForm [name="track_id"]'].forEach(selector=>{
        const select=$(selector);
        if(select&&selector==='#trackClassType'){const previous=select.value;select.innerHTML=typeOptions;select.value=previous;}
      });
      const routeOptions='<option value="">بدون محدودیت مسیر</option>'+state.tracks.filter(t=>Number(t.active)).map(t=>'<option value="'+Number(t.id)+'">'+escapeHtml(t.language_name+' · '+t.track_title+' · '+t.course_title+' · '+t.level_code)+'</option>').join('');
      ['#studentCreateForm [name="track_id"]','#studentEditForm [name="track_id"]','#resourceCreateForm [name="track_id"]','#structuredQuestionForm [name="track_id"]','#examCreateForm [name="track_id"]','#activityTrack'].forEach(selector=>{
        const select=$(selector);
        if(select){const previous=select.value;select.innerHTML=(selector==='#studentEditForm [name="track_id"]'?'<option value="">بدون مسیر آموزشی</option>':'<option value="">بدون محدودیت مسیر</option>')+routeOptions.replace('<option value="">بدون محدودیت مسیر</option>','');if(previous)select.value=previous;}
      });
      if($('#classTypeRows'))$('#classTypeRows').innerHTML=classTypes.length?classTypes.map(type=>'<tr><td>'+escapeHtml(type.title)+'</td><td dir="ltr">'+escapeHtml(type.code)+'</td><td><span class="table-status '+(Number(type.active)?'ok':'bad')+'">'+(Number(type.active)?'فعال':'غیرفعال')+'</span></td><td><button class="button button-secondary" data-class-type-status="'+Number(type.id)+'" data-active="'+Number(type.active)+'">'+(Number(type.active)?'غیرفعال‌سازی':'فعال‌سازی')+'</button></td></tr>').join(''):'<tr><td colspan="4" class="empty-cell">هنوز نوع کلاسی تعریف نشده است.</td></tr>';
      if($('#levelRows'))$('#levelRows').innerHTML=allLevels.length?allLevels.map(level=>{
        const language=activeLanguages.find(item=>item.language_code===level.language_code);
        return '<tr><td>'+escapeHtml(language?.native_name||language?.language_name||level.language_code)+'</td><td dir="ltr">'+escapeHtml(level.level_code)+'</td><td>'+escapeHtml(level.level_name)+'</td><td><span class="table-status '+(Number(level.active)?'ok':'bad')+'">'+(Number(level.active)?'فعال':'غیرفعال')+'</span></td><td><button class="button button-secondary" data-level-status="'+Number(level.id)+'" data-active="'+Number(level.active)+'">'+(Number(level.active)?'غیرفعال‌سازی':'فعال‌سازی')+'</button></td></tr>';
      }).join(''):'<tr><td colspan="5" class="empty-cell">هنوز سطحی تعریف نشده است.</td></tr>';
    } catch(error) {
      if($('#trackRows'))$('#trackRows').innerHTML='<tr><td colspan="7" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>';
      toast(error.message,'error');
    }
  }

  async function loadActivities() {
    try {
      const result=await api('/platform/activities');
      state.activities=result.rows||[];
      if($('#activityRows'))$('#activityRows').innerHTML=state.activities.length?state.activities.map(a=>
        '<tr><td>'+escapeHtml(a.title)+'</td><td>'+escapeHtml(a.activity_type==='assignment'?'تکلیف':'تمرین')+'</td><td>'+escapeHtml([a.subject,a.level].filter(Boolean).join(' / ')||'—')+'</td><td>'+number(a.question_count)+'</td><td>'+number(a.student_count)+'</td><td>'+number(a.submitted_count)+'</td><td>'+escapeHtml(faDate(a.due_at))+'</td><td><span class="table-status '+(a.status==='published'?'ok':'bad')+'">'+escapeHtml(statusLabel(a.status))+'</span></td><td><button class="button button-secondary" data-activity-status="'+Number(a.id)+'" data-status="'+escapeHtml(a.status)+'">'+(a.status==='published'?'بستن':'انتشار')+'</button></td></tr>'
      ).join(''):'<tr><td colspan="9" class="empty-cell">هنوز تمرین یا تکلیفی ساخته نشده است.</td></tr>';
    } catch(error) {
      if($('#activityRows'))$('#activityRows').innerHTML='<tr><td colspan="9" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>';
      toast(error.message,'error');
    }
  }

  async function loadActivitySubmissions() {
    try {
      const result=await api('/platform/activity-submissions');
      state.activitySubmissions=result.rows||[];
      if($('#activitySubmissionRows'))$('#activitySubmissionRows').innerHTML=state.activitySubmissions.length?state.activitySubmissions.map(s=>
        '<tr><td>'+escapeHtml(s.student_name+' · '+s.username)+'</td><td>'+escapeHtml(s.activity_title)+'</td><td>'+number(s.answered_count)+' / '+number(s.question_count)+'</td><td>'+escapeHtml(faDate(s.submitted_at))+'</td><td>'+escapeHtml(s.score===null?'—':s.score+' / '+s.total_points)+'</td><td><span class="table-status '+(s.status==='reviewed'?'ok':'bad')+'">'+escapeHtml(statusLabel(s.status))+'</span></td><td><button class="button button-secondary" data-activity-submission-detail="'+Number(s.id)+'">مشاهده و تصحیح</button></td></tr>'
      ).join(''):'<tr><td colspan="7" class="empty-cell">هنوز پاسخی برای تمرین‌ها ثبت نشده است.</td></tr>';
    } catch(error) {
      if($('#activitySubmissionRows'))$('#activitySubmissionRows').innerHTML='<tr><td colspan="7" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>';
      toast(error.message,'error');
    }
  }

  async function loadActivitySubmissionDetail(id) {
    try {
      const result=await api('/platform/activity-submissions/'+Number(id));
      const submission=result.submission, answers=result.answers||[];
      $('#activitySubmissionDetailPanel').hidden=false;
      $('#activitySubmissionDetailTitle').textContent=submission.student_name+' · '+submission.activity_title;
      $('#activitySubmissionDetailMeta').textContent='وضعیت: '+statusLabel(submission.status)+' | نمره: '+(submission.score??'—')+' از '+(submission.total_points??'—');
      $('#activitySubmissionAnswers').innerHTML=answers.map(row=>{
        let response=row.student_answer||'—';
        try{response=JSON.stringify(JSON.parse(response),null,2);}catch{}
        let options=null;
        try{options=row.options_json?JSON.parse(row.options_json):null;}catch{}
        const key=row.correct_answer?'<details><summary>پاسخ معیار</summary><pre>'+escapeHtml(row.correct_answer)+'</pre></details>':'';
        const manual=['written','speaking','listening','matching'].includes(row.question_type)||row.is_correct===null;
        const audio=row.audio_response_id?'<div class="audio-review"><b>پاسخ صوتی</b><audio controls preload="none" src="/api/platform/audio-responses/'+Number(row.audio_response_id)+'"></audio></div>':'';
        return '<article class="attempt-answer"><h4>'+escapeHtml(row.question_text)+'</h4><small>'+escapeHtml(questionTypeLabel(row.question_type))+' · بارم '+escapeHtml(row.points)+'</small>'+audio+'<div class="answer-columns"><div><b>پاسخ دانش‌آموز</b><pre>'+escapeHtml(response)+'</pre></div><div><b>گزینه‌ها</b><pre>'+escapeHtml(options?formatObject(options):'—')+'</pre></div></div>'+key+(manual?'<form class="grade-activity-answer-form" data-answer-id="'+Number(row.id)+'"><label>نمره<input class="input" name="awarded_points" type="number" min="0" max="'+Number(row.points)+'" step="0.25" value="'+Number(row.awarded_points||0)+'"></label><label>بازخورد<textarea class="input textarea" name="teacher_feedback" rows="2">'+escapeHtml(row.teacher_feedback||'')+'</textarea></label><button class="button button-primary" type="submit">ثبت تصحیح</button></form>':'<p class="muted-label">تصحیح خودکار · '+(Number(row.is_correct)===1?'درست':Number(row.is_correct)===0?'نادرست':'در انتظار')+'</p>')+'</article>';
      }).join('')||'<div class="empty-state">پاسخی ثبت نشده است.</div>';
    } catch(error){toast(error.message,'error');}
  }

  async function loadStudents() {
    const query = ($('#studentSearch')?.value || '').trim();
    const status = $('#studentStatusFilter')?.value || '';
    try {
      const result = await api('/platform/students?limit=200&q='+encodeURIComponent(query)+'&status='+encodeURIComponent(status));
      state.students = result.rows || [];
      if ($('#studentCount')) $('#studentCount').textContent = number(state.students.length)+' دانش‌آموز';
      if ($('#studentRows')) {
        $('#studentRows').innerHTML = state.students.length ? state.students.map(row => {
          const active = row.status === 'active';
          const course=[row.language_name,row.course_title,row.track_level||row.level,row.class_type_title,row.group_name].filter(Boolean).join(' · ');
          return '<tr><td>'+number(row.id)+'</td><td>'+escapeHtml(row.name)+'</td><td dir="ltr">'+escapeHtml(row.username)+'</td><td>'+escapeHtml(course||'—')+'</td><td>'+escapeHtml(faDate(row.last_seen_at))+'</td><td><span class="table-status '+(active?'ok':'bad')+'">'+escapeHtml(statusLabel(row.status))+'</span></td><td class="action-cell"><button class="button button-secondary" data-student-edit="'+Number(row.id)+'">ویرایش</button> <button class="button button-secondary" data-student-password="'+Number(row.id)+'">تغییر رمز</button> <button class="button button-secondary" data-student-status="'+Number(row.id)+'" data-status="'+escapeHtml(row.status)+'">'+(active?'غیرفعال':'فعال‌سازی')+'</button></td></tr>';
        }).join('') : '<tr><td colspan="7" class="empty-cell">دانش‌آموزی پیدا نشد.</td></tr>';
      }
      const options = '<option value="">بانک عمومی / همه دانش‌آموزان</option>' + state.students.map(s=>'<option value="'+Number(s.id)+'">'+escapeHtml(s.name+' · '+s.username)+'</option>').join('');
      ['#questionTargetStudent','#examTargetStudent','#activityTargetStudent'].forEach(selector => { if ($(selector)) { const current=$(selector).value; $(selector).innerHTML=options; if(current)$(selector).value=current; } });
    } catch(error) {
      if ($('#studentRows')) $('#studentRows').innerHTML='<tr><td colspan="7" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>';
      toast(error.message,'error');
    }
  }

  async function loadResources() {
    try {
      const result=await api('/platform/resources');
      state.resources=result.rows||[];
      const selectable=state.resources.filter(r=>r.content_text && String(r.content_text).trim());
      if ($('#questionResource')) {
        const current=$('#questionResource').value;
        $('#questionResource').innerHTML='<option value="">انتخاب منبع متنی</option>'+selectable.map(r=>'<option value="'+Number(r.id)+'">'+escapeHtml(r.title+' · '+(r.subject||r.resource_type))+'</option>').join('');
        if(current)$('#questionResource').value=current;
      }
      if ($('#resourceRows')) {
        $('#resourceRows').innerHTML=state.resources.length?state.resources.map(r=>'<tr><td>'+escapeHtml(r.title)+'</td><td>'+escapeHtml(r.resource_type)+'</td><td>'+escapeHtml(r.subject||'—')+'</td><td>'+escapeHtml(r.level||'—')+'</td><td><span class="table-status '+(Number(r.published)?'ok':'bad')+'">'+(Number(r.published)?'منتشر':'پیش‌نویس')+'</span></td><td>'+escapeHtml(faDate(r.created_at,false))+'</td><td><button class="button button-secondary" data-resource-publish="'+Number(r.id)+'" data-published="'+Number(r.published)+'">'+(Number(r.published)?'عدم انتشار':'انتشار')+'</button> <button class="danger-button" data-resource-delete="'+Number(r.id)+'">حذف</button></td></tr>').join(''):'<tr><td colspan="7" class="empty-cell">هنوز منبعی ثبت نشده است.</td></tr>';
      }
    } catch(error) {
      if ($('#resourceRows')) $('#resourceRows').innerHTML='<tr><td colspan="7" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>';
      toast(error.message,'error');
    }
  }

  async function loadPlatformQuestionBank() {
    const term=($('#bankQuestionSearch')?.value||'').trim();
    try {
      const result=await api('/platform/questions?limit=200&q='+encodeURIComponent(term));
      state.questions=result.rows||[];
      if ($('#bankQuestionCount')) $('#bankQuestionCount').textContent=number(state.questions.length)+' سؤال';
      if ($('#bankQuestionRows')) {
        $('#bankQuestionRows').innerHTML=state.questions.length?state.questions.map(q=>{
          const status=q.status==='published';
          const kind=questionTypeLabel(q.question_type);
          return '<tr><td>'+number(q.id)+'</td><td class="question-cell">'+escapeHtml(q.question_text)+'</td><td>'+escapeHtml(kind)+'</td><td>'+escapeHtml([q.subject,q.level].filter(Boolean).join(' / '))+'</td><td>'+escapeHtml(q.student_name||'عمومی')+'</td><td><span class="table-status '+(status?'ok':'bad')+'">'+escapeHtml(statusLabel(q.status))+'</span></td><td><button class="button button-secondary" data-question-publish="'+Number(q.id)+'" data-status="'+escapeHtml(q.status)+'">'+(status?'آرشیو':'انتشار')+'</button> <button class="danger-button" data-question-delete="'+Number(q.id)+'">حذف</button></td></tr>';
        }).join(''):'<tr><td colspan="7" class="empty-cell">سؤالی در بانک وجود ندارد.</td></tr>';
      }
    } catch(error) {
      if ($('#bankQuestionRows')) $('#bankQuestionRows').innerHTML='<tr><td colspan="7" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>';
      toast(error.message,'error');
    }
  }

  async function loadExams() {
    try {
      const result=await api('/platform/exams');
      state.exams=result.rows||[];
      if ($('#examRows')) $('#examRows').innerHTML=state.exams.length?state.exams.map(e=>'<tr><td>'+escapeHtml(e.title)+'</td><td>'+escapeHtml([e.subject,e.level].filter(Boolean).join(' / ')||'—')+'</td><td>'+number(e.question_count)+'</td><td>'+number(e.duration_minutes)+' دقیقه</td><td>'+escapeHtml(faDate(e.starts_at)+' / '+faDate(e.ends_at))+'</td><td><span class="table-status '+(e.status==='published'?'ok':'bad')+'">'+escapeHtml(statusLabel(e.status))+'</span></td><td><button class="button button-secondary" data-exam-status="'+Number(e.id)+'" data-status="'+escapeHtml(e.status)+'">'+(e.status==='published'?'بستن آزمون':'انتشار')+'</button></td></tr>').join(''):'<tr><td colspan="7" class="empty-cell">هنوز آزمونی ساخته نشده است.</td></tr>';
    } catch(error) { if ($('#examRows')) $('#examRows').innerHTML='<tr><td colspan="7" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>'; toast(error.message,'error'); }
  }

  async function loadAttempts() {
    try {
      const result=await api('/platform/attempts');
      state.attempts=result.rows||[];
      if ($('#attemptRows')) $('#attemptRows').innerHTML=state.attempts.length?state.attempts.map(a=>'<tr><td>'+escapeHtml(a.student_name+' · '+a.username)+'</td><td>'+escapeHtml(a.exam_title)+'</td><td>'+number(a.answered_count)+' / '+number(a.question_count)+'</td><td>'+escapeHtml(faDate(a.started_at))+'</td><td>'+escapeHtml(faDate(a.submitted_at))+'</td><td>'+escapeHtml(a.score===null?'—':a.score+' / '+a.total_points)+'</td><td><span class="table-status '+(a.status==='reviewed'?'ok':'bad')+'">'+escapeHtml(statusLabel(a.status))+'</span></td><td><button class="button button-secondary" data-attempt-detail="'+Number(a.id)+'">مشاهده و تصحیح</button></td></tr>').join(''):'<tr><td colspan="8" class="empty-cell">هنوز پاسخ‌نامه‌ای ثبت نشده است.</td></tr>';
    } catch(error) { if ($('#attemptRows')) $('#attemptRows').innerHTML='<tr><td colspan="8" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>'; toast(error.message,'error'); }
  }

  async function loadAttemptDetail(id) {
    try {
      const result=await api('/platform/attempts/'+Number(id));
      const a=result.attempt, answers=result.answers||[];
      $('#attemptDetailPanel').hidden=false;
      $('#attemptDetailTitle').textContent=a.student_name+' · '+a.exam_title;
      $('#attemptDetailMeta').textContent='وضعیت: '+statusLabel(a.status)+' | نمره: '+(a.score??'—')+' از '+(a.total_points??'—');
      $('#attemptAnswers').innerHTML=answers.map(row=>{
        let given=row.student_answer||'—', options=row.options_json;
        try { given=JSON.stringify(JSON.parse(given),null,2); } catch {}
        try { options=options?JSON.parse(options):null; } catch { options=null; }
        const correct=row.correct_answer ? '<details><summary>پاسخ معیار</summary><pre>'+escapeHtml(row.correct_answer)+'</pre></details>':'';
        const manual=['written','speaking','listening'].includes(row.question_type);
        const audio=row.audio_response_id?'<div class="audio-review"><b>پاسخ صوتی</b><audio controls preload="none" src="/api/platform/audio-responses/'+Number(row.audio_response_id)+'"></audio></div>':'';
        return '<article class="attempt-answer"><h4>'+escapeHtml(row.question_text)+'</h4><small>'+escapeHtml(questionTypeLabel(row.question_type))+' · بارم '+escapeHtml(row.points)+'</small>'+audio+'<div class="answer-columns"><div><b>پاسخ دانش‌آموز</b><pre>'+escapeHtml(given)+'</pre></div><div><b>گزینه‌ها</b><pre>'+escapeHtml(options?formatObject(options):'—')+'</pre></div></div>'+correct+(manual?'<form class="grade-answer-form" data-answer-id="'+Number(row.id)+'"><label>نمره<input class="input" name="awarded_points" type="number" min="0" max="'+Number(row.points)+'" step="0.25" value="'+Number(row.awarded_points||0)+'"></label><label>بازخورد<textarea class="input textarea" name="teacher_feedback" rows="2">'+escapeHtml(row.teacher_feedback||'')+'</textarea></label><button class="button button-primary" type="submit">ثبت تصحیح</button></form>':'<p class="muted-label">تصحیح خودکار · '+(Number(row.is_correct)===1?'درست':Number(row.is_correct)===0?'نادرست':'در انتظار')+'</p>')+'</article>';
      }).join('')||'<div class="empty-state">پاسخی برای نمایش وجود ندارد.</div>';
    } catch(error) { toast(error.message,'error'); }
  }

  async function loadMonitoring() {
    try {
      const result=await api('/platform/monitoring');
      const online=result.online||[], events=result.events||[];
      $('#onlineStudentCount').textContent=number(online.length);
      $('#monitorEventCount').textContent=number(events.length);
      $('#onlineStudentRows').innerHTML=online.length?online.map(s=>'<tr><td>'+escapeHtml(s.name)+'</td><td dir="ltr">'+escapeHtml(s.username)+'</td><td>'+escapeHtml([s.level,s.group_name].filter(Boolean).join(' / ')||'—')+'</td><td>'+escapeHtml(s.last_seen_platform||'نامشخص')+'</td><td>'+escapeHtml(faDate(s.last_seen_at))+'</td></tr>').join(''):'<tr><td colspan="5" class="empty-cell">در ۵ دقیقه اخیر کاربری فعال ثبت نشده است.</td></tr>';
      $('#monitorEventRows').innerHTML=events.length?events.map(e=>'<tr><td>'+escapeHtml(e.student_name)+'</td><td>'+escapeHtml(e.event_type)+'</td><td dir="ltr">'+escapeHtml(e.route||'—')+'</td><td>'+escapeHtml(e.platform||'—')+'</td><td>'+escapeHtml(faDate(e.created_at))+'</td></tr>').join(''):'<tr><td colspan="5" class="empty-cell">رویدادی ثبت نشده است.</td></tr>';
    } catch(error) { toast(error.message,'error'); }
  }

  async function loadPlatformSettings() {
    try {
      const result=await api('/platform/settings');
      state.platformSettings=result;
      $('#platformSettingsForm [name="api_base_url"]').value=result.api_base_url||'https://api.aiparseh.ir/api';
      $('#platformApiStatus').textContent='API فعال در تنظیمات پنل: '+(result.api_base_url||'—');
      $('#fontStatus').textContent=result.font_configured?'فونت سفارشی فعال':'بدون فونت سفارشی';
    } catch(error) { setMessage('#platformSettingsMessage',error.message,'error'); }
  }

  function bindPlatformForms() {
    $('#studentCreateForm').addEventListener('submit',async event=>{
      event.preventDefault(); const form=event.currentTarget; const data=payloadFromForm(form);
      setBusy(form,true,'در حال ساخت حساب...');
      try { await api('/platform/students',{method:'POST',body:json(data)}); form.reset(); setMessage('#studentCreateMessage','حساب دانش‌آموز ایجاد شد.','success'); await loadStudents(); }
      catch(error) { setMessage('#studentCreateMessage',error.message,'error'); }
      finally { setBusy(form,false); }
    });

    $('#studentEditForm').addEventListener('submit',async event=>{
      event.preventDefault();
      const form=event.currentTarget;
      const data=payloadFromForm(form);
      const id=Number(data.student_id);
      delete data.student_id;
      if(!id)return setMessage('#studentEditMessage','شناسه دانش‌آموز معتبر نیست.','error');
      data.track_id=data.track_id?Number(data.track_id):null;
      setBusy(form,true,'در حال ذخیره تغییرات...');
      try{
        await api('/platform/students/'+id,{method:'POST',body:json(data)});
        $('#studentEditDialog').close();
        await loadStudents();
        toast('اطلاعات و مسیر آموزشی دانش‌آموز به‌روزرسانی شد.','success');
      }catch(error){setMessage('#studentEditMessage',error.message,'error');}
      finally{setBusy(form,false);}
    });
    $('#cancelStudentEdit').addEventListener('click',()=>$('#studentEditDialog').close());


    $('#resourceCreateForm').addEventListener('submit',async event=>{
      event.preventDefault(); const form=event.currentTarget; const file=form.elements.resource_file.files[0];
      setBusy(form,true,'در حال ذخیره منبع...');
      try {
        let body;
        if(file) { body=new FormData(form); }
        else { body=json(payloadFromForm(form)); }
        const result=await api('/platform/resources',{method:'POST',body});
        form.reset(); setMessage('#resourceCreateMessage','منبع ذخیره شد. شناسه: '+number(result.id),'success'); await loadResources();
      } catch(error) { setMessage('#resourceCreateMessage',error.message,'error'); }
      finally { setBusy(form,false); }
    });

    $('#structuredQuestionForm').addEventListener('submit',async event=>{
      event.preventDefault(); const form=event.currentTarget; const data=payloadFromForm(form);
      data.resource_id=Number(data.resource_id); data.count=Number(data.count); data.target_student_id=data.target_student_id?Number(data.target_student_id):null;
      setBusy(form,true,'در حال خواندن منبع و ساخت سؤال...');
      try {
        const result=await api('/platform/questions/generate',{method:'POST',body:json(data)});
        $('#structuredQuestionOutput').textContent=(result.questions||[]).map((q,i)=>(i+1)+'. '+q.question_text+'\n'+(Array.isArray(q.options)?q.options.map(o=>(o.id||o.key||'')+' '+(o.text||o.label||o)).join('\n'):'')+'\nپاسخ: '+JSON.stringify(q.correct_answer)).join('\n\n')||result.output||'خروجی خالی بود.';
        setMessage('#structuredQuestionMessage',number(result.saved)+' سؤال در بانک ذخیره شد.','success');
        await loadPlatformQuestionBank();
      } catch(error) { setMessage('#structuredQuestionMessage',error.message,'error'); }
      finally { setBusy(form,false); }
    });

    $('#examCreateForm').addEventListener('submit',async event=>{
      event.preventDefault();const form=event.currentTarget;const data=payloadFromForm(form);
      data.question_ids=String(data.question_ids||'').split(',').map(x=>Number(x.trim())).filter(x=>Number.isInteger(x)&&x>0);
      data.duration_minutes=Number(data.duration_minutes);data.passing_score=Number(data.passing_score);
      if(data.starts_at)data.starts_at=data.starts_at.replace('T',' ')+':00'.slice(data.starts_at.length===16?0:3);
      if(data.ends_at)data.ends_at=data.ends_at.replace('T',' ')+':00'.slice(data.ends_at.length===16?0:3);
      data.target_student_id=data.target_student_id?Number(data.target_student_id):null;
      setBusy(form,true,'در حال ساخت آزمون...');
      try { const result=await api('/platform/exams',{method:'POST',body:json(data)});form.reset();setMessage('#examCreateMessage','آزمون ساخته شد. شناسه: '+number(result.id),'success');await loadExams(); }
      catch(error) { setMessage('#examCreateMessage',error.message,'error'); }
      finally { setBusy(form,false); }
    });


    $('#trackCreateForm').addEventListener('submit',async event=>{
      event.preventDefault();const form=event.currentTarget;const data=payloadFromForm(form);
      setBusy(form,true,'در حال ذخیره مسیر آموزشی...');
      try{const result=await api('/platform/tracks',{method:'POST',body:json(data)});form.reset();setMessage('#trackCreateMessage','مسیر آموزشی ذخیره شد. شناسه: '+number(result.id),'success');await loadTracks();}
      catch(error){setMessage('#trackCreateMessage',error.message,'error');}
      finally{setBusy(form,false);}
    });

    $('#classTypeCreateForm').addEventListener('submit',async event=>{
      event.preventDefault();const form=event.currentTarget;const data=payloadFromForm(form);
      data.code=String(data.code||'').trim().toLowerCase();
      setBusy(form,true,'در حال افزودن نوع کلاس...');
      try{
        await api('/platform/class-types',{method:'POST',body:json(data)});
        form.reset();
        setMessage('#classTypeMessage','نوع کلاس به فهرست اضافه شد.','success');
        await loadTracks();
      }catch(error){setMessage('#classTypeMessage',error.message,'error');}
      finally{setBusy(form,false);}
    });

    $('#levelCreateForm').addEventListener('submit',async event=>{
      event.preventDefault();const form=event.currentTarget;const data=payloadFromForm(form);
      data.language_code=String(data.language_code||'').toLowerCase();
      data.level_code=String(data.level_code||'').trim().toUpperCase();
      data.level_order=Number(data.level_order||100);
      setBusy(form,true,'در حال افزودن سطح آموزشی...');
      try{
        await api('/platform/levels',{method:'POST',body:json(data)});
        form.reset();
        if($('#levelLanguage'))$('#levelLanguage').value=data.language_code;
        setMessage('#levelMessage','سطح آموزشی به فهرست اضافه شد.','success');
        await loadTracks();
      }catch(error){setMessage('#levelMessage',error.message,'error');}
      finally{setBusy(form,false);}
    });

    $('#activityCreateForm').addEventListener('submit',async event=>{
      event.preventDefault();const form=event.currentTarget;const data=payloadFromForm(form);
      data.question_ids=String(data.question_ids||'').split(',').map(value=>Number(value.trim())).filter(value=>Number.isSafeInteger(value)&&value>0);
      data.track_id=data.track_id?Number(data.track_id):null;
      data.target_student_id=data.target_student_id?Number(data.target_student_id):null;
      if(data.due_at)data.due_at=data.due_at.replace('T',' ')+(data.due_at.length===16?':00':'');
      setBusy(form,true,'در حال ساخت و تخصیص تمرین...');
      try{const result=await api('/platform/activities',{method:'POST',body:json(data)});form.reset();setMessage('#activityCreateMessage','تمرین ایجاد شد و برای '+number(result.assigned_students)+' دانش‌آموز تخصیص یافت. شناسه: '+number(result.id),'success');await loadActivities();}
      catch(error){setMessage('#activityCreateMessage',error.message,'error');}
      finally{setBusy(form,false);}
    });

    $('#activitySubmissionAnswers').addEventListener('submit',async event=>{
      const form=event.target.closest('.grade-activity-answer-form');if(!form)return;
      event.preventDefault();const answerId=Number(form.dataset.answerId);const data=payloadFromForm(form);data.awarded_points=Number(data.awarded_points);
      try{await api('/platform/activity-answers/'+answerId+'/grade',{method:'POST',body:json(data)});toast('نمره پاسخ ذخیره شد.','success');await loadActivitySubmissions();}
      catch(error){toast(error.message,'error');}
    });
    $('#closeActivitySubmissionDetail').addEventListener('click',()=>$('#activitySubmissionDetailPanel').hidden=true);

    $('#platformSettingsForm').addEventListener('submit',async event=>{
      event.preventDefault();const data=payloadFromForm(event.currentTarget);
      try { const result=await api('/platform/settings',{method:'POST',body:json(data)});setMessage('#platformSettingsMessage',result.message,'success');await loadPlatformSettings(); }
      catch(error) { setMessage('#platformSettingsMessage',error.message,'error'); }
    });

    $('#fontForm').addEventListener('submit',async event=>{
      event.preventDefault();const form=event.currentTarget;const data=new FormData(form);
      setBusy(form,true,'در حال بارگذاری فونت...');
      try { const result=await api('/settings/font',{method:'POST',body:data});setMessage('#fontMessage',result.message,'success');form.reset();setTimeout(()=>location.reload(),700); }
      catch(error) { setMessage('#fontMessage',error.message,'error'); }
      finally { setBusy(form,false); }
    });

    $('#attemptAnswers').addEventListener('submit',async event=>{
      const form=event.target.closest('.grade-answer-form');if(!form)return;
      event.preventDefault();const answerId=Number(form.dataset.answerId);const data=payloadFromForm(form);
      data.awarded_points=Number(data.awarded_points);
      try { await api('/platform/answers/'+answerId+'/grade',{method:'POST',body:json(data)});toast('تصحیح ذخیره شد.','success');await loadAttempts(); }
      catch(error) { toast(error.message,'error'); }
    });
    $('#closeAttemptDetail').addEventListener('click',()=>$('#attemptDetailPanel').hidden=true);
  }

  function bindPlatformTables() {
    $('#studentRows').addEventListener('click',async event=>{
      const statusButton=event.target.closest('[data-student-status]');
      const passwordButton=event.target.closest('[data-student-password]');
      const editButton=event.target.closest('[data-student-edit]');
      if(editButton){
        const id=Number(editButton.dataset.studentEdit), row=state.students.find(item=>Number(item.id)===id);
        if(!row)return;
        const form=$('#studentEditForm'),dialog=$('#studentEditDialog');
        if(!form||!dialog)return;
        await loadTracks();
        form.elements.student_id.value=String(id);
        form.elements.name.value=row.name||'';
        form.elements.phone.value=row.phone||'';
        form.elements.email.value=row.email||'';
        form.elements.student_code.value=row.student_code||'';
        form.elements.group_name.value=row.group_name||'';
        form.elements.track_id.value=row.track_id?String(row.track_id):'';
        $('#studentEditMeta').textContent=(row.language_name||'')+' · '+(row.course_title||'')+' · '+(row.track_level||row.level||'بدون سطح');
        setMessage('#studentEditMessage','');
        if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','');
        return;
      }
      if(passwordButton){
        const id=Number(passwordButton.dataset.studentPassword), password=prompt('رمز جدید (حداقل ۸ کاراکتر):');
        if(password===null)return;
        if(password.length<8)return toast('رمز باید حداقل ۸ کاراکتر باشد.','error');
        try{await api('/platform/students/'+id+'/password',{method:'POST',body:json({password})});toast('رمز تغییر کرد و نشست‌های قبلی باطل شدند.','success');}
        catch(error){toast(error.message,'error');}
        return;
      }
      if(statusButton){
        const id=Number(statusButton.dataset.studentStatus), current=statusButton.dataset.status;
        const status=current==='active'?'disabled':'active';
        try{await api('/platform/students/'+id+'/status',{method:'POST',body:json({status})});await loadStudents();toast('وضعیت دانش‌آموز تغییر کرد.','success');}
        catch(error){toast(error.message,'error');}
      }
    });
    $('#resourceRows').addEventListener('click',async event=>{
      const publish=event.target.closest('[data-resource-publish]'), del=event.target.closest('[data-resource-delete]');
      try{
        if(publish){await api('/platform/resources/'+Number(publish.dataset.resourcePublish)+'/publish',{method:'POST',body:json({published:publish.dataset.published!=='1'})});await loadResources();}
        if(del){if(!confirm('منبع و فایل آن حذف شود؟'))return;await api('/platform/resources/'+Number(del.dataset.resourceDelete),{method:'DELETE',body:'{}'});await loadResources();}
      }catch(error){toast(error.message,'error');}
    });

    $('#bankQuestionRows').addEventListener('click',async event=>{
      const publish=event.target.closest('[data-question-publish]'), del=event.target.closest('[data-question-delete]');
      try{
        if(publish){const status=publish.dataset.status==='published'?'archived':'published';await api('/platform/questions/'+Number(publish.dataset.questionPublish)+'/status',{method:'POST',body:json({status})});await loadPlatformQuestionBank();}
        if(del){if(!confirm('سؤال از بانک حذف شود؟'))return;await api('/platform/questions/'+Number(del.dataset.questionDelete),{method:'DELETE',body:'{}'});await loadPlatformQuestionBank();}
      }catch(error){toast(error.message,'error');}
    });
    $('#examRows').addEventListener('click',async event=>{
      const button=event.target.closest('[data-exam-status]');if(!button)return;
      const status=button.dataset.status==='published'?'closed':'published';
      try{await api('/platform/exams/'+Number(button.dataset.examStatus)+'/status',{method:'POST',body:json({status})});await loadExams();}
      catch(error){toast(error.message,'error');}
    });

    $('#trackRows').addEventListener('click',async event=>{
      const button=event.target.closest('[data-track-status]');if(!button)return;
      try{await api('/platform/tracks/'+Number(button.dataset.trackStatus)+'/status',{method:'POST',body:json({active:button.dataset.active!=='1'})});await loadTracks();}
      catch(error){toast(error.message,'error');}
    });
    $('#classTypeRows').addEventListener('click',async event=>{
      const button=event.target.closest('[data-class-type-status]');if(!button)return;
      try{
        await api('/platform/class-types/'+Number(button.dataset.classTypeStatus)+'/status',{method:'POST',body:json({active:button.dataset.active!=='1'})});
        await loadTracks();
      }catch(error){toast(error.message,'error');}
    });
    $('#levelRows').addEventListener('click',async event=>{
      const button=event.target.closest('[data-level-status]');if(!button)return;
      try{
        await api('/platform/levels/'+Number(button.dataset.levelStatus)+'/status',{method:'POST',body:json({active:button.dataset.active!=='1'})});
        await loadTracks();
      }catch(error){toast(error.message,'error');}
    });
    $('#activityRows').addEventListener('click',async event=>{
      const button=event.target.closest('[data-activity-status]');if(!button)return;
      const status=button.dataset.status==='published'?'closed':'published';
      try{await api('/platform/activities/'+Number(button.dataset.activityStatus)+'/status',{method:'POST',body:json({status})});await loadActivities();}
      catch(error){toast(error.message,'error');}
    });
    $('#activitySubmissionRows').addEventListener('click',event=>{
      const button=event.target.closest('[data-activity-submission-detail]');if(button)loadActivitySubmissionDetail(Number(button.dataset.activitySubmissionDetail));
    });
    $('#attemptRows').addEventListener('click',event=>{
      const button=event.target.closest('[data-attempt-detail]');if(button)loadAttemptDetail(Number(button.dataset.attemptDetail));
    });
  }

  function initPlatformNavigation() {
    $('#refreshStudents').addEventListener('click',loadStudents);
    $('#refreshResources').addEventListener('click',loadResources);
    $('#refreshExams').addEventListener('click',loadExams);
    $('#refreshAttempts').addEventListener('click',loadAttempts);
    $('#refreshMonitoring').addEventListener('click',loadMonitoring);
    $('#refreshTracks').addEventListener('click',loadTracks);
    $('#refreshActivities').addEventListener('click',loadActivities);
    $('#refreshActivitySubmissions').addEventListener('click',loadActivitySubmissions);

    $('#studentSearch').addEventListener('input',()=>loadStudents());
    $('#studentStatusFilter').addEventListener('change',loadStudents);
  }

  function showPrompt(prompt) {
    if(!prompt)return;
    $('#promptSlug').value=prompt.slug;
    $('#promptBody').value=prompt.system_prompt;
    $('#promptEnabled').checked=!!Number(prompt.enabled);
    $('#promptMeta').textContent=prompt.title+' · '+prompt.generation_type+' · '+prompt.slug;
    setMessage('#promptMessage','');
  }

  async function loadPrompts() {
    try {
      const result=await api('/prompts');
      state.prompts=result.rows||[];
      const select=$('#promptSelect');
      const previous=select.value;
      select.innerHTML=state.prompts.map(row=>'<option value="'+escapeHtml(row.slug)+'">'+escapeHtml(row.title)+'</option>').join('');
      if(previous&&state.prompts.some(row=>row.slug===previous))select.value=previous;
      showPrompt(state.prompts.find(row=>row.slug===select.value)||state.prompts[0]);
    } catch(error) {
      setMessage('#promptMessage',error.message,'error');
    }
  }

  async function loadHistory() {
    try {
      const result=await api('/history');
      $('#historyList').innerHTML=result.rows.length?result.rows.map(row=>'<article class="history-card"><div class="history-meta"><span class="history-type">'+escapeHtml(row.generation_type)+'</span><time>'+escapeHtml(new Date(row.created_at).toLocaleString('fa-IR'))+'</time></div><h3>'+escapeHtml(row.title||'خروجی هوش مصنوعی')+'</h3><div class="history-model" dir="ltr">'+escapeHtml(row.model||'')+'</div><pre>'+escapeHtml(row.output||'')+'</pre><button class="text-action" data-history-copy="'+Number(row.id)+'">کپی خروجی ←</button></article>').join(''):'<div class="empty-state">هنوز خروجی ذخیره‌شده‌ای وجود ندارد.</div>';
      state.history = result.rows;
    } catch(error){toast(error.message,'error');}
  }

  function activateTabs() {
    $$('.catalog-tab').forEach(button=>button.addEventListener('click',()=>{
      $$('.catalog-tab').forEach(item=>item.classList.toggle('active',item===button));
      loadCatalog(button.dataset.catalog,true).catch(()=>{});
    }));
    $$('.tool-tab').forEach(button=>button.addEventListener('click',()=>{
      const container=button.closest('.page-view');
      $$('.tool-tab',container).forEach(item=>item.classList.toggle('active',item===button));
      $$('.tool-pane',container).forEach(pane=>pane.classList.toggle('active',pane.id===button.dataset.tool));
    }));
    $$('.nav-item').forEach(button=>button.addEventListener('click',()=>showPage(button.dataset.page)));
    $$('[data-go]').forEach(button=>button.addEventListener('click',()=>showPage(button.dataset.go)));
    $$('[data-copy]').forEach(button=>button.addEventListener('click',async()=>{
      const text=$('#'+button.dataset.copy)?.innerText||'';
      if(!text.trim()) return toast('خروجی برای کپی وجود ندارد.','error');
      try{await navigator.clipboard.writeText(text);toast('متن کپی شد.','success');}
      catch{toast('کپی خودکار ممکن نیست؛ متن را دستی انتخاب کنید.','error');}
    }));
  }

  function delegatedActions() {
    if (isDeveloperAdmin) {
    $('#modelGrid').addEventListener('click',event=>{
      const button=event.target.closest('[data-select-model]');
      if(!button)return;
      const kind=button.dataset.selectKind,id=button.dataset.selectModel;
      const select=$$('.model-select[data-kind="'+kind+'"]').find(node=>node);
      if(select)select.value=id;
      toast('مدل در اولین ابزار '+kind+' انتخاب شد.','success');
    });
    $('#userRows').addEventListener('click',async event=>{
      const button=event.target.closest('[data-user-status]');
      if(!button)return;
      const id=Number(button.dataset.userStatus);
      const active=button.dataset.active!=='1';
      button.disabled=true;
      try {
        await api('/users/'+id+'/status',{method:'POST',body:json({active})});
        toast(active?'حساب کاربر فعال شد.':'حساب کاربر غیرفعال شد.','success');
        await loadUsers();
      } catch(error) {
        toast(error.message,'error');
        button.disabled=false;
      }
    });

    }

    $('#bankQuestionRows').addEventListener('click',async event=>{
      const button=event.target.closest('[data-delete-bank-question]');
      if(!button)return;
      const id=Number(button.dataset.deleteBankQuestion);
      if(!Number.isSafeInteger(id)||id<1||!confirm('این سؤال از بانک حذف شود؟'))return;
      button.disabled=true;
      try {
        await api('/questions/'+id,{method:'DELETE'});
        toast('سؤال از بانک حذف شد.','success');
        await loadQuestionBank();
      } catch(error) {
        toast(error.message,'error');
        button.disabled=false;
      }
    });

    if (isDeveloperAdmin) {
    $('#promptSelect').addEventListener('change',()=>{
      showPrompt(state.prompts.find(row=>row.slug===$('#promptSelect').value));
    });
    $('#promptForm').addEventListener('submit',async event=>{
      event.preventDefault();
      const form=$('#promptForm');
      const data=payloadFromForm(form);
      setBusy(form,true,'در حال ذخیره قالب...');
      try {
        const result=await api('/prompts',{method:'POST',body:json(data)});
        setMessage('#promptMessage',result.message,'success');
        await loadPrompts();
      } catch(error) {
        setMessage('#promptMessage',error.message,'error');
      } finally {
        setBusy(form,false);
      }
    });

    $('#historyList').addEventListener('click',async event=>{
      const button=event.target.closest('[data-history-copy]'); if(!button)return;
      const row=(state.history||[]).find(item=>Number(item.id)===Number(button.dataset.historyCopy)); if(!row)return;
      try{await navigator.clipboard.writeText(row.output||'');toast('خروجی کپی شد.','success');}catch{toast('کپی خروجی انجام نشد.','error');}
    });
    const createAdminForm=$('#adminCreateForm');
    if(createAdminForm) createAdminForm.addEventListener('submit',async event=>{
      event.preventDefault(); const form=createAdminForm,data=payloadFromForm(form); setBusy(form,true,'در حال ایجاد حساب...');
      try{const result=await api('/users',{method:'POST',body:json(data)});form.reset();setMessage('#adminCreateMessage',result.message||'حساب ساخته شد.','success');toast('حساب ادمین ساخته شد.','success');await loadUsers();}
      catch(error){setMessage('#adminCreateMessage',error.message,'error');toast(error.message,'error');}finally{setBusy(form,false);}
    });
    }
  }

  function initNavigation() {
    $('#mobileMenu').addEventListener('click',()=>$('#sidebar').classList.toggle('open'));
    $('#globalRefresh').addEventListener('click',()=>showPage(state.currentPage));
    $('#refreshDashboard').addEventListener('click',loadDashboard);
    if (!isDeveloperAdmin) return;
    $('#refreshModels').addEventListener('click',()=>loadCatalog($('#page-models .catalog-tab.active')?.dataset.catalog||'models',true));
    $('#modelSearch').addEventListener('input',()=>renderCatalog($('#page-models .catalog-tab.active')?.dataset.catalog||'models'));
    $('#loadUsage').addEventListener('click',loadUsage);
    $('#refreshHistory').addEventListener('click',loadHistory);
    $('#refreshUsers').addEventListener('click',loadUsers);
    $('#userSearch').addEventListener('input',()=>loadUsers());
  }

  async function init() {
    const iconMap={dashboard:'fa-gauge-high',models:'fa-cubes',questions:'fa-wand-magic-sparkles',practice:'fa-list-check',review:'fa-file-pen',playground:'fa-terminal',multimodal:'fa-photo-film',structured:'fa-code',batches:'fa-layer-group',usage:'fa-chart-line',history:'fa-clock-rotate-left','question-bank':'fa-database',users:'fa-users',students:'fa-user-graduate',resources:'fa-book',exams:'fa-file-circle-check',attempts:'fa-clipboard-check',monitoring:'fa-chart-area',tracks:'fa-route',activities:'fa-list','activity-submissions':'fa-inbox',prompts:'fa-message',settings:'fa-gear'};
    $('.nav-item').forEach(n=>{const ic=$('i',n);if(ic){ic.className='fa-solid '+(iconMap[n.dataset.page]||'fa-circle');ic.textContent='';}});
    const splash=$('#parseStartup');if(splash){const mark=$('.mark',splash);if(mark){mark.innerHTML='<img src="/assets/Parseh_Language_Academy.png" alt="Parseh Language Academy">';}let dots=$('.dots',splash);if(!dots){dots=document.createElement('div');dots.className='dots';dots.innerHTML='<span></span><span></span><span></span>';splash.appendChild(dots);}setTimeout(()=>{splash.classList.add('done');splash.style.opacity='0';splash.style.visibility='hidden';splash.style.pointerEvents='none';setTimeout(()=>splash.remove(),450);},5000);}
    const initial=new URL(location.href).searchParams.get('page');if(initial&&$('#page-'+initial))showPage(initial,undefined,false);
    window.addEventListener('popstate',()=>{const p=new URL(location.href).searchParams.get('page')||'dashboard';if($('#page-'+p))showPage(p,undefined,false);});

    bindPlatformForms();
    bindPlatformTables();
    initPlatformNavigation();
    const label=new Date().toLocaleDateString('fa-IR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
    const todayLabel=$('#todayLabel'); if(todayLabel) todayLabel.textContent=label;
    initNavigation();
    activateTabs();
    delegatedActions();
    initForms();
    try {
      state.bootstrap=await api('/bootstrap');
      await loadDashboard();
      if (isDeveloperAdmin && state.bootstrap.provider_configured) {
        await Promise.allSettled([loadCatalog('models'),loadCatalog('decisions'),loadCatalog('images'),loadCatalog('audio'),loadCatalog('speech'),loadCatalog('embeddings')]);
      } else if (isDeveloperAdmin) {
        toast('برای فعال شدن ابزارهای هوش مصنوعی، ابتدا کلید سرویس را در تنظیمات ثبت کنید.','info');
      }
    } catch(error) {
      toast('خطا در راه‌اندازی کنسول: '+error.message,'error');
    }
  }

  init();
})();

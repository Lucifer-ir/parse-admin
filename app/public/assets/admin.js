'use strict';

(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const csrf = $('meta[name="csrf-token"]')?.content || '';
  const state = { catalogs: {}, lastQuestionOutput: '', bootstrap: null, currentPage: 'dashboard', users: [], questions: [], prompts: [] };
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

  function showPage(page, label) {
    const titles = {
      dashboard:'داشبورد', models:'کاتالوگ مدل‌ها', questions:'تولید سؤال', practice:'طراحی تمرین',
      review:'بررسی و ویرایش متن', playground:'AI Playground', multimodal:'تصویر و صوت',
      structured:'تصمیم و Embedding', batches:'Batch و رسید درخواست',
      usage:'مصرف و هزینه‌ها', history:'تاریخچه تولید', settings:'تنظیمات اتصال',
      'question-bank':'بانک سؤال‌ها', users:'مدیریت کاربران', prompts:'قالب‌های دستور AI'
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
    if (page === 'settings') loadSettings();
    if (page === 'question-bank') loadQuestionBank();
    if (page === 'users') loadUsers();
    if (page === 'prompts') loadPrompts();
    if (page === 'questions' || page === 'practice' || page === 'review' || page === 'playground') {
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
    try {
      const [data, boot] = await Promise.all([api('/dashboard'), api('/bootstrap')]);
      state.bootstrap = boot;
      $('#todayCost').textContent = money(data.today?.cost);
      $('#todayRequests').textContent = number(data.today?.requests) + ' درخواست';
      $('#monthCost').textContent = money(data.month?.cost);
      $('#monthRequests').textContent = number(data.month?.requests) + ' درخواست';
      $('#monthTokens').textContent = number(data.month?.tokens);
      $('#allRequests').textContent = number(data.totals?.requests) + ' درخواست کل';
      $('#modelCount').textContent = number((data.models || []).length);
      $('#userCount').textContent = number(data.users) + ' کاربر';
      $('#providerBadge').textContent = boot.provider_configured ? 'کلید ثبت شده' : 'نیازمند تنظیم';
      $('#providerBadge').classList.toggle('success', !!boot.provider_configured);
      $('#providerStateText').textContent = boot.provider_configured ? 'کلید API رمزگذاری‌شده در سرور ثبت شده است.' : 'برای فعال‌سازی قابلیت‌های هوش مصنوعی، کلید را ثبت کنید.';
      $('#providerMask').textContent = boot.provider_configured ? '••••••••••••••••' : 'پیکربندی نشده';
      const models = data.models || [];
      $('#modelUsage').innerHTML = models.length ? models.map(model => {
        const total = Math.max(1, ...models.map(x => Number(x.requests || 0)));
        const percent = Math.max(3, Math.round(Number(model.requests || 0) / total * 100));
        return '<div class="usage-model-row"><div><b>' + escapeHtml(model.model || 'مدل نامشخص') + '</b><span>' + number(model.requests) + ' درخواست · ' + money(model.cost) + ' تومان</span></div><div class="usage-bar"><i style="width:' + percent + '%"></i></div></div>';
      }).join('') : '<div class="empty-state compact-empty">هنوز درخواست ثبت نشده است.</div>';
      $('#recentUsage').innerHTML = (data.recent || []).length ? data.recent.map(row => '<tr><td>' + escapeHtml(row.operation) + '</td><td dir="ltr">' + escapeHtml(row.model || '—') + '</td><td><span class="table-status ' + (Number(row.status_code) < 400 ? 'ok' : 'bad') + '">' + number(row.status_code) + '</span></td><td>' + number(row.total_tokens) + '</td><td>' + money(row.charged_toman) + '</td><td>' + escapeHtml(new Date(row.created_at).toLocaleString('fa-IR')) + '</td></tr>').join('') : '<tr><td colspan="6" class="empty-cell">هیچ درخواست هوش مصنوعی از این پنل ثبت نشده است.</td></tr>';
    } catch (error) {
      toast('خطا در دریافت داشبورد: ' + error.message, 'error');
    }
  }

  function displayText(selector, text) {
    const node = $(selector);
    node.textContent = text || 'پاسخی از مدل دریافت نشد.';
  }

  function renderUsageDetails(selector, result) {
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
      if(!data.model||!data.message) return toast('مدل و پیام را وارد کنید.','error');
      $('#chatState').textContent='Responses API';
      setMessage('#chatMessage','در حال ارسال به Responses API...');
      try {
        const result=await api('/ai/responses',{method:'POST',body:json({
          model:data.model,input:data.responses_input || data.message,instructions:data.system,
          max_output_tokens:Number(data.max_tokens||1024),
          save_history:data.save_history,web_search:!!payloadFromForm($('#chatForm')).web_search,
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
        const result=await api('/ai/messages',{method:'POST',body:json({
          model:data.model,message:data.message,messages:data.messages,system:data.system,
          max_tokens:Number(data.max_tokens||1024),pdf_processing:data.pdf_processing
        })});
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
      event.preventDefault();const form=$('#imageEditForm');const body=new FormData(form);setBusy(form,true,'در حال ویرایش...');
      try{const result=await api('/ai/image-edit',{method:'POST',body});$('#imageEditOutput').textContent=formatObject(result.data);setMessage('#imageEditMessage','درخواست ویرایش انجام شد.','success');}
      catch(error){setMessage('#imageEditMessage',error.message,'error');}
      finally{setBusy(form,false);}
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
      try{const result=await api('/ai/batches',{method:'POST',body:json(data)});$('#batchOutput').textContent=formatObject(result.data);setMessage('#batchMessage','Batch ثبت شد.','success');}
      catch(error){setMessage('#batchMessage',error.message,'error');}
      finally{setBusy($('#batchForm'),false);}
    });

    $('#imageBatchForm').addEventListener('submit',async event=>{
      event.preventDefault();const data=payloadFromForm($('#imageBatchForm'));const prompts=String(data.prompts||'').split(/\n+/).map(x=>x.trim()).filter(Boolean);if(!prompts.length||prompts.length>10)return setMessage('#imageBatchMessage','بین ۱ تا ۱۰ شرح تصویر وارد کنید.','error');
      data.requests=prompts.map((prompt,index)=>({custom_id:'img-'+Date.now()+'-'+(index+1),type:'generation',body:{prompt}}));setBusy($('#imageBatchForm'),true,'در حال ثبت...');
      try{const result=await api('/ai/image-batches',{method:'POST',body:json(data)});$('#imageBatchOutput').textContent=formatObject(result.data);setMessage('#imageBatchMessage','Batch تصویر ثبت شد.','success');}
      catch(error){setMessage('#imageBatchMessage',error.message,'error');}
      finally{setBusy($('#imageBatchForm'),false);}
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

  async function loadUsage() {
    const now=new Date();const first=new Date(now.getFullYear(),now.getMonth(),1);
    if(!$('#usageFrom').value) $('#usageFrom').value=first.toISOString().slice(0,10);
    if(!$('#usageTo').value) $('#usageTo').value=now.toISOString().slice(0,10);
    const from=$('#usageFrom').value,to=$('#usageTo').value;
    try{
      const result=await api('/usage?from='+encodeURIComponent(from)+'&to='+encodeURIComponent(to));
      $('#usageRequests').textContent=number(result.summary.requests);
      $('#usageTokens').textContent=number(result.summary.tokens);
      $('#usageCost').textContent=money(result.summary.cost);
      $('#usageRows').innerHTML=result.rows.length?result.rows.map(row=>'<tr><td>'+escapeHtml(new Date(row.created_at).toLocaleString('fa-IR'))+'</td><td>'+escapeHtml(row.operation)+'</td><td dir="ltr">'+escapeHtml(row.model||'—')+'</td><td><span class="table-status '+(Number(row.status_code)<400?'ok':'bad')+'">'+number(row.status_code)+'</span></td><td>'+number(row.input_tokens)+'</td><td>'+number(row.output_tokens)+'</td><td>'+money(row.charged_toman)+'</td><td>'+number(row.latency_ms)+' ms</td></tr>').join(''):'<tr><td colspan="8" class="empty-cell">در این بازه گزارشی ثبت نشده است.</td></tr>';
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
        return '<tr><td>'+number(row.id)+'</td><td dir="ltr">'+escapeHtml(row.email)+'</td><td>'+escapeHtml(row.role)+'</td><td><span class="table-status '+(active?'ok':'bad')+'">'+(active?'فعال':'غیرفعال')+'</span></td><td>'+escapeHtml(new Date(row.created_at).toLocaleDateString('fa-IR'))+'</td><td><button class="button button-secondary" data-user-status="'+Number(row.id)+'" data-active="'+(active?'1':'0')+'">'+(active?'غیرفعال‌سازی':'فعال‌سازی')+'</button></td></tr>';
      }).join(''):'<tr><td colspan="6" class="empty-cell">کاربری پیدا نشد.</td></tr>';
    } catch(error) {
      $('#userRows').innerHTML='<tr><td colspan="6" class="empty-cell">'+escapeHtml(error.message)+'</td></tr>';
      toast(error.message,'error');
    }
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
      const button=event.target.closest('[data-history-copy]');
      if(!button)return;
      const row=(state.history||[]).find(item=>Number(item.id)===Number(button.dataset.historyCopy));
      if(!row)return;
      try{await navigator.clipboard.writeText(row.output||'');toast('خروجی کپی شد.','success');}
      catch{toast('کپی خروجی انجام نشد.','error');}
    });
  }

  function initNavigation() {
    $('#mobileMenu').addEventListener('click',()=>$('#sidebar').classList.toggle('open'));
    $('#globalRefresh').addEventListener('click',()=>showPage(state.currentPage));
    $('#refreshDashboard').addEventListener('click',loadDashboard);
    $('#refreshModels').addEventListener('click',()=>loadCatalog($('#page-models .catalog-tab.active')?.dataset.catalog||'models',true));
    $('#modelSearch').addEventListener('input',()=>renderCatalog($('#page-models .catalog-tab.active')?.dataset.catalog||'models'));
    $('#loadUsage').addEventListener('click',loadUsage);
    $('#refreshHistory').addEventListener('click',loadHistory);
    $('#refreshQuestionBank').addEventListener('click',loadQuestionBank);
    $('#refreshUsers').addEventListener('click',loadUsers);
    $('#bankQuestionSearch').addEventListener('input',()=>loadQuestionBank());
    $('#userSearch').addEventListener('input',()=>loadUsers());
  }

  async function init() {
    const label=new Date().toLocaleDateString('fa-IR',{weekday:'long',year:'numeric',month:'long',day:'numeric'});
    $('#todayLabel').textContent=label;
    initNavigation();
    activateTabs();
    delegatedActions();
    initForms();
    try {
      state.bootstrap=await api('/bootstrap');
      await loadDashboard();
      if (state.bootstrap.provider_configured) {
        await Promise.allSettled([loadCatalog('models'),loadCatalog('decisions'),loadCatalog('images'),loadCatalog('audio'),loadCatalog('speech'),loadCatalog('embeddings')]);
      } else {
        toast('برای فعال شدن ابزارهای هوش مصنوعی، ابتدا کلید سرویس را در تنظیمات ثبت کنید.','info');
      }
    } catch(error) {
      toast('خطا در راه‌اندازی کنسول: '+error.message,'error');
    }
  }

  init();
})();

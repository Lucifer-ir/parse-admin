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
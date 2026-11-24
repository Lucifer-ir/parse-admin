<section class="page-view" id="page-question-bank">
      <div class="page-heading"><div><div class="eyebrow">STRUCTURED QUESTION LIBRARY</div><h1>بانک هوشمند سؤال</h1><p>تولید ساختاریافته از فایل منبع، ذخیره دائمی و استفاده مجدد در تمرین و آزمون.</p></div><button class="button button-ghost" id="refreshQuestionBank">↻ به‌روزرسانی</button></div>
      <div class="workspace-grid">
        <form class="panel-card form-panel" id="structuredQuestionForm">
          <div class="panel-heading"><div><h3>ساخت سؤال از منبع</h3><p>مدل، متن منبع و نوع سؤال را مشخص کن.</p></div><span class="tag tag-ai">AI</span></div>
          <label>فایل متنی / جزوه<select class="input" name="resource_id" id="questionResource" required><option value="">انتخاب منبع آموزشی</option></select></label>
          <?php if ($isDeveloperAdmin): ?><label>مدل<select class="input model-select" name="model" data-kind="models" required><option value="">انتخاب مدل</option></select></label><?php endif; ?>
          <label>درس / رشته<input class="input" name="subject" placeholder="مثلاً زبان انگلیسی" required></label>
          <label>مسیر آموزشی<select class="input" name="track_id" required><option value="">انتخاب مسیر، زبان، ترم و سطح</option></select></label>
          <p class="muted-label">سطح سؤال از سطح مسیر آموزشی انتخاب‌شده تعیین می‌شود.</p>
          <label>نشانی فایل صوتی سؤال (اختیاری)<input class="input" name="audio_url" type="url" dir="ltr" placeholder="https://example.com/audio.mp3"></label>
          <div class="form-two"><label>مهارت<select class="input" name="skill"><option value="general">عمومی</option><option value="listening">Listening</option><option value="speaking">Speaking</option><option value="reading">Reading</option><option value="writing">Writing</option><option value="grammar">Grammar</option><option value="vocabulary">Vocabulary</option></select></label><label>نوع سؤال<select class="input" name="question_type"><option value="multiple_choice">چهارگزینه‌ای</option><option value="written">تشریحی</option><option value="true_false">درست / نادرست</option><option value="fill_blank">جای خالی</option><option value="matching">وصل‌کردنی</option><option value="ordering">مرتب‌سازی</option><option value="listening">شنیداری</option><option value="speaking">گفتاری</option></select></label></div>
          <div class="form-two"><label>تعداد سؤال<input class="input" type="number" name="count" value="10" min="1" max="40"></label><label>سطح دشواری<select class="input" name="difficulty"><option value="easy">آسان</option><option value="medium" selected>متوسط</option><option value="hard">سخت</option></select></label></div>
          <label>اختصاص به دانش‌آموز<select class="input" name="target_student_id" id="questionTargetStudent"><option value="">بانک عمومی</option></select></label>
          <button class="button button-primary full-button" type="submit">ساخت و ذخیره در بانک سؤال</button><div class="form-message" id="structuredQuestionMessage"></div>
        </form>
        <section class="panel-card result-panel"><div class="panel-heading"><div><h3>خروجی ساختاریافته</h3><p>گزینه‌ها، پاسخ صحیح، توضیح و بارم به تفکیک هر سؤال ذخیره می‌شوند.</p></div></div><div id="structuredQuestionOutput" class="output-area">برای تولید، یک منبع متنی انتخاب کن.</div></section>
      </div>
      <section class="panel-card table-panel">
        <div class="filter-row"><input id="bankQuestionSearch" class="input" placeholder="جست‌وجوی متن سؤال یا درس..."><span id="bankQuestionCount" class="muted-label"></span></div>
        <div class="table-scroll"><table><thead><tr><th>شناسه</th><th>سؤال</th><th>نوع</th><th>درس / سطح</th><th>دانش‌آموز</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="bankQuestionRows"><tr><td colspan="7" class="empty-cell">در حال دریافت...</td></tr></tbody></table></div>
      </section>
    </section>
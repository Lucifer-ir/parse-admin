<section class="page-view" id="page-tracks">
      <div class="page-heading"><div><div class="eyebrow">LEARNING STRUCTURE</div><h1>زبان و مسیرهای آموزشی</h1><p>زبان، رشته، دوره و سطح را یک‌بار تعریف کن و دانش‌آموز، کتاب، تمرین و آزمون را به آن متصل کن.</p></div><button class="button button-ghost" id="refreshTracks">↻ به‌روزرسانی</button></div>
      <div class="workspace-grid">
        <form class="panel-card form-panel" id="trackCreateForm"><h3>تعریف مسیر آموزشی</h3>
          <label>زبان<select class="input" name="language_code" id="trackLanguage" required><option value="en">انگلیسی</option><option value="de">آلمانی</option><option value="fr">فرانسوی</option></select></label>
          <label>رشته / مسیر<input class="input" name="track_title" placeholder="زبان عمومی" required></label>
          <label>ترم / دوره آموزشی<input class="input" name="course_title" placeholder="ترم یک" required></label>
          <div class="form-two"><label>سطح<select class="input" name="level_code" id="trackLevel" required></select></label><label>نوع کلاس<select class="input" name="class_type_id" id="trackClassType" required><option value="">انتخاب نوع کلاس</option></select></label></div>
          <label>توضیحات<textarea class="input textarea" name="description" rows="2"></textarea></label>
          <button class="button button-primary full-button" type="submit">ذخیره مسیر آموزشی</button><div class="form-message" id="trackCreateMessage"></div>
        </form>
        <section class="panel-card result-panel"><h3>نحوه تخصیص</h3><div class="security-row"><i>1</i><span><b>تعریف ساختار</b><small>هر مسیر شامل زبان، رشته، ترم یا دوره، سطح و نوع کلاس مشخص است.</small></span></div><div class="security-row"><i>2</i><span><b>اتصال دانش‌آموز</b><small>حساب دانش‌آموز را به مسیر ثبت‌شده وصل کن.</small></span></div><div class="security-row"><i>3</i><span><b>محتوای متناسب</b><small>کتاب، تمرین و آزمون می‌توانند بر اساس مسیر و سطح فیلتر شوند.</small></span></div></section>
      </div>
      <section class="panel-card" style="margin-top:16px">
        <h3>تعریف نوع کلاس</h3>
        <form id="classTypeCreateForm" class="form-panel">
          <div class="form-two"><label>شناسه انگلیسی<input class="input" name="code" placeholder="hybrid" dir="ltr" required></label><label>عنوان نوع کلاس<input class="input" name="title" placeholder="کلاس ترکیبی" required></label></div>
          <label>توضیحات<input class="input" name="description"></label>
          <button class="button button-primary" type="submit">افزودن نوع کلاس</button><div id="classTypeMessage" class="form-message"></div>
        </form>
        <div class="table-scroll"><table><thead><tr><th>نوع کلاس</th><th>شناسه</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="classTypeRows"></tbody></table></div>
      </section>
      <section class="panel-card" style="margin-top:16px">
        <h3>مدیریت سطح‌های آموزشی</h3>
        <p class="muted-label">سطح‌ها را یک‌بار برای هر زبان تعریف کن؛ سپس هنگام ساخت مسیر و تغییر سطح دانش‌آموز از فهرست آماده انتخاب می‌شوند.</p>
        <form id="levelCreateForm" class="form-panel">
          <div class="form-two"><label>زبان<select class="input" name="language_code" id="levelLanguage" required></select></label><label>کد سطح<input class="input" name="level_code" placeholder="A3" dir="ltr" required></label></div>
          <div class="form-two"><label>عنوان سطح<input class="input" name="level_name" placeholder="Upper Elementary" required></label><label>ترتیب نمایش<input class="input" name="level_order" type="number" min="1" max="999" value="100" required></label></div>
          <button class="button button-primary" type="submit">افزودن سطح</button><div id="levelMessage" class="form-message"></div>
        </form>
        <div class="table-scroll"><table><thead><tr><th>زبان</th><th>کد سطح</th><th>عنوان</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="levelRows"></tbody></table></div>
      </section>
      <section class="panel-card table-panel"><div class="table-scroll"><table><thead><tr><th>زبان</th><th>رشته</th><th>دوره</th><th>سطح</th><th>نوع کلاس</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="trackRows"><tr><td colspan="7" class="empty-cell">در حال دریافت...</td></tr></tbody></table></div></section>
    </section>
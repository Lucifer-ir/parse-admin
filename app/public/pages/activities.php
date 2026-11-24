<section class="page-view" id="page-activities">
      <div class="page-heading"><div><div class="eyebrow">PRACTICE & ASSIGNMENTS</div><h1>تمرین‌ها و تکالیف</h1><p>از بانک سؤال یک تمرین بساز، مسیر یا سطح هدف و مهلت انجام را انتخاب کن.</p></div><button class="button button-ghost" id="refreshActivities">↻ به‌روزرسانی</button></div>
      <div class="workspace-grid">
        <form class="panel-card form-panel" id="activityCreateForm"><h3>ساخت تمرین جدید</h3>
          <label>عنوان<input class="input" name="title" required></label>
          <div class="form-two"><label>نوع<select class="input" name="activity_type"><option value="practice">تمرین</option><option value="assignment">تکلیف</option></select></label><label>وضعیت<select class="input" name="status"><option value="draft">پیش‌نویس</option><option value="published">انتشار</option></select></label></div>
          <label>درس<input class="input" name="subject"></label>
          <label>مسیر آموزشی<select class="input" name="track_id" id="activityTrack"><option value="">بدون محدودیت مسیر</option></select></label>
          <p class="muted-label">سطح تمرین و گروه هدف از مسیر انتخاب‌شده دریافت می‌شود.</p>
          <label>دانش‌آموز مشخص (اختیاری)<select class="input" name="target_student_id" id="activityTargetStudent"><option value="">تخصیص بر اساس سطح / مسیر</option></select></label>
          <label>مهلت انجام<input class="input" name="due_at" type="datetime-local"></label>
          <label>شناسه سؤال‌های منتشرشده در بانک (با کاما جدا شود)<input class="input" name="question_ids" placeholder="12, 18, 24" required></label>
          <label>دستورالعمل<textarea class="input textarea" name="instructions" rows="3"></textarea></label>
          <button class="button button-primary full-button" type="submit">ساخت و تخصیص تمرین</button><div class="form-message" id="activityCreateMessage"></div>
        </form>
        <section class="panel-card result-panel"><h3>وضعیت تکالیف</h3><div class="security-row"><i>✓</i><span><b>قابل استفاده مجدد</b><small>سؤال‌ها از بانک سؤال لینک می‌شوند؛ سؤال تکراری لازم نیست دوباره ساخته شود.</small></span></div><div class="security-row"><i>◷</i><span><b>مهلت انجام</b><small>می‌توان برای هر تمرین تاریخ و ساعت پایان تعیین کرد.</small></span></div><div class="security-row"><i>✎</i><span><b>تصحیح</b><small>سؤال‌های عینی خودکار و پاسخ تشریحی با بررسی مدرس نمره‌دهی می‌شوند.</small></span></div></section>
      </div>
      <section class="panel-card table-panel"><div class="table-scroll"><table><thead><tr><th>عنوان</th><th>نوع</th><th>درس / سطح</th><th>سؤال</th><th>دانش‌آموز</th><th>ارسال</th><th>مهلت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="activityRows"><tr><td colspan="9" class="empty-cell">در حال دریافت...</td></tr></tbody></table></div></section>
    </section>
<section class="page-view" id="page-exams">
      <div class="page-heading"><div><div class="eyebrow">EXAM BUILDER</div><h1>طراحی آزمون و زمان‌بندی</h1><p>آزمون زمان‌دار را از سؤال‌های بانک بساز و به یک دانش‌آموز یا همه سطح مربوط اختصاص بده.</p></div><button class="button button-ghost" id="refreshExams">↻ به‌روزرسانی</button></div>
      <div class="workspace-grid">
        <form class="panel-card form-panel" id="examCreateForm"><h3>آزمون جدید</h3>
          <label>عنوان آزمون<input class="input" name="title" required></label>
          <label>درس<input class="input" name="subject"></label>
          <label>مسیر آموزشی<select class="input" name="track_id"><option value="">بدون محدودیت مسیر</option></select></label>
          <p class="muted-label">سطح آزمون از سطح مسیر آموزشی انتخاب‌شده تعیین می‌شود.</p>
          <div class="form-two"><label>مدت آزمون (دقیقه)<input class="input" name="duration_minutes" type="number" min="1" max="600" value="30"></label><label>نمره قبولی (%)<input class="input" name="passing_score" type="number" min="0" max="100" value="50"></label></div>
          <div class="form-two"><label>شروع (اختیاری)<input class="input" name="starts_at" type="datetime-local"></label><label>پایان (اختیاری)<input class="input" name="ends_at" type="datetime-local"></label></div>
          <label>اختصاص به دانش‌آموز<select class="input" name="target_student_id" id="examTargetStudent"><option value="">تمام دانش‌آموزان سطح انتخاب‌شده</option></select></label>
          <div class="form-two"><label>روش انتخاب سؤال<select class="input" name="question_selection"><option value="manual">انتخاب دستی شناسه سؤال</option><option value="automatic">انتخاب خودکار از بانک</option></select></label><label>تعداد سؤال در انتخاب خودکار<input class="input" type="number" name="question_count" min="1" max="100" value="10"></label></div><label id="examManualQuestions">شناسه سؤال‌های بانک (با کاما جدا شود)<input class="input" name="question_ids" placeholder="12, 18, 24"></label><label id="examAutoLevel">سطح موردنظر (اختیاری)<input class="input" name="question_level" placeholder="A1, A2, B1, B2, C1, C2"></label><p class="muted-label">در انتخاب خودکار، سؤال‌های قبلاً استفاده‌شده در آزمون‌های دیگر همین مسیر تا حد امکان کنار گذاشته می‌شوند.</p>
          <div class="form-two"><label class="check-row"><input type="checkbox" name="shuffle_questions"><span>ترتیب تصادفی سؤال‌ها</span></label><label class="check-row"><input type="checkbox" name="shuffle_options"><span>ترتیب تصادفی گزینه‌ها</span></label></div>
          <label>وضعیت<select class="input" name="status"><option value="draft">پیش‌نویس</option><option value="published">منتشرشده</option></select></label>
          <button class="button button-primary full-button" type="submit">ساخت آزمون</button><div class="form-message" id="examCreateMessage"></div>
        </form>
        <section class="panel-card result-panel"><h3>کنترل زمان و تصحیح</h3><div class="security-row"><i>◷</i><span><b>زمان سمت سرور</b><small>مهلت آزمون در API کنترل می‌شود و با بستن اپ از نو آغاز نمی‌شود.</small></span></div><div class="security-row"><i>✓</i><span><b>تصحیح خودکار</b><small>چهارگزینه‌ای، درست/نادرست و جای‌خالی با کلید پاسخ نمره می‌گیرند.</small></span></div><div class="security-row"><i>✎</i><span><b>تصحیح دستی</b><small>تشریحی و پاسخ‌های گفتاری نیازمند بررسی مدرس هستند.</small></span></div></section>
      </div>
      <section class="panel-card table-panel"><div class="table-scroll"><table><thead><tr><th>عنوان</th><th>درس / سطح</th><th>تعداد سؤال</th><th>زمان</th><th>شروع / پایان</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="examRows"><tr><td colspan="7" class="empty-cell">در حال دریافت...</td></tr></tbody></table></div></section>
    </section>
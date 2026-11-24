<section class="page-view" id="page-students">
      <div class="page-heading"><div><div class="eyebrow">STUDENT ACCOUNTS</div><h1>مدیریت دانش‌آموزان</h1><p>ایجاد حساب، تعیین رمز، سطح، گروه و کنترل دسترسی به اپلیکیشن.</p></div><button class="button button-ghost" id="refreshStudents">↻ به‌روزرسانی</button></div>
      <div class="workspace-grid">
        <form class="panel-card form-panel" id="studentCreateForm"><h3>ایجاد دانش‌آموز جدید</h3>
          <label>نام و نام خانوادگی<input class="input" name="name" required></label>
          <div class="form-two"><label>نام کاربری<input class="input" name="username" required minlength="3"></label><label>کد دانش‌آموزی<input class="input" name="student_code"></label></div>
          <div class="form-two"><label>شماره تماس<input class="input" name="phone" dir="ltr"></label><label>ایمیل<input class="input" name="email" type="email" dir="ltr"></label></div>
          <label>کلاس / گروه<input class="input" name="group_name" placeholder="مثلاً گروه عصر"></label><p class="muted-label">سطح و زبان از مسیر آموزشی انتخاب‌شده تعیین می‌شود.</p>
          <label>مسیر آموزشی<select class="input" name="track_id" required><option value="">انتخاب مسیر، زبان و سطح</option></select></label>
          <label>رمز عبور اولیه<input class="input" name="password" type="password" minlength="8" autocomplete="new-password" required></label>
          <button class="button button-primary full-button" type="submit">ساخت حساب دانش‌آموز</button><div class="form-message" id="studentCreateMessage"></div>
        </form>
        <section class="panel-card result-panel"><h3>راهنمای حساب</h3><div class="security-row"><i>✓</i><span><b>ورود با نام کاربری</b><small>دانش‌آموز با نام کاربری و رمز تعیین‌شده در اپ وارد می‌شود.</small></span></div><div class="security-row"><i>✓</i><span><b>تغییر یا بازیابی رمز</b><small>مدیر می‌تواند رمز جدید تعیین کند؛ نشست‌های قبلی باطل می‌شوند.</small></span></div><div class="security-row"><i>✓</i><span><b>سطح و گروه</b><small>تخصیص محتوا و آزمون‌ها بر اساس سطح و گروه قابل انجام است.</small></span></div></section>
      </div>
      <section class="panel-card table-panel"><div class="filter-row"><input id="studentSearch" class="input" placeholder="جست‌وجوی نام، نام کاربری، شماره یا کد..."><select id="studentStatusFilter" class="input"><option value="">همه وضعیت‌ها</option><option value="active">فعال</option><option value="disabled">غیرفعال</option><option value="pending">در انتظار</option></select><span id="studentCount" class="muted-label"></span></div><div class="table-scroll"><table><thead><tr><th>شناسه</th><th>نام</th><th>نام کاربری</th><th>مسیر / زبان / ترم / سطح / گروه</th><th>آخرین فعالیت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody id="studentRows"><tr><td colspan="7" class="empty-cell">در حال دریافت...</td></tr></tbody></table></div></section>
      <dialog class="panel-card" id="studentEditDialog" style="width:min(560px,calc(100% - 28px));border:0;border-radius:16px;padding:24px;box-shadow:0 24px 80px #102b2540">
        <form id="studentEditForm" class="form-panel">
          <div class="panel-heading"><div><h3>ویرایش دانش‌آموز</h3><p id="studentEditMeta" class="muted-label"></p></div></div>
          <input type="hidden" name="student_id">
          <label>نام و نام خانوادگی<input class="input" name="name" required></label>
          <div class="form-two"><label>شماره تماس<input class="input" name="phone" dir="ltr"></label><label>ایمیل<input class="input" name="email" type="email" dir="ltr"></label></div>
          <div class="form-two"><label>کد دانش‌آموزی<input class="input" name="student_code"></label><label>کلاس / گروه<input class="input" name="group_name"></label></div>
          <label>مسیر / زبان / ترم / سطح<select class="input" name="track_id" id="studentEditTrack" required><option value="">انتخاب مسیر آموزشی</option></select></label>
          <div class="form-message" id="studentEditMessage"></div>
          <div class="form-two"><button type="button" class="button button-secondary" id="cancelStudentEdit">انصراف</button><button type="submit" class="button button-primary">ذخیره تغییرات</button></div>
        </form>
      </dialog>
    </section>
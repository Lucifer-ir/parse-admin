<section class="page-view" id="page-users">
      <div class="page-heading"><div><div class="eyebrow">USER & ACCESS MANAGEMENT</div><h1>مدیریت کاربران</h1><p>مشاهده حساب‌ها و کنترل وضعیت فعال یا غیرفعال آن‌ها.</p></div><button class="button button-ghost" id="refreshUsers">↻ به‌روزرسانی</button></div>
      <section class="panel-card form-panel" style="margin-bottom:16px"><div class="panel-heading"><div><h3>ایجاد حساب مدیر</h3><p>نوع دسترسی هر مدیر هنگام ساخت حساب مشخص می‌شود.</p></div></div>
        <form id="adminCreateForm"><div class="form-two"><label>ایمیل مدیر<input class="input" type="email" name="email" required maxlength="190" placeholder="admin@example.com"></label><label>رمز عبور اولیه<input class="input" type="password" name="password" minlength="14" required placeholder="حداقل ۱۴ کاراکتر"></label></div>
          <label>نوع ادمین<select class="input" name="role" required><option value="education_admin">ادمین آموزشی — آزمون‌ساز و مدیریت آموزشی</option><option value="developer_admin">ادمین برنامه‌نویس — دسترسی کامل سیستم</option></select></label>
          <div class="inline-actions"><button class="button button-primary" type="submit">ایجاد حساب مدیر</button><span class="form-message" id="adminCreateMessage"></span></div></form></section>
      <section class="panel-card table-panel">
        <div class="filter-row"><input id="userSearch" class="input" placeholder="جست‌وجوی ایمیل کاربر..."><span id="userCount" class="muted-label"></span></div>
        <div class="table-scroll"><table><thead><tr><th>شناسه</th><th>ایمیل</th><th>نقش</th><th>وضعیت</th><th>تاریخ عضویت</th><th>عملیات</th></tr></thead><tbody id="userRows"><tr><td colspan="6" class="empty-cell">در حال دریافت...</td></tr></tbody></table></div>
      </section>
      <p class="notice">برای جلوگیری از قفل‌شدن پنل، غیرفعال‌کردن حساب مدیر فعلی مجاز نیست.</p>
    </section>
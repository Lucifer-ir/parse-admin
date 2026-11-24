<section class="page-view" id="page-resources">
      <div class="page-heading"><div><div class="eyebrow">LEARNING LIBRARY</div><h1>کتاب‌ها و جزوه‌ها</h1><p>مرکز محتوای آموزشی؛ فایل‌های TXT و MD برای تولید سؤال و PDF برای نمایش داخل اپ.</p></div><button class="button button-ghost" id="refreshResources">↻ به‌روزرسانی</button></div>
      <div class="workspace-grid">
        <form class="panel-card form-panel" id="resourceCreateForm"><h3>افزودن کتاب یا منبع</h3>
          <label>عنوان<input class="input" name="title" required></label>
          <div class="form-two"><label>نوع<select class="input" name="resource_type"><option value="book">کتاب</option><option value="handout">جزوه</option><option value="lesson">درس</option><option value="course">دوره</option><option value="source">فایل منبع AI</option></select></label><label>درس / رشته<input class="input" name="subject"></label></div>
          <div class="form-two"><label>نمایش در اپ<select class="input" name="published"><option value="0">پیش‌نویس</option><option value="1">منتشر شود</option></select></label></div>
          <label>مسیر آموزشی<select class="input" name="track_id"><option value="">بدون مسیر مشخص</option></select></label>
          <label>توضیحات<textarea class="input textarea" name="description" rows="2"></textarea></label>
          <label>فایل منبع (TXT / MD / PDF)<input class="input" type="file" name="resource_file" accept=".txt,.md,.pdf"></label>
          <label>متن منبع (اختیاری)<textarea class="input textarea" name="content_text" rows="5" placeholder="برای تولید سؤال از روی محتوا، متن را اینجا قرار دهید یا فایل TXT/MD بارگذاری کنید."></textarea></label>
          <button class="button button-primary full-button" type="submit">ذخیره در کتابخانه</button><div class="form-message" id="resourceCreateMessage"></div>
        </form>
        <section class="panel-card result-panel"><h3>انواع محتوا</h3><div class="security-row"><i>▣</i><span><b>کتاب و جزوه</b><small>فایل PDF منتشرشده در فهرست محتوای اپ نمایش داده می‌شود.</small></span></div><div class="security-row"><i>✧</i><span><b>منبع تولید AI</b><small>متن فایل TXT/MD برای تولید سؤال ساختاریافته قابل استفاده است.</small></span></div><div class="notice">فایل‌های متنی با UTF-8 ذخیره شوند. برای PDF، نمایش و دانلود در اپ فعال است؛ استخراج متن PDF در این نسخه انجام نمی‌شود.</div></section>
      </div>
      <section class="panel-card table-panel"><div class="table-scroll"><table><thead><tr><th>عنوان</th><th>نوع</th><th>درس</th><th>سطح</th><th>انتشار</th><th>تاریخ</th><th>عملیات</th></tr></thead><tbody id="resourceRows"><tr><td colspan="7" class="empty-cell">در حال دریافت...</td></tr></tbody></table></div></section>
    </section>
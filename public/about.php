<?php
require_once __DIR__ . '/../config/bootstrap.php';
$pageTitle = 'درباره تولیدی | جوراب رابطی';

ob_start();
?>

<section class="page-head">
    <h1 class="section-title">درباره جوراب رابطی</h1>
</section>

<section class="about-body">
    <p>
        «جوراب رابطی» پلتفرم آنلاین خرید عمده جوراب است که فروشگاه‌داران،
        پخش‌کنندگان و برندها را مستقیماً به خط تولید کارخانه وصل می‌کند —
        بدون واسطه، با قیمت کارخانه و کیفیت تضمینی.
    </p>
    <p>
        ما باور داریم خرید عمده باید مثل خرید از یک دوست مطمئن باشد:
        قیمت شفاف، جنس درست، ارسال سریع و کسی که اگر مشکلی پیش آمد،
        پشت تلفن هست.
    </p>
</section>

<!-- ▂▂▂ اعداد ما ▂▂▂ -->
<section class="stats-row">
    <div class="stat-box">
        <div class="stat-num">+۱۲۰</div>
        <div class="stat-label">مدل تولیدی</div>
    </div>
    <div class="stat-box">
        <div class="stat-num">+۸۵۰</div>
        <div class="stat-label">فروشگاه طرف قرارداد</div>
    </div>
    <div class="stat-box">
        <div class="stat-num">۲۴h</div>
        <div class="stat-label">آماده‌سازی سفارش</div>
    </div>
</section>

<!-- ▂▂▂ چطور کار می‌کنیم؟ ▂▂▂ -->
<section class="section">
    <h2 class="section-title">چطور کار می‌کنیم؟</h2>
    <div class="steps-list">
        <div class="step-item">
            <span class="step-num">۱</span>
            <div>
                <b>کشف و انتخاب</b>
                <p>از کاتالوگ آنلاین، مدل و رنگ و سایز مورد نظرتان را با قیمت شفاف ببینید.</p>
            </div>
        </div>
        <div class="step-item">
            <span class="step-num">۲</span>
            <div>
                <b>سفارش و تأیید</b>
                <p>سفارش را ثبت کنید — ما موجودی را قطعی و آماده ارسال می‌کنیم.</p>
            </div>
        </div>
        <div class="step-item">
            <span class="step-num">۳</span>
            <div>
                <b>ارسال و خرید مجدد</b>
                <p>بسته‌بندی حرفه‌ای و ارسال سراسری. سابقه خرید شما ذخیره می‌شود تا دفعه بعد با یک کلیک سفارش بدهید.</p>
            </div>
        </div>
    </div>
</section>

<!-- ▂▂▂ CTA ▂▂▂ -->
<section class="section">
    <div class="why-card">
        <div class="why-icon">🤝</div>
        <h2>بیایید شروع کنیم</h2>
        <p>ثبت‌نام کنید و از قیمت‌های ویژه اعضا بهره‌مند شوید — یا اگر سوال دارید، تماس بگیرید.</p>
        <div class="hero-actions" style="flex-direction: row; flex-wrap: wrap;">
            <a href="/register" class="btn btn-orange">ثبت‌نام عمده‌فروشی</a>
            <a href="/contact" class="btn btn-ghost">تماس با ما</a>
        </div>
    </div>
</section>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';

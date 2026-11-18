<?php
/* Auto-detect bootstrap (local + host) */
$__bp = [
    __DIR__ . '/../sock-b2b/config/bootstrap.php',
    __DIR__ . '/../config/bootstrap.php',
];
foreach ($__bp as $__b) {
    if (is_file($__b)) {
        require_once $__b;
        break;
    }
}
unset($__bp, $__b);
$pageTitle = 'درباره تولیدی | جوراب رابطی';

ob_start();
?>

<link rel="stylesheet" href="/assets/css/contact-about.css">

<div class="rbt-page-wrapper rbt-about-page" dir="rtl">

    <!-- ================= HERO ================= -->
    <section class="rbt-hero">
        <div class="rbt-hero-pattern" aria-hidden="true"></div>
        <div class="rbt-hero-glow" aria-hidden="true"></div>

        <div class="rbt-hero-content">
            <div class="rbt-eyebrow">
                <span class="rbt-eyebrow-dot"></span>
                تولیدی و پخش عمده جوراب
            </div>

            <h1>
                از خط تولید،
                <span>مستقیم به فروشگاه شما.</span>
            </h1>

            <p>
                «جوراب رابطی» پلتفرم آنلاین خرید عمده جوراب است که فروشگاه‌داران،
                پخش‌کنندگان و برندها را مستقیماً به خط تولید کارخانه وصل می‌کند —
                بدون واسطه، با قیمت کارخانه و کیفیت تضمینی.
            </p>

            <div class="rbt-hero-bottom">
                <span class="rbt-hero-line"></span>
                <span>قیمت شفاف · جنس درست · ارسال سریع</span>
            </div>
        </div>

        <div class="rbt-hero-decoration" aria-hidden="true">
            <span class="rbt-decoration-circle circle-one"></span>
            <span class="rbt-decoration-circle circle-two"></span>
            <span class="rbt-decoration-word">R</span>
        </div>

        <div class="rbt-hero-index" aria-hidden="true">RABETI / ABOUT</div>
    </section>


    <!-- ================= STORY ================= -->
    <section class="rbt-story">
        <div class="rbt-story-text">
            <span class="rbt-kicker">OUR STORY</span>
            <h2>خرید عمده، مثل خرید از یک دوست مطمئن.</h2>
            <p>
                ما باور داریم خرید عمده باید ساده و مطمئن باشد:
                قیمت شفاف، جنس درست، ارسال سریع و کسی که اگر مشکلی پیش آمد،
                پشت تلفن هست.
            </p>
        </div>

        <div class="rbt-story-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M12 2 3 7v10l9 5 9-5V7l-9-5Z" />
                <path d="M12 22V12M3 7l9 5 9-5" />
            </svg>
            <div>
                <strong>بدون واسطه</strong>
                <p>ارتباط مستقیم با کارخانه یعنی قیمت واقعی تولید و موجودی قطعی.</p>
            </div>
        </div>
    </section>


    <!-- ================= STATS ================= -->
    <section class="rbt-stats">
        <div class="rbt-stat">
            <div class="rbt-stat-num">+۱۲۰</div>
            <div class="rbt-stat-label">مدل تولیدی</div>
        </div>
        <div class="rbt-stat">
            <div class="rbt-stat-num">+۸۵۰</div>
            <div class="rbt-stat-label">فروشگاه طرف قرارداد</div>
        </div>
        <div class="rbt-stat">
            <div class="rbt-stat-num">۲۴<span>h</span></div>
            <div class="rbt-stat-label">آماده‌سازی سفارش</div>
        </div>
    </section>


    <!-- ================= STEPS ================= -->
    <section class="rbt-steps-section">
        <div class="rbt-section-heading">
            <div>
                <span class="rbt-kicker">HOW IT WORKS</span>
                <h2>چطور کار می‌کنیم؟</h2>
            </div>
            <span class="rbt-steps-count">03 / STEPS</span>
        </div>

        <div class="rbt-steps">
            <article class="rbt-step">
                <div class="rbt-step-top">
                    <span class="rbt-step-num">۱</span>
                    <span class="rbt-step-line" aria-hidden="true"></span>
                </div>
                <h3>کشف و انتخاب</h3>
                <p>از کاتالوگ آنلاین، مدل و رنگ و سایز مورد نظرتان را با قیمت شفاف ببینید.</p>
            </article>

            <article class="rbt-step">
                <div class="rbt-step-top">
                    <span class="rbt-step-num">۲</span>
                    <span class="rbt-step-line" aria-hidden="true"></span>
                </div>
                <h3>سفارش و تأیید</h3>
                <p>سفارش را ثبت کنید — ما موجودی را قطعی و آماده ارسال می‌کنیم.</p>
            </article>

            <article class="rbt-step">
                <div class="rbt-step-top">
                    <span class="rbt-step-num">۳</span>
                    <span class="rbt-step-line" aria-hidden="true"></span>
                </div>
                <h3>ارسال و خرید مجدد</h3>
                <p>بسته‌بندی حرفه‌ای و ارسال سراسری. سابقه خرید شما ذخیره می‌شود تا دفعه بعد با یک کلیک سفارش بدهید.</p>
            </article>
        </div>
    </section>


    <!-- ================= CTA ================= -->
    <section class="rbt-cta">
        <div class="rbt-cta-glow" aria-hidden="true"></div>

        <div class="rbt-cta-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M16 3h5v5M21 3l-7.5 7.5M8 21H3v-5M3 21l7.5-7.5" />
                <circle cx="12" cy="12" r="3.2" />
            </svg>
        </div>

        <span class="rbt-kicker">RABETI / YOUR PARTNER</span>
        <h2>بیایید شروع کنیم.</h2>
        <p>ثبت‌نام کنید و از قیمت‌های ویژه اعضا بهره‌مند شوید — یا اگر سوال دارید، تماس بگیرید.</p>

        <div class="rbt-cta-actions">
            <button type="button" class="rbt-btn-gold" data-auth-open="register">ثبت‌نام عمده‌فروش</button>
            <a href="/contact" class="rbt-btn-ghost">تماس با ما</a>
        </div>
    </section>

</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';
?>
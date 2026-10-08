<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once ROOT_PATH . '/config/database.php';

$errors = [];
$saved  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name    = trim($_POST['name'] ?? '');
    $mobile  = trim($_POST['mobile'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (mb_strlen($name) < 3)                $errors['name'] = 'نام را کامل وارد کنید';
    if (!preg_match('/^09\d{9}$/', $mobile)) $errors['mobile'] = 'شماره موبایل معتبر نیست';
    if (mb_strlen($message) < 10)            $errors['message'] = 'پیام را کامل‌تر بنویسید';

    if (!$errors) {
        $stmt = db()->prepare("
            INSERT INTO contact_messages (name, mobile, subject, message)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$name, $mobile, $subject ?: null, $message]);
        $saved = true;
    }
}

$pageTitle = 'تماس با ما | جوراب رابطی';
ob_start();
?>

<!-- ══════════ هدر صفحه ══════════ -->
<section class="contact-hero">
    <div class="contact-hero-inner">
        <br>
        <span class="contact-hero-badge">📞 پاسخ‌گوی شما هستیم</span>
        <h1 class="contact-hero-title">با ما در تماس باشید</h1>
        <p class="contact-hero-sub">
            شنبه تا پنجشنبه، ۹ تا ۱۷ — تیم پشتیبانی رابطی آماده پاسخگویی به شماست.
        </p>
    </div>
    <div class="contact-hero-glow" aria-hidden="true"></div>
</section>

<div class="contact-grid">

    <!-- ══════════ اطلاعات تماس ══════════ -->
    <div class="contact-info">

        <!-- تلفن ثابت -->
        <a href="tel:09197693149" class="contact-card">
            <span class="contact-card-icon">
                <svg width="24" height="24" fill="none" stroke="currentColor"
                    stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
                </svg>
            </span>
            <div class="contact-card-body">
                <b>تلفن ثابت</b>
                <p dir="ltr" class="ltr-num">۰۲۱ - ۱۲۳۴ ۵۶۷۸</p>
            </div>
            <span class="contact-card-arrow">←</span>
        </a>

        <!-- موبایل / واتساپ -->
        <a href="tel:09121234567" class="contact-card">
            <span class="contact-card-icon">
                <svg width="24" height="24" fill="none" stroke="currentColor"
                    stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                </svg>
            </span>
            <div class="contact-card-body">
                <b>موبایل / واتساپ</b>
                <p dir="ltr" class="ltr-num">09197693149</p>
            </div>
            <span class="contact-card-arrow">←</span>
        </a>

        <!-- آدرس -->
        <div class="contact-card contact-card-static">
            <span class="contact-card-icon">
                <svg width="24" height="24" fill="none" stroke="currentColor"
                    stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" />
                </svg>
            </span>
            <div class="contact-card-body">
                <b> آدرس </b>
                <p>بازار بزرگ تهران - بازار عباس آباد سرای لطفی پور پلاک 22 </p>
            </div>
        </div>

        <!-- ساعت کاری -->
        <div class="contact-hours">
            <div class="contact-hours-head">
                <svg width="18" height="18" fill="none" stroke="currentColor"
                    stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                        d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
                <b>ساعت کاری</b>
            </div>
            <div class="contact-hours-row">
                <span>شنبه تا پنجشنبه</span>
                <strong>۹:۰۰ — ۱۷:۰۰</strong>
            </div>
            <div class="contact-hours-row">
                <span>جمعه</span>
                <strong class="closed">تعطیل</strong>
            </div>
        </div>

    </div>

    <!-- ══════════ فرم ══════════ -->
    <div class="contact-form-wrap">

        <?php if ($saved): ?>

            <div class="contact-success">
                <div class="contact-success-icon">
                    <svg width="42" height="42" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <h2>پیام شما با موفقیت ثبت شد</h2>
                <p>همکاران ما در اولین فرصت با شما تماس می‌گیرند.</p>
                <a href="/" class="btn btn-primary">بازگشت به خانه</a>
            </div>

        <?php else: ?>

            <div class="contact-form-head">
                <h2>فرم تماس</h2>
                <p>فرم زیر را پر کنید، در کمترین زمان پاسخ می‌دهیم.</p>
            </div>

            <form class="contact-form" method="post" action="/contact" novalidate>

                <?php if ($errors): ?>
                    <div class="form-alert">
                        <svg width="18" height="18" fill="none" stroke="currentColor"
                            stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                        </svg>
                        لطفاً خطاهای فرم را اصلاح کنید
                    </div>
                <?php endif; ?>

                <div class="form-row-2">
                    <div class="form-field">
                        <label>نام و نام خانوادگی <i>*</i></label>
                        <input type="text" name="name"
                            value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                            placeholder="مثلاً: علی رضایی"
                            class="<?= isset($errors['name']) ? 'has-error' : '' ?>">
                        <?php if (isset($errors['name'])): ?>
                            <span class="field-error"><?= $errors['name'] ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="form-field">
                        <label>شماره موبایل <i>*</i></label>
                        <input type="tel" name="mobile" dir="ltr"
                            value="<?= htmlspecialchars($_POST['mobile'] ?? '') ?>"
                            placeholder="09121234567"
                            class="<?= isset($errors['mobile']) ? 'has-error' : '' ?>">
                        <?php if (isset($errors['mobile'])): ?>
                            <span class="field-error"><?= $errors['mobile'] ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-field">
                    <label>موضوع</label>
                    <input type="text" name="subject"
                        value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                        placeholder="مثلاً: استعلام قیمت عمده">
                </div>

                <div class="form-field">
                    <label>پیام شما <i>*</i></label>
                    <textarea name="message" rows="5"
                        placeholder="پیام خود را بنویسید..."
                        class="<?= isset($errors['message']) ? 'has-error' : '' ?>"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                    <?php if (isset($errors['message'])): ?>
                        <span class="field-error"><?= $errors['message'] ?></span>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary contact-submit">
                    <span>ارسال پیام</span>
                    <svg width="18" height="18" fill="none" stroke="currentColor"
                        stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                    </svg>
                </button>

            </form>

        <?php endif; ?>

    </div>

</div>

<?php
$content = ob_get_clean();
require ROOT_PATH . '/views/layouts/main.php';

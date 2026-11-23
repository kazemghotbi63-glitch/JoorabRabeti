<?php
/* [FILE] public/checkout-address.php — نسخه v2 (Dropdown استان/شهر + اعتبارسنجی سرور) */

/* ─── Auto-detect bootstrap ─── */
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

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';
require_once ROOT_PATH . '/app/services/AddressService.php';
require_once ROOT_PATH . '/app/services/PricingService.php';

/* ═══════════════════════════════════════════════════════════════
 * Helper: لیست استان‌ها و شهرستان‌های ایران
 * ═══════════════════════════════════════════════════════════════ */
if (!function_exists('iran_provinces')) {
    function iran_provinces(): array
    {
        return [
            'آذربایجان شرقی'      => ['تبریز', 'مراغه', 'مرند', 'اهر', 'بناب', 'میانه', 'سراب', 'آذرشهر', 'اسکو', 'شبستر', 'عجب‌شیر', 'هریس', 'جلفا', 'ملکان', 'بستان‌آباد'],
            'آذربایجان غربی'      => ['ارومیه', 'خوی', 'میاندوآب', 'بوکان', 'مهاباد', 'سلماس', 'نقده', 'پیرانشهر', 'سردشت', 'شاهین‌دژ', 'تکاب', 'ماکو', 'چالدران', 'اشنویه'],
            'اردبیل'              => ['اردبیل', 'پارس‌آباد', 'مشکین‌شهر', 'خلخال', 'گرمی', 'بیله‌سوار', 'نمین', 'نیر', 'کوثر', 'سرعین'],
            'اصفهان'              => ['اصفهان', 'کاشان', 'نجف‌آباد', 'خمینی‌شهر', 'شهرضا', 'زرین‌شهر', 'فولادشهر', 'نطنز', 'آران و بیدگل', 'گلپایگان', 'خوانسار', 'سمیرم', 'فریدن', 'چادگان', 'دهاقان', 'برخوار', 'شاهین‌شهر'],
            'البرز'               => ['کرج', 'فردیس', 'هشتگرد', 'نظرآباد', 'اشتهارد', 'طالقان', 'محمدشهر', 'ماهدشت', 'گرمدره', 'کمال‌شهر'],
            'ایلام'               => ['ایلام', 'دهلران', 'آبدانان', 'ایوان', 'دره‌شهر', 'مهران', 'ملکشاهی', 'چرداول', 'بدره'],
            'بوشهر'               => ['بوشهر', 'برازجان', 'گناوه', 'کنگان', 'دیلم', 'دیر', 'جم', 'خورموج', 'اهرم', 'عسلویه'],
            'تهران'               => ['تهران', 'شهریار', 'اسلامشهر', 'رباط‌کریم', 'ورامین', 'پاکدشت', 'قرچک', 'پردیس', 'دماوند', 'فیروزکوه', 'ملارد', 'قدس', 'بهارستان', 'پیشوا', 'چهاردانگه', 'لواسان', 'شمشک'],
            'چهارمحال و بختیاری'  => ['شهرکرد', 'بروجن', 'فارسان', 'لردگان', 'سامان', 'اردل', 'کیار', 'بن', 'خانمیرزا'],
            'خراسان جنوبی'        => ['بیرجند', 'قائن', 'فردوس', 'نهبندان', 'طبس', 'بشرویه', 'سربیشه', 'درمیان', 'خوسف', 'زیرکوه'],
            'خراسان رضوی'         => ['مشهد', 'نیشابور', 'سبزوار', 'تربت حیدریه', 'قوچان', 'کاشمر', 'تربت جام', 'گناباد', 'چناران', 'خواف', 'درگز', 'طرقبه', 'شاندیز', 'فریمان', 'بردسکن', 'جغتای', 'خلیل‌آباد', 'رشتخوار', 'کلات', 'مه‌ولات', 'باخرز', 'تایباد', 'بجستان', 'جوبین'],
            'خراسان شمالی'        => ['بجنورد', 'شیروان', 'اسفراین', 'آشخانه', 'گرمه', 'جاجرم', 'فاروج', 'راز', 'صفی‌آباد', 'مانه و سملقان'],
            'خوزستان'             => ['اهواز', 'آبادان', 'خرمشهر', 'دزفول', 'اندیمشک', 'بهبهان', 'ماهشهر', 'شوشتر', 'شوش', 'ایذه', 'باغ‌ملک', 'امیدیه', 'رامهرمز', 'مسجد سلیمان', 'هندیجان', 'بندر ماهشهر', 'حمیدیه', 'سوسنگرد', 'لالی', 'هفتکل', 'رامشیر', 'آغاجاری', 'کارون'],
            'زنجان'               => ['زنجان', 'ابهر', 'خرمدره', 'قیدار', 'هیدج', 'صائین‌قلعه', 'ماه‌نشان', 'طارم', 'ایجرود', 'سلطانیه'],
            'سمنان'               => ['سمنان', 'شاهرود', 'گرمسار', 'دامغان', 'مهدی‌شهر', 'آرادان', 'میامی', 'بسطام', 'مجن'],
            'سیستان و بلوچستان'   => ['زاهدان', 'چابهار', 'زابل', 'ایرانشهر', 'سراوان', 'خاش', 'کنارک', 'نیک‌شهر', 'میرجاوه', 'راسک', 'سرباز', 'قصرقند', 'زهک', 'هیرمند', 'نیمروز', 'هامون'],
            'فارس'                => ['شیراز', 'مرودشت', 'کازرون', 'جهرم', 'فسا', 'داراب', 'لار', 'آباده', 'صدرا', 'نی‌ریز', 'اقلید', 'استهبان', 'سپیدان', 'فراشبند', 'قیر و کارزین', 'لامرد', 'مهر', 'خرامه', 'سروستان', 'زرین‌دشت', 'بوانات', 'ارسنجان', 'پاسارگاد', 'رستم'],
            'قزوین'               => ['قزوین', 'تاکستان', 'آبیک', 'بوئین‌زهرا', 'الوند', 'محمدیه', 'اقبالیه', 'آوج', 'شال', 'ضیاءآباد'],
            'قم'                  => ['قم', 'سلفچگان', 'پردیسان', 'کهک', 'جعفریه', 'دستجرد'],
            'کردستان'             => ['سنندج', 'سقز', 'مریوان', 'بانه', 'قروه', 'بیجار', 'دیواندره', 'کامیاران', 'دهگلان', 'سروآباد'],
            'کرمان'               => ['کرمان', 'رفسنجان', 'سیرجان', 'بم', 'جیرفت', 'زرند', 'کهنوج', 'بردسیر', 'بافت', 'شهربابک', 'راور', 'انار', 'ریگان', 'منوجان', 'فهرج', 'قلعه‌گنج', 'نرماشیر'],
            'کرمانشاه'            => ['کرمانشاه', 'اسلام‌آباد غرب', 'هرسین', 'سنقر', 'پاوه', 'صحنه', 'قصر شیرین', 'جوانرود', 'سرپل ذهاب', 'کنگاور', 'گیلانغرب', 'روانسر', 'ثلاث باباجانی', 'دالاهو'],
            'کهگیلویه و بویراحمد' => ['یاسوج', 'دوگنبدان', 'دهدشت', 'سی‌سخت', 'لیکک', 'چرام', 'باشت', 'مارگون', 'بویراحمد'],
            'گلستان'              => ['گرگان', 'گنبد کاووس', 'علی‌آباد', 'بندر ترکمن', 'آزادشهر', 'کردکوی', 'بندر گز', 'آق‌قلا', 'رامیان', 'مینودشت', 'کلاله', 'مراوه‌تپه', 'گمیشان'],
            'گیلان'               => ['رشت', 'بندر انزلی', 'لاهیجان', 'آستارا', 'آستانه اشرفیه', 'تالش', 'رودسر', 'رودبار', 'صومعه‌سرا', 'فومن', 'شفت', 'ماسال', 'سیاهکل', 'املش', 'خمام', 'لنگرود', 'منجیل', 'رستم‌آباد'],
            'لرستان'              => ['خرم‌آباد', 'بروجرد', 'دورود', 'الیگودرز', 'کوهدشت', 'نورآباد', 'پل‌دختر', 'ازنا', 'الشتر', 'سلسله', 'چگنی', 'رومشکان'],
            'مازندران'            => ['ساری', 'بابل', 'آمل', 'قائم‌شهر', 'بهشهر', 'چالوس', 'نوشهر', 'رامسر', 'تنکابن', 'بابلسر', 'فریدون‌کنار', 'محمودآباد', 'نکا', 'سوادکوه', 'جویبار', 'عباس‌آباد', 'کلاردشت', 'شیرگاه', 'بلده', 'پل سفید', 'گلوگاه'],
            'مرکزی'               => ['اراک', 'ساوه', 'خمین', 'محلات', 'دلیجان', 'شازند', 'تفرش', 'آشتیان', 'زرندیه', 'فراهان', 'کمیجان', 'خنداب'],
            'هرمزگان'             => ['بندرعباس', 'قشم', 'میناب', 'بندر لنگه', 'کیش', 'بندر خمیر', 'رودان', 'بستک', 'پارسیان', 'حاجی‌آباد', 'ابوموسی', 'سیریک', 'بشاگرد'],
            'همدان'               => ['همدان', 'ملایر', 'نهاوند', 'تویسرکان', 'اسدآباد', 'بهار', 'کبودرآهنگ', 'رزن', 'لالجین', 'فامنین'],
            'یزد'                 => ['یزد', 'میبد', 'اردکان', 'بافق', 'مهریز', 'ابرکوه', 'تفت', 'خاتم', 'اشکذر', 'بهاباد', 'مروست'],
        ];
    }
}

/* ─── دسترسی — ⭐ خرید مهمان‌پسند: مهمان هم می‌تواند آدرس ثبت کند ─── */
$isCustomer = is_customer_logged_in();
if (!$isCustomer && is_admin_logged_in()) {
    header('Location: /admin');
    exit;
}

$h      = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$userId = (int)($_SESSION['customer_id'] ?? 0);
$db     = db();

/* ─── سبد باید پر باشد (کاربر: DB — مهمان: session) ─── */
if ($isCustomer) {
    $stmt = $db->prepare("SELECT COUNT(*) FROM cart_items ci JOIN carts c ON c.id=ci.cart_id
                          WHERE c.user_id=? AND c.status='active'");
    $stmt->execute([$userId]);
    $hasCart = (bool)$stmt->fetchColumn();
} else {
    $hasCart = !empty($_SESSION['guest_cart'])
        && is_array($_SESSION['guest_cart'])
        && array_sum($_SESSION['guest_cart']) > 0;
}
if (!$hasCart) {
    header('Location: /cart');
    exit;
}

/* ─── کنترل حداقل مجموع خرید محصولات جفتی ─── */
if ($isCustomer) {
    $pairQty = PricingService::getPairQtyByUser($db, $userId);
} else {
    $pairQty = 0;
    $gIds = array_map('intval', array_keys($_SESSION['guest_cart']));
    if ($gIds) {
        $gPh = implode(',', array_fill(0, count($gIds), '?'));
        $stmt = $db->prepare("SELECT p.id,
                COALESCE((SELECT spec_value FROM product_specs
                          WHERE product_id = p.id AND spec_key = 'واحد سفارش' LIMIT 1), 'جفتی') AS unit_code
            FROM products p WHERE p.id IN ($gPh)");
        $stmt->execute($gIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['unit_code'] === 'جفتی') {
                $pairQty += (int)$_SESSION['guest_cart'][$row['id']];
            }
        }
    }
}
if (PricingService::violatesPairMinimum($pairQty)) {
    header('Location: /cart');
    exit;
}

$error       = '';
$provincesList = iran_provinces();
$oldProvince = '';
$oldCity     = '';

/* ═══════════════════════════════════════════════════════════════
 * POST: انتخاب آدرس ذخیره‌شده
 * ═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'pick') {
    verify_csrf();

    $addressId = (int)($_POST['address_id'] ?? 0);
    $addr      = AddressService::get($userId, $addressId);

    if ($addr) {
        $_SESSION['checkout_address_id'] = $addressId;
        header('Location: /checkout-shipping');
        exit;
    }
    $error = 'آدرس انتخابی یافت نشد';
}

/* ═══════════════════════════════════════════════════════════════
 * POST: افزودن آدرس جدید
 * ═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'add') {
    verify_csrf();

    $oldProvince = trim((string)($_POST['province'] ?? ''));
    $oldCity     = trim((string)($_POST['city']     ?? ''));

    try {
        /* ─── اعتبارسنجی استان/شهر ─── */
        if (!isset($provincesList[$oldProvince])) {
            throw new RuntimeException('استان انتخاب‌شده معتبر نیست. لطفاً از لیست انتخاب کنید.');
        }
        if (!in_array($oldCity, $provincesList[$oldProvince], true)) {
            throw new RuntimeException('شهر انتخاب‌شده با استان همخوانی ندارد.');
        }

        if ($isCustomer) {
            $addressId = AddressService::create($userId, [
                'label'           => $_POST['label'] ?? '',
                'province'        => $oldProvince,
                'city'            => $oldCity,
                'address'         => $_POST['address'] ?? '',
                'postal_code'     => trim($_POST['postal_code'] ?? ''),
                'plate_no'        => trim($_POST['plate_no'] ?? ''),
                'unit_no'         => trim($_POST['unit_no'] ?? ''),
                'receiver_name'   => trim($_POST['receiver_name'] ?? ''),
                'receiver_mobile' => strtr(
                    trim($_POST['receiver_mobile'] ?? ''),
                    ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']
                ),
                'is_default'      => isset($_POST['is_default']),
            ]);
            $_SESSION['checkout_address_id'] = $addressId;
        } else {
            /* ⭐ مهمان: آدرس در session ذخیره می‌شود؛ بعد از ورود/ثبت‌نام در
               مرحلهٔ آخر، به‌صورت خودکار به آدرس واقعی مشتری ارتقا می‌یابد */
            $_SESSION['checkout_guest_address'] = [
                'label'           => trim($_POST['label'] ?? ''),
                'province'        => $oldProvince,
                'city'            => $oldCity,
                'address'         => trim($_POST['address'] ?? ''),
                'postal_code'     => trim($_POST['postal_code'] ?? ''),
                'plate_no'        => trim($_POST['plate_no'] ?? ''),
                'unit_no'         => trim($_POST['unit_no'] ?? ''),
                'receiver_name'   => trim($_POST['receiver_name'] ?? ''),
                'receiver_mobile' => strtr(
                    trim($_POST['receiver_mobile'] ?? ''),
                    ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']
                ),
            ];
            $_SESSION['checkout_address_id'] = 0;
        }

        header('Location: /checkout-shipping');
        exit;
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

/* ─── داده‌های نمایش ─── */
$addresses  = $isCustomer ? AddressService::list($userId) : [];
$selectedId = (int)($_SESSION['checkout_address_id'] ?? 0);

$pageTitle = 'آدرس ارسال | رابطی';
ob_start();
?>

<style>
    /* استایل یکسان select با input در co-form */
    .co-form select {
        width: 100%;
        box-sizing: border-box;
        padding: 10px 12px;
        border: 1px solid #dbe2ea;
        border-radius: 9px;
        font-family: inherit;
        font-size: 13px;
        background-color: #fff;
        color: #1e293b;
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='3'><polyline points='6 9 12 15 18 9'/></svg>");
        background-repeat: no-repeat;
        background-position: left 12px center;
        padding-left: 32px;
    }

    .co-form select:disabled {
        background-color: #f8fafc;
        color: #94a3b8;
        cursor: not-allowed;
        opacity: .7;
    }

    .co-form select:focus {
        outline: none;
        border-color: #0ea5e9;
        box-shadow: 0 0 0 3px rgba(14, 165, 233, .12);
    }
</style>

<nav class="breadcrumb">
    <a href="/">خانه</a> ›
    <a href="/cart">سبد</a> ›
    <span>آدرس ارسال</span>
</nav>
<script>try{sessionStorage.setItem('rabetiCheckout','1');}catch(e){}</script>

<section class="catalog-head">
    <h1 class="catalog-title">آدرس ارسال سفارش</h1>
    <p class="catalog-sub">سفارش به کدام آدرس ارسال شود؟</p>
</section>

<?php if ($error): ?>
    <div class="form-alert error" style="display:block;">⚠️ <?= $h($error) ?></div>
<?php endif; ?>

<?php if ($addresses): ?>
    <div class="addr-list">

        <?php foreach ($addresses as $a): ?>
            <div class="addr-card <?= $a['is_default'] ? 'is-default' : '' ?>">
                <div class="addr-card-body">
                    <b>
                        🏪 <?= $h($a['label']) ?>
                        <?= $a['is_default']
                            ? '<span class="st-badge st-converted">پیش‌فرض</span>'
                            : '' ?>
                    </b>
                    <p>
                        <?= $h($a['province']) ?>، <?= $h($a['city']) ?> —
                        <?= $h($a['address']) ?>
                        <?= $a['plate_no'] ? '، پلاک ' . $h($a['plate_no']) : '' ?>
                        <?= $a['unit_no']  ? '، واحد ' . $h($a['unit_no'])  : '' ?>
                    </p>
                    <?php if ($a['postal_code']): ?>
                        <small>
                            کدپستی:
                            <span dir="ltr"><?= $h($a['postal_code']) ?></span>
                        </small>
                    <?php endif; ?>
                </div>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="do" value="pick">
                    <input type="hidden" name="address_id" value="<?= (int)$a['id'] ?>">
                    <button type="submit" class="btn btn-primary">همین آدرس ←</button>
                </form>
            </div>
        <?php endforeach; ?>

    </div>
<?php endif; ?>

<!-- ▂▂▂ افزودن آدرس جدید ▂▂▂ -->
<div class="addr-new-wrap">
    <details <?= $addresses ? '' : 'open' ?>>
        <summary><?= $addresses ? '+ افزودن آدرس جدید' : 'ثبت آدرس ارسال' ?></summary>

        <form method="post" class="co-form" id="addrForm">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="add">

            <div class="form-row">
                <div class="form-field">
                    <label>عنوان آدرس</label>
                    <input type="text" name="label" placeholder="مثلاً: فروشگاه / انبار"
                        value="<?= $h($_POST['label'] ?? '') ?>">
                </div>

                <div class="form-field">
                    <label>استان <i>*</i></label>
                    <select name="province" id="provinceSelect" required>
                        <option value="">— انتخاب استان —</option>
                        <?php foreach ($provincesList as $provinceName => $cities): ?>
                            <option value="<?= $h($provinceName) ?>"
                                data-cities="<?= $h(implode('|', $cities)) ?>"
                                <?= $oldProvince === $provinceName ? 'selected' : '' ?>>
                                <?= $h($provinceName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label>شهر <i>*</i></label>
                    <select name="city" id="citySelect" required
                        <?= $oldProvince ? '' : 'disabled' ?>>
                        <option value="">— ابتدا استان را انتخاب کنید —</option>
                    </select>
                </div>

                <div class="form-field">
                    <label>کد پستی</label>
                    <input type="text" name="postal_code" dir="ltr"
                        inputmode="numeric" maxlength="10" placeholder="۱۰ رقم"
                        value="<?= $h($_POST['postal_code'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <label>آدرس کامل <i>*</i></label>
                <textarea name="address" rows="2" required
                    placeholder="خیابان، کوچه، ..."><?= $h($_POST['address'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label>پلاک</label>
                    <input type="text" name="plate_no" dir="ltr"
                        value="<?= $h($_POST['plate_no'] ?? '') ?>">
                </div>
                <div class="form-field">
                    <label>واحد</label>
                    <input type="text" name="unit_no" dir="ltr"
                        value="<?= $h($_POST['unit_no'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-field">
                    <label>نام گیرنده (اختیاری)</label>
                    <input type="text" name="receiver_name" placeholder="خالی = خود شما"
                        value="<?= $h($_POST['receiver_name'] ?? '') ?>">
                </div>
                <div class="form-field">
                    <label>موبایل گیرنده (اختیاری)</label>
                    <input type="tel" name="receiver_mobile" dir="ltr" placeholder="09xxxxxxxxx"
                        value="<?= $h($_POST['receiver_mobile'] ?? '') ?>">
                </div>
            </div>

            <?php if ($isCustomer): ?>
            <label class="form-check">
                <input type="checkbox" name="is_default" value="1"
                    <?= (($_POST['do'] ?? '') === 'add' && !isset($_POST['is_default'])) ? '' : 'checked' ?>>
                این آدرس پیش‌فرض سفارش‌های بعدی باشد
            </label>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary co-submit">ذخیره و ادامه ←</button>
        </form>
    </details>
</div>

<script>
    (function() {
        'use strict';

        const form = document.getElementById('addrForm');
        const provSel = document.getElementById('provinceSelect');
        const citySel = document.getElementById('citySelect');

        if (!form || !provSel || !citySel) return;

        /* ─── پر کردن شهرها بر اساس استان انتخاب‌شده ─── */
        function fillCities(provinceValue, selectedCity) {
            citySel.innerHTML = '';

            if (!provinceValue) {
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = '— ابتدا استان را انتخاب کنید —';
                citySel.appendChild(opt);
                citySel.disabled = true;
                return;
            }

            const firstOpt = document.createElement('option');
            firstOpt.value = '';
            firstOpt.textContent = '— انتخاب شهر —';
            citySel.appendChild(firstOpt);

            const citiesRaw = provSel.selectedOptions[0].dataset.cities || '';
            const cities = citiesRaw.split('|').filter(Boolean);

            cities.forEach(function(name) {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (selectedCity && selectedCity === name) opt.selected = true;
                citySel.appendChild(opt);
            });

            citySel.disabled = false;
        }

        /* ─── تغییر استان → بازسازی شهرها ─── */
        provSel.addEventListener('change', function() {
            fillCities(provSel.value, null);
        });

        /* ─── بازیابی مقدار قبلی بعد از خطا ─── */
        const oldProv = <?= json_encode($oldProvince, JSON_UNESCAPED_UNICODE) ?>;
        const oldCity = <?= json_encode($oldCity,     JSON_UNESCAPED_UNICODE) ?>;

        if (oldProv) {
            provSel.value = oldProv;
            fillCities(oldProv, oldCity);
        }

        /* ─── اعتبارسنجی نهایی قبل از submit ─── */
        form.addEventListener('submit', function(e) {
            if (!provSel.value) {
                e.preventDefault();
                alert('لطفاً استان را انتخاب کنید.');
                provSel.focus();
                return;
            }
            if (!citySel.value) {
                e.preventDefault();
                alert('لطفاً شهر را انتخاب کنید.');
                citySel.focus();
            }
        });
    })();
</script>

<?php
$content = ob_get_clean();
// checkout.css: قوانین مشترک مراحل تسویه (checkout-) — فقط صفحات checkout
$pageCss = ['/assets/css/checkout.css?v=1'];
require ROOT_PATH . '/views/layouts/main.php';

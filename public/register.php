<?php
/* ═══════════════════════════════════════════════════════════════════
 *  RABETI — ثبت‌نام مشتری  |  v2 | 2026-10-23
 *
 *  تغییرات:
 *    • ⭐ افزودن کد ملی (اجباری، ۱۰ رقمی، اعتبارسنجی چک‌سام)
 *    • تبدیل خودکار ارقام فارسی/عربی → انگلیسی
 *    • حفظ مقادیر فرم بعد از خطا
 * ═══════════════════════════════════════════════════════════════════ */

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

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

if (is_logged_in()) {
    header('Location: /');
    exit;
}

/* ═══ Helper: تبدیل ارقام فارسی/عربی به انگلیسی ═══ */
$toEnDigits = static function (string $s): string {
    return strtr($s, [
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
    ]);
};

/* ═══ Helper: اعتبارسنجی کد ملی ایران (چک‌سام ۱۰ رقمی) ═══ */
$isValidNationalCode = static function (string $code): bool {
    if (strlen($code) !== 10 || !ctype_digit($code)) {
        return false;
    }
    // کدهای تکراری (0000000000، 1111111111 و ...) نامعتبرند
    if (preg_match('/^(\d)\1{9}$/', $code)) {
        return false;
    }
    $check = (int)$code[9];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$code[$i] * (10 - $i);
    }
    $r = $sum % 11;
    return ($r < 2 && $check === $r) || ($r >= 2 && $check === 11 - $r);
};

$error = '';

$fullName     = trim((string)($_POST['full_name']     ?? ''));
$companyName  = trim((string)($_POST['company_name']  ?? ''));
$mobile       = trim((string)($_POST['mobile']        ?? ''));
$nationalCode = $toEnDigits(trim((string)($_POST['national_code'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    /* تبدیل موبایل (فارسی → انگلیسی) */
    $mobile = $toEnDigits($mobile);

    $password  = (string)($_POST['password']  ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if ($fullName === '') {
        $error = 'نام و نام خانوادگی را وارد کنید.';
    } elseif ($companyName === '') {
        $error = 'نام مغازه / شرکت را وارد کنید.';
    } elseif (!preg_match('/^09\d{9}$/', $mobile)) {
        $error = 'شماره موبایل صحیح نیست.';
    } elseif (!$isValidNationalCode($nationalCode)) {
        $error = 'کد ملی ۱۰ رقمی معتبر وارد کنید.';
    } elseif (strlen($password) < 8) {
        $error = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
    } elseif ($password !== $password2) {
        $error = 'تکرار رمز عبور صحیح نیست.';
    } else {
        try {
            $result = register_customer(
                $fullName,
                $companyName,
                $mobile,
                $password,
                $nationalCode
            );

            if (!$result['ok']) {
                $error = $result['error'];
            } else {
                header('Location: /?registered=1');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'ثبت‌نام انجام نشد. لطفاً دوباره تلاش کنید.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ثبت‌نام | رابطی</title>

    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/app.css?v=22">

    <style>
        body.register-page {
            margin: 0;
            background: #f6f2ea;
            color: #253a42;
            font-family: Vazirmatn, Tahoma, sans-serif;
        }

        .register-screen {
            min-height: 100svh;
            display: grid;
            place-items: center;
            padding: 22px;
            box-sizing: border-box;
        }

        .register-card {
            width: min(500px, 100%);
            padding: 28px;
            border: 1px solid #e6e0d7;
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 24px 70px rgba(28, 45, 53, .09);
        }

        .register-brand {
            text-align: center;
            margin-bottom: 22px;
        }

        .register-mark {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            margin: 0 auto 12px;
            border-radius: 15px;
            background: #1d5261;
            color: #fff;
            font-size: 19px;
            font-weight: 900;
        }

        .register-brand h1 {
            margin: 0 0 6px;
            color: #243941;
            font-size: 22px;
            font-weight: 900;
        }

        .register-brand p {
            margin: 0;
            color: #879297;
            font-size: 11px;
            line-height: 1.9;
        }

        .register-error {
            margin-bottom: 14px;
            padding: 10px 12px;
            border: 1px solid #edcfca;
            border-radius: 11px;
            background: #fff6f4;
            color: #ae4840;
            font-size: 11px;
            line-height: 1.8;
        }

        .register-field {
            margin-bottom: 13px;
        }

        .register-field label {
            display: block;
            margin-bottom: 7px;
            color: #364a52;
            font-size: 11px;
            font-weight: 800;
        }

        .register-field label i {
            color: #d87932;
            font-style: normal;
        }

        .register-field input {
            width: 100%;
            height: 45px;
            padding: 0 12px;
            box-sizing: border-box;
            border: 1px solid #dce3e6;
            border-radius: 11px;
            background: #fbfcfc;
            color: #243841;
            font-family: inherit;
            font-size: 13px;
            outline: none;
        }

        .register-field input:focus {
            background: #fff;
            border-color: #719aa6;
            box-shadow: 0 0 0 4px rgba(113, 154, 166, .10);
        }

        .register-hint {
            display: block;
            margin-top: 6px;
            color: #929da2;
            font-size: 9px;
        }

        .register-submit {
            width: 100%;
            height: 46px;
            margin-top: 4px;
            border: 0;
            border-radius: 12px;
            background: #d97c34;
            color: #fff;
            font-family: inherit;
            font-size: 12px;
            font-weight: 900;
            cursor: pointer;
        }

        .register-back {
            margin-top: 17px;
            text-align: center;
            color: #8c969a;
            font-size: 10px;
        }

        .register-back a {
            color: #4b7180;
            text-decoration: none;
            font-weight: 800;
        }

        @media (max-width: 560px) {
            .register-screen {
                min-height: 100svh;
                padding: 14px;
                align-items: center;
            }

            .register-card {
                padding: 21px 17px;
                border-radius: 19px;
            }

            .register-brand {
                margin-bottom: 18px;
            }

            .register-brand h1 {
                font-size: 20px;
            }

            .register-field {
                margin-bottom: 11px;
            }

            .register-field input {
                height: 43px;
            }
        }
    </style>
</head>

<body class="register-page">

    <div class="register-screen">
        <div class="register-card">

            <div class="register-brand">
                <div class="register-mark">ر</div>
                <h1>ایجاد حساب رابطی</h1>
                <p>
                    برای ادامه سفارش و استفاده از امکانات فروشگاه،
                    اطلاعات خود را وارد کنید.
                </p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="register-error">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/register" novalidate>
                <?= csrf_field() ?>

                <div class="register-field">
                    <label>
                        نام و نام خانوادگی
                        <i>*</i>
                    </label>
                    <input
                        type="text"
                        name="full_name"
                        value="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="name"
                        required>
                </div>

                <div class="register-field">
                    <label>
                        نام مغازه / شرکت
                        <i>*</i>
                    </label>
                    <input
                        type="text"
                        name="company_name"
                        value="<?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="organization"
                        required>
                </div>

                <div class="register-field">
                    <label>
                        شماره موبایل
                        <i>*</i>
                    </label>
                    <input
                        type="tel"
                        name="mobile"
                        value="<?= htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8') ?>"
                        dir="ltr"
                        inputmode="numeric"
                        autocomplete="tel"
                        placeholder="09121234567"
                        required>
                    <span class="register-hint">
                        این شماره برای ورود به حساب استفاده می‌شود.
                    </span>
                </div>

                <!-- ⭐ فیلد کد ملی (اجباری) -->
                <div class="register-field">
                    <label>
                        کد ملی
                        <i>*</i>
                    </label>
                    <input
                        type="text"
                        name="national_code"
                        id="nationalCode"
                        value="<?= htmlspecialchars($nationalCode, ENT_QUOTES, 'UTF-8') ?>"
                        dir="ltr"
                        inputmode="numeric"
                        maxlength="10"
                        pattern="[0-9]{10}"
                        placeholder="10 رقم"
                        autocomplete="off"
                        required>
                    <span class="register-hint">
                        کد ملی ۱۰ رقمی خود را وارد کنید.
                    </span>
                </div>

                <div class="register-field">
                    <label>
                        رمز عبور
                        <i>*</i>
                    </label>
                    <input
                        type="password"
                        name="password"
                        dir="ltr"
                        autocomplete="new-password"
                        placeholder="حداقل ۴ کاراکتر"
                        required>
                </div>

                <div class="register-field">
                    <label>
                        تکرار رمز عبور
                        <i>*</i>
                    </label>
                    <input
                        type="password"
                        name="password2"
                        dir="ltr"
                        autocomplete="new-password"
                        required>
                </div>

                <button type="submit" class="register-submit">
                    ایجاد حساب
                </button>
            </form>

            <div class="register-back">
                حساب دارید؟
                <a href="/">بازگشت به سایت</a>
            </div>

        </div>
    </div>

    <script>
        /* ⭐ تبدیل خودکار ارقام فارسی/عربی به انگلیسی + فقط رقم */
        (function() {
            const input = document.getElementById('nationalCode');
            if (!input) return;

            const fa = '۰۱۲۳۴۵۶۷۸۹';
            const ar = '٠١٢٣٤٥٦٧٨٩';

            input.addEventListener('input', function() {
                let v = this.value;

                // تبدیل فارسی → انگلیسی
                v = v.replace(/[۰-۹]/g, d => fa.indexOf(d));
                // تبدیل عربی → انگلیسی
                v = v.replace(/[٠-٩]/g, d => ar.indexOf(d));
                // حذف غیر رقم
                v = v.replace(/[^\d]/g, '');
                // محدودیت ۱۰ رقم
                if (v.length > 10) v = v.slice(0, 10);

                this.value = v;
            });
        })();

        /* ⭐ تبدیل خودکار موبایل (فارسی → انگلیسی) */
        (function() {
            const input = document.querySelector('input[name="mobile"]');
            if (!input) return;

            const fa = '۰۱۲۳۴۵۶۷۸۹';
            const ar = '٠١٢٣٤٥٦٧٨٩';

            input.addEventListener('input', function() {
                let v = this.value;
                v = v.replace(/[۰-۹]/g, d => fa.indexOf(d));
                v = v.replace(/[٠-٩]/g, d => ar.indexOf(d));
                v = v.replace(/[^\d]/g, '');
                if (v.length > 11) v = v.slice(0, 11);
                this.value = v;
            });
        })();
    </script>

</body>

</html>
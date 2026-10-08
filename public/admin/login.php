<?php
require_once __DIR__ . '/../../config/bootstrap.php';
// admin/login.php

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

if (is_admin_logged_in()) {
    header('Location: /admin');
    exit;
}

$error  = '';
$mobile = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $mobile   = trim($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attempt_admin_login($mobile, $password)) {

        header('Location: /admin');
        exit;
    }

    $error =
        'شماره موبایل یا رمز عبور نادرست است.';
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0">

    <title>
        ورود مدیریت | رابطی
    </title>

    <link
        href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="/assets/css/app.css?v=18">

    <style>
        .admin-login-page {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            box-sizing: border-box;
            background:
                radial-gradient(circle at top right,
                    rgba(53, 104, 118, .10),
                    transparent 38%),
                #f6f2ea;
        }

        .admin-login-card {
            width: min(440px, 100%);
            background: #fff;
            border: 1px solid #e8e2d9;
            border-radius: 24px;
            padding: 30px;
            box-sizing: border-box;
            box-shadow:
                0 24px 70px rgba(31, 48, 57, .10);
        }

        .admin-login-brand {
            text-align: center;
            margin-bottom: 26px;
        }

        .admin-login-mark {
            width: 56px;
            height: 56px;
            margin: 0 auto 14px;
            display: grid;
            place-items: center;
            border-radius: 17px;
            background: #1e5262;
            color: #fff;
            font-size: 23px;
            font-weight: 900;
            box-shadow: 0 10px 25px rgba(30, 82, 98, .20);
        }

        .admin-login-brand h1 {
            margin: 0 0 6px;
            color: #213740;
            font-size: 22px;
            font-weight: 900;
        }

        .admin-login-brand p {
            margin: 0;
            color: #7c878d;
            font-size: 12px;
            line-height: 1.9;
        }

        .admin-login-label {
            display: block;
            margin-bottom: 7px;
            color: #334750;
            font-size: 12px;
            font-weight: 800;
        }

        .admin-login-field {
            margin-bottom: 15px;
        }

        .admin-login-field input {
            width: 100%;
            height: 46px;
            padding: 0 13px;
            box-sizing: border-box;
            border: 1px solid #dce3e6;
            border-radius: 12px;
            background: #fbfcfc;
            color: #23353d;
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: .18s ease;
        }

        .admin-login-field input:focus {
            background: #fff;
            border-color: #6d9ba8;
            box-shadow: 0 0 0 4px rgba(109, 155, 168, .10);
        }

        .admin-login-submit {
            width: 100%;
            height: 46px;
            border: 0;
            border-radius: 12px;
            background: #d77c36;
            color: #fff;
            font-family: inherit;
            font-size: 13px;
            font-weight: 900;
            cursor: pointer;
            transition: .18s ease;
        }

        .admin-login-submit:hover {
            transform: translateY(-1px);
            filter: brightness(.98);
        }

        .admin-login-error {
            padding: 10px 12px;
            margin-bottom: 14px;
            border: 1px solid #efd0cc;
            border-radius: 11px;
            background: #fff6f4;
            color: #b44b41;
            font-size: 12px;
            line-height: 1.8;
        }

        .admin-login-footer {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #edf0f1;
            text-align: center;
            color: #8b969b;
            font-size: 11px;
        }

        .admin-login-footer a {
            color: #3e6e7b;
            text-decoration: none;
            font-weight: 800;
        }

        @media (max-width: 520px) {

            .admin-login-page {
                padding: 15px;
            }

            .admin-login-card {
                padding: 22px 18px;
                border-radius: 20px;
            }

        }
    </style>

</head>

<body>

    <div class="admin-login-page">

        <div class="admin-login-card">

            <div class="admin-login-brand">

                <div class="admin-login-mark">
                    ر
                </div>

                <h1>
                    ورود مدیریت
                </h1>

                <p>
                    دسترسی امن به پنل مدیریت فروشگاه رابطی
                </p>

            </div>

            <?php if ($error): ?>

                <div class="admin-login-error">
                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>

            <?php endif; ?>

            <form method="post">

                <div class="admin-login-field">

                    <label
                        class="admin-login-label"
                        for="adminMobile">
                        شماره موبایل
                    </label>

                    <input
                        id="adminMobile"
                        type="tel"
                        name="mobile"
                        dir="ltr"
                        inputmode="numeric"
                        autocomplete="tel"
                        placeholder="09121234567"
                        value="<?= htmlspecialchars(
                                    $mobile,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                        required>

                </div>

                <div class="admin-login-field">

                    <label
                        class="admin-login-label"
                        for="adminPassword">
                        رمز عبور
                    </label>

                    <input
                        id="adminPassword"
                        type="password"
                        name="password"
                        dir="ltr"
                        autocomplete="current-password"
                        required>

                </div>

                <button
                    type="submit"
                    class="admin-login-submit">
                    ورود به پنل
                </button>

            </form>

            <div class="admin-login-footer">

                <a href="/">
                    بازگشت به سایت
                </a>

            </div>

        </div>

    </div>

</body>

</html>
<?php
require_once __DIR__ . '/../../config/bootstrap.php';
// api/register.php

require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(array $data, int $status = 200)
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'ok'    => false,
        'error' => 'متد درخواست نامعتبر است.'
    ], 405);
}

verify_csrf();

try {
    $fullName = trim($_POST['full_name'] ?? '');
    $companyName = trim($_POST['company_name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    // تبدیل اعداد فارسی به انگلیسی
    $mobile = strtr($mobile, [
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
    ]);

    // اعتبارسنجی
    if ($fullName === '') {
        json_response([
            'ok'    => false,
            'error' => 'نام و نام خانوادگی را وارد کنید.'
        ], 422);
    }

    if (!preg_match('/^09\d{9}$/', $mobile)) {
        json_response([
            'ok'    => false,
            'error' => 'شماره موبایل صحیح نیست.'
        ], 422);
    }

    if (strlen($password) < 8) {
        json_response([
            'ok'    => false,
            'error' => 'رمز عبور باید حداقل ۸ کاراکتر باشد.'
        ], 422);
    }

    if ($password !== $password2) {
        json_response([
            'ok'    => false,
            'error' => 'تکرار رمز عبور صحیح نیست.'
        ], 422);
    }

    // ثبت مشتری
    $result = register_customer(
        $fullName,
        $companyName,
        $mobile,
        $password
    );

    if (!$result['ok']) {
        json_response($result, 409);
    }

    json_response([
        'ok'       => true,
        'role'     => 'customer',
        'name'     => $result['name'],
        'id'       => $result['id'],
        'redirect' => '/'
    ], 200);
} catch (PDOException $e) {

    error_log(
        'REGISTER PDO ERROR: ' .
            $e->getMessage()
    );

    json_response([
        'ok'    => false,
        'error' => 'خطای پایگاه داده هنگام ثبت‌نام.'
    ], 500);
} catch (Throwable $e) {

    error_log(
        'REGISTER ERROR: ' .
            $e->getMessage()
    );

    json_response([
        'ok'    => false,
        'error' => 'خطای سرور هنگام ثبت‌نام.'
    ], 500);
}

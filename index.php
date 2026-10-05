<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$app = $config['app'];
$domain = trim((string) ($app['domain'] ?? ''));
$smsConfig = $config['sms'];
$auctionConfig = $config['auction'] ?? [];
$auctionDurationDays = max(1, min(3650, (int) ($auctionConfig['duration_days'] ?? 14)));
$auctionStartValue = (string) ($auctionConfig['start_at'] ?? '2026-10-05T14:30:00+03:30');
try {
    $auctionStartAt = new DateTimeImmutable($auctionStartValue, new DateTimeZone('Asia/Tehran'));
} catch (Throwable $exception) {
    error_log('Invalid auction start_at in config.php: ' . $exception->getMessage());
    $auctionStartAt = new DateTimeImmutable('2026-10-05T14:30:00+03:30');
}
$auctionEndAt = $auctionStartAt->modify('+' . $auctionDurationDays . ' days');
$auctionRemainingSeconds = max(0, $auctionEndAt->getTimestamp() - time());
$countdownParts = [
    'days' => intdiv($auctionRemainingSeconds, 86400),
    'hours' => intdiv($auctionRemainingSeconds % 86400, 3600),
    'minutes' => intdiv($auctionRemainingSeconds % 3600, 60),
    'seconds' => $auctionRemainingSeconds % 60,
];

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
]);
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function toEnglishDigits(string $value): string
{
    return strtr($value, [
        '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
    ]);
}

function toPersianDigits(string $value): string
{
    return strtr($value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}

/** Convert a Gregorian calendar date to the Jalali (Solar Hijri) calendar. */
function gregorianToJalali(int $year, int $month, int $day): array
{
    $gregorianMonthLengths = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $jalaliMonthLengths = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    $gy = $year - 1600;
    $gm = $month - 1;
    $gd = $day - 1;
    $days = 365 * $gy + intdiv($gy + 3, 4) - intdiv($gy + 99, 100) + intdiv($gy + 399, 400);
    for ($i = 0; $i < $gm; $i++) {
        $days += $gregorianMonthLengths[$i];
    }
    if ($gm > 1 && (($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0)) {
        $days++;
    }
    $days += $gd;
    $jalaliDayNumber = $days - 79;
    $jalaliCycles = intdiv($jalaliDayNumber, 12053);
    $jalaliDayNumber %= 12053;
    $jy = 979 + 33 * $jalaliCycles + 4 * intdiv($jalaliDayNumber, 1461);
    $jalaliDayNumber %= 1461;
    if ($jalaliDayNumber >= 366) {
        $jy += intdiv($jalaliDayNumber - 1, 365);
        $jalaliDayNumber = ($jalaliDayNumber - 1) % 365;
    }
    for ($i = 0; $i < 11 && $jalaliDayNumber >= $jalaliMonthLengths[$i]; $i++) {
        $jalaliDayNumber -= $jalaliMonthLengths[$i];
    }
    return [$jy, $i + 1, $jalaliDayNumber + 1];
}

function formatPersianDateTime(DateTimeImmutable $date): string
{
    $tehranDate = $date->setTimezone(new DateTimeZone('Asia/Tehran'));
    [$year, $month, $day] = gregorianToJalali(
        (int) $tehranDate->format('Y'),
        (int) $tehranDate->format('n'),
        (int) $tehranDate->format('j')
    );
    $monthNames = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    return toPersianDigits(sprintf('%d %s %d ساعت %s', $day, $monthNames[$month - 1], $year, $tehranDate->format('H:i')));
}

function normalizeMobile(string $mobile): string
{
    $mobile = toEnglishDigits(trim($mobile));
    $mobile = preg_replace('/[\s\-().]/u', '', $mobile) ?? '';
    if (str_starts_with($mobile, '+98')) {
        $mobile = '0' . substr($mobile, 3);
    } elseif (str_starts_with($mobile, '0098')) {
        $mobile = '0' . substr($mobile, 4);
    } elseif (str_starts_with($mobile, '98') && strlen($mobile) === 12) {
        $mobile = '0' . substr($mobile, 2);
    }
    return $mobile;
}

function maskMobile(string $mobile): string
{
    $length = strlen($mobile);
    if ($length <= 6) {
        return str_repeat('*', $length);
    }
    return substr($mobile, 0, 4) . str_repeat('*', $length - 6) . substr($mobile, -2);
}

function newCaptcha(): void
{
    $left = random_int(2, 9);
    $right = random_int(1, 8);
    $_SESSION['captcha_answer'] = (string) ($left + $right);
    $_SESSION['captcha_question'] = $left . ' + ' . $right;
}

function connectDatabase(array $database): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $database['host'],
        (int) $database['port'],
        $database['name'],
        $database['charset']
    );
    return new PDO($dsn, $database['user'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function sendPatternSms(array $smsConfig, string $to, int|string $patternId, array $variables): array
{
    if (empty($smsConfig['username']) || empty($smsConfig['api_key']) || empty($patternId)) {
        return ['ok' => false, 'reference' => null, 'error' => 'SMS credentials or pattern ID are not configured'];
    }
    if (!preg_match('/^09\d{9}$/', normalizeMobile($to))) {
        return ['ok' => false, 'reference' => null, 'error' => 'Invalid SMS recipient'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'reference' => null, 'error' => 'PHP cURL extension is required'];
    }

    // BaseServiceNumber expects one indexed text value per template variable.
    // Preserve scalar text for the already-working one-variable user pattern.
    // Use the panel username and the API key in place of the panel password.
    $patternValues = array_map(static fn($v) => str_replace(',', ' ', (string) $v), $variables);
    $patternText = count($patternValues) === 1 ? $patternValues[0] : array_values($patternValues);
    $payload = http_build_query([
        'username' => $smsConfig['username'],
        'password' => $smsConfig['api_key'],
        'text' => $patternText,
        'to' => $to,
        'bodyId' => (int) $patternId,
    ]);
    $ch = curl_init($smsConfig['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8'],
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        return ['ok' => false, 'reference' => null, 'error' => $curlError !== '' ? $curlError : 'SMS provider HTTP error ' . $statusCode];
    }

    $responseText = trim((string) $response);
    $decoded = json_decode($responseText, true);
    $providerValue = is_array($decoded) ? ($decoded['Value'] ?? $decoded['value'] ?? $decoded['Result'] ?? $decoded['result'] ?? null) : null;
    if ($providerValue === null && preg_match('/^-?\d+$/', $responseText)) {
        $providerValue = $responseText;
    }
    if ($providerValue === null) {
        $providerValue = $responseText;
    }

    // MeliPayamak returns a long numeric RecId when accepted and negative/short codes on failure.
    $reference = (string) $providerValue;
    $success = ctype_digit($reference) && strlen($reference) > 15;
    return ['ok' => $success, 'reference' => $success ? $reference : null, 'error' => $success ? null : 'MeliPayamak response: ' . mb_substr($responseText, 0, 240)];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['captcha_answer'])) {
    newCaptcha();
}

$errors = [];
$successMessage = '';
$form = ['full_name' => '', 'mobile' => '', 'email' => '', 'price' => '', 'description' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($form as $key => $_) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    if (!hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'درخواست معتبر نیست؛ صفحه را تازه‌سازی کرده و دوباره تلاش کنید.';
    }
    if (!empty($_POST['website'] ?? '')) {
        $errors[] = 'درخواست شما قابل پردازش نیست.';
    }

    $fullName = preg_replace('/\s+/u', ' ', $form['full_name']) ?? '';
    $mobile = normalizeMobile($form['mobile']);
    $email = trim($form['email']);
    $priceInput = toEnglishDigits($form['price']);
    $priceFormatValid = $priceInput !== '' && preg_match('/^[0-9\s,٬،]+$/u', $priceInput) === 1;
    $digitsPrice = preg_replace('/[\s,٬،]/u', '', $priceInput) ?? '';
    $digitsPrice = preg_replace('/[^0-9]/', '', $digitsPrice) ?? '';
    $description = $form['description'];

    if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
        $errors[] = 'نام و نام خانوادگی را به‌درستی وارد کنید.';
    }
    if (!preg_match('/^09\d{9}$/', $mobile)) {
        $errors[] = 'شماره موبایل باید شماره معتبر ایران باشد؛ مانند 09123456789.';
    }
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) {
        $errors[] = 'آدرس ایمیل معتبر نیست.';
    }
    if (!$priceFormatValid || $digitsPrice === '' || strlen($digitsPrice) < 4 || strlen($digitsPrice) > 20 || trim($digitsPrice, '0') === '') {
        $errors[] = 'قیمت پیشنهادی باید مبلغی معتبر و بزرگ‌تر از صفر باشد.';
    }
    if (mb_strlen($description) > 2000) {
        $errors[] = 'توضیحات نباید بیشتر از ۲۰۰۰ نویسه باشد.';
    }
    $captcha = toEnglishDigits(trim((string) ($_POST['captcha'] ?? '')));
    if (!hash_equals((string) ($_SESSION['captcha_answer'] ?? ''), $captcha)) {
        $errors[] = 'پاسخ کپچا درست نیست؛ دوباره تلاش کنید.';
    }
    if (isset($_SESSION['last_submit_at']) && time() - (int) $_SESSION['last_submit_at'] < 10) {
        $errors[] = 'لطفاً چند ثانیه صبر کنید و دوباره ارسال کنید.';
    }

    if (!$errors) {
        try {
            $pdo = connectDatabase($config['database']);
            $insert = $pdo->prepare(
                'INSERT INTO bids (full_name, mobile, email, price, description, sms_admin_status, sms_user_status, user_sms_sent, created_at)
                 VALUES (:full_name, :mobile, :email, :price, :description, :sms_admin_status, :sms_user_status, :user_sms_sent, NOW())'
            );
            $insert->execute([
                ':full_name' => $fullName,
                ':mobile' => $mobile,
                ':email' => $email !== '' ? $email : null,
                ':price' => $digitsPrice,
                ':description' => $description !== '' ? $description : null,
                ':sms_admin_status' => 'pending',
                ':sms_user_status' => 'pending',
                ':user_sms_sent' => 0,
            ]);
            $bidId = (int) $pdo->lastInsertId();
            $_SESSION['last_submit_at'] = time();

            $userSms = sendPatternSms($smsConfig, $mobile, $smsConfig['user_pattern_id'], [$fullName]);
            if ($userSms['ok']) {
                $adminSms = sendPatternSms($smsConfig, normalizeMobile((string) $smsConfig['admin_mobile']), $smsConfig['admin_pattern_id'], [$mobile, $digitsPrice]);
            } else {
                $adminSms = ['ok' => false, 'reference' => null, 'error' => 'Skipped because user SMS was not accepted'];
            }

            $update = $pdo->prepare('UPDATE bids SET sms_admin_status = :admin_status, sms_user_status = :user_status, user_sms_sent = :user_sms_sent, sms_admin_reference = :admin_reference, sms_user_reference = :user_reference WHERE id = :id');
            $update->execute([
                ':admin_status' => $adminSms['ok'] ? 'sent' : 'failed',
                ':user_status' => $userSms['ok'] ? 'sent' : 'failed',
                ':admin_reference' => $adminSms['reference'],
                ':user_reference' => $userSms['reference'],
                ':user_sms_sent' => $userSms['ok'] ? 1 : 0,
                ':id' => $bidId,
            ]);

            $successMessage = 'پیشنهاد شما برای دامنه ' . $domain . ' با موفقیت ثبت شد.';
            if ($userSms['ok']) {
                $successMessage .= ' پیامک تأیید نیز برای شما ارسال شد.';
            } else {
                error_log('MeliPayamak user notification failed for bid #' . $bidId . ': ' . (string) $userSms['error']);
            }
            if ($userSms['ok'] && !$adminSms['ok']) {
                error_log('MeliPayamak admin notification failed for bid #' . $bidId . ': ' . (string) $adminSms['error']);
            } elseif (!$userSms['ok']) {
                error_log('MeliPayamak admin notification skipped for bid #' . $bidId . ' because user SMS was not accepted');
            }
            $form = ['full_name' => '', 'mobile' => '', 'email' => '', 'price' => '', 'description' => ''];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            error_log('Auction application error: ' . $exception->getMessage());
            $errors[] = 'در حال حاضر ثبت پیشنهاد انجام نشد. لطفاً بعداً دوباره تلاش کنید.';
        }
    }
    newCaptcha();
}

$bids = [];
$databaseError = false;
try {
    $pdo = connectDatabase($config['database']);
    $bids = $pdo->query('SELECT id, mobile, price, created_at FROM (SELECT id, mobile, price, created_at FROM bids ORDER BY created_at DESC, id DESC LIMIT 10) AS latest_bids ORDER BY price DESC, created_at DESC, id DESC')->fetchAll();
} catch (Throwable $exception) {
    error_log('Auction database connection error: ' . $exception->getMessage());
    $databaseError = true;
}

function formatPrice(string|int $price): string
{
    $price = (string) $price;
    return preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $price) ?? $price;
}

$csrfToken = (string) $_SESSION['csrf_token'];
$captchaQuestion = (string) ($_SESSION['captcha_question'] ?? '');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2563eb">
    <meta name="description" content="<?= h('ثبت و بررسی پیشنهادهای خرید دامنه ' . $domain . '؛ پیشنهادها پس از ثبت مقایسه می‌شوند و در صورت تأیید با پیشنهاددهنده تماس گرفته می‌شود.') ?>">
    <title>مزایده دامنه <?= h($domain) ?> | پیشنهاد قیمت</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        :root{--ink:#321a2b;--muted:#806577;--pink:#f7d8e5;--pink2:#fff2f7;--magenta:#c83778;--magenta-dark:#9e205b;--line:#f1dbe5;--white:#fff;--green:#177b55;--shadow:0 20px 60px rgba(113,40,76,.12)}
        *{box-sizing:border-box}body{margin:0;color:var(--ink);font-family:'Vazirmatn',Tahoma,sans-serif;background:radial-gradient(ellipse at 7% 0%,#fff6fa 0,#fbe5ee 35%,#f6d4e2 100%);min-height:100vh}
        a{color:inherit}.wrap{width:min(1160px,calc(100% - 36px));margin:auto}.topbar{padding:23px 0;display:flex;align-items:center;justify-content:space-between;gap:16px}.brand{display:flex;align-items:center;gap:11px;font-weight:800}.brand-mark{padding:5px;width:auto;height:42px;border-radius:15px;background:linear-gradient(135deg,#d94b88,#9d2862);color:white;display:grid;place-items:center;font-size:20px;box-shadow:0 8px 18px #c8377840}.top-link{font-size:13px;color:var(--muted);text-decoration:none}
        .hero{position:relative;overflow:hidden;padding:44px 48px 40px;border-radius:30px;background:linear-gradient(120deg,#9e205b 0%,#c83778 48%,#e66a9d 100%);color:#fff;box-shadow:0 24px 60px #9e205b35}.hero:before,.hero:after{content:"";position:absolute;border:1px solid #ffffff24;border-radius:50%;width:310px;height:310px;left:-75px;top:-190px}.hero:after{width:210px;height:210px;left:80px;top:-110px}.hero-content{position:relative;z-index:1;max-width:760px}.eyebrow{display:inline-flex;gap:8px;align-items:center;padding:7px 13px;border-radius:30px;background:#ffffff20;font-size:12px;font-weight:600}.eyebrow-dot{width:7px;height:7px;background:#ffc7dc;border-radius:50%}.hero h1{font-size:clamp(29px,5vw,47px);line-height:1.35;margin:19px 0 10px;font-weight:800;letter-spacing:-1px}.hero h1 span{color:#ffd8e8;direction:ltr;unicode-bidi:isolate;display:inline-block}.hero p{max-width:650px;margin:0;color:#fff0f6;line-height:2.05;font-size:15px}.hero-meta{display:flex;flex-wrap:wrap;gap:9px;margin-top:23px}.meta-pill{border:1px solid #ffffff45;background:#ffffff16;padding:8px 12px;border-radius:12px;font-size:12px}
        .layout{display:grid;grid-template-columns:minmax(0,1.14fr) minmax(330px,.86fr);gap:22px;margin:24px 0 48px;align-items:start}.card{background:rgba(255,255,255,.89);border:1px solid #fff;border-radius:24px;box-shadow:var(--shadow);backdrop-filter:blur(8px)}.form-card{padding:27px}.list-card{padding:26px}.section-heading{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:23px}.section-heading h2{font-size:20px;margin:0 0 6px}.section-heading p{margin:0;color:var(--muted);font-size:12px;line-height:1.9}.section-number{width:38px;height:38px;border-radius:13px;background:#fbe4ed;color:var(--magenta);font-weight:800;display:grid;place-items:center;flex:none}.fields{display:grid;grid-template-columns:1fr 1fr;gap:15px}.field{display:flex;flex-direction:column;gap:7px}.field.full{grid-column:1/-1}.field label{font-size:12px;font-weight:700}.required{color:var(--magenta)}.field input,.field textarea{width:100%;font:inherit;font-size:13px;color:var(--ink);border:1px solid var(--line);background:#fffbfd;border-radius:13px;padding:12px 14px;outline:none;transition:.18s}.field input:focus,.field textarea:focus{border-color:#d86094;box-shadow:0 0 0 4px #f7d8e580;background:#fff}.field textarea{resize:vertical;min-height:92px}.hint{font-size:10px;color:#987f8c}.captcha-row{display:flex;align-items:center;gap:10px}.captcha-question{direction:ltr;unicode-bidi:isolate;background:#fbe4ed;padding:12px 14px;border-radius:12px;font-weight:800;color:var(--magenta);white-space:nowrap}.captcha-row input{min-width:0}.submit{width:100%;border:0;border-radius:14px;padding:14px;background:linear-gradient(110deg,#c83778,#a52560);color:#fff;font:inherit;font-size:14px;font-weight:800;cursor:pointer;box-shadow:0 11px 24px #b9327040;transition:transform .18s,filter .18s}.submit:hover{transform:translateY(-2px);filter:brightness(1.05)}.privacy-note{font-size:10px;color:var(--muted);line-height:1.9;text-align:center;margin:12px 4px 0}.alert{padding:13px 15px;border-radius:13px;margin:0 0 17px;font-size:12px;line-height:1.8}.alert.error{color:#9a214e;background:#fff0f4;border:1px solid #f6cad9}.alert.success{color:#176343;background:#edfbf5;border:1px solid #c5efda}.alert.warning{color:#8b5c11;background:#fff8e8;border:1px solid #f2e0ab}.alert ul{margin:5px 0 0;padding-right:19px}
        .list-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.live-tag{font-size:10px;color:var(--green);background:#e9f8f0;padding:6px 9px;border-radius:99px;font-weight:700;white-space:nowrap}.bid-count{font-size:11px;color:var(--muted);margin-top:5px}.bid-list{margin-top:16px;display:grid;gap:10px}.bid-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px 14px;border:1px solid #f3e5eb;background:linear-gradient(90deg,#fff,#fff9fc);border-radius:15px}.bid-row:first-child{border-color:#e9b1c9;background:linear-gradient(110deg,#fff5f9,#fff)}.bid-left{display:flex;flex-direction:column;gap:3px;min-width:0}.bid-price{font-size:14px;font-weight:800;direction:ltr;unicode-bidi:isolate;text-align:right}.bid-mobile{font-size:11px;color:var(--muted);direction:ltr;unicode-bidi:isolate;text-align:right;letter-spacing:.4px}.bid-right{text-align:left;flex:none}.rank{display:block;font-size:9px;color:#aa8596;margin-bottom:3px}.unit{font-size:10px;color:var(--muted);margin-right:4px}.empty{padding:28px 14px;text-align:center;color:var(--muted);background:#fff9fc;border-radius:15px;font-size:12px;line-height:2}.empty strong{display:block;color:var(--ink);font-size:14px}.sort-note{font-size:10px;color:var(--muted);line-height:1.9;margin-top:14px;padding-top:13px;border-top:1px solid var(--line)}.db-note{font-size:11px;color:#8b5c11;background:#fff8e8;padding:11px 12px;border-radius:12px;line-height:1.8;margin-top:14px}.footer{padding:0 0 27px;text-align:center;color:#947688;font-size:10px}.hp{position:absolute!important;left:-10000px!important;opacity:0!important}
        @media(max-width:850px){.layout{grid-template-columns:1fr}.list-card{order:2}.form-card{order:1}.hero{padding:34px 27px}.wrap{width:min(100% - 28px,680px)}}@media(max-width:520px){.topbar{padding:15px 0}.top-link{font-size:11px}.hero{border-radius:23px;padding:29px 22px}.hero p{font-size:13px}.hero h1{font-size:30px}.form-card,.list-card{padding:20px;border-radius:20px}.fields{grid-template-columns:1fr}.field.full{grid-column:auto}.captcha-row{gap:7px}.captcha-question{padding:12px 10px}.bid-row{padding:12px}.section-heading h2{font-size:18px}}
        /* Layout safety overrides: use the complete viewport width without creating horizontal scrolling. */
        html,body{max-width:100%;overflow-x:hidden}
        .wrap{width:100%;max-width:none;margin:0;padding-inline:clamp(14px,2.5vw,36px)}
        .layout{grid-template-columns:minmax(0,1.14fr) minmax(0,.86fr)}
        .layout>section,.card,.fields,.field,.section-heading>div:first-child{min-width:0}
        .fields{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
        .list-head{flex-wrap:wrap}
        .bid-price{overflow-wrap:anywhere}
        .captcha-row{min-width:0}
        .captcha-row input{flex:1;min-width:0}
        .hp{left:auto!important;top:0!important;width:1px!important;height:1px!important;margin:-1px!important;padding:0!important;overflow:hidden!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important}
        .bid-table-wrap{width:100%;overflow:hidden;margin-top:16px}
        .bid-table{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;font-size:12px}
        .bid-table th,.bid-table td{padding:12px 8px;text-align:right;border-bottom:1px solid var(--line);vertical-align:middle}
        .bid-table th{background:#fff2f7;color:var(--muted);font-size:11px}
        .bid-table th:first-child,.bid-table td:first-child{width:50px;text-align:center}
        .bid-table tbody tr:first-child td{background:#fff7fb;font-weight:700}
        .bid-table .price-cell{direction:ltr;unicode-bidi:isolate;text-align:right;overflow-wrap:anywhere;font-variant-numeric:tabular-nums}
        .bid-table .phone-cell{direction:ltr;unicode-bidi:isolate;text-align:right;white-space:nowrap;font-size:11px;letter-spacing:.3px}
        .bid-table .unit{display:inline-block;direction:rtl;font-weight:400}
        @media(max-width:520px){.bid-table th,.bid-table td{padding:10px 5px;font-size:10px}.bid-table th:first-child,.bid-table td:first-child{width:38px}.bid-table .phone-cell{font-size:10px}}
        @media(max-width:850px){.layout{grid-template-columns:minmax(0,1fr)}.wrap{padding-inline:14px}}
    </style>
</head>
<body>
<div class="wrap">
    <header class="topbar">
        <div class="brand"><div class="brand-mark" dir="ltr"><?= h($domain) ?></div><span>مزایده دامنه</span></div>
        <a class="top-link" href="#offer-form">ثبت پیشنهاد قیمت <span aria-hidden="true">←</span></a>
    </header>
    <main>
        <section class="hero" aria-labelledby="page-title">
            <div class="hero-content">
                <div class="eyebrow"><span class="eyebrow-dot"></span>فرصتی برای مالکیت یک نام به‌یادماندنی</div>
                <h1 id="page-title">پیشنهاد قیمت برای <span dir="ltr"><?= h($domain) ?></span></h1>
                <p>قیمت‌های پیشنهادی دریافت و با یکدیگر مقایسه می‌شوند. در صورت تأیید پیشنهاد، برای هماهنگی و ادامه فرایند با شما تماس خواهیم گرفت. ثبت پیشنهاد به‌تنهایی به معنی نهایی شدن فروش نیست.</p>
                <div class="hero-meta"><div class="meta-pill">دامنه مزایده: <b dir="ltr"><?= h($domain) ?></b></div><div class="meta-pill">ثبت آنلاین و آسان</div><div class="meta-pill">حریم خصوصی شماره همراه</div></div>
                <section class="auction-countdown" id="auction-countdown" role="timer" aria-live="off" data-end="<?= $auctionEndAt->getTimestamp() * 1000 ?>">
                    <div class="countdown-heading">
                        <span class="countdown-kicker">شمارش معکوس مزایده</span>
                        <strong>پایان مزایده: <?= h(formatPersianDateTime($auctionEndAt)) ?></strong>
                    </div>
                    <div class="countdown-units"<?= $auctionRemainingSeconds === 0 ? ' hidden' : '' ?>>
                        <div class="countdown-unit"><strong data-unit="days"><?= h(toPersianDigits((string) $countdownParts['days'])) ?></strong><span>روز</span></div>
                        <div class="countdown-unit"><strong data-unit="hours"><?= h(toPersianDigits(str_pad((string) $countdownParts['hours'], 2, '0', STR_PAD_LEFT))) ?></strong><span>ساعت</span></div>
                        <div class="countdown-unit"><strong data-unit="minutes"><?= h(toPersianDigits(str_pad((string) $countdownParts['minutes'], 2, '0', STR_PAD_LEFT))) ?></strong><span>دقیقه</span></div>
                        <div class="countdown-unit"><strong data-unit="seconds"><?= h(toPersianDigits(str_pad((string) $countdownParts['seconds'], 2, '0', STR_PAD_LEFT))) ?></strong><span>ثانیه</span></div>
                    </div>
                    <p class="countdown-ended"<?= $auctionRemainingSeconds > 0 ? ' hidden' : '' ?>>مهلت مزایده به پایان رسیده است.</p>
                </section>
            </div>
        </section>

        <div class="layout">
            <section class="card list-card" aria-labelledby="bid-list-title">
                <div class="section-heading">
                    <div><div class="list-head"><h2 id="bid-list-title">پیشنهادهای ثبت‌شده</h2><span class="live-tag">مرتب سازی بر اساس بیشترین قیمت</span></div><div class="bid-count"><?= count($bids) ?> پیشنهاد اخیر</div></div>
                    <div class="section-number">✅</div>
                </div>
                <?php if ($bids): ?>
                    <div class="bid-table-wrap">
                        <table class="bid-table">
                            <thead><tr><th scope="col">ردیف</th><th scope="col">قیمت پیشنهادی</th><th scope="col">شماره</th></tr></thead>
                            <tbody>
                            <?php foreach ($bids as $index => $bid): ?>
                                <tr><td class="row-number"><?= h((string) ($index + 1)) ?></td><td class="price-cell"><span class="unit"><?= h((string) $app['currency']) ?></span><bdi dir="ltr"><?= h((string) $bid['price']) ?></bdi></td><td class="phone-cell" aria-label="شماره همراه پوشانده شده"><?= h(maskMobile((string) $bid['mobile'])) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="sort-note">پیشنهادها از بالاترین قیمت به پایین‌ترین مرتب شده‌اند. برای رعایت حریم خصوصی، بخشی از شماره همراه پوشانده می‌شود.</div>
                <?php else: ?>
                    <div class="empty"><strong><?= $databaseError ? 'فهرست فعلاً در دسترس نیست' : 'هنوز پیشنهادی ثبت نشده است' ?></strong><?= $databaseError ? 'اتصال پایگاه داده را بررسی کنید.' : ('اولین پیشنهاد برای دامنه ' . h($domain) . ' را شما ثبت کنید.') ?></div>
                <?php endif; ?>
                <?php if ($databaseError): ?><div class="db-note">پایگاه داده هنوز آماده یا در دسترس نیست. لطفاً اطلاعات اتصال را در <code>config.php</code> تنظیم و فایل <code>database.sql</code> را اجرا کنید.</div><?php endif; ?>
            </section>

            <section class="card form-card" id="offer-form" aria-labelledby="form-title">
                <div class="section-heading">
                    <div><h2 id="form-title">ثبت پیشنهاد شما</h2><p>اطلاعاتتان را وارد کنید تا پیشنهادتان ثبت و بررسی شود.</p></div>
                    <div class="section-number">✅</div>
                </div>
                <?php if ($successMessage !== ''): ?><div class="alert success" role="status"><?= h($successMessage) ?></div><?php endif; ?>
                <?php if ($errors): ?><div class="alert error" role="alert">لطفاً موارد زیر را بررسی کنید:<ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
                <form method="post" action="#offer-form" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <div class="hp" aria-hidden="true"><label>وب‌سایت<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <div class="fields">
                        <div class="field full"><label for="full_name">نام و نام خانوادگی <span class="required">*</span></label><input id="full_name" name="full_name" type="text" required minlength="3" maxlength="120" autocomplete="name" value="<?= h($form['full_name']) ?>" placeholder="نام و نام خانوادگی"></div>
                        <div class="field"><label for="mobile">شماره موبایل <span class="required">*</span></label><input id="mobile" name="mobile" type="tel" inputmode="tel" required autocomplete="tel" maxlength="16" value="<?= h($form['mobile']) ?>" placeholder="09123456789"><span class="hint"></span></div>
                        <div class="field"><label for="email">ایمیل <span class="hint">(اختیاری)</span></label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" value="<?= h($form['email']) ?>" placeholder="name@example.com"></div>
                        <div class="field full"><label for="price">قیمت پیشنهادی (تومان) <span class="required">*</span></label><input id="price" name="price" type="text" inputmode="numeric" required value="<?= h($form['price']) ?>" placeholder="مثلاً ۲۵۰,۰۰۰,۰۰۰"></div>
                        <div class="field full"><label for="description">توضیحات <span class="hint">(اختیاری)</span></label><textarea id="description" name="description" maxlength="2000" placeholder="اگر نکته‌ای درباره پیشنهادتان دارید، اینجا بنویسید."><?= h($form['description']) ?></textarea></div>
                        <div class="field full"><label for="captcha">کپچای ساده: حاصل جمع را وارد کنید <span class="required">*</span></label><div class="captcha-row"><span class="captcha-question" aria-label="سؤال کپچا"><?= h($captchaQuestion) ?> = ؟</span><input id="captcha" name="captcha" type="text" inputmode="numeric" required maxlength="3" autocomplete="off" placeholder="پاسخ"></div></div>
                        <div class="field full"><button class="submit" type="submit">ثبت پیشنهاد قیمت <span aria-hidden="true">←</span></button></div>
                    </div>
                </form>
            </section>
        </div>
    </main>
    <footer class="footer">© <?= date('Y') ?> مزایده دامنه <span dir="ltr"><?= h($domain) ?></span> · طراحی و اجرا توسط <a href="https://l-ka.com">الکا</a></footer>
</div>
<script>
    (() => {
        const timer = document.getElementById('auction-countdown');
        if (!timer) return;
        const endAt = Number(timer.dataset.end);
        const units = timer.querySelector('.countdown-units');
        const ended = timer.querySelector('.countdown-ended');
        const formatter = new Intl.NumberFormat('fa-IR', {useGrouping: false, minimumIntegerDigits: 2});
        let intervalId = null;
        const update = () => {
            const secondsLeft = Math.max(0, Math.floor((endAt - Date.now()) / 1000));
            const values = {
                days: Math.floor(secondsLeft / 86400),
                hours: Math.floor((secondsLeft % 86400) / 3600),
                minutes: Math.floor((secondsLeft % 3600) / 60),
                seconds: secondsLeft % 60
            };
            Object.entries(values).forEach(([name, value]) => {
                const element = timer.querySelector(`[data-unit="${name}"]`);
                if (element) element.textContent = formatter.format(value);
            });
            if (secondsLeft === 0) {
                units.hidden = true;
                ended.hidden = false;
                if (intervalId) clearInterval(intervalId);
            }
        };
        update();
        intervalId = setInterval(update, 1000);
    })();
</script>
</body>
</html>

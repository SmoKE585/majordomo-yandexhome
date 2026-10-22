<?php
/**
 * OAuth2 authorization page shown during Yandex account linking.
 *
 * This is a public page: it does not load the admin bundle and uses its own
 * small stylesheet. Credentials are the module login/password, not the admin
 * account.
 *
 * @package MajorDoMo
 */

require_once __DIR__ . '/server.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$request = OAuth2\Request::createFromGlobals();
$yandexhome->WriteLog($yandexhome->IncomingRequestFormat($request));

$response = new OAuth2\Response();
if (!$server->validateAuthorizeRequest($request, $response)) {
    $response->send();
    $yandexhome->WriteLog('authorize.php >>> invalid request');
    exit;
}

if (empty($_SESSION['yh_csrf'])) {
    $_SESSION['yh_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['yh_csrf'];
$csrfValid = isset($_POST['csrf']) && is_string($_POST['csrf']) && hash_equals($csrf, $_POST['csrf']);

$userName = (string)$yandexhome->config['USER_NAME'];
$userPass = (string)$yandexhome->config['USER_PASS'];
$auth = $userName !== '' && $userPass !== ''
    && isset($_SESSION['user']) && hash_equals($userName, (string)$_SESSION['user']);
$_SESSION['AUTH'] = $auth;
$message = '';

if (!empty($_SESSION['message'])) {
    $message = (string)$_SESSION['message'];
    unset($_SESSION['message']);
}

if ($auth && isset($_POST['authorized']) && $csrfValid) {
    $isAuthorized = ($_POST['authorized'] === 'yes');
    $server->handleAuthorizeRequest($request, $response, $isAuthorized);
    $response->send();
    unset($_SESSION['user'], $_SESSION['AUTH'], $_SESSION['yh_csrf']);
    session_destroy();
    $yandexhome->WriteLog('authorize.php >>> ' . ($isAuthorized ? 'granted' : 'denied'));
    exit;
}

if (!$auth && isset($_POST['login'], $_POST['password'])) {
    $attempts = isset($_SESSION['yh_login_attempts']) ? (int)$_SESSION['yh_login_attempts'] : 0;
    $login = is_string($_POST['login']) ? trim($_POST['login']) : '';
    $password = is_string($_POST['password']) ? (string)$_POST['password'] : '';

    if (!$csrfValid) {
        $message = 'Сессия истекла. Обновите страницу и повторите попытку.';
    } elseif ($userName !== '' && $userPass !== ''
        && hash_equals($userName, $login) && hash_equals($userPass, $password)) {
        session_regenerate_id(true);
        $_SESSION['user'] = $userName;
        $_SESSION['AUTH'] = true;
        $_SESSION['yh_login_attempts'] = 0;
        $auth = true;
    } else {
        $attempts++;
        $_SESSION['yh_login_attempts'] = $attempts;
        if ($attempts > 2) {
            sleep(min($attempts, 5));
        }
        $message = 'Неверный логин или пароль. Повторите попытку.';
    }
}

$clientId = isset($request->query['client_id']) ? (string)$request->query['client_id'] : '';
$root = defined('ROOTHTML') ? (string)ROOTHTML : '/';
$logo = rtrim($root, '/') . '/assets/img/modules/yandexhome.png';
$css = rtrim($root, '/') . '/assets/modules/yandexhome/authorize.css';
$font = rtrim($root, '/') . '/3rdparty/fonts/rubik/rubik.css';

$e = static function ($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= $auth ? 'Доступ к MajorDoMo' : 'Вход в MajorDoMo' ?></title>
    <link rel="icon" href="<?= $e($logo) ?>">
    <link rel="stylesheet" href="<?= $e($font) ?>">
    <link rel="stylesheet" href="<?= $e($css) ?>">
</head>
<body class="yh-auth">
<main class="yh-auth__main">
    <section class="yh-auth__card" aria-labelledby="yh-auth-title">
        <header class="yh-auth__header">
            <img class="yh-auth__logo" src="<?= $e($logo) ?>" width="48" height="48" alt="">
            <div>
                <div class="yh-auth__eyebrow">Yandex Home · MajorDoMo</div>
                <h1 class="yh-auth__title" id="yh-auth-title">
                    <?= $auth ? 'Подтвердите доступ' : 'Вход в аккаунт MajorDoMo' ?>
                </h1>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="yh-auth__alert" role="alert"><?= $e($message) ?></div>
        <?php endif; ?>

        <?php if ($auth): ?>
            <div class="yh-auth__body">
                <p>Здравствуйте, <strong><?= $e($userName) ?></strong>!</p>
                <p>Приложение <strong>Yandex Home</strong> запрашивает доступ к вашему аккаунту
                    <strong>MajorDoMo</strong>, чтобы просматривать и менять состояние привязанных устройств.</p>
                <div class="yh-auth__note">
                    <span class="yh-auth__note-label">OAuth2 Client ID</span>
                    <code><?= $e($clientId) ?></code>
                </div>
            </div>
            <form method="post" class="yh-auth__actions">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <button class="yh-auth__button yh-auth__button--primary" type="submit" name="authorized" value="yes">
                    Предоставить доступ
                </button>
                <button class="yh-auth__button" type="submit" name="authorized" value="no">
                    Отказать
                </button>
            </form>
        <?php else: ?>
            <div class="yh-auth__body">
                <p>Введите логин и пароль, указанные в настройках модуля <strong>Yandex Home</strong>.
                    Это отдельная учётная запись для связки аккаунтов, а не пароль администратора.</p>
            </div>
            <form method="post" class="yh-auth__form">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <label class="yh-auth__field">
                    <span class="yh-auth__label">Логин</span>
                    <input class="yh-auth__input" type="text" name="login" autocomplete="username" required autofocus>
                </label>
                <label class="yh-auth__field">
                    <span class="yh-auth__label">Пароль</span>
                    <input class="yh-auth__input" type="password" name="password" autocomplete="current-password" required>
                </label>
                <button class="yh-auth__button yh-auth__button--primary yh-auth__button--block" type="submit">
                    Войти
                </button>
            </form>
        <?php endif; ?>

        <footer class="yh-auth__footer">
            <span>Защищённый OAuth 2.0 запрос</span>
            <span>MajorDoMo</span>
        </footer>
    </section>
</main>
</body>
</html>

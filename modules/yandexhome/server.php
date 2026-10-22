<?php
/**
 * Shared bootstrap for Yandex Home webhooks.
 *
 * These endpoints are called by Yandex directly (outside the admin shell), so
 * they boot the project the same way as other module endpoints: application
 * root, config, libraries, settings, then the OAuth2 server.
 *
 * @package MajorDoMo
 */

$applicationRoot = dirname(__DIR__, 2);
require_once $applicationRoot . '/bootstrap.php';
require_once MD_APP_ROOT . '/config.php';
require_once MD_APP_ROOT . '/lib/loader.php';
require_once MD_APP_ROOT . '/load_settings.php';
require_once DIR_MODULES . '/yandexhome/yandexhome.class.php';

$yandexhome = new yandexhome();

$dbName = \MajorDoMo\Core\Config::string('DB_NAME');
$dbHost = \MajorDoMo\Core\Config::string('DB_HOST');
$dbPort = \MajorDoMo\Core\Config::string('DB_PORT');
$dsn = 'mysql:dbname=' . $dbName . ';host=' . $dbHost;
if ($dbPort !== '') {
    $dsn .= ';port=' . $dbPort;
}
$dsn .= ';charset=utf8mb4';

require_once __DIR__ . '/lib/OAuth2/Autoloader.php';
OAuth2\Autoloader::register();

$storage = new OAuth2\Storage\MajordomoPdo(array(
    'dsn' => $dsn,
    'username' => \MajorDoMo\Core\Config::string('DB_USER'),
    'password' => \MajorDoMo\Core\Config::string('DB_PASSWORD'),
));

$server = new OAuth2\Server($storage, array(
    'access_lifetime' => 7 * 24 * 3600,
));
$server->addGrantType(new OAuth2\GrantType\ClientCredentials($storage));
$server->addGrantType(new OAuth2\GrantType\AuthorizationCode($storage));
$server->addGrantType(new OAuth2\GrantType\RefreshToken($storage, array(
    'always_issue_new_refresh_token' => true,
)));

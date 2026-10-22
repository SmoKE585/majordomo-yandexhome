<?php
/**
 * OAuth2 token endpoint (authorization code, refresh token, client credentials).
 *
 * @package MajorDoMo
 */

require_once __DIR__ . '/server.php';

header('Cache-Control: no-store');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$request = OAuth2\Request::createFromGlobals();
$yandexhome->WriteLog($yandexhome->IncomingRequestFormat($request));

$server->handleTokenRequest($request)->send();
$yandexhome->WriteLog('token.php >>> response sent');

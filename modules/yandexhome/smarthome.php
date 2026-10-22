<?php
/**
 * Yandex smart-home webhook (Provider Adapter API).
 *
 * Public endpoint called by Yandex:
 *   POST /modules/yandexhome/smarthome.php/v1.0/user/devices
 *   POST /modules/yandexhome/smarthome.php/v1.0/user/devices/query
 *   POST /modules/yandexhome/smarthome.php/v1.0/user/devices/action
 *   POST /modules/yandexhome/smarthome.php/v1.0/user/unlink
 *
 * @package MajorDoMo
 */

require_once __DIR__ . '/server.php';

header('X-Content-Type-Options: nosniff');

$request = OAuth2\Request::createFromGlobals();
$pathInfo = isset($request->server['PATH_INFO']) ? (string)$request->server['PATH_INFO'] : '';
$content = is_array($request->request) ? $request->request : array();

$respond = static function ($body, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo $body;
};

// Availability check: Yandex may POST a ping without a path.
if ($pathInfo === '' || $pathInfo === '/') {
    $command = isset($content['request']['command']) ? (string)$content['request']['command'] : '';
    $utterance = isset($content['request']['original_utterance']) ? (string)$content['request']['original_utterance'] : '';
    if ($command === 'ping' || $utterance === 'ping') {
        if ($yandexhome->debug_ping) {
            $yandexhome->WriteLog($yandexhome->IncomingRequestFormat($request));
            $yandexhome->WriteLog('smarthome.php <<< PING');
        }
        $respond($yandexhome->encodeYandexJson(array(
            'version' => YandexHomeCatalog::API_VERSION,
            'session' => array(
                'session_id' => isset($content['session']['session_id']) ? $content['session']['session_id'] : '',
                'message_id' => isset($content['session']['message_id']) ? $content['session']['message_id'] : '',
                'user_id' => isset($content['session']['user_id']) ? $content['session']['user_id'] : '',
            ),
            'response' => array('text' => 'pong'),
        )));
        exit;
    }

    $respond($yandexhome->encodeYandexJson(array('success' => false, 'message' => 'Bad request')), 400);
    exit;
}

$yandexhome->WriteLog($yandexhome->IncomingRequestFormat($request));

if (!$server->verifyResourceRequest($request)) {
    $server->getResponse()->send();
    $yandexhome->WriteLog('smarthome.php >>> access denied');
    exit;
}

$content['request_id'] = isset($request->headers['X_REQUEST_ID'])
    ? (string)$request->headers['X_REQUEST_ID']
    : '';

$apiQuery = explode('/', trim($pathInfo, '/'));
$version = array_shift($apiQuery);
$scope = array_shift($apiQuery);
if ($version !== 'v' . YandexHomeCatalog::API_VERSION || $scope !== 'user') {
    $yandexhome->WriteLog('smarthome.php === Unsupported API version: ' . $pathInfo);
    $respond($yandexhome->encodeYandexJson(array('success' => false, 'message' => 'Unsupported API version')), 400);
    exit;
}

$resource = isset($apiQuery[0]) ? (string)$apiQuery[0] : '';
if ($resource === 'devices') {
    $action = isset($apiQuery[1]) ? (string)$apiQuery[1] : '';
    if ($action === 'query') {
        $body = $yandexhome->HandleQueryRequest($content);
    } elseif ($action === 'action') {
        $body = $yandexhome->HandleExecuteRequest($content);
    } elseif ($action === '') {
        $body = $yandexhome->HandleSyncRequest($content);
    } else {
        $yandexhome->WriteLog('smarthome.php === Unsupported devices command: ' . $action);
        $respond($yandexhome->encodeYandexJson(array('success' => false, 'message' => 'Unsupported command')), 400);
        exit;
    }
} elseif ($resource === 'unlink' && count($apiQuery) === 1) {
    $body = $yandexhome->HandleUnlinkRequest($content);
} else {
    $yandexhome->WriteLog('smarthome.php === Unsupported API command: ' . $pathInfo);
    $respond($yandexhome->encodeYandexJson(array('success' => false, 'message' => 'Unsupported command')), 400);
    exit;
}

$respond($body);
$yandexhome->WriteLog('smarthome.php >>> ' . $body);

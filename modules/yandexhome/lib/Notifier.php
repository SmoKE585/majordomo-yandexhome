<?php
/**
 * Yandex Dialogs notification client.
 *
 * Sends discovery/state callbacks for skills that enable reportable state. The
 * module keeps working in polling mode when the callback credentials are not
 * configured: all methods return a structured result instead of throwing.
 *
 * @package MajorDoMo
 */

final class YandexHomeNotifier
{
    /** @var array<string,mixed> */
    private $config;

    /** @var YandexHomeTraitMapper */
    private $mapper;

    /**
     * @param array<string,mixed> $config Module config.
     * @param YandexHomeTraitMapper $mapper
     */
    public function __construct(array $config, YandexHomeTraitMapper $mapper)
    {
        $this->config = $config;
        $this->mapper = $mapper;
    }

    /**
     * Skill/dialog id used by callback URLs.
     *
     * @return string
     */
    public function skillId()
    {
        if (!empty($this->config['SKILL_ID'])) {
            return trim((string)$this->config['SKILL_ID']);
        }
        // Backward compatibility: older settings used CLIENT_KEY as skill id.
        if (!empty($this->config['CLIENT_KEY'])) {
            return trim((string)$this->config['CLIENT_KEY']);
        }

        return '';
    }

    /**
     * Whether callbacks can be sent.
     *
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->config['SKILL_ACCESS_TOKEN']) && $this->skillId() !== '';
    }

    /**
     * Yandex user id derived from the module login.
     *
     * @return string
     */
    public function userId()
    {
        return md5(isset($this->config['USER_NAME']) ? (string)$this->config['USER_NAME'] : '');
    }

    /**
     * Send a discovery callback.
     *
     * @return array{ok:bool,http:int,body:string,error:string}
     */
    public function sendDiscovery()
    {
        if (!$this->isConfigured()) {
            return array('ok' => false, 'http' => 0, 'body' => '', 'error' => 'Callback credentials are not configured');
        }

        $payload = array(
            'ts' => microtime(true),
            'payload' => array('user_id' => $this->userId()),
        );

        return $this->request('/callback/discovery', $payload);
    }

    /**
     * Send one device state callback.
     *
     * @param array<string,mixed> $deviceState
     * @return array{ok:bool,http:int,body:string,error:string}
     */
    public function sendState(array $deviceState)
    {
        if (!$this->isConfigured()) {
            return array('ok' => false, 'http' => 0, 'body' => '', 'error' => 'Callback credentials are not configured');
        }

        $payload = array(
            'ts' => microtime(true),
            'payload' => array(
                'user_id' => $this->userId(),
                'devices' => array($deviceState),
            ),
        );

        return $this->request('/callback/state', $payload);
    }

    /**
     * POST a JSON payload to the Yandex Dialogs API.
     *
     * @param string $path
     * @param array<string,mixed> $payload
     * @return array{ok:bool,http:int,body:string,error:string}
     */
    private function request($path, array $payload)
    {
        $url = 'https://dialogs.yandex.net/api/v1/skills/'
            . rawurlencode($this->skillId()) . $path;
        $body = $this->mapper->encodeJson($payload);

        if (!function_exists('curl_init')) {
            return array('ok' => false, 'http' => 0, 'body' => '', 'error' => 'PHP curl extension is not available');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return array('ok' => false, 'http' => 0, 'body' => '', 'error' => 'Cannot initialize curl');
        }

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        curl_setopt($curl, CURLOPT_TIMEOUT, 15);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-type: application/json',
            'Authorization: OAuth ' . (string)$this->config['SKILL_ACCESS_TOKEN'],
        ));

        $this->applyProxy($curl);

        $response = curl_exec($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        return array(
            'ok' => $httpCode >= 200 && $httpCode < 300,
            'http' => $httpCode,
            'body' => is_string($response) ? $response : '',
            'error' => $error !== '' ? $error : '',
        );
    }

    /**
     * Apply the project-wide proxy settings when configured.
     *
     * @param resource $curl
     * @return void
     */
    private function applyProxy($curl)
    {
        if (!class_exists('\\MajorDoMo\\Core\\Config')) {
            return;
        }
        $proxy = \MajorDoMo\Core\Config::string('USE_PROXY');
        if ($proxy === '') {
            return;
        }
        curl_setopt($curl, CURLOPT_PROXY, $proxy);
        $proxyAuth = \MajorDoMo\Core\Config::string('USE_PROXY_AUTH');
        if ($proxyAuth !== '') {
            curl_setopt($curl, CURLOPT_PROXYUSERPWD, $proxyAuth);
        }
    }
}

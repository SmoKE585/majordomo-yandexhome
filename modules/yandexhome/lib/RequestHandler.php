<?php
/**
 * Yandex smart-home request adapter.
 *
 * Implements the three Provider Adapter API calls used by the module:
 * discovery (`/user/devices`), state (`/user/devices/query`) and control
 * (`/user/devices/action`). Payloads are kept compatible with the legacy
 * module; only the code structure and validation changed.
 *
 * @package MajorDoMo
 */

final class YandexHomeRequestHandler
{
    /** @var YandexHomeRepository */
    private $repository;

    /** @var YandexHomeTraitMapper */
    private $mapper;

    /** @var YandexHomeNotifier */
    private $notifier;

    /** @var string */
    private $moduleName;

    /** @var array<string,mixed> */
    private $config;

    /** @var callable|null */
    private $logger;

    /**
     * @param YandexHomeRepository $repository
     * @param YandexHomeTraitMapper $mapper
     * @param YandexHomeNotifier $notifier
     * @param string $moduleName
     * @param array<string,mixed> $config
     * @param callable|null $logger
     */
    public function __construct(
        YandexHomeRepository $repository,
        YandexHomeTraitMapper $mapper,
        YandexHomeNotifier $notifier,
        $moduleName,
        array $config,
        $logger = null
    ) {
        $this->repository = $repository;
        $this->mapper = $mapper;
        $this->notifier = $notifier;
        $this->moduleName = (string)$moduleName;
        $this->config = $config;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    /**
     * Discovery: list all devices.
     *
     * @param array<string,mixed> $content
     * @return string
     */
    public function handleSync(array $content)
    {
        $this->log('Incoming sync request');
        $devices = array();

        foreach ($this->repository->devices() as $device) {
            $traits = $this->repository->traits($device);
            $instanceConfig = $this->repository->instanceConfigFromDevice($device);
            $config = $this->mapper->buildDeviceConfig(
                $device,
                $traits,
                $instanceConfig,
                $this->notifier->isConfigured()
            );
            if ($config === '') {
                continue;
            }
            if (!isset($device['CONFIG']) || $device['CONFIG'] !== $config) {
                $device['CONFIG'] = $config;
                $device['TRAITS'] = json_encode($traits, YandexHomeCatalog::JSON_FLAGS);
                $this->repository->saveDevice($device);
            }

            $decoded = json_decode($config, true);
            if ($this->mapper->isValidDiscoveryDevice($decoded)) {
                $this->log('Sync device: ID=' . (isset($device['ID']) ? $device['ID'] : '')
                    . '; TITLE=' . (isset($device['TITLE']) ? $device['TITLE'] : '')
                    . '; TYPE=' . (isset($decoded['type']) ? $decoded['type'] : ''));
                $devices[] = $decoded;
            }
        }

        return $this->mapper->encodeJson(array(
            'request_id' => isset($content['request_id']) ? $content['request_id'] : '',
            'payload' => array(
                'user_id' => $this->notifier->userId(),
                'devices' => $devices,
            ),
        ));
    }

    /**
     * Query: current state of the requested devices.
     *
     * @param array<string,mixed> $content
     * @return string
     */
    public function handleQuery(array $content)
    {
        $devices = array();
        $requested = isset($content['devices']) && is_array($content['devices']) ? $content['devices'] : array();

        foreach ($requested as $requestDevice) {
            $deviceId = isset($requestDevice['id']) ? (int)$requestDevice['id'] : 0;
            $this->log('Incoming query request for device ID' . $deviceId);

            $device = $this->repository->device($deviceId);
            if ($device === null || empty($device['TRAITS'])) {
                $devices[] = array(
                    'id' => $deviceId,
                    'error_code' => 'DEVICE_NOT_FOUND',
                    'error_message' => 'DEVICE_NOT_FOUND_MSG',
                );
                continue;
            }

            $traits = $this->repository->traits($device);
            $capabilities = array();
            $properties = array();
            foreach ($traits as $traitType => $trait) {
                $definition = YandexHomeCatalog::instance($traitType);
                if ($definition === null) {
                    continue;
                }
                $capability = isset($definition['capability']) ? (string)$definition['capability'] : '';
                if ($capability === 'video_stream') {
                    continue;
                }

                $value = $this->mapper->linkedValue($trait, $definition);
                $state = array(
                    'instance' => YandexHomeCatalog::instanceName($traitType, $definition),
                    'value' => $this->mapper->normalizeValueForState($traitType, $definition, $value, $trait),
                );

                if (YandexHomeCatalog::isPropertyCapability($capability)) {
                    $properties[] = array(
                        'type' => YandexHomeCatalog::PREFIX_PROPERTIES . $capability,
                        'state' => $state,
                    );
                } else {
                    $capabilities[] = array(
                        'type' => YandexHomeCatalog::PREFIX_CAPABILITIES . $capability,
                        'state' => $state,
                    );
                }
            }

            $deviceState = array('id' => $deviceId);
            if ($capabilities !== array()) {
                $deviceState['capabilities'] = $capabilities;
            }
            if ($properties !== array()) {
                $deviceState['properties'] = $properties;
            }
            $devices[] = $deviceState;
        }

        return $this->mapper->encodeJson(array(
            'request_id' => isset($content['request_id']) ? $content['request_id'] : '',
            'payload' => array('devices' => $devices),
        ));
    }

    /**
     * Execute: apply capability changes.
     *
     * @param array<string,mixed> $content
     * @return string
     */
    public function handleExecute(array $content)
    {
        $devices = array();
        $requested = isset($content['payload']['devices']) && is_array($content['payload']['devices'])
            ? $content['payload']['devices']
            : array();

        foreach ($requested as $requestDevice) {
            $deviceId = isset($requestDevice['id']) ? (int)$requestDevice['id'] : 0;
            $this->log('Incoming action request for device ID' . $deviceId);

            $device = $this->repository->device($deviceId);
            $traits = $device !== null ? $this->repository->traits($device) : array();
            $capabilities = array();

            $requestCapabilities = isset($requestDevice['capabilities']) && is_array($requestDevice['capabilities'])
                ? $requestDevice['capabilities']
                : array();

            foreach ($requestCapabilities as $capability) {
                $type = isset($capability['type']) ? (string)$capability['type'] : '';
                $stateRequest = isset($capability['state']) && is_array($capability['state']) ? $capability['state'] : array();
                $value = array_key_exists('value', $stateRequest) ? $stateRequest['value'] : null;
                $instance = isset($stateRequest['instance']) ? (string)$stateRequest['instance'] : '';
                $relative = isset($stateRequest['relative']) && $stateRequest['relative'] === true;

                $state = array('instance' => $instance);
                $errorCode = '';
                $errorMessage = '';

                if ($device === null) {
                    $errorCode = 'DEVICE_NOT_FOUND';
                    $errorMessage = 'DEVICE_NOT_FOUND_MSG';
                } else {
                    $traitType = $this->mapper->findTraitTypeByInstance($traits, $instance, $type);
                    $trait = $traitType !== null && isset($traits[$traitType]) ? $traits[$traitType] : null;
                    $definition = $traitType !== null ? YandexHomeCatalog::instance($traitType) : null;

                    if (!is_array($trait) || !is_array($definition)) {
                        $errorCode = 'INVALID_ACTION';
                        $errorMessage = 'INVALID_ACTION_MSG';
                    } else {
                        $capabilityName = isset($definition['capability']) ? (string)$definition['capability'] : '';
                        if ($capabilityName === 'video_stream' && $instance === 'get_stream') {
                            $streamUrl = $this->mapper->linkedValue($trait, $definition);
                            if (!$streamUrl) {
                                $errorCode = 'DEVICE_UNREACHABLE';
                                $errorMessage = 'Stream URL is empty';
                            } else {
                                $state['value'] = array(
                                    'stream_url' => (string)$streamUrl,
                                    'protocol' => 'hls',
                                );
                            }
                        } else {
                            $linkedObject = isset($trait['linked_object']) ? (string)$trait['linked_object'] : '';
                            $linkedProperty = isset($trait['linked_property']) ? (string)$trait['linked_property'] : '';
                            if ($linkedObject === '' || $linkedProperty === '') {
                                $errorCode = 'INVALID_ACTION';
                                $errorMessage = 'Linked object/property not configured';
                            } else {
                                if ($relative && $capabilityName === 'range') {
                                    $value = (float)getGlobal($linkedObject . '.' . $linkedProperty) + (float)$value;
                                }
                                $converted = $this->mapper->normalizeValueForWrite($traitType, $definition, $value, $trait);
                                if (!empty($this->config['READONLY_MODE'])) {
                                    $this->log('The property was not set: the module is in read-only mode');
                                    $errorCode = 'NOT_SUPPORTED_IN_CURRENT_MODE';
                                    $errorMessage = 'The device is not controlled in this mode. The module is in read-only mode.';
                                } else {
                                    setGlobal(
                                        $linkedObject . '.' . $linkedProperty,
                                        $converted,
                                        array($this->moduleName => '0')
                                    );
                                    $this->log("Object '$linkedObject', property '$linkedProperty', set value=" . json_encode($converted));
                                }
                            }
                        }
                    }
                }

                $state['action_result'] = $errorCode === ''
                    ? array('status' => 'DONE')
                    : array('status' => 'ERROR', 'error_code' => $errorCode, 'error_message' => $errorMessage);

                $capabilities[] = array('type' => $type, 'state' => $state);
            }

            $deviceResult = array('id' => $deviceId);
            if ($capabilities !== array()) {
                $deviceResult['capabilities'] = $capabilities;
            } else {
                $deviceResult['action_result'] = array(
                    'status' => 'ERROR',
                    'error_code' => $device !== null ? 'INVALID_ACTION' : 'DEVICE_NOT_FOUND',
                    'error_message' => $device !== null ? 'No capabilities in request' : 'DEVICE_NOT_FOUND_MSG',
                );
            }
            $devices[] = $deviceResult;
        }

        return $this->mapper->encodeJson(array(
            'request_id' => isset($content['request_id']) ? $content['request_id'] : '',
            'payload' => array('devices' => $devices),
        ));
    }

    /**
     * Unlink: Yandex asks the provider to forget the account.
     *
     * The legacy adapter only logged this event; tokens live in the OAuth
     * table and are removed when the user unlinks the skill on the Yandex side.
     *
     * @param array<string,mixed> $content
     * @return string
     */
    public function handleUnlink(array $content)
    {
        $this->log('Incoming unlink request');

        return $this->mapper->encodeJson(array(
            'request_id' => isset($content['request_id']) ? $content['request_id'] : '',
        ));
    }

    /**
     * Write a debug message through the module logger.
     *
     * @param string $message
     * @return void
     */
    private function log($message)
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, (string)$message);
        }
    }
}

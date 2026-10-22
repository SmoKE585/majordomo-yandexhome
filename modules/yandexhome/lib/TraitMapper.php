<?php
/**
 * Base trait value mapping for the Yandex Home adapter.
 *
 * Converts MajorDoMo property values to Yandex state values and back, and
 * builds the discovery payload stored in `yandexhome_devices.CONFIG`.
 *
 * @package MajorDoMo
 */

final class YandexHomeTraitMapper
{
    /**
     * Normalize a stored trait list: stable keys, current type names, defaults.
     *
     * @param array<string,mixed> $traits
     * @return array<string,array<string,mixed>>
     */
    public function normalizeTraits(array $traits)
    {
        $result = array();
        foreach ($traits as $key => $trait) {
            if (!is_array($trait)) {
                continue;
            }
            $type = isset($trait['type']) ? (string)$trait['type'] : (string)$key;
            $type = YandexHomeCatalog::normalizeTraitType($type);
            if (YandexHomeCatalog::instance($type) === null) {
                continue;
            }

            $trait['type'] = $type;
            $trait['linked_object'] = isset($trait['linked_object']) ? trim((string)$trait['linked_object']) : '';
            $trait['linked_property'] = isset($trait['linked_property']) ? trim((string)$trait['linked_property']) : '';
            $trait['reportable'] = !empty($trait['reportable']);
            if (!isset($trait['description']) || $trait['description'] === '') {
                $definition = YandexHomeCatalog::instance($type);
                $trait['description'] = isset($definition['description']) ? $definition['description'] : $type;
            }
            if (!isset($trait['value_map_preset']) || !is_string($trait['value_map_preset'])) {
                $trait['value_map_preset'] = YandexHomeCatalog::defaultPreset($type);
            }
            if (!isset($trait['value_map']) || !is_array($trait['value_map'])) {
                $trait['value_map'] = YandexHomeCatalog::defaultValueMap($type);
            }
            $normalizedMap = array();
            foreach ($trait['value_map'] as $source => $target) {
                $source = strtolower(trim((string)$source));
                $target = trim((string)$target);
                if ($source !== '' && $target !== '') {
                    $normalizedMap[$source] = $target;
                }
            }
            $trait['value_map'] = $normalizedMap;
            $result[$type] = $trait;
        }

        return $result;
    }

    /**
     * Normalize per-trait instance configuration (range overrides, split).
     *
     * @param array<string,mixed> $config
     * @return array<string,array<string,mixed>>
     */
    public function normalizeInstanceConfig(array $config)
    {
        $result = array();
        foreach ($config as $type => $settings) {
            if (!is_array($settings)) {
                continue;
            }
            $type = YandexHomeCatalog::normalizeTraitType($type);
            if (YandexHomeCatalog::instance($type) === null) {
                continue;
            }
            $normalized = array();
            if (isset($settings['parameters']) && is_array($settings['parameters'])) {
                $parameters = array();
                if (isset($settings['parameters']['range']) && is_array($settings['parameters']['range'])) {
                    $range = array();
                    foreach (array('min', 'max', 'precision') as $key) {
                        if (isset($settings['parameters']['range'][$key]) && is_numeric($settings['parameters']['range'][$key])) {
                            $range[$key] = $settings['parameters']['range'][$key] + 0;
                        }
                    }
                    if ($range !== array()) {
                        $parameters['range'] = $range;
                    }
                }
                if (array_key_exists('split', $settings['parameters'])) {
                    $parameters['split'] = !empty($settings['parameters']['split']);
                }
                if ($parameters !== array()) {
                    $normalized['parameters'] = $parameters;
                }
            }
            if ($normalized !== array()) {
                $result[$type] = $normalized;
            }
        }

        return $result;
    }

    /**
     * Default trait record for a newly added trait.
     *
     * @param string $type
     * @return array<string,mixed>
     */
    public function defaultTrait($type)
    {
        $type = YandexHomeCatalog::normalizeTraitType($type);
        $definition = YandexHomeCatalog::instance($type);

        return array(
            'type' => $type,
            'description' => isset($definition['description']) ? (string)$definition['description'] : $type,
            'linked_object' => '',
            'linked_property' => '',
            'reportable' => false,
            'value_map_preset' => YandexHomeCatalog::defaultPreset($type),
            'value_map' => YandexHomeCatalog::defaultValueMap($type),
        );
    }

    /**
     * Normalize a linked property value for a Yandex state payload.
     *
     * @param string $traitType
     * @param array<string,mixed> $instanceDef
     * @param mixed $value
     * @param array<string,mixed> $trait
     * @return mixed
     */
    public function normalizeValueForState($traitType, array $instanceDef, $value, array $trait = array())
    {
        if (isset($trait['value_map']) && is_array($trait['value_map']) && $trait['value_map'] !== array()) {
            $mapKey = strtolower(trim((string)$value));
            if (isset($trait['value_map'][$mapKey])) {
                $value = $trait['value_map'][$mapKey];
            }
        }

        $valueType = isset($instanceDef['value_type']) ? (string)$instanceDef['value_type'] : 'string';
        switch ($valueType) {
            case 'bool':
                if (is_bool($value)) {
                    return $value;
                }
                $normalized = strtolower(trim((string)$value));
                if (in_array($normalized, array('1', 'true', 'on', 'yes'), true)) {
                    return true;
                }
                if (in_array($normalized, array('0', 'false', 'off', 'no', ''), true)) {
                    return false;
                }

                return ((float)$value !== 0.0);
            case 'float':
                return (float)$value;
            case 'int':
                return (int)$value;
            case 'rgb':
                $rgb = preg_replace('/^#/', '', (string)$value);

                return hexdec((string)$rgb);
            case 'event':
                return $this->normalizeEventValueForState($traitType, $value, $trait);
            default:
                return $value;
        }
    }

    /**
     * Normalize a Yandex value before writing it to a property.
     *
     * @param string $traitType
     * @param array<string,mixed> $instanceDef
     * @param mixed $value
     * @param array<string,mixed> $trait
     * @return mixed
     */
    public function normalizeValueForWrite($traitType, array $instanceDef, $value, array $trait = array())
    {
        $valueType = isset($instanceDef['value_type']) ? (string)$instanceDef['value_type'] : 'string';
        switch ($valueType) {
            case 'bool':
                return ($value === true || $value === 1 || $value === '1') ? 1 : 0;
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'rgb':
                if (is_numeric($value)) {
                    return str_pad(dechex((int)$value), 6, '0', STR_PAD_LEFT);
                }

                return preg_replace('/^#/', '', (string)$value);
            case 'event':
                return (string)$value;
            default:
                return $value;
        }
    }

    /**
     * Map a raw event value to the Yandex event enum.
     *
     * @param string $traitType
     * @param mixed $value
     * @param array<string,mixed> $trait
     * @return string
     */
    public function normalizeEventValueForState($traitType, $value, array $trait = array())
    {
        if (is_string($value) && $value !== '') {
            $known = array(
                'opened', 'closed', 'detected', 'not_detected', 'click', 'double_click', 'long_press',
                'leak', 'dry', 'high', 'low', 'normal', 'empty',
            );
            if (in_array($value, $known, true)) {
                return $value;
            }
        }

        switch ($traitType) {
            case 'motion_sensor':
            case 'smoke_sensor':
            case 'gas_sensor':
                return $value ? 'detected' : 'not_detected';
            case 'water_leak_sensor':
                return $value ? 'leak' : 'dry';
            case 'open_sensor':
                $linkedObject = isset($trait['linked_object']) ? (string)$trait['linked_object'] : '';
                if ($linkedObject !== '') {
                    $ncno = function_exists('gg') ? gg($linkedObject . '.ncno') : '';
                    if ($ncno === 'nc') {
                        return $value ? 'closed' : 'opened';
                    }
                }

                return $value ? 'opened' : 'closed';
            case 'battery_level_event_sensor':
                return $value ? 'low' : 'normal';
            case 'food_level_event_sensor':
            case 'water_level_event_sensor':
                if ((string)$value === '0') {
                    return 'empty';
                }
                if ((string)$value === '1') {
                    return 'low';
                }
                if ((string)$value === '2') {
                    return 'normal';
                }

                return $value ? 'low' : 'normal';
            default:
                $normalized = strtolower((string)$value);
                if ($normalized === '1' || $normalized === 'true' || $normalized === 'on') {
                    return 'detected';
                }
                if ($normalized === '0' || $normalized === 'false' || $normalized === 'off') {
                    return 'not_detected';
                }

                return (string)$value;
        }
    }

    /**
     * Current value of a trait's linked property (or its default).
     *
     * @param array<string,mixed> $trait
     * @param array<string,mixed> $instanceDef
     * @return mixed
     */
    public function linkedValue(array $trait, array $instanceDef)
    {
        $linkedObject = isset($trait['linked_object']) ? (string)$trait['linked_object'] : '';
        $linkedProperty = isset($trait['linked_property']) ? (string)$trait['linked_property'] : '';
        if ($linkedObject !== '' && $linkedProperty !== '') {
            return getGlobal($linkedObject . '.' . $linkedProperty);
        }
        if (array_key_exists('default_value', $instanceDef)) {
            return $instanceDef['default_value'];
        }

        return null;
    }

    /**
     * Find a trait by its Yandex instance name and request capability type.
     *
     * @param array<string,mixed> $traits
     * @param string $instance
     * @param string $requestType
     * @return string|null
     */
    public function findTraitTypeByInstance(array $traits, $instance, $requestType)
    {
        foreach ($traits as $traitType => $trait) {
            if (!is_array($trait)) {
                continue;
            }
            $rawType = isset($trait['type']) ? (string)$trait['type'] : (string)$traitType;
            $definition = YandexHomeCatalog::instance($rawType);
            if ($definition === null) {
                continue;
            }
            if (YandexHomeCatalog::instanceName($rawType, $definition) !== $instance) {
                continue;
            }
            $capability = isset($definition['capability']) ? (string)$definition['capability'] : '';
            $expected = YandexHomeCatalog::isPropertyCapability($capability)
                ? YandexHomeCatalog::PREFIX_PROPERTIES . $capability
                : YandexHomeCatalog::PREFIX_CAPABILITIES . $capability;
            if ($requestType === $expected) {
                return (string)$rawType;
            }
        }

        return null;
    }

    /**
     * Whether any trait is configured for state callbacks.
     *
     * @param array<string,mixed> $traits
     * @return bool
     */
    public function hasReportableState(array $traits)
    {
        foreach ($traits as $trait) {
            if (is_array($trait) && !empty($trait['reportable'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the discovery device JSON written to `CONFIG`.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $traits
     * @param array<string,mixed> $instanceConfig
     * @param bool $reportableConfigured
     * @return string Empty string when the device cannot be discovered.
     */
    public function buildDeviceConfig(array $device, array $traits, array $instanceConfig = array(), $reportableConfigured = false)
    {
        $capabilities = array();
        $properties = array();
        $colorCapabilityIndex = null;

        $rawType = isset($device['TYPE']) ? trim((string)$device['TYPE']) : 'other';
        if (strpos($rawType, YandexHomeCatalog::PREFIX_TYPES) === 0) {
            $rawType = substr($rawType, strlen(YandexHomeCatalog::PREFIX_TYPES));
        }
        if ($rawType === '') {
            $rawType = 'other';
        }

        foreach ($traits as $trait) {
            if (!is_array($trait) || !isset($trait['type'])) {
                continue;
            }
            $traitType = YandexHomeCatalog::normalizeTraitType((string)$trait['type']);
            $definition = YandexHomeCatalog::instance($traitType);
            if ($definition === null) {
                continue;
            }

            $reportable = $reportableConfigured && !empty($trait['reportable']);
            if (isset($definition['reportable_fixed'])) {
                $reportable = $reportableConfigured && (bool)$definition['reportable_fixed'];
            }
            $retrievable = isset($definition['retrievable']) ? (bool)$definition['retrievable'] : true;
            $capability = isset($definition['capability']) ? (string)$definition['capability'] : '';

            $parameters = array();
            if (isset($instanceConfig[$traitType]['parameters']) && is_array($instanceConfig[$traitType]['parameters'])) {
                $parameters = $instanceConfig[$traitType]['parameters'];
            } elseif (isset($definition['parameters']) && is_array($definition['parameters'])) {
                $parameters = $definition['parameters'];
            }

            if ($capability === 'float' || $capability === 'event' || ($capability !== 'color_setting' && $capability !== 'video_stream')) {
                $parameters['instance'] = YandexHomeCatalog::instanceName($traitType, $definition);
            }

            $entry = array(
                'type' => (YandexHomeCatalog::isPropertyCapability($capability)
                    ? YandexHomeCatalog::PREFIX_PROPERTIES
                    : YandexHomeCatalog::PREFIX_CAPABILITIES) . $capability,
                'parameters' => $parameters,
                'retrievable' => $retrievable,
                'reportable' => $reportable,
            );

            if ($capability === 'video_stream') {
                $entry['retrievable'] = false;
                $entry['reportable'] = false;
            }

            if ($capability === 'color_setting') {
                if ($colorCapabilityIndex === null) {
                    $capabilities[] = $entry;
                    $colorCapabilityIndex = count($capabilities) - 1;
                } else {
                    $existing = isset($capabilities[$colorCapabilityIndex]['parameters'])
                        ? $capabilities[$colorCapabilityIndex]['parameters']
                        : array();
                    $capabilities[$colorCapabilityIndex]['parameters'] = array_merge($existing, $parameters);
                    if (empty($capabilities[$colorCapabilityIndex]['reportable'])) {
                        $capabilities[$colorCapabilityIndex]['reportable'] = $reportable;
                    }
                }
                continue;
            }

            if (YandexHomeCatalog::isPropertyCapability($capability)) {
                $properties[] = $entry;
            } else {
                $capabilities[] = $entry;
            }
        }

        $model = isset($device['MODEL']) ? trim((string)$device['MODEL']) : '';
        $model = $model === '' ? 'MajorDoMo' : $model . ' | MajorDoMo';

        $discovery = array(
            'id' => isset($device['ID']) ? $device['ID'] : '',
            'name' => isset($device['TITLE']) ? $device['TITLE'] : '',
            'status_info' => array('reportable' => $reportableConfigured && $this->hasReportableState($traits)),
            'type' => YandexHomeCatalog::PREFIX_TYPES . $rawType,
            'device_info' => array(
                'manufacturer' => isset($device['MANUFACTURER']) && $device['MANUFACTURER'] !== ''
                    ? $device['MANUFACTURER']
                    : 'MajorDoMo',
                'model' => $model,
                'hw_version' => isset($device['HW_VERSION']) ? (string)$device['HW_VERSION'] : '',
                'sw_version' => isset($device['SW_VERSION']) ? (string)$device['SW_VERSION'] : '',
            ),
        );

        if (isset($device['ROOM']) && $device['ROOM'] !== '') {
            $discovery['room'] = $device['ROOM'];
        }
        if (isset($device['DESCRIPTION']) && $device['DESCRIPTION'] !== '') {
            $discovery['description'] = $device['DESCRIPTION'];
        }
        if ($capabilities !== array()) {
            $discovery['capabilities'] = $capabilities;
        }
        if ($properties !== array()) {
            $discovery['properties'] = $properties;
        }

        if (!$this->isValidDiscoveryDevice($discovery)) {
            return '';
        }

        return $this->encodeJson($discovery);
    }

    /**
     * A discovery device must be complete enough for Yandex.
     *
     * @param mixed $device
     * @return bool
     */
    public function isValidDiscoveryDevice($device)
    {
        if (!is_array($device)) {
            return false;
        }
        if (empty($device['id']) || empty($device['name']) || empty($device['type'])) {
            return false;
        }
        if (!isset($device['status_info']) || !is_array($device['status_info']) || !isset($device['status_info']['reportable'])) {
            return false;
        }

        $hasCapabilities = isset($device['capabilities']) && is_array($device['capabilities']) && $device['capabilities'] !== array();
        $hasProperties = isset($device['properties']) && is_array($device['properties']) && $device['properties'] !== array();

        return $hasCapabilities || $hasProperties;
    }

    /**
     * Encode a Yandex JSON payload with safe flags.
     *
     * @param mixed $data
     * @return string
     */
    public function encodeJson($data)
    {
        $json = json_encode($data, YandexHomeCatalog::JSON_FLAGS);

        return $json !== false ? $json : '{"request_id":"","payload":{"devices":[]}}';
    }
}

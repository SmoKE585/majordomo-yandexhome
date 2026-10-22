<?php
/**
 * Yandex Home persistence layer.
 *
 * Keeps SQL access in one place so the module class, webhooks and the admin UI
 * all use the same validation and linked-property behaviour.
 *
 * @package MajorDoMo
 */

final class YandexHomeRepository
{
    /** @var string */
    private $moduleName;

    /** @var YandexHomeTraitMapper */
    private $mapper;

    /**
     * @param string $moduleName
     * @param YandexHomeTraitMapper $mapper
     */
    public function __construct($moduleName, YandexHomeTraitMapper $mapper)
    {
        $this->moduleName = (string)$moduleName;
        $this->mapper = $mapper;
    }

    /**
     * All devices ordered by room and title.
     *
     * @return array<int,array<string,mixed>>
     */
    public function devices()
    {
        $rows = SQLSelect('SELECT * FROM yandexhome_devices ORDER BY ROOM, TITLE');

        return is_array($rows) ? $rows : array();
    }

    /**
     * One device by id.
     *
     * @param int $id
     * @return array<string,mixed>|null
     */
    public function device($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return null;
        }
        $row = SQLSelectOne('SELECT * FROM yandexhome_devices WHERE ID=' . $id);

        return is_array($row) && !empty($row['ID']) ? $row : null;
    }

    /**
     * Create or update a device.
     *
     * @param array<string,mixed> $device
     * @return int Device id.
     */
    public function saveDevice(array $device)
    {
        $normalized = array(
            'TITLE' => isset($device['TITLE']) ? trim((string)$device['TITLE']) : '',
            'TYPE' => isset($device['TYPE']) ? trim((string)$device['TYPE']) : '',
            'ROOM' => isset($device['ROOM']) ? trim((string)$device['ROOM']) : '',
            'DESCRIPTION' => isset($device['DESCRIPTION']) ? trim((string)$device['DESCRIPTION']) : '',
            'MANUFACTURER' => isset($device['MANUFACTURER']) ? trim((string)$device['MANUFACTURER']) : '',
            'MODEL' => isset($device['MODEL']) ? trim((string)$device['MODEL']) : '',
            'SW_VERSION' => isset($device['SW_VERSION']) ? trim((string)$device['SW_VERSION']) : '',
            'HW_VERSION' => isset($device['HW_VERSION']) ? trim((string)$device['HW_VERSION']) : '',
            'TRAITS' => isset($device['TRAITS']) ? (string)$device['TRAITS'] : '{}',
            'CONFIG' => isset($device['CONFIG']) ? (string)$device['CONFIG'] : '',
        );

        $id = isset($device['ID']) ? (int)$device['ID'] : 0;
        if ($id > 0) {
            $normalized['ID'] = $id;
            SQLUpdate('yandexhome_devices', $normalized);

            return $id;
        }

        return (int)SQLInsert('yandexhome_devices', $normalized);
    }

    /**
     * Delete a device and detach all linked properties.
     *
     * @param int $id
     * @return void
     */
    public function deleteDevice($id)
    {
        $device = $this->device($id);
        if ($device === null) {
            return;
        }
        $this->detachLinkedProperties($device);
        SQLExec('DELETE FROM yandexhome_devices WHERE ID=' . (int)$device['ID']);
    }

    /**
     * Decode a device trait list.
     *
     * @param array<string,mixed> $device
     * @return array<string,array<string,mixed>>
     */
    public function traits(array $device)
    {
        $traits = isset($device['TRAITS']) ? json_decode((string)$device['TRAITS'], true) : array();

        return is_array($traits) ? $this->mapper->normalizeTraits($traits) : array();
    }

    /**
     * Current OAuth client/token record.
     *
     * @return array<string,mixed>|null
     */
    public function oauth()
    {
        $row = SQLSelectOne('SELECT * FROM yandexhome_oauth');

        return is_array($row) ? $row : null;
    }

    /**
     * Keep the OAuth client credentials in sync with module settings.
     *
     * @param string $clientId
     * @param string $clientSecret
     * @return void
     */
    public function syncOAuthClient($clientId, $clientSecret)
    {
        $row = $this->oauth();
        if ($row === null) {
            $newRow = array(
                'CLIENT_ID' => (string)$clientId,
                'CLIENT_SECRET' => (string)$clientSecret,
            );
            SQLInsert('yandexhome_oauth', $newRow);

            return;
        }

        if ((string)$row['CLIENT_ID'] === (string)$clientId && (string)$row['CLIENT_SECRET'] === (string)$clientSecret) {
            return;
        }

        $row['CLIENT_ID'] = (string)$clientId;
        $row['CLIENT_SECRET'] = (string)$clientSecret;
        SQLUpdate('yandexhome_oauth', $row);
    }

    /**
     * Detach every linked property used by a device.
     *
     * @param array<string,mixed> $device
     * @return void
     */
    public function detachLinkedProperties(array $device)
    {
        $traits = $this->traits($device);
        foreach ($traits as $trait) {
            $this->detachTraitProperties($trait);
        }
    }

    /**
     * Detach one trait's linked property.
     *
     * @param array<string,mixed> $trait
     * @return void
     */
    public function detachTraitProperties(array $trait)
    {
        $object = isset($trait['linked_object']) ? trim((string)$trait['linked_object']) : '';
        $property = isset($trait['linked_property']) ? trim((string)$trait['linked_property']) : '';
        if ($object !== '' && $property !== '') {
            removeLinkedProperty($object, $property, $this->moduleName);
        }
    }

    /**
     * Attach one trait's linked property.
     *
     * @param array<string,mixed> $trait
     * @return void
     */
    public function attachTraitProperties(array $trait)
    {
        $object = isset($trait['linked_object']) ? trim((string)$trait['linked_object']) : '';
        $property = isset($trait['linked_property']) ? trim((string)$trait['linked_property']) : '';
        if ($object !== '' && $property !== '') {
            addLinkedProperty($object, $property, $this->moduleName);
        }
    }

    /**
     * Persist normalized traits for a device.
     *
     * @param array<string,mixed> $device
     * @param array<string,mixed> $traits
     * @return void
     */
    public function saveTraits(array $device, array $traits)
    {
        if (!isset($device['ID'])) {
            return;
        }
        $device['TRAITS'] = json_encode($traits, YandexHomeCatalog::JSON_FLAGS);
        SQLUpdate('yandexhome_devices', $device);
    }

    /**
     * Extract per-trait parameters (range overrides, split) from stored CONFIG.
     *
     * The device table stores the discovery payload, not the editor state, so
     * the editor reconstructs range/split parameters from it.
     *
     * @param array<string,mixed> $device
     * @return array<string,array<string,mixed>>
     */
    public function instanceConfigFromDevice(array $device)
    {
        $config = isset($device['CONFIG']) ? json_decode((string)$device['CONFIG'], true) : null;
        if (!is_array($config)) {
            return array();
        }

        $traits = $this->traits($device);
        $entries = array();
        foreach (array('capabilities', 'properties') as $key) {
            if (!isset($config[$key]) || !is_array($config[$key])) {
                continue;
            }
            foreach ($config[$key] as $entry) {
                if (!is_array($entry) || !isset($entry['type'])) {
                    continue;
                }
                $entries[] = $entry;
            }
        }

        $result = array();
        foreach ($traits as $traitType => $trait) {
            $definition = YandexHomeCatalog::instance($traitType);
            if ($definition === null) {
                continue;
            }
            $instanceName = YandexHomeCatalog::instanceName($traitType, $definition);
            $capability = isset($definition['capability']) ? (string)$definition['capability'] : '';
            $expectedType = (YandexHomeCatalog::isPropertyCapability($capability)
                ? YandexHomeCatalog::PREFIX_PROPERTIES
                : YandexHomeCatalog::PREFIX_CAPABILITIES) . $capability;

            foreach ($entries as $entry) {
                if ((string)$entry['type'] !== $expectedType) {
                    continue;
                }
                $parameters = isset($entry['parameters']) && is_array($entry['parameters'])
                    ? $entry['parameters']
                    : array();
                if (isset($parameters['instance']) && (string)$parameters['instance'] !== $instanceName) {
                    continue;
                }
                unset($parameters['instance'], $parameters['modes'], $parameters['events'], $parameters['color_scene']);
                if ($parameters !== array()) {
                    $result[$traitType] = array('parameters' => $parameters);
                }
                break;
            }
        }

        return $this->mapper->normalizeInstanceConfig($result);
    }

    /**
     * Rebuild stored CONFIG values for legacy rows.
     *
     * @param bool $reportableConfigured
     * @return int Number of updated devices.
     */
    public function rebuildDeviceConfigs($reportableConfigured)
    {
        $updated = 0;
        foreach ($this->devices() as $device) {
            $traits = $this->traits($device);
            if ($traits === array()) {
                continue;
            }
            $instanceConfig = $this->instanceConfigFromDevice($device);
            $config = $this->mapper->buildDeviceConfig($device, $traits, $instanceConfig, $reportableConfigured);
            if ($config !== (string)$device['CONFIG']) {
                $device['CONFIG'] = $config;
                SQLUpdate('yandexhome_devices', $device);
                $updated++;
            }
        }

        return $updated;
    }
}

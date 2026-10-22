<?php
/**
 * Yandex Home module.
 *
 * Private Yandex smart-home skill adapter for MajorDoMo: OAuth2 account
 * linking, Provider Adapter API (discovery/query/action), and an admin UI for
 * mapping MajorDoMo object properties to Yandex capabilities.
 *
 * @package MajorDoMo
 */

require_once __DIR__ . '/lib/Catalog.php';
require_once __DIR__ . '/lib/TraitMapper.php';
require_once __DIR__ . '/lib/Repository.php';
require_once __DIR__ . '/lib/Notifier.php';
require_once __DIR__ . '/lib/RequestHandler.php';

class yandexhome extends module
{
    /** @var string */
    public $version = '2.0.0';

    /** @var array<string,array<string,string>> */
    public $devices_type = array();

    /** @var array<string,array<string,mixed>> */
    public $devices_instance = array();

    /** @var array<string,array{label:string,map:array<string,string>}> */
    public $value_map_presets = array();

    /** @var bool */
    public $debug = false;

    /** @var bool */
    public $debug_ping = false;

    /** @var YandexHomeCatalog */
    private $catalog;

    /** @var YandexHomeTraitMapper */
    private $mapper;

    /** @var YandexHomeRepository */
    private $repository;

    /** @var YandexHomeNotifier */
    private $notifier;

    /** @var YandexHomeRequestHandler */
    private $handler;

    /**
     * Module constructor.
     */
    public function __construct()
    {
        $this->name = 'yandexhome';
        $this->title = 'Yandex Home';
        $this->module_category = '<#LANG_SECTION_DEVICES#>';
        $this->checkInstalled();

        $this->getConfig();
        $defaults = array(
            'USER_NAME' => '',
            'USER_PASS' => '',
            'CLIENT_ID' => '',
            'CLIENT_KEY' => '',
            'SKILL_ID' => '',
            'SKILL_ACCESS_TOKEN' => '',
            'LOG_DEBMES' => 0,
            'LOG_PING' => 0,
            'READONLY_MODE' => 0,
        );
        $this->config = array_merge($defaults, is_array($this->config) ? $this->config : array());

        $this->debug = !empty($this->config['LOG_DEBMES']);
        $this->debug_ping = !empty($this->config['LOG_PING']);

        $this->catalog = new YandexHomeCatalog();
        $this->mapper = new YandexHomeTraitMapper();
        $this->repository = new YandexHomeRepository($this->name, $this->mapper);
        $this->notifier = new YandexHomeNotifier($this->config, $this->mapper);
        $this->handler = new YandexHomeRequestHandler(
            $this->repository,
            $this->mapper,
            $this->notifier,
            $this->name,
            $this->config,
            array($this, 'WriteLog')
        );

        $this->devices_type = YandexHomeCatalog::deviceTypes();
        $this->devices_instance = YandexHomeCatalog::instances();
        $this->value_map_presets = YandexHomeCatalog::valueMapPresets();
    }

    /**
     * Saving module parameters.
     *
     * @param int $data
     * @return string
     */
    public function saveParams($data = 1)
    {
        $params = array();
        if (isset($this->id)) {
            $params['id'] = $this->id;
        }
        if (isset($this->view_mode)) {
            $params['view_mode'] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $params['edit_mode'] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $params['tab'] = $this->tab;
        }

        return parent::saveParams($params);
    }

    /**
     * Reading module parameters from the query string.
     *
     * @return void
     */
    public function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;

        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Run the module.
     *
     * @return void
     */
    public function run()
    {
        $out = array();
        $this->action = isset($this->action) ? (string)$this->action : '';
        $this->view_mode = isset($this->view_mode) ? (string)$this->view_mode : '';
        $this->mode = isset($this->mode) ? (string)$this->mode : '';
        // Generic admin routes may pass only `id`; treat it as the device card.
        if ($this->action == 'admin' && $this->view_mode === ''
            && isset($this->id) && (int)$this->id > 0) {
            $this->view_mode = 'edit_yandexhome_devices';
        }
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }

        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }

        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;
        $this->data = $out;

        $parser = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $parser->result;
    }

    /**
     * Admin UI.
     *
     * @param array<string,mixed> $out
     * @return void
     */
    public function admin(&$out)
    {
        if ($this->view_mode === 'update_settings') {
            $this->saveSettings();
        }
        if ($this->view_mode === 'create_fake_devices') {
            $created = $this->createFakeDevicesForIconSync();
            $this->redirect('?view_mode=settings&fake_created=' . $created);
        }
        if ($this->view_mode === 'delete_fake_devices') {
            $deleted = $this->deleteFakeDevicesForIconSync();
            $this->redirect('?view_mode=settings&fake_deleted=' . $deleted);
        }
        if ($this->view_mode === 'run_migration') {
            $updated = $this->migrateDeviceConfigs();
            $this->redirect('?view_mode=settings&migrated=' . $updated);
        }
        if ($this->view_mode === 'test_callback') {
            $result = $this->sendDiscoveryCallback(true);
            $status = $result['ok'] ? 'ok' : 'error';
            $this->redirect('?view_mode=settings&callback=' . $status . '&http=' . (int)$result['http']);
        }
        if ($this->view_mode === 'clear_oauth') {
            SQLExec('DELETE FROM yandexhome_oauth');
            $this->redirect('?view_mode=settings&oauth_cleared=1');
        }

        if ($this->view_mode === '' || $this->view_mode === 'search_yandexhome_devices') {
            $this->searchYandexHomeDevices($out);
        }
        if ($this->view_mode === 'addnew_yandexhome_devices') {
            $this->editYandexHomeDevice($out, 0);
        }
        if ($this->view_mode === 'edit_yandexhome_devices') {
            $this->editYandexHomeDevice($out, isset($this->id) ? (int)$this->id : 0);
        }
        if ($this->view_mode === 'delete_yandexhome_devices') {
            $this->deleteYandexHomeDevice(isset($this->id) ? (int)$this->id : 0);
            $this->redirect('?');
        }
        if ($this->view_mode === 'settings' || $this->view_mode === 'update_settings') {
            $this->settings($out);
        }
        if ($this->view_mode === 'about') {
            $this->about($out);
        }
    }

    /**
     * Front-end hook. The adapter has no public UI: webhooks live in dedicated
     * files (`authorize.php`, `token.php`, `smarthome.php`).
     *
     * @param array<string,mixed> $out
     * @return void
     */
    public function usual(&$out)
    {
        $out['NOTICE'] = 'Yandex Home is an integration endpoint; use the admin section to configure it.';
    }

    /**
     * Device list.
     *
     * @param array<string,mixed> $out
     * @return void
     */
    private function searchYandexHomeDevices(&$out)
    {
        $res = $this->repository->devices();
        $rooms = array();
        $traitsTotal = 0;
        $reportableDevices = 0;
        $linkedTraits = 0;

        foreach ($res as $index => $row) {
            $traits = $this->repository->traits($row);
            $traitLabels = array();
            $reportable = false;
            $linked = 0;
            foreach ($traits as $trait) {
                $definition = YandexHomeCatalog::instance(isset($trait['type']) ? $trait['type'] : '');
                $label = isset($trait['description']) && $trait['description'] !== ''
                    ? (string)$trait['description']
                    : (isset($trait['type']) ? (string)$trait['type'] : '');
                $traitLabels[] = array(
                    'LABEL' => $this->e($label),
                    'TYPE' => $this->e(isset($trait['type']) ? $trait['type'] : ''),
                    'CAPABILITY' => $this->e($definition !== null && isset($definition['capability']) ? $definition['capability'] : ''),
                    'LINKED' => !empty($trait['linked_object']) && !empty($trait['linked_property']) ? 1 : 0,
                );
                $traitsTotal++;
                if (!empty($trait['reportable'])) {
                    $reportable = true;
                }
                if (!empty($trait['linked_object']) && !empty($trait['linked_property'])) {
                    $linked++;
                    $linkedTraits++;
                }
            }
            if ($reportable) {
                $reportableDevices++;
            }
            if (isset($row['ROOM']) && $row['ROOM'] !== '') {
                $rooms[$row['ROOM']] = true;
            }

            $res[$index]['ICON'] = YandexHomeCatalog::deviceIcon(isset($row['TYPE']) ? $row['TYPE'] : '');
            $res[$index]['TYPE_TITLE'] = $this->e(YandexHomeCatalog::deviceDescription(isset($row['TYPE']) ? $row['TYPE'] : ''));
            $res[$index]['TRAITS_LIST'] = $traitLabels;
            $res[$index]['TRAITS_COUNT'] = count($traitLabels);
            $res[$index]['LINKED_COUNT'] = $linked;
            $res[$index]['ALL_LINKED'] = ($traitLabels !== array() && $linked === count($traitLabels)) ? 1 : 0;
            $res[$index]['REPORTABLE'] = $reportable ? 1 : 0;
            $res[$index]['CONFIGURED'] = $linked > 0 ? 1 : 0;
            $res[$index]['ROOM_RAW'] = $this->e(isset($row['ROOM']) ? $row['ROOM'] : '');
            $res[$index]['SEARCH'] = $this->e(trim(
                (isset($row['TITLE']) ? $row['TITLE'] : '') . ' '
                . (isset($row['TYPE']) ? $row['TYPE'] : '') . ' '
                . (isset($row['ROOM']) ? $row['ROOM'] : '') . ' '
                . (isset($row['DESCRIPTION']) ? $row['DESCRIPTION'] : '') . ' '
                . implode(' ', array_column($traitLabels, 'LABEL'))
            ));
            foreach (array('TITLE', 'ROOM', 'DESCRIPTION', 'MANUFACTURER', 'MODEL', 'SW_VERSION', 'HW_VERSION') as $field) {
                if (isset($res[$index][$field])) {
                    $res[$index][$field] = $this->e($res[$index][$field]);
                }
            }
        }

        $out['RESULT'] = $res;
        $out['DEVICES_TOTAL'] = count($res);
        $out['ROOMS_TOTAL'] = count($rooms);
        $roomOptions = array();
        $roomNames = array_keys($rooms);
        sort($roomNames, SORT_NATURAL | SORT_FLAG_CASE);
        $activeRoom = (string)gr('room');
        foreach ($roomNames as $roomName) {
            $roomOptions[] = array(
                'TITLE' => $this->e($roomName),
                'SELECTED' => $activeRoom !== '' && $activeRoom === $roomName ? 1 : 0,
            );
        }
        $out['ROOM_OPTIONS'] = $roomOptions;
        $out['FILTER_ROOM'] = $this->e(gr('room'));
        $out['TRAITS_TOTAL'] = $traitsTotal;
        $out['REPORTABLE_DEVICES_TOTAL'] = $reportableDevices;
        $out['LINKED_TRAITS_TOTAL'] = $linkedTraits;
        $out['OAUTH_CONFIGURED'] = ($this->config['CLIENT_ID'] !== '' && $this->config['CLIENT_KEY'] !== '') ? 1 : 0;
        $out['CALLBACK_CONFIGURED'] = $this->notifier->isConfigured() ? 1 : 0;
        $out['READONLY_MODE'] = !empty($this->config['READONLY_MODE']) ? 1 : 0;
        $out['YANDEX_CONSOLE_URL'] = 'https://dialogs.yandex.ru/developer/skills/';
        $out['HINT'] = $this->buildMainHint();
    }

    /**
     * Add/edit device form.
     *
     * @param array<string,mixed> $out
     * @param int $id
     * @return void
     */
    private function editYandexHomeDevice(&$out, $id)
    {
        $id = (int)$id;
        $rec = $this->repository->device($id);
        if ($rec === null) {
            $rec = array(
                'ID' => 0,
                'TITLE' => '',
                'TYPE' => '',
                'ROOM' => '',
                'DESCRIPTION' => '',
                'MANUFACTURER' => '',
                'MODEL' => '',
                'SW_VERSION' => '',
                'HW_VERSION' => '',
                'TRAITS' => '',
                'CONFIG' => '',
            );
        }
        $oldTraits = $this->repository->traits($rec);
        $oldInstanceConfig = $this->repository->instanceConfigFromDevice($rec);

        if ($this->mode === 'update') {
            $ok = true;
            $rec['TITLE'] = trim((string)gr('title'));
            if ($rec['TITLE'] === '') {
                $out['ERR_TITLE'] = 1;
                $ok = false;
            }
            $rec['TYPE'] = trim((string)gr('type'));
            if ($rec['TYPE'] === '' || YandexHomeCatalog::deviceType($rec['TYPE']) === null) {
                $out['ERR_TYPE'] = 1;
                $ok = false;
            }
            $rec['ROOM'] = trim((string)gr('location'));
            $rec['DESCRIPTION'] = trim((string)gr('description'));
            $rec['MANUFACTURER'] = trim((string)gr('manufacturer'));
            $rec['MODEL'] = trim((string)gr('model'));
            $rec['SW_VERSION'] = trim((string)gr('sw_version'));
            $rec['HW_VERSION'] = trim((string)gr('hw_version'));

            $traitsJson = (string)gr('traits_json');
            $traits = json_decode($traitsJson, true);
            if (!is_array($traits) || $traits === array()) {
                $out['ERR_TRAITS'] = 1;
                $ok = false;
                $traits = array();
            } else {
                $traits = $this->mapper->normalizeTraits($traits);
                if ($traits === array()) {
                    $out['ERR_TRAITS'] = 1;
                    $ok = false;
                }
            }

            $instanceJson = (string)gr('instance_json');
            $instanceConfig = json_decode($instanceJson, true);
            $instanceConfig = is_array($instanceConfig) ? $this->mapper->normalizeInstanceConfig($instanceConfig) : array();

            if ($ok) {
                $rec['TRAITS'] = json_encode($traits, YandexHomeCatalog::JSON_FLAGS);

                // Insert/update first: discovery needs the real device id.
                $newId = $this->repository->saveDevice($rec);
                $rec['ID'] = $newId;
                $rec['CONFIG'] = $this->mapper->buildDeviceConfig(
                    $rec,
                    $traits,
                    $instanceConfig,
                    $this->notifier->isConfigured()
                );
                $this->repository->saveDevice($rec);

                $this->syncLinkedProperties($oldTraits, $traits);
                $this->WriteLog('Device saved: ID=' . $newId . '; TITLE=' . $rec['TITLE'] . '; TYPE=' . $rec['TYPE']);
                $this->sendDiscoveryCallback(false);
                $out['OK'] = 1;
                $oldTraits = $traits;
                $oldInstanceConfig = $instanceConfig;
            } else {
                $out['ERR'] = 1;
                // Keep submitted traits visible after validation errors.
                $oldTraits = $traits;
                $oldInstanceConfig = $instanceConfig;
            }
        }

        $out['ID'] = (int)$rec['ID'] > 0 ? (int)$rec['ID'] : '';
        $out['TITLE'] = $this->e($rec['TITLE']);
        $out['TYPE'] = $this->e($rec['TYPE']);
        $out['ROOM'] = $this->e($rec['ROOM']);
        $out['DESCRIPTION'] = $this->e($rec['DESCRIPTION']);
        $out['MANUFACTURER'] = $this->e($rec['MANUFACTURER']);
        $out['MODEL'] = $this->e($rec['MODEL']);
        $out['SW_VERSION'] = $this->e($rec['SW_VERSION']);
        $out['HW_VERSION'] = $this->e($rec['HW_VERSION']);
        $out['DEVICES_TYPE_GROUPS'] = $this->typeGroupsForTemplate($rec['TYPE']);
        $out['INSTANCE_GROUPS'] = $this->instanceGroupsForTemplate();
        $out['LOCATION_OPTIONS'] = $this->locationOptions($rec['ROOM']);
        $out['OBJECTS'] = $this->objectList();
        $out['REPORTABLE_CONFIGURED'] = $this->notifier->isConfigured() ? 1 : 0;
        $out['DEVICE_ICON'] = YandexHomeCatalog::deviceIcon($rec['TYPE']);
        $out['DEVICE_TYPE_TITLE'] = $this->e(YandexHomeCatalog::deviceDescription($rec['TYPE']));
        $out['HINT'] = $this->buildEditorHint();
        $out['EDITOR_DATA_JSON'] = $this->encodeEditorData($oldTraits, $oldInstanceConfig);

        if ($rec['ID'] > 0) {
            $out['CONFIG_JSON'] = $this->prettyJson($rec['CONFIG']);
            $out['TRAITS_TOTAL'] = count($oldTraits);
            $out['LINKED_TRAITS_TOTAL'] = $this->countLinkedTraits($oldTraits);
        }
    }

    /**
     * Delete a device.
     *
     * @param int $id
     * @return void
     */
    private function deleteYandexHomeDevice($id)
    {
        $this->repository->deleteDevice((int)$id);
        $this->sendDiscoveryCallback(false);
    }

    /**
     * Settings and maintenance page.
     *
     * @param array<string,mixed> $out
     * @return void
     */
    private function settings(&$out)
    {
        $out['USER_NAME'] = $this->e($this->config['USER_NAME']);
        $out['USER_PASS'] = $this->e($this->config['USER_PASS']);
        $out['CLIENT_ID'] = $this->e($this->config['CLIENT_ID']);
        $out['CLIENT_KEY'] = $this->e($this->config['CLIENT_KEY']);
        $out['SKILL_ID'] = $this->e($this->config['SKILL_ID']);
        $out['SKILL_ACCESS_TOKEN'] = $this->e($this->config['SKILL_ACCESS_TOKEN']);
        $out['LOG_DEBMES'] = !empty($this->config['LOG_DEBMES']) ? 1 : 0;
        $out['LOG_PING'] = !empty($this->config['LOG_PING']) ? 1 : 0;
        $out['READONLY_MODE'] = !empty($this->config['READONLY_MODE']) ? 1 : 0;
        $out['USER_ID'] = md5((string)$this->config['USER_NAME']);
        $out['OAUTH_CONFIGURED'] = ($this->config['CLIENT_ID'] !== '' && $this->config['CLIENT_KEY'] !== '') ? 1 : 0;
        $out['CALLBACK_CONFIGURED'] = $this->notifier->isConfigured() ? 1 : 0;
        $out['WEBHOOK_AUTHORIZE'] = $this->absoluteUrl('modules/yandexhome/authorize.php');
        $out['WEBHOOK_TOKEN'] = $this->absoluteUrl('modules/yandexhome/token.php');
        $out['WEBHOOK_SMARTHOME'] = $this->absoluteUrl('modules/yandexhome/smarthome.php');
        $out['WEBHOOK_AUTHORIZE_PATH'] = ROOTHTML . 'modules/yandexhome/authorize.php';
        $out['WEBHOOK_TOKEN_PATH'] = ROOTHTML . 'modules/yandexhome/token.php';
        $out['WEBHOOK_SMARTHOME_PATH'] = ROOTHTML . 'modules/yandexhome/smarthome.php';
        $out['HINT'] = $this->buildSettingsHint();

        $oauth = $this->repository->oauth();
        $out['OAUTH_ACCESS_TOKEN'] = $oauth !== null && !empty($oauth['ACCESS_TOKEN'])
            ? $this->maskToken($oauth['ACCESS_TOKEN'])
            : '';
        $out['OAUTH_ACCESS_EXPIRES'] = $oauth !== null && !empty($oauth['ACCESS_TOKEN_EXPIRES'])
            ? $this->e($oauth['ACCESS_TOKEN_EXPIRES'])
            : '';
        $out['OAUTH_REFRESH_TOKEN'] = $oauth !== null && !empty($oauth['REFRESH_TOKEN'])
            ? $this->maskToken($oauth['REFRESH_TOKEN'])
            : '';
        $out['OAUTH_REFRESH_EXPIRES'] = $oauth !== null && !empty($oauth['REFRESH_TOKEN_EXPIRES'])
            ? $this->e($oauth['REFRESH_TOKEN_EXPIRES'])
            : '';
        $out['OAUTH_REDIRECT_URI'] = $oauth !== null && !empty($oauth['REDIRECT_URI'])
            ? $this->e($oauth['REDIRECT_URI'])
            : '';
        $out['OAUTH_EXISTS'] = $oauth !== null ? 1 : 0;

        $out['FLASH_SAVED'] = gr('saved', 'int');
        $fakeCreated = gr('fake_created', 'int');
        $fakeDeleted = gr('fake_deleted', 'int');
        $migrated = gr('migrated', 'int');
        $out['FLASH_FAKE_CREATED'] = $fakeCreated > 0 ? $fakeCreated : '';
        $out['FLASH_FAKE_DELETED'] = $fakeDeleted > 0 ? $fakeDeleted : '';
        $out['FLASH_MIGRATED'] = $migrated > 0 ? $migrated : '';
        $out['FLASH_CALLBACK'] = (string)gr('callback');
        $out['FLASH_CALLBACK_HTTP'] = gr('http', 'int');
        $out['FLASH_OAUTH_CLEARED'] = gr('oauth_cleared', 'int');
        $out['DEVICES_TOTAL'] = count($this->repository->devices());
        $out['FAKE_DEVICES_TOTAL'] = $this->countFakeDevices();
        $out['KB_URL'] = ROOTHTML . 'panel/knowledge_base/module/yandexhome/README.html';
    }

    /**
     * About page.
     *
     * @param array<string,mixed> $out
     * @return void
     */
    private function about(&$out)
    {
        $out['KB_URL'] = ROOTHTML . 'panel/knowledge_base/module/yandexhome/README.html';
        $out['YANDEX_CONSOLE_URL'] = 'https://dialogs.yandex.ru/developer/skills/';
        $out['DEVICES_TOTAL'] = count($this->repository->devices());
        $out['TRAITS_TOTAL'] = count(YandexHomeCatalog::instances());
        $out['DEVICE_TYPES_TOTAL'] = count(YandexHomeCatalog::deviceTypes());
        $out['MODULE_VERSION'] = $this->version;
    }

    /**
     * Save settings.
     *
     * @return void
     */
    private function saveSettings()
    {
        $this->config['USER_NAME'] = trim((string)gr('user_name'));
        $this->config['USER_PASS'] = (string)gr('user_pass');
        $this->config['CLIENT_ID'] = trim((string)gr('client_id'));
        $this->config['CLIENT_KEY'] = trim((string)gr('client_key'));
        $this->config['SKILL_ID'] = trim((string)gr('skill_id'));
        $this->config['SKILL_ACCESS_TOKEN'] = trim((string)gr('skill_access_token'));
        $this->config['LOG_DEBMES'] = gr('log_debmes') ? 1 : 0;
        $this->config['LOG_PING'] = gr('log_ping') ? 1 : 0;
        $this->config['READONLY_MODE'] = gr('readonly_mode') ? 1 : 0;
        $this->saveConfig();

        $this->repository->syncOAuthClient($this->config['CLIENT_ID'], $this->config['CLIENT_KEY']);
        $this->redirect('?view_mode=settings&saved=1');
    }

    /**
     * Process a linked property change and notify Yandex about new state.
     *
     * @param string $object
     * @param string $property
     * @param mixed $value
     * @return void
     */
    public function PropertySetHandle($object, $property, $value)
    {
        if (!$this->notifier->isConfigured()) {
            return;
        }

        $object = (string)$object;
        $property = (string)$property;
        $this->WriteLog("PropertySetHandle for object '$object' and property '$property'");
        $rows = SQLSelect(
            "SELECT * FROM yandexhome_devices WHERE TRAITS LIKE '%" . DBSafe($object)
            . "%' AND TRAITS LIKE '%" . DBSafe($property) . "%'"
        );
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            $traits = $this->repository->traits($row);
            foreach ($traits as $trait) {
                if (empty($trait['reportable'])) {
                    continue;
                }
                if ((string)$trait['linked_object'] !== $object || (string)$trait['linked_property'] !== $property) {
                    continue;
                }
                $traitType = (string)$trait['type'];
                $definition = YandexHomeCatalog::instance($traitType);
                if ($definition === null || (isset($definition['capability']) && $definition['capability'] === 'video_stream')) {
                    continue;
                }
                $capability = isset($definition['capability']) ? (string)$definition['capability'] : '';
                $state = array(
                    'instance' => YandexHomeCatalog::instanceName($traitType, $definition),
                    'value' => $this->mapper->normalizeValueForState($traitType, $definition, $value, $trait),
                );
                $deviceState = array('id' => $row['ID']);
                if (YandexHomeCatalog::isPropertyCapability($capability)) {
                    $deviceState['properties'] = array(array(
                        'type' => YandexHomeCatalog::PREFIX_PROPERTIES . $capability,
                        'state' => $state,
                    ));
                } else {
                    $deviceState['capabilities'] = array(array(
                        'type' => YandexHomeCatalog::PREFIX_CAPABILITIES . $capability,
                        'state' => $state,
                    ));
                }
                $result = $this->notifier->sendState($deviceState);
                $this->WriteLog('State callback HTTP ' . $result['http'] . ': ' . $result['body']);
            }
        }
    }

    /**
     * Discovery request (Provider Adapter API).
     *
     * @param array<string,mixed> $content
     * @return string
     */
    public function HandleSyncRequest($content)
    {
        return $this->handler->handleSync($content);
    }

    /**
     * Query request (Provider Adapter API).
     *
     * @param array<string,mixed> $content
     * @return string
     */
    public function HandleQueryRequest($content)
    {
        return $this->handler->handleQuery($content);
    }

    /**
     * Execute request (Provider Adapter API).
     *
     * @param array<string,mixed> $content
     * @return string
     */
    public function HandleExecuteRequest($content)
    {
        return $this->handler->handleExecute($content);
    }

    /**
     * Unlink request (Provider Adapter API).
     *
     * @param array<string,mixed> $content
     * @return string
     */
    public function HandleUnlinkRequest($content)
    {
        return $this->handler->handleUnlink($content);
    }

    /**
     * Build discovery JSON for a device (public wrapper for integrations).
     *
     * @param array<string,mixed> $rec
     * @param array<string,mixed> $newDevTraits
     * @param array<string,mixed> $devicesInstance
     * @return string
     */
    public function buildDeviceConfig($rec, $newDevTraits, $devicesInstance = array())
    {
        return $this->mapper->buildDeviceConfig(
            is_array($rec) ? $rec : array(),
            is_array($newDevTraits) ? $this->mapper->normalizeTraits($newDevTraits) : array(),
            is_array($devicesInstance) ? $this->mapper->normalizeInstanceConfig($devicesInstance) : array(),
            $this->notifier->isConfigured()
        );
    }

    /**
     * Rebuild stored CONFIG values.
     *
     * @return int
     */
    public function migrateDeviceConfigs()
    {
        return $this->repository->rebuildDeviceConfigs($this->notifier->isConfigured());
    }

    /**
     * Delete all linked properties of a device (legacy public method).
     *
     * @param int $id
     * @param array<string,mixed>|false $properties
     * @return void
     */
    public function DeleteLinkedProperties($id, $properties = false)
    {
        $device = $this->repository->device((int)$id);
        if ($device === null) {
            return;
        }
        if (is_array($properties)) {
            foreach ($this->mapper->normalizeTraits($properties) as $trait) {
                $this->repository->detachTraitProperties($trait);
            }
            return;
        }
        $this->repository->detachLinkedProperties($device);
    }

    /**
     * Fake devices used to populate the Yandex icon list.
     *
     * @param string $type
     * @return array<string,array<string,mixed>>
     */
    public function getFakeTraitsForType($type)
    {
        if ($type === 'camera') {
            $traitType = 'get_stream';
        } elseif (strpos((string)$type, 'sensor') === 0 || strpos((string)$type, 'smart_meter') === 0) {
            $traitType = 'temperature_sensor';
        } else {
            $traitType = 'on';
        }

        return array($traitType => $this->mapper->defaultTrait($traitType));
    }

    /**
     * Create fake devices for icon sync.
     *
     * @return int
     */
    public function createFakeDevicesForIconSync()
    {
        $created = 0;
        foreach (YandexHomeCatalog::deviceTypes() as $typeName => $definition) {
            $title = YandexHomeCatalog::FAKE_DEVICE_TITLE_PREFIX . ' ' . $typeName;
            $existing = SQLSelectOne(
                "SELECT ID FROM yandexhome_devices WHERE TITLE='" . DBSafe($title)
                . "' AND ROOM='" . DBSafe(YandexHomeCatalog::FAKE_DEVICE_ROOM) . "'"
            );
            if (is_array($existing) && !empty($existing['ID'])) {
                continue;
            }
            $traits = $this->getFakeTraitsForType($typeName);
            $device = array(
                'TITLE' => $title,
                'TYPE' => $typeName,
                'ROOM' => YandexHomeCatalog::FAKE_DEVICE_ROOM,
                'DESCRIPTION' => 'Auto fake device for Yandex icon sync',
                'MANUFACTURER' => 'MajorDoMo',
                'MODEL' => 'YH Fake',
                'SW_VERSION' => '1',
                'HW_VERSION' => '1',
                'TRAITS' => json_encode($traits, YandexHomeCatalog::JSON_FLAGS),
            );
            $device['CONFIG'] = $this->mapper->buildDeviceConfig(
                $device,
                $traits,
                array(),
                $this->notifier->isConfigured()
            );
            $this->repository->saveDevice($device);
            $created++;
        }
        if ($created > 0) {
            $this->sendDiscoveryCallback(false);
        }

        return $created;
    }

    /**
     * Delete fake devices.
     *
     * @return int
     */
    public function deleteFakeDevicesForIconSync()
    {
        $rows = SQLSelect(
            "SELECT ID FROM yandexhome_devices WHERE TITLE LIKE '" . DBSafe(YandexHomeCatalog::FAKE_DEVICE_TITLE_PREFIX)
            . "%' AND ROOM='" . DBSafe(YandexHomeCatalog::FAKE_DEVICE_ROOM) . "'"
        );
        if (!is_array($rows)) {
            return 0;
        }
        $deleted = 0;
        foreach ($rows as $row) {
            $this->repository->deleteDevice((int)$row['ID']);
            $deleted++;
        }
        if ($deleted > 0) {
            $this->sendDiscoveryCallback(false);
        }

        return $deleted;
    }

    /**
     * Count fake devices.
     *
     * @return int
     */
    private function countFakeDevices()
    {
        $row = SQLSelectOne(
            "SELECT COUNT(*) AS TOTAL FROM yandexhome_devices WHERE TITLE LIKE '"
            . DBSafe(YandexHomeCatalog::FAKE_DEVICE_TITLE_PREFIX) . "%' AND ROOM='"
            . DBSafe(YandexHomeCatalog::FAKE_DEVICE_ROOM) . "'"
        );

        return is_array($row) && isset($row['TOTAL']) ? (int)$row['TOTAL'] : 0;
    }

    /**
     * Send the discovery callback to Yandex.
     *
     * @param bool $returnResult Return a structured result instead of a boolean.
     * @return array{ok:bool,http:int,body:string,error:string}|bool
     */
    public function sendDiscoveryCallback($returnResult = false)
    {
        $result = $this->notifier->sendDiscovery();
        $this->WriteLog('Discovery callback HTTP ' . $result['http'] . ': ' . $result['body']);

        return $returnResult ? $result : $result['ok'];
    }

    /**
     * Skill id used for callbacks.
     *
     * @return string
     */
    public function getReportableSkillId()
    {
        return $this->notifier->skillId();
    }

    /**
     * Whether callbacks are configured.
     *
     * @return bool
     */
    public function isReportableConfigured()
    {
        return $this->notifier->isConfigured();
    }

    /**
     * Enable read-only mode (legacy public API).
     *
     * @return void
     */
    public function ReadonlyModeEnable()
    {
        $this->getConfig();
        $this->config['READONLY_MODE'] = 1;
        $this->saveConfig();
    }

    /**
     * Disable read-only mode (legacy public API).
     *
     * @return void
     */
    public function ReadonlyModeDisable()
    {
        $this->getConfig();
        $this->config['READONLY_MODE'] = 0;
        $this->saveConfig();
    }

    /**
     * Debug logger.
     *
     * @param string $msg
     * @return void
     */
    public function WriteLog($msg)
    {
        if ($this->debug) {
            DebMes((string)$msg, $this->name);
        }
    }

    /**
     * Format an incoming OAuth2 request for the log.
     *
     * @param object $request
     * @return string
     */
    public function IncomingRequestFormat($request)
    {
        $method = isset($request->server['REQUEST_METHOD']) ? $request->server['REQUEST_METHOD'] : '';
        if (isset($request->server['HTTP_X_FORWARDED_FOR'])) {
            $remoteIp = $request->server['HTTP_X_FORWARDED_FOR'];
        } else {
            $remoteIp = isset($request->server['REMOTE_ADDR']) ? $request->server['REMOTE_ADDR'] : '';
        }
        $script = isset($request->server['REQUEST_URI']) ? $request->server['REQUEST_URI'] : '';
        $content = json_encode(isset($request->request) ? $request->request : array());

        return $method . ' ' . $script . ' ' . $remoteIp . ' <<< ' . $content;
    }

    /**
     * Normalize a legacy trait name (public wrapper).
     *
     * @param string $traitType
     * @return string
     */
    public function normalizeTraitType($traitType)
    {
        return YandexHomeCatalog::normalizeTraitType($traitType);
    }

    /**
     * Trait definition (public wrapper).
     *
     * @param string $traitType
     * @return array<string,mixed>|null
     */
    public function getInstanceDefinition($traitType)
    {
        return YandexHomeCatalog::instance($traitType);
    }

    /**
     * Yandex instance name (public wrapper).
     *
     * @param string $traitType
     * @param array<string,mixed>|null $instanceDef
     * @return string
     */
    public function getInstanceName($traitType, $instanceDef = null)
    {
        return YandexHomeCatalog::instanceName($traitType, $instanceDef);
    }

    /**
     * Device type description (public wrapper).
     *
     * @param string $type
     * @return string
     */
    public function getDeviceTypeDescription($type)
    {
        return YandexHomeCatalog::deviceDescription($type);
    }

    /**
     * Whether a capability is a Yandex property (public wrapper).
     *
     * @param string $capability
     * @return bool
     */
    public function isPropertyCapability($capability)
    {
        return YandexHomeCatalog::isPropertyCapability($capability);
    }

    /**
     * State value normalizer (public wrapper).
     *
     * @param string $traitType
     * @param array<string,mixed> $instanceDef
     * @param mixed $value
     * @param array<string,mixed> $trait
     * @return mixed
     */
    public function normalizeValueForState($traitType, $instanceDef, $value, $trait = array())
    {
        return $this->mapper->normalizeValueForState($traitType, $instanceDef, $value, $trait);
    }

    /**
     * Write value normalizer (public wrapper).
     *
     * @param string $traitType
     * @param array<string,mixed> $instanceDef
     * @param mixed $value
     * @param array<string,mixed> $trait
     * @return mixed
     */
    public function normalizeValueForWrite($traitType, $instanceDef, $value, $trait = array())
    {
        return $this->mapper->normalizeValueForWrite($traitType, $instanceDef, $value, $trait);
    }

    /**
     * Find a trait by instance (public wrapper).
     *
     * @param array<string,mixed> $traits
     * @param string $instance
     * @param string $requestType
     * @return string|null
     */
    public function findTraitTypeByInstance($traits, $instance, $requestType)
    {
        return $this->mapper->findTraitTypeByInstance(
            is_array($traits) ? $this->mapper->normalizeTraits($traits) : array(),
            $instance,
            $requestType
        );
    }

    /**
     * Encode JSON with the module's flags (public wrapper).
     *
     * @param mixed $data
     * @return string
     */
    public function encodeYandexJson($data)
    {
        return $this->mapper->encodeJson($data);
    }

    /**
     * Discovery device validation (public wrapper).
     *
     * @param mixed $device
     * @return bool
     */
    public function isValidDiscoveryDevice($device)
    {
        return $this->mapper->isValidDiscoveryDevice($device);
    }

    /**
     * Whether a trait list has reportable state.
     *
     * @param array<string,mixed> $traits
     * @return bool
     */
    public function hasReportableState($traits)
    {
        return $this->notifier->isConfigured()
            && $this->mapper->hasReportableState(is_array($traits) ? $this->mapper->normalizeTraits($traits) : array());
    }

    /**
     * Module installation.
     *
     * @param string $data
     * @return void
     */
    public function install($data = '')
    {
        parent::install($data);
        if ($this->repository instanceof YandexHomeRepository) {
            $this->migrateDeviceConfigs();
        }
    }

    /**
     * Module uninstallation.
     *
     * @return void
     */
    public function uninstall()
    {
        if ($this->repository instanceof YandexHomeRepository) {
            foreach ($this->repository->devices() as $device) {
                $this->repository->detachLinkedProperties($device);
            }
        }
        SQLExec('DROP TABLE IF EXISTS yandexhome_devices');
        SQLExec('DROP TABLE IF EXISTS yandexhome_oauth');
        parent::uninstall();
    }

    /**
     * Database schema.
     *
     * @param string $data
     * @return void
     */
    public function dbInstall($data = '')
    {
        $data = <<<EOD
 yandexhome_devices: ID int(10) unsigned NOT NULL auto_increment
 yandexhome_devices: TITLE varchar(255) NOT NULL DEFAULT ''
 yandexhome_devices: TYPE varchar(100) NOT NULL DEFAULT ''
 yandexhome_devices: ROOM varchar(100) NOT NULL DEFAULT ''
 yandexhome_devices: DESCRIPTION varchar(255) NOT NULL DEFAULT ''
 yandexhome_devices: MANUFACTURER varchar(100) NOT NULL DEFAULT ''
 yandexhome_devices: MODEL varchar(100) NOT NULL DEFAULT ''
 yandexhome_devices: SW_VERSION varchar(100) NOT NULL DEFAULT ''
 yandexhome_devices: HW_VERSION varchar(100) NOT NULL DEFAULT ''
 yandexhome_devices: TRAITS text
 yandexhome_devices: CONFIG text

 yandexhome_oauth: ID int(10) unsigned NOT NULL auto_increment
 yandexhome_oauth: CLIENT_ID varchar(80) NOT NULL
 yandexhome_oauth: CLIENT_SECRET varchar(80) DEFAULT NULL
 yandexhome_oauth: ACCESS_TOKEN varchar(40) DEFAULT NULL
 yandexhome_oauth: ACCESS_TOKEN_EXPIRES timestamp NULL DEFAULT NULL
 yandexhome_oauth: REFRESH_TOKEN varchar(40) DEFAULT NULL
 yandexhome_oauth: REFRESH_TOKEN_EXPIRES timestamp NULL DEFAULT NULL
 yandexhome_oauth: AUTHORIZATION_CODE varchar(40) DEFAULT NULL
 yandexhome_oauth: AUTHORIZATION_CODE_EXPIRES timestamp NULL DEFAULT NULL
 yandexhome_oauth: REDIRECT_URI varchar(2000) DEFAULT NULL
EOD;

        parent::dbInstall($data);
    }

    /**
     * Type groups for the form select.
     *
     * @param string $selectedType
     * @return array<int,array<string,mixed>>
     */
    private function typeGroupsForTemplate($selectedType = '')
    {
        $selectedType = (string)$selectedType;
        $groups = array();
        foreach (YandexHomeCatalog::deviceTypeGroups() as $group) {
            $types = array();
            foreach ($group['types'] as $type) {
                $types[] = array(
                    'DEVICE_NAME' => $this->e($type['device_name']),
                    'DESCRIPTION' => $this->e($type['description']),
                    'ICON' => $this->e($type['icon']),
                    'SELECTED' => $type['device_name'] === $selectedType ? 1 : 0,
                );
            }
            $groups[] = array(
                'LABEL' => $this->e($group['label']),
                'TYPES' => $types,
            );
        }

        return $groups;
    }

    /**
     * Location options with the current room preselected.
     *
     * @param string $selectedRoom
     * @return array<int,array<string,mixed>>
     */
    private function locationOptions($selectedRoom = '')
    {
        $rows = SQLSelect('SELECT ID, TITLE FROM locations ORDER BY TITLE');
        $result = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $title = isset($row['TITLE']) ? (string)$row['TITLE'] : '';
                $result[] = array(
                    'TITLE' => $this->e($title),
                    'SELECTED' => $title === (string)$selectedRoom ? 1 : 0,
                );
            }
        }

        return $result;
    }

    /**
     * Capability groups for the trait picker.
     *
     * @return array<int,array<string,mixed>>
     */
    private function instanceGroupsForTemplate()
    {
        $groups = array();
        foreach (YandexHomeCatalog::instanceGroups() as $group) {
            $types = array();
            foreach ($group['types'] as $type) {
                $types[] = array(
                    'INSTANCE_NAME' => $this->e($type['instance_name']),
                    'DESCRIPTION' => $this->e($type['description']),
                    'ICON' => $this->e($type['icon']),
                );
            }
            $groups[] = array(
                'LABEL' => $this->e($group['label']),
                'ICON' => $this->e($group['icon']),
                'TYPES' => $types,
            );
        }

        return $groups;
    }

    /**
     * Objects available for linking.
     *
     * @return array<int,array<string,string>>
     */
    private function objectList()
    {
        $rows = SQLSelect('SELECT TITLE, DESCRIPTION FROM objects ORDER BY TITLE');
        if (!is_array($rows)) {
            return array();
        }
        $result = array();
        foreach ($rows as $row) {
            $result[] = array(
                'TITLE' => $this->e(isset($row['TITLE']) ? $row['TITLE'] : ''),
                'DESCRIPTION' => $this->e(isset($row['DESCRIPTION']) ? $row['DESCRIPTION'] : ''),
            );
        }

        return $result;
    }

    /**
     * JSON payload consumed by the trait editor.
     *
     * @param array<string,mixed> $traits
     * @param array<string,mixed> $instanceConfig
     * @return string
     */
    private function encodeEditorData(array $traits, array $instanceConfig)
    {
        $catalog = array();
        foreach (YandexHomeCatalog::instances() as $type => $definition) {
            $catalog[$type] = array(
                'type' => $type,
                'instance' => YandexHomeCatalog::instanceName($type, $definition),
                'description' => isset($definition['description']) ? $definition['description'] : $type,
                'capability' => isset($definition['capability']) ? $definition['capability'] : '',
                'value_type' => isset($definition['value_type']) ? $definition['value_type'] : 'string',
                'parameters' => isset($definition['parameters']) ? $definition['parameters'] : array(),
                'allowed_values' => YandexHomeCatalog::allowedValues($type),
            );
        }

        $groups = array();
        foreach (YandexHomeCatalog::instanceGroups() as $group) {
            $types = array();
            foreach ($group['types'] as $type) {
                $types[] = $type['instance_name'];
            }
            $groups[] = array('label' => $group['label'], 'types' => $types);
        }

        $presets = array();
        foreach (YandexHomeCatalog::valueMapPresets() as $key => $preset) {
            $presets[$key] = $preset;
        }

        $objects = array();
        foreach ($this->objectList() as $object) {
            $objects[] = array(
                'title' => html_entity_decode($object['TITLE'], ENT_QUOTES, 'UTF-8'),
                'description' => html_entity_decode($object['DESCRIPTION'], ENT_QUOTES, 'UTF-8'),
            );
        }

        $jobs = array(
            'traits' => $traits,
            'instanceConfig' => $instanceConfig,
            'catalog' => $catalog,
            'groups' => $groups,
            'presets' => $presets,
            'objects' => $objects,
            'reportableConfigured' => $this->notifier->isConfigured(),
            'propertyUrl' => ROOTHTML . 'panel/linkedobject.html?ajax=1&op=properties',
        );

        $json = json_encode($jobs, YandexHomeCatalog::JSON_FLAGS | JSON_HEX_TAG | JSON_HEX_AMP);
        if ($json === false) {
            return '{}';
        }

        // partLoad treats `$(` in inserted HTML as inline JS; keep JSON inert.
        return str_replace('$', '\\u0024', $json);
    }

    /**
     * Diff and persist linked properties between the old and new trait list.
     *
     * @param array<string,mixed> $oldTraits
     * @param array<string,mixed> $newTraits
     * @return void
     */
    private function syncLinkedProperties(array $oldTraits, array $newTraits)
    {
        foreach ($oldTraits as $type => $trait) {
            if (isset($newTraits[$type])) {
                continue;
            }
            $this->repository->detachTraitProperties($trait);
        }

        foreach ($newTraits as $type => $trait) {
            $newObject = isset($trait['linked_object']) ? (string)$trait['linked_object'] : '';
            $newProperty = isset($trait['linked_property']) ? (string)$trait['linked_property'] : '';
            $oldObject = isset($oldTraits[$type]['linked_object']) ? (string)$oldTraits[$type]['linked_object'] : '';
            $oldProperty = isset($oldTraits[$type]['linked_property']) ? (string)$oldTraits[$type]['linked_property'] : '';
            if ($oldObject . '.' . $oldProperty === $newObject . '.' . $newProperty) {
                continue;
            }
            if ($oldObject !== '' && $oldProperty !== '') {
                removeLinkedProperty($oldObject, $oldProperty, $this->name);
            }
            if ($newObject !== '' && $newProperty !== '') {
                addLinkedProperty($newObject, $newProperty, $this->name);
            }
        }
    }

    /**
     * Count linked traits.
     *
     * @param array<string,mixed> $traits
     * @return int
     */
    private function countLinkedTraits(array $traits)
    {
        $count = 0;
        foreach ($traits as $trait) {
            if (!empty($trait['linked_object']) && !empty($trait['linked_property'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Main-page hint HTML.
     *
     * @return string
     */
    private function buildMainHint()
    {
        if (!class_exists('MDJUI')) {
            require_once ROOT . 'lib/ui/MDJUI.php';
        }
        $steps = array(
            'Создайте приватный навык «Умный дом» в Яндекс Диалогах и укажите три вебхука модуля.',
            'Заполните OAuth2 ID/KEY и логин с паролем на странице настроек, затем соберите связку аккаунтов.',
            'Добавьте устройства и привяжите свойства объектов к их умениям.',
        );

        return MDJUI::hint(array(
            'tone' => 'tip',
            'title' => 'Как подключить Алису',
            'text' => 'Модуль работает как приватный навык Яндекса: сначала публикуются вебхуки, затем создаётся навык и связка аккаунтов.',
            'steps' => $steps,
            'actions' => MDJUI::button('Открыть настройки', array(
                'variant' => 'secondary',
                'size' => 'sm',
                'href' => '?view_mode=settings',
                'icon' => 'settings',
            )),
        ));
    }

    /**
     * Editor hint HTML.
     *
     * @return string
     */
    private function buildEditorHint()
    {
        if (!class_exists('MDJUI')) {
            require_once ROOT . 'lib/ui/MDJUI.php';
        }

        return MDJUI::hint(array(
            'tone' => 'info',
            'title' => 'Как связать умение с MajorDoMo',
            'text' => 'У каждого умения выберите объект и свойство, через которое устройством управляет MajorDoMo. Для «Вкл/Выкл» удобно использовать свойство со значениями 1 и 0.',
            'points' => array(
                'Диапазоны (яркость, температура) можно ограничить значениями Min/Max/Step.',
                'Reportable отправляет изменения в Яндекс без запроса Алисы, если настроены токен и ID навыка.',
                'Split разделяет одну кнопку on_off на отдельные «Включить» и «Выключить».',
            ),
            'compact' => true,
        ));
    }

    /**
     * Settings hint HTML.
     *
     * @return string
     */
    private function buildSettingsHint()
    {
        if (!class_exists('MDJUI')) {
            require_once ROOT . 'lib/ui/MDJUI.php';
        }

        return MDJUI::hint(array(
            'tone' => 'warning',
            'title' => 'Вебхуки должны быть доступны из интернета',
            'text' => 'Яндекс обращается к authorize.php, token.php и smarthome.php по HTTPS. Обратный прокси не должен требовать логин и пароль на этих трёх адресах.',
            'points' => array(
                'OAuth2 ID и KEY вводятся в консоли Яндекс Диалогов при создании связки аккаунтов.',
                'Токен и ID навыка нужны только для reportable-уведомлений.',
                'Автономный режим запрещает запись в свойства объектов.',
            ),
        ));
    }

    /**
     * Absolute URL for the public webhook cards.
     *
     * @param string $path
     * @return string
     */
    private function absoluteUrl($path)
    {
        $configuredBase = class_exists('\\MajorDoMo\\Core\\Config')
            ? trim(\MajorDoMo\Core\Config::string('BASE_URL'))
            : '';
        if ($configuredBase !== '') {
            return rtrim($configuredBase, '/') . '/' . ltrim((string)$path, '/');
        }

        $scheme = 'http';
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        }
        $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
        $root = defined('ROOTHTML') ? (string)ROOTHTML : '/';

        return $scheme . '://' . $host . rtrim($root, '/') . '/' . ltrim((string)$path, '/');
    }

    /**
     * Pretty-print stored JSON for the drawer.
     *
     * @param string $json
     * @return string
     */
    private function prettyJson($json)
    {
        $decoded = json_decode((string)$json, true);
        if (!is_array($decoded)) {
            return '';
        }
        $encoded = json_encode($decoded, YandexHomeCatalog::JSON_FLAGS | JSON_PRETTY_PRINT);

        return $encoded !== false ? $this->e($encoded) : '';
    }

    /**
     * Mask a token for display.
     *
     * @param string $token
     * @return string
     */
    private function maskToken($token)
    {
        $token = (string)$token;
        $length = strlen($token);
        if ($length <= 8) {
            return $this->e($token);
        }

        return $this->e(substr($token, 0, 4) . '…' . substr($token, -4));
    }

    /**
     * HTML-escape a value for the template.
     *
     * @param mixed $value
     * @return string
     */
    private function e($value)
    {
        $escaped = htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // `$(` is treated as inline JS by partLoad.
        return str_replace('$', '&#36;', $escaped);
    }
}

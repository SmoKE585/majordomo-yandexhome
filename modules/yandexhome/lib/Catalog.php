<?php
/**
 * Yandex Home device/instance catalog.
 *
 * Single source of truth for device types, capabilities/properties and the
 * value presets shown in the admin UI. The catalog is static: it does not touch
 * the database, so it can be used by webhooks, admin pages and tests alike.
 *
 * @package MajorDoMo
 */

final class YandexHomeCatalog
{
    public const PREFIX_CAPABILITIES = 'devices.capabilities.';
    public const PREFIX_PROPERTIES = 'devices.properties.';
    public const PREFIX_TYPES = 'devices.types.';
    public const API_VERSION = '1.0';
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    public const FAKE_DEVICE_TITLE_PREFIX = '[YH_FAKE]';
    public const FAKE_DEVICE_ROOM = '__YH_FAKE__';

    /** @var array<string,array<string,string>>|null */
    private static $deviceTypes = null;

    /** @var array<string,array<string,mixed>>|null */
    private static $instances = null;

    /** @var array<string,array{label:string,map:array<string,string>}>|null */
    private static $valueMapPresets = null;

    /**
     * Device types supported by the Yandex smart-home platform.
     *
     * @return array<string,array{device_name:string,description:string,icon:string,group:string}>
     */
    public static function deviceTypes()
    {
        if (self::$deviceTypes !== null) {
            return self::$deviceTypes;
        }

        $types = array(
            // Освещение.
            'light' => array('Свет', 'bulb', 'Освещение'),
            'light.strip' => array('Светодиодная лента', 'bulb', 'Освещение'),
            'light.ceiling' => array('Люстра', 'bulb', 'Освещение'),
            'light.lamp' => array('Лампа', 'bulb', 'Освещение'),
            'light.garland' => array('Гирлянда', 'bulb', 'Освещение'),
            'light.sconce' => array('Бра', 'bulb', 'Освещение'),
            'light.torchere' => array('Торшер', 'bulb', 'Освещение'),
            'light.dimmable' => array('Диммируемый свет', 'bulb', 'Освещение'),

            // Электрика.
            'socket' => array('Розетка', 'plug', 'Электрика'),
            'switch' => array('Выключатель', 'toggle-right', 'Электрика'),
            'switch.relay' => array('Реле', 'toggle-right', 'Электрика'),

            // Климат.
            'thermostat' => array('Термостат', 'temperature', 'Климат'),
            'thermostat.ac' => array('Кондиционер', 'air-conditioning', 'Климат'),
            'thermostat.heater' => array('Обогреватель', 'flame', 'Климат'),
            'humidifier' => array('Увлажнитель', 'droplet', 'Климат'),
            'purifier' => array('Очиститель воздуха', 'wind', 'Климат'),
            'ventilation' => array('Вентиляция', 'windmill', 'Климат'),
            'ventilation.fan' => array('Вентилятор', 'windmill', 'Климат'),

            // Медиа.
            'media_device' => array('Медиаустройство', 'device-tv', 'Медиа'),
            'media_device.tv' => array('Телевизор', 'device-tv', 'Медиа'),
            'media_device.tv_box' => array('ТВ-приставка', 'device-tv', 'Медиа'),
            'media_device.receiver' => array('Ресивер', 'device-speaker', 'Медиа'),
            'camera' => array('Камера', 'video', 'Медиа'),

            // Техника.
            'cooking' => array('Кухонная техника', 'tools-kitchen', 'Техника'),
            'cooking.coffee_maker' => array('Кофеварка', 'coffee', 'Техника'),
            'cooking.kettle' => array('Чайник', 'coffee', 'Техника'),
            'cooking.multicooker' => array('Мультиварка', 'tools-kitchen-2', 'Техника'),
            'refrigerator' => array('Холодильник', 'fridge', 'Техника'),
            'washing_machine' => array('Стиральная машина', 'wash-machine', 'Техника'),
            'dishwasher' => array('Посудомоечная машина', 'wash-machine', 'Техника'),
            'iron' => array('Утюг/парогенератор', 'ironing', 'Техника'),
            'vacuum_cleaner' => array('Пылесос', 'vacuum-cleaner', 'Техника'),

            // Открывание и доступ.
            'openable' => array('Открываемое устройство', 'door', 'Открывание и доступ'),
            'openable.barrier' => array('Шлагбаум/ворота', 'barrier-block', 'Открывание и доступ'),
            'openable.curtain' => array('Шторы/жалюзи', 'window', 'Открывание и доступ'),
            'openable.door_lock' => array('Замок двери', 'lock', 'Открывание и доступ'),
            'openable.intercom' => array('Домофон', 'phone', 'Открывание и доступ'),
            'openable.valve' => array('Клапан', 'droplet', 'Открывание и доступ'),
            'remote_car' => array('Автомобиль', 'car', 'Открывание и доступ'),

            // Датчики.
            'sensor' => array('Датчик', 'access-point', 'Датчики'),
            'sensor.motion' => array('Датчик движения', 'walk', 'Датчики'),
            'sensor.vibration' => array('Датчик вибрации', 'activity', 'Датчики'),
            'sensor.illumination' => array('Датчик освещённости', 'brightness', 'Датчики'),
            'sensor.open' => array('Датчик открытия', 'door', 'Датчики'),
            'sensor.climate' => array('Климатический датчик', 'temperature', 'Датчики'),
            'sensor.water_leak' => array('Датчик протечки', 'droplet', 'Датчики'),
            'sensor.button' => array('Кнопка/событие кнопки', 'bell', 'Датчики'),
            'sensor.gas' => array('Датчик газа', 'flame', 'Датчики'),
            'sensor.smoke' => array('Датчик дыма', 'alarm', 'Датчики'),

            // Счётчики.
            'smart_meter' => array('Счётчик', 'gauge', 'Счётчики'),
            'smart_meter.cold_water' => array('Счётчик холодной воды', 'gauge', 'Счётчики'),
            'smart_meter.electricity' => array('Счётчик электричества', 'bolt', 'Счётчики'),
            'smart_meter.gas' => array('Счётчик газа', 'flame', 'Счётчики'),
            'smart_meter.heat' => array('Счётчик тепла', 'temperature', 'Счётчики'),
            'smart_meter.hot_water' => array('Счётчик горячей воды', 'gauge', 'Счётчики'),

            // Питомцы.
            'pet_drinking_fountain' => array('Поилка для животных', 'droplet', 'Питомцы'),
            'pet_feeder' => array('Кормушка для животных', 'dog-bowl', 'Питомцы'),

            // Другое.
            'other' => array('Другое устройство', 'device-unknown', 'Другое'),
        );

        $result = array();
        foreach ($types as $name => $definition) {
            $result[$name] = array(
                'device_name' => $name,
                'description' => $definition[0],
                'icon' => $definition[1],
                'group' => $definition[2],
            );
        }

        self::$deviceTypes = $result;

        return self::$deviceTypes;
    }

    /**
     * One device type definition.
     *
     * @param string $type
     * @return array<string,string>|null
     */
    public static function deviceType($type)
    {
        $types = self::deviceTypes();
        $type = trim((string)$type);

        return isset($types[$type]) ? $types[$type] : null;
    }

    /**
     * Device types grouped for `<optgroup>` rendering.
     *
     * @return array<int,array{label:string,types:array<int,array<string,string>>}>
     */
    public static function deviceTypeGroups()
    {
        $groups = array();
        foreach (self::deviceTypes() as $type) {
            if (!isset($groups[$type['group']])) {
                $groups[$type['group']] = array('label' => $type['group'], 'types' => array());
            }
            $groups[$type['group']]['types'][] = $type;
        }

        return array_values($groups);
    }

    /**
     * Capabilities and properties supported by the adapter.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function instances()
    {
        if (self::$instances !== null) {
            return self::$instances;
        }

        $instances = array(
            // capabilities: on_off
            'on' => array(
                'instance' => 'on',
                'description' => 'Включить/выключить',
                'capability' => 'on_off',
                'default_value' => 0,
                'value_type' => 'bool',
                'parameters' => array('split' => false),
            ),

            // capabilities: toggle
            'backlight' => array('instance' => 'backlight', 'description' => 'Подсветка', 'capability' => 'toggle', 'default_value' => 0, 'value_type' => 'bool'),
            'controls_locked' => array('instance' => 'controls_locked', 'description' => 'Блокировка управления', 'capability' => 'toggle', 'default_value' => 0, 'value_type' => 'bool'),
            'ionization' => array('instance' => 'ionization', 'description' => 'Ионизация', 'capability' => 'toggle', 'default_value' => 0, 'value_type' => 'bool'),
            'keep_warm' => array('instance' => 'keep_warm', 'description' => 'Поддержание тепла', 'capability' => 'toggle', 'default_value' => 0, 'value_type' => 'bool'),
            'mute' => array('instance' => 'mute', 'description' => 'Без звука', 'capability' => 'toggle', 'default_value' => 0, 'value_type' => 'bool'),
            'oscillation' => array('instance' => 'oscillation', 'description' => 'Поворот/качание', 'capability' => 'toggle', 'default_value' => 0, 'value_type' => 'bool'),
            'pause' => array('instance' => 'pause', 'description' => 'Пауза', 'capability' => 'toggle', 'default_value' => 0, 'value_type' => 'bool'),

            // capabilities: range
            'brightness' => array(
                'instance' => 'brightness',
                'description' => 'Яркость',
                'capability' => 'range',
                'default_value' => 50,
                'value_type' => 'int',
                'parameters' => array(
                    'unit' => 'unit.percent',
                    'random_access' => true,
                    'range' => array('min' => 1, 'max' => 100, 'precision' => 1),
                ),
            ),
            'channel' => array(
                'instance' => 'channel',
                'description' => 'Канал',
                'capability' => 'range',
                'default_value' => 1,
                'value_type' => 'int',
                'parameters' => array(
                    'random_access' => true,
                    'range' => array('min' => 0, 'max' => 999, 'precision' => 1),
                ),
            ),
            'humidity' => array(
                'instance' => 'humidity',
                'description' => 'Влажность',
                'capability' => 'range',
                'default_value' => 40,
                'value_type' => 'int',
                'parameters' => array(
                    'unit' => 'unit.percent',
                    'random_access' => true,
                    'range' => array('min' => 0, 'max' => 100, 'precision' => 1),
                ),
            ),
            'open' => array(
                'instance' => 'open',
                'description' => 'Степень открытия',
                'capability' => 'range',
                'default_value' => 0,
                'value_type' => 'int',
                'parameters' => array(
                    'unit' => 'unit.percent',
                    'random_access' => true,
                    'range' => array('min' => 0, 'max' => 100, 'precision' => 1),
                ),
            ),
            'temperature' => array(
                'instance' => 'temperature',
                'description' => 'Температура',
                'capability' => 'range',
                'default_value' => 22,
                'value_type' => 'int',
                'parameters' => array(
                    'unit' => 'unit.temperature.celsius',
                    'random_access' => true,
                    'range' => array('min' => 1, 'max' => 100, 'precision' => 1),
                ),
            ),
            'volume' => array(
                'instance' => 'volume',
                'description' => 'Громкость',
                'capability' => 'range',
                'default_value' => 20,
                'value_type' => 'int',
                'parameters' => array(
                    'random_access' => true,
                    'range' => array('min' => 1, 'max' => 100, 'precision' => 1),
                ),
            ),
        );

        $modes = array(
            'cleanup_mode' => array('Режим уборки', 'auto', array('wet_cleaning', 'dry_cleaning', 'mixed_cleaning', 'auto', 'eco', 'smart', 'turbo', 'quiet', 'normal', 'fast', 'max', 'low')),
            'coffee_mode' => array('Режим кофеварки', 'americano', array('americano', 'cappuccino', 'double', 'espresso', 'double_espresso', 'latte')),
            'dishwashing' => array('Режим мойки посуды', 'auto', array('auto', 'eco', 'normal', 'intensive', 'express', 'glass', 'pre_rinse', 'fast')),
            'fan_speed' => array('Скорость вентиляции', 'auto', array('auto', 'eco', 'quiet', 'low', 'medium', 'normal', 'high', 'turbo', 'max', 'min')),
            'heat' => array('Режим нагрева', 'one', array('one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten')),
            'input_source' => array('Источник сигнала', 'one', array('one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten')),
            'program' => array('Программа', 'auto', array('auto', 'eco', 'quiet', 'normal', 'turbo', 'min', 'medium', 'max', 'express', 'fast', 'slow', 'smart', 'high', 'low', 'aspic', 'baby_food', 'baking', 'bread', 'boiling', 'cereals', 'cheesecake', 'deep_fryer', 'dessert', 'fowl', 'frying', 'macaroni', 'milk_porridge', 'multicooker', 'pasta', 'pilaf', 'pizza', 'sauce', 'slow_cook', 'soup', 'steam', 'stewing', 'vacuum', 'yogurt')),
            'swing' => array('Направление потока', 'stationary', array('horizontal', 'vertical', 'stationary', 'auto', 'turbo')),
            'tea_mode' => array('Режим приготовления чая', 'black_tea', array('black_tea', 'flower_tea', 'green_tea', 'herbal_tea', 'oolong_tea', 'puerh_tea', 'red_tea', 'white_tea', 'express')),
            'thermostat' => array('Температурный режим', 'auto', array('auto', 'cool', 'dry', 'fan_only', 'heat', 'preheat', 'eco')),
            'ventilation_mode' => array('Режим вентиляции', 'auto', array('auto', 'supply_air', 'extraction_air')),
            'work_speed' => array('Скорость работы', 'normal', array('auto', 'eco', 'quiet', 'low', 'medium', 'normal', 'high', 'turbo', 'max', 'min', 'fast', 'slow')),
        );
        foreach ($modes as $name => $definition) {
            $values = array();
            foreach ($definition[2] as $value) {
                $values[] = array('value' => $value);
            }
            $instances[$name] = array(
                'instance' => $name,
                'description' => $definition[0],
                'capability' => 'mode',
                'default_value' => $definition[1],
                'value_type' => 'string',
                'parameters' => array('modes' => $values),
            );
        }

        $instances['rgb'] = array(
            'instance' => 'rgb',
            'description' => 'Цвет RGB',
            'capability' => 'color_setting',
            'default_value' => '000000',
            'value_type' => 'rgb',
            'parameters' => array('color_model' => 'rgb'),
        );
        $instances['temperature_k'] = array(
            'instance' => 'temperature_k',
            'description' => 'Цветовая температура',
            'capability' => 'color_setting',
            'default_value' => 4500,
            'value_type' => 'int',
            'parameters' => array('temperature_k' => array('min' => 1500, 'max' => 9000)),
        );
        $scenes = array();
        foreach (array('alarm', 'alice', 'candle', 'dinner', 'fantasy', 'garland', 'jungle', 'movie', 'neon', 'night', 'ocean', 'party', 'reading', 'rest', 'romance', 'siren', 'sunrise', 'sunset') as $scene) {
            $scenes[] = array('id' => $scene);
        }
        $instances['scene'] = array(
            'instance' => 'scene',
            'description' => 'Цветовая сцена',
            'capability' => 'color_setting',
            'default_value' => 'alarm',
            'value_type' => 'string',
            'parameters' => array('color_scene' => array('scenes' => $scenes)),
        );
        $instances['get_stream'] = array(
            'instance' => 'get_stream',
            'description' => 'Видеопоток камеры (HLS URL в привязанном свойстве)',
            'capability' => 'video_stream',
            'default_value' => '',
            'value_type' => 'stream_url',
            'retrievable' => false,
            'reportable_fixed' => false,
            'parameters' => array('protocols' => array('hls')),
        );

        $floats = array(
            'amperage_sensor' => array('amperage', 'Ток (A)', 'unit.ampere'),
            'battery_level_sensor' => array('battery_level', 'Уровень заряда (%)', 'unit.percent'),
            'co2_level_sensor' => array('co2_level', 'CO2 (ppm)', 'unit.ppm'),
            'electricity_meter_sensor' => array('electricity_meter', 'Счётчик электроэнергии (кВт⋅ч)', 'unit.kilowatt_hour'),
            'food_level_sensor' => array('food_level', 'Уровень корма (%)', 'unit.percent'),
            'gas_meter_sensor' => array('gas_meter', 'Счётчик газа (м³)', 'unit.cubic_meter'),
            'heat_meter_sensor' => array('heat_meter', 'Счётчик тепла (Гкал)', 'unit.gigacalorie'),
            'humidity_sensor' => array('humidity', 'Влажность (%)', 'unit.percent'),
            'illumination_sensor' => array('illumination', 'Освещённость (lux)', 'unit.illumination.lux'),
            'meter_sensor' => array('meter', 'Универсальный счётчик', ''),
            'pm1_density_sensor' => array('pm1_density', 'PM1 (мкг/м³)', 'unit.density.mcg_m3'),
            'pm2_5_density_sensor' => array('pm2.5_density', 'PM2.5 (мкг/м³)', 'unit.density.mcg_m3'),
            'pm10_density_sensor' => array('pm10_density', 'PM10 (мкг/м³)', 'unit.density.mcg_m3'),
            'power_sensor' => array('power', 'Мощность (W)', 'unit.watt'),
            'pressure_sensor' => array('pressure', 'Давление (мм рт. ст.)', 'unit.pressure.mmhg'),
            'temperature_sensor' => array('temperature', 'Температура (°C)', 'unit.temperature.celsius'),
            'tvoc_sensor' => array('tvoc', 'TVOC (мкг/м³)', 'unit.density.mcg_m3'),
            'voltage_sensor' => array('voltage', 'Напряжение (V)', 'unit.volt'),
            'water_level_sensor' => array('water_level', 'Уровень воды (%)', 'unit.percent'),
            'water_meter_sensor' => array('water_meter', 'Счётчик воды (м³)', 'unit.cubic_meter'),
        );
        foreach ($floats as $name => $definition) {
            $parameters = array();
            if ($definition[2] !== '') {
                $parameters['unit'] = $definition[2];
            }
            $instances[$name] = array(
                'instance' => $definition[0],
                'description' => $definition[1],
                'capability' => 'float',
                'default_value' => 0,
                'value_type' => 'float',
                'parameters' => $parameters,
            );
        }

        $events = array(
            'vibration_sensor' => array('vibration', 'Датчик вибрации', 'tilt', array('tilt', 'fall', 'vibration')),
            'open_sensor' => array('open', 'Датчик открытия', 'closed', array('opened', 'closed')),
            'button_sensor' => array('button', 'Событие кнопки', 'click', array('click', 'double_click', 'long_press')),
            'motion_sensor' => array('motion', 'Датчик движения', 'not_detected', array('detected', 'not_detected')),
            'smoke_sensor' => array('smoke', 'Датчик дыма', 'not_detected', array('detected', 'not_detected', 'high')),
            'gas_sensor' => array('gas', 'Датчик газа', 'not_detected', array('detected', 'not_detected', 'high')),
            'water_leak_sensor' => array('water_leak', 'Датчик протечки', 'dry', array('dry', 'leak')),
            'battery_level_event_sensor' => array('battery_level', 'Событие уровня батареи', 'normal', array('low', 'normal')),
            'food_level_event_sensor' => array('food_level', 'Событие уровня корма', 'normal', array('empty', 'low', 'normal')),
            'water_level_event_sensor' => array('water_level', 'Событие уровня воды', 'normal', array('empty', 'low', 'normal')),
        );
        foreach ($events as $name => $definition) {
            $values = array();
            foreach ($definition[3] as $value) {
                $values[] = array('value' => $value);
            }
            $instances[$name] = array(
                'instance' => $definition[0],
                'description' => $definition[1],
                'capability' => 'event',
                'default_value' => $definition[2],
                'value_type' => 'event',
                'parameters' => array('events' => $values),
            );
        }

        self::$instances = $instances;

        return self::$instances;
    }

    /**
     * One capability/property definition.
     *
     * @param string $type
     * @return array<string,mixed>|null
     */
    public static function instance($type)
    {
        $instances = self::instances();
        $type = self::normalizeTraitType($type);

        return isset($instances[$type]) ? $instances[$type] : null;
    }

    /**
     * Capability groups used by the trait picker.
     *
     * @return array<int,array{label:string,icon:string,types:array<int,array<string,string>>}>
     */
    public static function instanceGroups()
    {
        $groups = array(
            'on_off' => array('Управление: включить/выключить', 'toggle-right'),
            'toggle' => array('Управление: переключатели', 'switch'),
            'range' => array('Управление: диапазоны', 'adjustments'),
            'mode' => array('Управление: режимы', 'list'),
            'color_setting' => array('Управление: цвет', 'palette'),
            'video_stream' => array('Камера: видеопоток', 'video'),
            'float' => array('Датчики: числовые значения', 'wave-sine'),
            'event' => array('Датчики: события', 'bell'),
        );

        $result = array();
        foreach ($groups as $capability => $meta) {
            $result[$capability] = array('label' => $meta[0], 'icon' => $meta[1], 'types' => array());
        }
        foreach (self::instances() as $name => $definition) {
            $capability = isset($definition['capability']) ? (string)$definition['capability'] : '';
            if (!isset($result[$capability])) {
                continue;
            }
            $result[$capability]['types'][] = array(
                'instance_name' => $name,
                'description' => isset($definition['description']) ? (string)$definition['description'] : $name,
                'icon' => $result[$capability]['icon'],
            );
        }

        return array_values($result);
    }

    /**
     * Value-map presets offered in the device editor.
     *
     * @return array<string,array{label:string,map:array<string,string>}>
     */
    public static function valueMapPresets()
    {
        if (self::$valueMapPresets !== null) {
            return self::$valueMapPresets;
        }

        self::$valueMapPresets = array(
            'none' => array('label' => 'Без преобразования', 'map' => array()),
            'custom' => array('label' => 'Пользовательский', 'map' => array()),
            'bool_onoff_10' => array(
                'label' => '1/0 → on/off',
                'map' => array('1' => 'on', '0' => 'off', 'true' => 'on', 'false' => 'off'),
            ),
            'bool_open_closed_10' => array(
                'label' => '1/0 → opened/closed',
                'map' => array('1' => 'opened', '0' => 'closed', 'true' => 'opened', 'false' => 'closed'),
            ),
            'bool_detected_10' => array(
                'label' => '1/0 → detected/not_detected',
                'map' => array('1' => 'detected', '0' => 'not_detected', 'true' => 'detected', 'false' => 'not_detected'),
            ),
            'bool_leak_10' => array(
                'label' => '1/0 → leak/dry',
                'map' => array('1' => 'leak', '0' => 'dry', 'true' => 'leak', 'false' => 'dry'),
            ),
            'level_012_food_water' => array(
                'label' => '0/1/2 → empty/low/normal',
                'map' => array('0' => 'empty', '1' => 'low', '2' => 'normal'),
            ),
            'button_123' => array(
                'label' => '1/2/3 → click/double_click/long_press',
                'map' => array('1' => 'click', '2' => 'double_click', '3' => 'long_press'),
            ),
        );

        return self::$valueMapPresets;
    }

    /**
     * Legacy instance names remapped to the current catalog keys.
     *
     * @return array<string,string>
     */
    public static function legacyAliases()
    {
        return array(
            'pm2.5_density_sensor' => 'pm2_5_density_sensor',
        );
    }

    /**
     * Normalize a legacy trait/instance name.
     *
     * @param string $type
     * @return string
     */
    public static function normalizeTraitType($type)
    {
        $type = trim((string)$type);
        $aliases = self::legacyAliases();

        return isset($aliases[$type]) ? $aliases[$type] : $type;
    }

    /**
     * Yandex `instance` name for a trait.
     *
     * @param string $type
     * @param array<string,mixed>|null $definition
     * @return string
     */
    public static function instanceName($type, $definition = null)
    {
        if (!is_array($definition)) {
            $definition = self::instance($type);
        }
        if (is_array($definition) && isset($definition['instance']) && $definition['instance'] !== '') {
            return (string)$definition['instance'];
        }

        return (string)$type;
    }

    /**
     * Whether a capability is reported as a Yandex `properties` entry.
     *
     * @param string $capability
     * @return bool
     */
    public static function isPropertyCapability($capability)
    {
        return $capability === 'float' || $capability === 'event';
    }

    /**
     * Default value map for a trait type.
     *
     * @param string $type
     * @return array<string,string>
     */
    public static function defaultValueMap($type)
    {
        return $type === 'on'
            ? array('1' => 'on', '0' => 'off', 'true' => 'on', 'false' => 'off')
            : array();
    }

    /**
     * Default value-map preset for a trait type.
     *
     * @param string $type
     * @return string
     */
    public static function defaultPreset($type)
    {
        return $type === 'on' ? 'bool_onoff_10' : 'none';
    }

    /**
     * Enum values accepted by Yandex for a trait.
     *
     * @param string $type
     * @return array<int,string>
     */
    public static function allowedValues($type)
    {
        $definition = self::instance($type);
        if (!is_array($definition)) {
            return array();
        }
        $capability = isset($definition['capability']) ? $definition['capability'] : '';
        $parameters = isset($definition['parameters']) && is_array($definition['parameters'])
            ? $definition['parameters']
            : array();
        $key = $capability === 'mode' ? 'modes' : ($capability === 'event' ? 'events' : '');
        if ($key === '' || !isset($parameters[$key]) || !is_array($parameters[$key])) {
            return array();
        }
        $values = array();
        foreach ($parameters[$key] as $item) {
            if (is_array($item) && isset($item['value'])) {
                $values[] = (string)$item['value'];
            }
        }

        return $values;
    }

    /**
     * Icon for a device type.
     *
     * @param string $type
     * @return string
     */
    public static function deviceIcon($type)
    {
        $definition = self::deviceType($type);

        return $definition !== null ? $definition['icon'] : 'device-unknown';
    }

    /**
     * Human description for a device type.
     *
     * @param string $type
     * @return string
     */
    public static function deviceDescription($type)
    {
        $definition = self::deviceType($type);

        return $definition !== null ? $definition['description'] : (string)$type;
    }
}

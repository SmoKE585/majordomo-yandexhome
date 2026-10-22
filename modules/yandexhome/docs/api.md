---
title: API и внутреннее устройство
audience: agent
category: module-yandexhome
tags: api, provider adapter, oauth, разработка, архитектура
description: Вебхуки, формат запросов и ответов, классы модуля и точки расширения.
order: 30
---

# API и внутреннее устройство

## Файлы

| Файл | Назначение |
| --- | --- |
| `modules/yandexhome/yandexhome.class.php` | Контроллер админки, публичные обёртки, install/uninstall. |
| `modules/yandexhome/lib/Catalog.php` | Каталог типов устройств, умений и пресетов значений. |
| `modules/yandexhome/lib/TraitMapper.php` | Нормализация значений, построение discovery-конфига. |
| `modules/yandexhome/lib/Repository.php` | SQL, связи свойств, пересборка CONFIG. |
| `modules/yandexhome/lib/Notifier.php` | Discovery/state callbacks в Яндекс Диалоги. |
| `modules/yandexhome/lib/RequestHandler.php` | `sync`, `query`, `action`, `unlink`. |
| `modules/yandexhome/server.php` | Общий boot и OAuth2-сервер для вебхуков. |
| `modules/yandexhome/authorize.php` | Страница авторизации OAuth2. |
| `modules/yandexhome/token.php` | Выдача и обновление токена. |
| `modules/yandexhome/smarthome.php` | Provider Adapter API. |

OAuth2-библиотека лежит в `3rdparty/oauth2-server-php/` (namespace `OAuth2`). Хранилище — `OAuth2\Storage\MajordomoPdo`.

## Вебхуки

| Метод | URL | Обработчик |
| --- | --- | --- |
| GET/POST | `/modules/yandexhome/authorize.php` | OAuth2 authorize |
| POST | `/modules/yandexhome/token.php` | token / refresh |
| POST | `/modules/yandexhome/smarthome.php/v1.0/user/devices` | `HandleSyncRequest` |
| POST | `/modules/yandexhome/smarthome.php/v1.0/user/devices/query` | `HandleQueryRequest` |
| POST | `/modules/yandexhome/smarthome.php/v1.0/user/devices/action` | `HandleExecuteRequest` |
| POST | `/modules/yandexhome/smarthome.php/v1.0/user/unlink` | `HandleUnlinkRequest` |

`GET /modules/yandexhome/smarthome.php` без path и с телом `{"request":{"command":"ping"}}` отвечает `pong`.

## OAuth2

- Grant types: `client_credentials`, `authorization_code`, `refresh_token`.
- Access token живёт 7 дней.
- Таблица `yandexhome_oauth` хранит клиента и токены.
- Логин/пароль для связки аккаунтов хранятся в `project_modules.DATA` модуля (`USER_NAME`, `USER_PASS`).
- `authorize.php` сравнивает пароль через `hash_equals()` и использует CSRF-токен в сессии.

## Discovery

Для каждого устройства `Repository::instanceConfigFromDevice()` восстанавливает параметры редактора, затем `TraitMapper::buildDeviceConfig()` собирает:

```json
{
  "id": "1",
  "name": "Свет на кухне",
  "type": "devices.types.light",
  "status_info": {"reportable": true},
  "device_info": {"manufacturer": "MajorDoMo", "model": "..."},
  "capabilities": [
    {
      "type": "devices.capabilities.on_off",
      "parameters": {"instance": "on", "split": false},
      "retrievable": true,
      "reportable": true
    }
  ],
  "properties": [
    {
      "type": "devices.properties.float",
      "parameters": {"instance": "temperature", "unit": "unit.temperature.celsius"},
      "retrievable": true,
      "reportable": false
    }
  ]
}
```

Конфиг сохраняется в `yandexhome_devices.CONFIG` и пересобирается при сохранении устройства.

## Query

Ответ содержит `capabilities` и/или `properties` с `state.instance` и `state.value`. Значения читаются через `getGlobal('Object.Property')`. Если привязки нет, используется `default_value` из каталога.

## Action

- `capabilities[].state.value` записывается через `setGlobal()` с источником модуля;
- `relative: true` поддерживается для `range` (прибавляет значение к текущему);
- `video_stream` + `instance=get_stream` возвращает `stream_url` из привязанного свойства;
- в автономном режиме запись запрещена, возвращается `NOT_SUPPORTED_IN_CURRENT_MODE`;
- ошибки: `DEVICE_NOT_FOUND`, `INVALID_ACTION`, `DEVICE_UNREACHABLE`.

## Reportable

`PropertySetHandle($object, $property, $value)` ищет устройства по `TRAITS LIKE` и отправляет `callback/state` только для умений с `reportable=true`. Для отправки нужны `SKILL_ACCESS_TOKEN` и `SKILL_ID` (или legacy `CLIENT_KEY`).

## Расширение каталога

1. Добавьте тип устройства или умение в `lib/Catalog.php`.
2. Проверьте, что имя `instance` совпадает с документацией Яндекса.
3. Для нового `capability` добавьте ветку нормализации в `TraitMapper`.
4. Для нового типа значения — обработку в `normalizeValueForState()`/`normalizeValueForWrite()`.
5. Обновите `docs/devices.md` и, при необходимости, пресеты значений.

Каталог не читает БД и не зависит от админки, поэтому его можно использовать в тестах и CLI.

## Совместимость

Публичные методы старого класса сохранены как обёртки: `HandleSyncRequest`, `HandleQueryRequest`, `HandleExecuteRequest`, `HandleUnlinkRequest`, `buildDeviceConfig`, `migrateDeviceConfigs`, `DeleteLinkedProperties`, `getReportableSkillId`, `isReportableConfigured`, `ReadonlyModeEnable`, `ReadonlyModeDisable`, `WriteLog`, `IncomingRequestFormat`. Внешний код, который их вызывает, продолжит работать.

# oauth2-server-php

Вендорная копия `bshaffer/oauth2-server-php` (MIT), используемая модулем
Yandex Home как OAuth 2.0 provider.

- Namespace: `OAuth2\`
- Точка подключения: `Autoloader.php`
- Хранилище MajorDoMo: `Storage/MajordomoPdo.php` (таблица `yandexhome_oauth`)

Неиспользуемые драйверы хранилищ (Cassandra, Couchbase, DynamoDB, Mongo,
MongoDB, Redis) удалены: модуль работает только с PDO/MySQL. При обновлении
библиотеки не возвращайте их без отдельной задачи.

Правки в `Storage/MajordomoPdo.php` должны оставаться совместимыми с
существующей таблицей `yandexhome_oauth` (формат данных менять нельзя).

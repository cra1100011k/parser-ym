# Интеграция с Яндекс Картами

Тестовое приложение на Laravel + Vue 3 для подключения карточки организации в Яндекс Картах, загрузки рейтинга, счетчиков и отзывов.

## Демо

Рабочее приложение:

```text
http://31.76.49.89
```

Доступ:

```text
Email: test@example.com
Password: password
```

## Возможности

- авторизация через Laravel Sanctum;
- один сид-пользователь без регистрации;
- сохранение ссылки на карточку организации;
- валидация ссылок разных форматов Яндекс Карт;
- загрузка названия организации, среднего рейтинга, количества оценок и количества отзывов;
- загрузка до 600 доступных отзывов;
- сохранение результата парсинга в БД;
- пагинация отзывов по 50 штук без перезагрузки страницы;
- очистка сохраненных данных;
- обработка состояний загрузки и ошибок в интерфейсе.

## Стек

- PHP 8.3
- Laravel 13
- Laravel Sanctum
- SQLite
- Vue 3 Composition API
- Vite

## Локальный запуск

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
npm run build
php artisan serve --host=127.0.0.1 --port=8010
```

Для загрузки отзывов нужно запустить очередь во втором терминале:

```bash
php artisan queue:work --tries=1 --timeout=240
```

Приложение будет доступно по адресу:

```text
http://127.0.0.1:8010
```

Для разработки фронта можно отдельно запустить Vite:

```bash
npm run dev
```

## Переменные окружения

Для локального запуска достаточно `.env.example`.

Важные переменные:

```env
APP_URL=http://127.0.0.1:8010
DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
SESSION_DRIVER=database
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:8010,localhost:8010,127.0.0.1:8000,localhost:8000,127.0.0.1,localhost
SESSION_DOMAIN=null
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=300
```

Если порт отличается, нужно поменять `APP_URL` и добавить домен с портом в `SANCTUM_STATEFUL_DOMAINS`.

## Как работает парсинг

Данные получаются через разбор публичной страницы карточки и внутренних запросов, которые использует сама страница Яндекс Карт.

Сначала `YandexMapUrlParser` извлекает `oid` организации из ссылки. Поддерживаются ссылки вида:

```text
https://yandex.ru/maps/org/.../{oid}/reviews/
https://yandex.ru/maps/org/.../{oid}/
https://yandex.ru/maps/...&poi[uri]=ymapsbm1://org?oid={oid}
```

Дальше `YandexMapReviewsService`:

1. Загружает HTML карточки с браузерными заголовками.
2. Достает из HTML название организации, рейтинг, количество оценок, количество отзывов, `requestId`, `sessionId` и `locale`.
3. Получает `csrfToken` через внутренний endpoint отзывов.
4. Делает постраничные запросы к `maps/api/business/fetchReviews`.
5. Загружает максимум 12 страниц по 50 отзывов, то есть до 600 отзывов.
6. Делает небольшую паузу между запросами.
7. Нормализует отзывы и сохраняет их в БД.

Я выбрала этот подход вместо headless-браузера, потому что для тестового приложения он проще в деплое, быстрее и дешевле по ресурсам. Playwright или другой headless-браузер я бы добавила как fallback, если Яндекс изменит внутренние запросы или начнет отдавать нужные данные только после выполнения JavaScript.

## Кэширование и пагинация

При сохранении ссылки приложение ставит задачу импорта в очередь. Воркер один раз загружает доступные отзывы и сохраняет их в таблицу `reviews`.

После этого пагинация работает уже через БД, а не через повторный парсинг Яндекса на каждое переключение страницы. Такой подход уменьшает количество внешних запросов, ускоряет интерфейс и снижает риск упереться в антибот-защиту.

Отзывы отдаются через Laravel pagination по 50 штук на страницу.

## Структура БД

Основные таблицы:

- `users` - пользователь для входа;
- `organizations` - подключенная организация, исходная ссылка, `yandex_oid`, название, рейтинг и счетчики;
- `reviews` - отзывы организации: автор, дата, текст, оценка, внешний id и сырой ответ;
- `jobs` - очередь импорта отзывов.

## Основные файлы

- `app/Services/YandexMaps/YandexMapUrlParser.php` - разбор ссылки и извлечение `oid`;
- `app/Services/YandexMaps/YandexMapReviewsService.php` - загрузка HTML, извлечение метаданных и отзывов;
- `app/Services/YandexMaps/YandexOrganizationImportService.php` - импорт организации и сохранение в БД;
- `app/Jobs/ImportYandexOrganizationReviews.php` - фоновая задача загрузки отзывов;
- `app/Http/Controllers/OrganizationController.php` - API для организации и отзывов;
- `resources/js/App.vue` - SPA-интерфейс;
- `database/migrations/*create_organizations_table.php` - таблица организаций;
- `database/migrations/*create_reviews_table.php` - таблица отзывов.

## API

Все API-роуты защищены Sanctum.

```text
GET    /api/user
GET    /api/organization
POST   /api/organization
DELETE /api/organization
GET    /api/organizations/{organization}/reviews?page=1
```

## Обработка ошибок

Обработаны основные ситуации:

- ссылка не ведет на Яндекс Карты;
- в ссылке не найден идентификатор организации;
- карточка недоступна;
- Яндекс вернул пустой HTML;
- не удалось найти рейтинг или служебные параметры в HTML;
- API отзывов вернул ошибку или неожиданный ответ;
- Яндекс вернул пустой список отзывов при ненулевом счетчике отзывов;
- пользователь не авторизован;
- сессия истекла.

Технические детали ошибок парсера пишутся в лог Laravel.

## Тесты

```bash
php artisan test
```

Добавлены unit-тесты на `YandexMapUrlParser` и feature-тесты на авторизацию, валидацию ссылки, постановку импорта в очередь и пагинацию отзывов.

## Деплой

На хостинге document root должен указывать на папку `public`.

Базовые команды:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Для production-окружения:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://31.76.49.89
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/parser-ym/database/database.sqlite
SANCTUM_STATEFUL_DOMAINS=31.76.49.89
SESSION_DOMAIN=null
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=300
```

Также нужен постоянный queue worker, например через systemd или Supervisor:

```bash
php artisan queue:work database --sleep=3 --tries=1 --timeout=240
```

## Что бы я добавила дальше

Если бы было больше времени, я бы добавила:

- загрузку фотографий к отзывам, если они есть в ответе Яндекс Карт;
- мониторинг очереди и повторный запуск неудачных задач из интерфейса;
- кнопку ручного обновления отзывов;
- хранение истории обновлений организации;
- больше feature-тестов на сценарии с ошибками внешнего источника;
- fallback через Playwright для случаев, когда внутренний endpoint Яндекса меняется;
- ограничение частоты запросов к парсеру;
- более подробный экран статуса парсинга;
- docker-compose для полностью одинакового локального запуска.

## Комментарий

Главное ограничение решения - зависимость от внутреннего endpoint Яндекс Карт и структуры HTML. Это осознанный компромисс для тестового приложения: решение быстрее и проще деплоится, но при изменениях на стороне Яндекса парсер нужно будет адаптировать.

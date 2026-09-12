# Maps Reviews — интеграция с Яндекс.Картами

Небольшое SPA-приложение: Laravel 12 API + Vue 3 (Composition API) + Sanctum.
Подключаете ссылку на карточку организации в Яндекс.Картах, парсер тянет рейтинг,
счётчики и доступные отзывы (~до 600), UI показывает их с пагинацией по 50.

## Быстрый старт (docker-compose)

```bash
cp .env.example .env
docker compose up --build
```

Приложение: http://127.0.0.1:8080

Логин по умолчанию:

- email: `demo@example.com`
- password: `password`

В docker-compose крутятся два сервиса: `app` (HTTP) и `queue` (воркер парсинга).

## Локально без Docker

Нужны PHP 8.2+, Composer, Node 20+.

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run build

# терминал 1
php artisan serve

# терминал 2
php artisan queue:work
```

Для разработки фронта: `npm run dev` вместо `npm run build`.

## Переменные окружения

| Переменная | Назначение |
|---|---|
| `SEED_USER_EMAIL` / `SEED_USER_PASSWORD` | Единственный пользователь |
| `YANDEX_BASE_URL` | База для запросов (`https://yandex.com` предпочтительнее `.ru` с датацентровых IP) |
| `YANDEX_REVIEW_CAP` | Верхняя граница отзывов (по умолчанию 600) |
| `YANDEX_PAGE_DELAY_US` | Пауза между страницами парсинга (мкс) |
| `SANCTUM_STATEFUL_DOMAINS` | Домены для cookie-auth SPA |
| `APP_URL` | Публичный URL приложения |

## Как устроен парсинг

Официального API отзывов у Яндекса нет. Публичный виджет отдаёт максимум несколько
отзывов. Данные берём с публичной карточки организации.

### Выбранный способ: серверный HTML + `?page=N`

1. Из ссылки достаём `businessId` / slug (`YandexUrl`).
2. Качаем `GET {base}/maps/org/{slug}/{id}/reviews/?lang=ru&page=N`.
3. В HTML ищем JSON в `<script type="application/json">` и рекурсивно находим узел
   с `ratingData` (средний рейтинг, `ratingCount`, `reviewCount`) и
   `reviewResults.reviews` (до 50 штук на страницу).
4. Листаем `page=2..N` с паузой, пока страницы не кончатся или не упрёмся в ~600.
5. Результат пишем в БД (`updateOrCreate` по `yandex_review_id`), UI читает только нашу БД.

Почему не signed `fetchReviews` и не headless по умолчанию — см. ниже.

### Кэш и пагинация UI

Парсинг запускается один раз (очередь), результат кэшируется в SQLite/БД.
Переключение страниц в SPA — обычный `paginate(50)` по таблице `reviews`,
Яндекс при листании **не** дергается. Повторный парсинг — явная кнопка.

Это осознанный выбор: ~600 отзывов × частые клики по страницам нельзя тащить
с Яндекса на каждый HTTP-запрос пользователя.

## Сравнение подходов (п.2 доп. требований)

| | HTML + `?page=N` (выбрано) | Headless (Playwright/Puppeteer) | Разбор signed `fetchReviews` |
|---|---|---|---|
| Скорость | Высокая | Низкая (браузер, скролл) | Высокая при рабочей подписи |
| Стабильность | Зависит от SSR-разметки/`?page` | Ловит капчи/флаки с DC IP | Ломается при смене алгоритма `s` |
| Сложность | Средняя | Высокая (Chromium в проде) | Высокая (нужно воспроизвести JS-подпись) |
| Антибот | Меньше шума, но IP важен | Легче «как браузер», чаще капча с DC | Холодный запрос без валидного `s` → 400 |

**Почему так:** `fetchReviews` подписывается одноразовым `s` из JS Яндекса — с бэка
его надёжно не повторить. Headless умеет перехватить ответы, но тяжёлый, медленный
и с датацентрового IP часто упирается в SmartCaptcha. SSR-страница отзывов уже
отдаёт нужный JSON и пагинируется через `?page=N` — этого достаточно для тестового
и для аккуратного продакшен-прототипа.

Риск: Яндекс может убрать `?page` или поменять форму JSON. Тогда логичный fallback —
headless со скроллом / перехват `fetchReviews`.

## Устойчивость к смене разметки (п.1)

Парсер **не** возвращает «тихий» пустой успех:

- нет HTTP 2xx / обрыв сети → `unreachable`
- в ответе капча без `ratingData`/`reviewId` → `captcha`
- HTML есть, но нет `ratingData` и не удалось вытащить счётчики из `og:description` → `markup_changed` (пишем в лог длину HTML и признаки state blob)
- пустой/слишком короткий ответ → `empty`
- оболочка карт без карточки организации → `not_found`

Статус и текст ошибки сохраняются в `organizations.parse_status` / `parse_error` и
видны в UI. Job не ретраит «постоянные» ошибки вроде `markup_changed` / `not_found`.

Практический сигнал «парсер сломался»: рост доли `markup_changed` в логах/мониторинге
после деплоя Яндекса, при том что URL организаций валидные.

## Очередь и масштаб (п.3)

Парсинг уходит в `ParseOrganizationJob` (database queue):

- прогресс 0–100% в `parse_progress` (UI поллит `/api/organization`);
- до 3 попыток с backoff `30/90/180` сек на сетевые/капча сбои;
- timeout джобы 300 сек.

Для сети ~50 филиалов × ~600 отзывов:

- один job на организацию, rate-limit на очередь (например, 1–2 concurrent worker’а);
- не парсить синхронно в HTTP-запросе сохранения ссылки;
- расписание ночного refresh по stale-TTL;
- отдельная таблица/метрика прогресса уже заложена в модели организации.

В этом прототипе очередь реализована; горизонтальное масштабирование воркеров —
вопрос деплоя (несколько `queue` сервисов + Redis/SQS вместо database driver).

## Анти-бан (п.4)

Что сделано сейчас:

- целевой хост `yandex.com` (дружелюбнее к DC IP, чем `.ru`);
- реалистичный User-Agent и `Accept-Language`;
- пауза между страницами (`YANDEX_PAGE_DELAY_US`);
- кэш в БД → живые запросы к Яндексу редкие.

Что добавить на объёме (описано, не обязательно всё в коде):

- троттлинг: глобальный лимит RPS + jitter;
- exponential backoff и «карантин» организации/прокси при `captcha`;
- ротация residential-прокси и пула User-Agent;
- джиттер расписания, чтобы 50 филиалов не стартовали в одну секунду;
- алерт «нас забанили»: доля `captcha`/`unreachable` выше порога → пауза воркеров.

## Идемпотентность и история (п.5)

- Уникальный ключ `(organization_id, yandex_review_id)` + `updateOrCreate` —
  повторный парсинг обновляет записи, не плодит дубли.
- После успешного парсинга пишется снимок в `organization_snapshots`
  (рейтинг, число оценок/отзывов, сколько сохранили у нас, timestamp).
  По двум соседним снимкам видно «было → стало» по счётчикам.

Если дорабатывать историю отзывов точечно: таблица `review_revisions`
или soft-diff полей `text`/`rating` между прогонами.

## Структура

```
app/Services/Yandex/     # URL + парсер (вне контроллеров)
app/Jobs/ParseOrganizationJob.php
app/Http/Controllers/Api/
resources/js/pages/      # Login / Settings / Organization
```

## Тесты

```bash
php artisan test
```

Юнит-тесты URL и разбора fixture HTML — без сети.

## Что доделал бы при большем времени

- UI сравнения соседних `organization_snapshots` и алерт на падение рейтинга.
- Fallback headless, если `?page`/JSON пропадут.
- Периодический refresh по TTL, мульти-организации на аккаунт.
- Residential proxy pool + healthcheck.
- Выкладка на managed hosting с Redis queue и бэкапами SQLite/Postgres.
- Сбор ответов организации (`businessComment`) и фото отзывов.

## Формат сдачи

- Репозиторий: этот проект (`git` инициализирован локально; запушьте на GitHub/GitLab при сдаче).
- Локально: `docker compose up --build` → http://127.0.0.1:8080
- Быстрое публичное демо (пока крутится `php artisan serve` + `cloudflared tunnel`):
  поднимите туннель `cloudflared tunnel --url http://127.0.0.1:8000`,
  пропишите хост в `APP_URL` и `SANCTUM_STATEFUL_DOMAINS`.
- Для постоянного хостинга: VPS / Railway / Render с `docker compose`
  (сервисы `app` + `queue`) и теми же env-переменными.

Логин по умолчанию: `demo@example.com` / `password`.

# Maps Reviews — Laravel + Vue + Яндекс.Карты

SPA: подключаете ссылку на организацию в Яндекс.Картах, парсер тянет рейтинг и
публично доступные отзывы, UI показывает их с пагинацией.

Один поток Яндекса обычно обрывается около **600** отзывов. Парсер обходит это через
несколько выдач (сортировки, аспекты, звёзды) с дедупом — полный счётчик карточки
всё равно может быть недоступен.

## Быстрый старт

```bash
cp .env.example .env
make up          # ENV=dev по умолчанию
# make up ENV=prod
```

Приложение: http://127.0.0.1:8080  
Логин: `demo@example.com` / `password`

Сервисы: `db` (PostgreSQL), `app` (HTTP), `queue` (воркер парсинга).

|                      | Dev (`make up`)                                 | Prod (`make up ENV=prod`)   |
| -------------------- | ----------------------------------------------- | --------------------------- |
| Compose              | `docker-compose.yml` + `docker-compose.dev.yml` | + `docker-compose.prod.yml` |
| Порт Postgres наружу | `5433`                                          | закрыт                      |

```bash
make test
```

## Переменные

| Переменная | Назначение |
| --- | --- |
| `DB_*` | PostgreSQL (`5433` → `5432` в Docker) |
| `SEED_USER_EMAIL` / `SEED_USER_PASSWORD` | Пользователь |
| `YANDEX_BASE_URL` | `https://yandex.com` предпочтительнее с DC IP |
| `YANDEX_PAGE_DELAY_US` | Пауза между страницами API (мкс) |
| `SANCTUM_STATEFUL_DOMAINS` | Cookie-auth SPA |
| `APP_URL` | Публичный URL |

Лимит отзывов задаётся в UI настроек (`review_cap`), не через env.

## Парсинг

1. Из URL — `businessId` / slug (`YandexUrl`).
2. HTML первой страницы отзывов → cookies, `csrfToken` / `sessionId` / `reqId`, аспекты.
3. Signed `fetchReviews` по сортировкам, аспектам и звёздам; дедуп по `reviewId`.
   Fallback — HTML `?page=N`.
4. Каждый успешный прогон пишет снимок (`organization_snapshots`) и отзывы к нему.
   Повторный парсинг не перезаписывает старые снимки.

Ошибки: `unreachable`, `captcha`, `markup_changed`, `empty`, `not_found` —
в `parse_status` / `parse_error`. Постоянные ошибки джоба не ретраит.

Джоба: прогресс + текст этапа (`parse_message`), до 3 попыток, timeout 1800 сек.

## Анти-бан (сейчас)

- хост `yandex.com`, реалистичный UA, пауза между страницами;
- кэш в БД → редкие живые запросы к Яндексу.

## Структура

```
app/Services/Yandex/          # URL + парсер
app/Jobs/ParseOrganizationJob.php
app/Http/Controllers/Api/
resources/js/pages/           # Login / Settings / Organization / Snapshot
```

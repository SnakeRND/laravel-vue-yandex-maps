<?php

namespace App\Services\Yandex;

use App\Exceptions\YandexParseException;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YandexMapsParser
{
    private ?CookieJar $cookieJar = null;

    private ?string $csrfToken = null;

    private ?string $sessionId = null;

    private ?string $reqId = null;

    public function fetchPage(YandexUrl $org, int $page = 1): array
    {
        $html = $this->downloadHtml($org, $page);

        return $this->extract($html);
    }

    public function fetchAll(YandexUrl $org, ?callable $onProgress = null, ?int $reviewCap = null): array
    {
        $cap = max(0, (int) ($reviewCap ?? 0));
        $pageSize = max(1, (int) config('yandex.page_size', 50));
        $delay = (int) config('yandex.page_delay_us', 100_000);

        $this->cookieJar = new CookieJar;
        $html = $this->downloadHtml($org, 1);
        $first = $this->extract($html);
        $this->hydrateApiContext($html);

        $byId = [];
        foreach ($first['reviews'] as $review) {
            $byId[$review['yandex_review_id']] = $review;
        }

        $reported = $first['reviews_count'];
        $aspects = $this->extractAspects($html);
        $queries = $this->buildQueryVariants(array_keys($aspects));
        $totalQueries = max(count($queries), 1);

        if ($onProgress) {
            $onProgress(5, 'Карточка загружена · уникальных отзывов: '.count($byId));
        }

        if ($this->canUseSignedApi()) {
            foreach ($queries as $queryIndex => $queryExtra) {
                if ($cap > 0 && count($byId) >= $cap) {
                    break;
                }

                $label = $this->queryLabel($queryExtra, $aspects);
                if ($onProgress) {
                    $onProgress(
                        $this->stageProgress($queryIndex, $totalQueries, 0, 1),
                        sprintf(
                            'Этап %d/%d: %s · уникальных отзывов: %d',
                            $queryIndex + 1,
                            $totalQueries,
                            $label,
                            count($byId),
                        ),
                    );
                }

                $this->paginateSignedApi(
                    $org,
                    $queryExtra,
                    $byId,
                    $cap,
                    $pageSize,
                    $delay,
                    $queryIndex,
                    $totalQueries,
                    $label,
                    $onProgress,
                );
            }
        } else {
            if ($onProgress) {
                $onProgress(8, 'API недоступен, листаем HTML-страницы · уникальных отзывов: '.count($byId));
            }

            $this->paginateHtmlPages($org, $byId, $cap, $pageSize, $delay, $reported, $onProgress);
        }

        if ($onProgress) {
            $onProgress(99, 'Сохранение · уникальных отзывов: '.count($byId));
        }

        return [
            'name' => $first['name'],
            'average_rating' => $first['average_rating'],
            'ratings_count' => $first['ratings_count'],
            'reviews_count' => $first['reviews_count'],
            'reviews' => array_values($byId),
        ];
    }

    private function buildQueryVariants(array $aspectIds): array
    {
        $queries = [
            ['ranking' => 'by_relevance_org'],
            ['ranking' => 'by_time'],
            ['ranking' => 'by_rating_desc'],
            ['ranking' => 'by_rating_asc'],
        ];

        foreach ($aspectIds as $aspectId) {
            $queries[] = ['ranking' => 'by_aspect_tone_desc', 'aspectId' => $aspectId];
            $queries[] = ['ranking' => 'by_aspect_tone_asc', 'aspectId' => $aspectId];
        }

        foreach ([1, 2, 3, 4, 5] as $star) {
            $queries[] = ['ranking' => 'by_time', 'rating' => $star];
        }

        return $queries;
    }

    private function queryLabel(array $query, array $aspectNames): string
    {
        if (isset($query['aspectId'])) {
            $name = $aspectNames[(string) $query['aspectId']] ?? (string) $query['aspectId'];
            $tone = ($query['ranking'] ?? '') === 'by_aspect_tone_asc'
                ? 'сначала негатив'
                : 'сначала позитив';

            return 'аспект «'.$name.'», '.$tone;
        }

        if (isset($query['rating'])) {
            return 'фильтр '.$query['rating'].'★';
        }

        return match ($query['ranking'] ?? '') {
            'by_relevance_org' => 'сортировка по релевантности',
            'by_time' => 'сортировка по времени',
            'by_rating_desc' => 'сначала высокие оценки',
            'by_rating_asc' => 'сначала низкие оценки',
            default => 'выдача отзывов',
        };
    }

    private function stageProgress(int $queryIndex, int $totalQueries, int $page, int $estPages): int
    {
        $within = min(1, max(0, $page) / max($estPages, 1));
        $fraction = ($queryIndex + $within) / max($totalQueries, 1);

        return (int) min(99, max(5, round(5 + $fraction * 90)));
    }

    private function paginateSignedApi(
        YandexUrl $org,
        array $queryExtra,
        array &$byId,
        int $cap,
        int $pageSize,
        int $delay,
        int $queryIndex,
        int $totalQueries,
        string $label,
        ?callable $onProgress,
    ): void {
        $maxPages = $cap > 0
            ? (int) ceil($cap / $pageSize) + 2
            : 40;
        $estPages = min($maxPages, 12);

        for ($page = 1; $page <= $maxPages; $page++) {
            if ($cap > 0 && count($byId) >= $cap) {
                return;
            }

            if ($page > 1) {
                usleep($delay);
            }

            $chunk = $this->fetchReviewsApi($org, array_merge($queryExtra, [
                'page' => $page,
                'pageSize' => $pageSize,
            ]));

            if ($chunk === null || $chunk === []) {
                return;
            }

            $added = 0;
            foreach ($chunk as $review) {
                if ($cap > 0 && count($byId) >= $cap) {
                    return;
                }

                $id = $review['yandex_review_id'];
                if (! isset($byId[$id])) {
                    $byId[$id] = $review;
                    $added++;
                }
            }

            if ($onProgress) {
                $onProgress(
                    $this->stageProgress($queryIndex, $totalQueries, $page, $estPages),
                    sprintf(
                        'Этап %d/%d: %s · стр. %d · уникальных отзывов: %d',
                        $queryIndex + 1,
                        $totalQueries,
                        $label,
                        $page,
                        count($byId),
                    ),
                );
            }

            if ($added === 0) {
                return;
            }
        }
    }

    private function paginateHtmlPages(
        YandexUrl $org,
        array &$byId,
        int $cap,
        int $pageSize,
        int $delay,
        ?int $reported,
        ?callable $onProgress,
    ): void {
        $maxPages = $cap > 0
            ? (int) ceil($cap / $pageSize) + 2
            : ($reported && $reported > 0
                ? (int) ceil($reported / $pageSize) + 5
                : 40);

        for ($page = 2; $page <= $maxPages; $page++) {
            if ($cap > 0 && count($byId) >= $cap) {
                break;
            }

            usleep($delay);

            $chunk = $this->fetchPage($org, $page);
            if ($chunk['reviews'] === []) {
                break;
            }

            $added = 0;
            foreach ($chunk['reviews'] as $review) {
                if ($cap > 0 && count($byId) >= $cap) {
                    break;
                }

                if (! isset($byId[$review['yandex_review_id']])) {
                    $byId[$review['yandex_review_id']] = $review;
                    $added++;
                }
            }

            if ($onProgress) {
                $fraction = min(1, $page / max($maxPages, 1));
                $onProgress(
                    (int) min(99, max(8, round(8 + $fraction * 87))),
                    sprintf('HTML-страница %d · уникальных отзывов: %d', $page, count($byId)),
                );
            }

            if ($added === 0) {
                break;
            }
        }
    }

    private function canUseSignedApi(): bool
    {
        return $this->csrfToken && $this->sessionId && $this->reqId;
    }

    private function hydrateApiContext(string $html): void
    {
        $this->csrfToken = $this->extractJsonString($html, 'csrfToken');
        $this->sessionId = $this->extractJsonString($html, 'sessionId');
        $this->reqId = $this->extractAddrsRequestId($html);
    }

    private function fetchReviewsApi(YandexUrl $org, array $params): ?array
    {
        if (! $this->canUseSignedApi()) {
            return null;
        }

        $query = array_merge([
            'ajax' => 1,
            'businessId' => $org->yandexId,
            'csrfToken' => $this->csrfToken,
            'locale' => 'ru_RU',
            'reqId' => $this->reqId,
            'sessionId' => $this->sessionId,
        ], $params);

        $query['s'] = $this->signYandexQuery($query);
        $base = rtrim((string) config('yandex.base_url'), '/');
        $url = $base.'/maps/api/business/fetchReviews?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $referer = $org->reviewsUrl($base, 1);

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('yandex.user_agent'),
                'Accept' => 'application/json',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                'Referer' => $referer,
                'X-Retpath-Y' => $referer,
            ])
                ->withOptions(['cookies' => $this->cookieJar])
                ->timeout((int) config('yandex.http_timeout', 25))
                ->get($url);
        } catch (ConnectionException|RequestException $e) {
            Log::warning('Yandex fetchReviews failed', [
                'yandex_id' => $org->yandexId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        if (isset($body['csrfToken']) && is_string($body['csrfToken']) && $body['csrfToken'] !== '') {
            $this->csrfToken = $body['csrfToken'];
        }

        if (isset($body['error'])) {
            return null;
        }

        $reviews = $body['data']['reviews'] ?? null;
        if (! is_array($reviews)) {
            return [];
        }

        $mapped = [];
        foreach ($reviews as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $review = $this->mapRawReview($raw);
            if ($review !== null) {
                $mapped[] = $review;
            }
        }

        return $mapped;
    }

    private function mapRawReview(array $raw): ?array
    {
        if (empty($raw['reviewId'])) {
            return null;
        }

        $author = $raw['author'] ?? null;

        return [
            'yandex_review_id' => (string) $raw['reviewId'],
            'author_name' => is_array($author) ? ($author['name'] ?? null) : null,
            'rating' => isset($raw['rating']) ? (int) $raw['rating'] : null,
            'text' => isset($raw['text']) ? (string) $raw['text'] : null,
            'reviewed_at' => $raw['updatedTime'] ?? $raw['time'] ?? null,
        ];
    }

    private function signYandexQuery(array $query): string
    {
        uksort($query, fn (string $left, string $right): int => strtolower($left) <=> strtolower($right));

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $hash = 5381;

        for ($index = 0, $length = strlen($queryString); $index < $length; $index++) {
            $hash = ((33 * $hash) ^ ord($queryString[$index])) & 0xFFFFFFFF;
        }

        return (string) $hash;
    }

    private function extractJsonString(string $text, string $key): ?string
    {
        $quotedKey = preg_quote($key, '/');

        if (! preg_match('/"'.$quotedKey.'":"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/u', $text, $matches)) {
            return null;
        }

        $decoded = json_decode('"'.$matches[1].'"');

        return is_string($decoded) ? $decoded : $matches[1];
    }

    private function extractAddrsRequestId(string $html): ?string
    {
        preg_match_all('/"requestId":"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/u', $html, $matches);

        foreach ($matches[1] ?? [] as $requestId) {
            $decoded = json_decode('"'.$requestId.'"');
            $requestId = is_string($decoded) ? $decoded : $requestId;

            if (str_contains($requestId, 'addrs-upper')) {
                return $requestId;
            }
        }

        return null;
    }

    private function extractAspects(string $html): array
    {
        $aspects = [];

        foreach ($this->jsonScriptPayloads($html) as $data) {
            $this->collectAspects($data, $aspects);
        }

        return $aspects;
    }

    private function collectAspects(array $node, array &$aspects): void
    {
        if (isset($node['id'], $node['text'], $node['count'], $node['positive'])
            && is_scalar($node['id'])
            && ctype_digit((string) $node['id'])) {
            $aspects[(string) $node['id']] = (string) $node['text'];
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectAspects($value, $aspects);
            }
        }
    }

    private function downloadHtml(YandexUrl $org, int $page): string
    {
        $url = $org->reviewsUrl(config('yandex.base_url'), $page);

        try {
            $request = Http::withHeaders([
                'User-Agent' => config('yandex.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            ])->timeout((int) config('yandex.http_timeout', 25));

            if ($this->cookieJar) {
                $request = $request->withOptions(['cookies' => $this->cookieJar]);
            }

            $response = $request->get($url);
        } catch (ConnectionException|RequestException $e) {
            $status = $e instanceof RequestException ? $e->response?->status() : null;

            Log::warning('Yandex connection failed', [
                'yandex_id' => $org->yandexId,
                'page' => $page,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            if (in_array($status, [404, 410], true)) {
                throw YandexParseException::notFound();
            }

            throw YandexParseException::unreachable();
        }

        if (! $response->successful()) {
            Log::warning('Yandex HTTP error', [
                'yandex_id' => $org->yandexId,
                'page' => $page,
                'status' => $response->status(),
            ]);

            if (in_array($response->status(), [404, 410], true)) {
                throw YandexParseException::notFound();
            }

            throw YandexParseException::unreachable();
        }

        $html = $response->body();

        if ($html === '' || strlen($html) < 1000) {
            throw YandexParseException::empty();
        }

        if ($this->looksLikeCaptcha($html)) {
            Log::warning('Yandex captcha detected', [
                'yandex_id' => $org->yandexId,
                'page' => $page,
            ]);

            throw YandexParseException::captcha();
        }

        return $html;
    }

    private function looksLikeCaptcha(string $html): bool
    {
        $hasReviews = str_contains($html, '"reviewId"') || str_contains($html, '"ratingData"');
        $hasChallenge = (bool) preg_match('/showcaptcha|checkcaptcha|smartcaptcha|captcha\.yandex/i', $html);

        return $hasChallenge && ! $hasReviews;
    }

    private function extract(string $html): array
    {
        $node = $this->findRatingNode($html);

        if ($node === null) {
            $fallback = $this->countersFromMeta($html);
            if ($fallback === null) {
                if ($this->looksLikeEmptyShell($html)) {
                    throw YandexParseException::notFound();
                }

                Log::error('Yandex markup changed: ratingData not found', [
                    'html_length' => strlen($html),
                    'has_state_view' => str_contains($html, 'state-view'),
                    'has_app_json' => str_contains($html, 'application/json'),
                ]);

                throw YandexParseException::markupChanged();
            }

            return [
                'name' => $fallback['name'],
                'average_rating' => $fallback['average'],
                'ratings_count' => $fallback['ratings'],
                'reviews_count' => $fallback['reviews'],
                'reviews' => [],
            ];
        }

        $rating = $node['ratingData'] ?? [];
        $reviews = [];

        foreach (($node['reviewResults']['reviews'] ?? []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $review = $this->mapRawReview($raw);
            if ($review !== null) {
                $reviews[] = $review;
            }
        }

        return [
            'name' => $node['name'] ?? $node['title'] ?? null,
            'average_rating' => isset($rating['ratingValue']) ? round((float) $rating['ratingValue'], 2) : null,
            'ratings_count' => isset($rating['ratingCount']) ? (int) $rating['ratingCount'] : null,
            'reviews_count' => isset($rating['reviewCount']) ? (int) $rating['reviewCount'] : null,
            'reviews' => $reviews,
        ];
    }

    private function findRatingNode(string $html): ?array
    {
        foreach ($this->jsonScriptPayloads($html) as $data) {
            $found = $this->searchRatingNode($data);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function jsonScriptPayloads(string $html): array
    {
        if (! preg_match_all('#<script[^>]*type="application/json"[^>]*>(.*?)</script>#s', $html, $matches)
            && ! preg_match_all('#<script[^>]*class="state-view"[^>]*>(.*?)</script>#s', $html, $matches)) {
            return [];
        }

        $blocks = $matches[1];
        usort($blocks, fn ($a, $b) => strlen($b) <=> strlen($a));

        $payloads = [];
        foreach ($blocks as $block) {
            $data = json_decode(html_entity_decode($block, ENT_QUOTES | ENT_HTML5), true);
            if (! is_array($data)) {
                $data = json_decode($block, true);
            }
            if (is_array($data)) {
                $payloads[] = $data;
            }
        }

        return $payloads;
    }

    private function searchRatingNode(array $node): ?array
    {
        if (isset($node['ratingData']) && is_array($node['ratingData'])
            && (isset($node['ratingData']['ratingCount']) || isset($node['ratingData']['reviewCount']))) {
            return $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = $this->searchRatingNode($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function countersFromMeta(string $html): ?array
    {
        if (! preg_match('#property="og:description"\s+content="([^"]+)"#', $html, $m)
            && ! preg_match('#content="([^"]+)"\s+property="og:description"#', $html, $m)) {
            return null;
        }

        $desc = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        if (! preg_match('#Рейтинг\s+([\d,\.]+)\s+на основе\s+([\d\s]+)\s+оцен\w+\s+и\s+([\d\s]+)\s+отзыв#u', $desc, $mm)) {
            return null;
        }

        $name = null;
        if (preg_match('#property="og:title"\s+content="([^"]+)"#', $html, $t)
            || preg_match('#content="([^"]+)"\s+property="og:title"#', $html, $t)) {
            $title = html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5);
            $name = trim(explode(',', $title)[0]);
            $name = preg_replace('#^Отзывы об\s+[«"]?#u', '', $name) ?? $name;
            $name = rtrim($name, '»"');
        }

        return [
            'name' => $name,
            'average' => (float) str_replace(',', '.', $mm[1]),
            'ratings' => (int) preg_replace('/\s+/', '', $mm[2]),
            'reviews' => (int) preg_replace('/\s+/', '', $mm[3]),
        ];
    }

    private function looksLikeEmptyShell(string $html): bool
    {
        $genericTitle = (bool) preg_match('#<title>[^<]*Яндекс\s*Карты\s*—\s*транспорт#u', $html);
        $noOrgPayload = ! str_contains($html, '"ratingData"') && ! str_contains($html, '"reviewId"');

        return $genericTitle && $noOrgPayload;
    }
}

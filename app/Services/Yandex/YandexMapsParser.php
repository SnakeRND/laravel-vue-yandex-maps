<?php

namespace App\Services\Yandex;

use App\Exceptions\YandexParseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches organization rating and reviews from the public Yandex Maps card.
 *
 * Approach: the reviews tab server-renders an embedded JSON blob with
 * ratingData + up to 50 reviews. Extra pages are available via ?page=N
 * (same blob shape). This avoids the signed fetchReviews XHR and a headless browser.
 */
class YandexMapsParser
{
    /**
     * Fetch one page of reviews + organization counters.
     *
     * @return array{
     *     name: ?string,
     *     average_rating: ?float,
     *     ratings_count: ?int,
     *     reviews_count: ?int,
     *     reviews: list<array{yandex_review_id: string, author_name: ?string, rating: ?int, text: ?string, reviewed_at: ?string}>
     * }
     */
    public function fetchPage(YandexUrl $org, int $page = 1): array
    {
        $html = $this->download($org, $page);

        return $this->extract($html);
    }

    /**
     * Walk ?page=1..N until empty page or review cap.
     *
     * @param  callable(int $fetched, int $target): void|null  $onProgress
     * @return array{
     *     name: ?string,
     *     average_rating: ?float,
     *     ratings_count: ?int,
     *     reviews_count: ?int,
     *     reviews: list<array{yandex_review_id: string, author_name: ?string, rating: ?int, text: ?string, reviewed_at: ?string}>
     * }
     */
    public function fetchAll(YandexUrl $org, ?callable $onProgress = null): array
    {
        $cap = (int) config('yandex.review_cap', 600);
        $pageSize = (int) config('yandex.page_size', 50);
        $maxPages = (int) ceil($cap / $pageSize) + 1;
        $delay = (int) config('yandex.page_delay_us', 400_000);

        $first = $this->fetchPage($org, 1);

        $byId = [];
        foreach ($first['reviews'] as $review) {
            $byId[$review['yandex_review_id']] = $review;
        }

        $target = min($cap, $first['reviews_count'] ?? $cap);
        if ($onProgress) {
            $onProgress(count($byId), max($target, 1));
        }

        for ($page = 2; $page <= $maxPages && count($byId) < $cap; $page++) {
            usleep($delay);

            $chunk = $this->fetchPage($org, $page);
            if ($chunk['reviews'] === []) {
                break;
            }

            $added = 0;
            foreach ($chunk['reviews'] as $review) {
                if (! isset($byId[$review['yandex_review_id']])) {
                    $byId[$review['yandex_review_id']] = $review;
                    $added++;
                }
            }

            if ($added === 0) {
                break;
            }

            if ($onProgress) {
                $onProgress(count($byId), max($target, 1));
            }
        }

        return [
            'name' => $first['name'],
            'average_rating' => $first['average_rating'],
            'ratings_count' => $first['ratings_count'],
            'reviews_count' => $first['reviews_count'],
            'reviews' => array_values($byId),
        ];
    }

    private function download(YandexUrl $org, int $page): string
    {
        $url = $org->reviewsUrl(config('yandex.base_url'), $page);

        try {
            $response = Http::withHeaders([
                'User-Agent' => config('yandex.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            ])
                ->timeout((int) config('yandex.http_timeout', 25))
                ->get($url);
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

    /**
     * @return array{
     *     name: ?string,
     *     average_rating: ?float,
     *     ratings_count: ?int,
     *     reviews_count: ?int,
     *     reviews: list<array{yandex_review_id: string, author_name: ?string, rating: ?int, text: ?string, reviewed_at: ?string}>
     * }
     */
    private function extract(string $html): array
    {
        $node = $this->findRatingNode($html);

        if ($node === null) {
            $fallback = $this->countersFromMeta($html);
            if ($fallback === null) {
                // Generic maps shell without org payload usually means a missing/geo-blocked card.
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
            if (! is_array($raw) || empty($raw['reviewId'])) {
                continue;
            }

            $author = $raw['author'] ?? null;
            $authorName = is_array($author) ? ($author['name'] ?? null) : null;

            $reviews[] = [
                'yandex_review_id' => (string) $raw['reviewId'],
                'author_name' => $authorName,
                'rating' => isset($raw['rating']) ? (int) $raw['rating'] : null,
                'text' => isset($raw['text']) ? (string) $raw['text'] : null,
                'reviewed_at' => $raw['updatedTime'] ?? $raw['time'] ?? null,
            ];
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
        if (! preg_match_all('#<script[^>]*type="application/json"[^>]*>(.*?)</script>#s', $html, $matches)) {
            // Older / alternate markup: class="state-view"
            if (! preg_match_all('#<script[^>]*class="state-view"[^>]*>(.*?)</script>#s', $html, $matches)) {
                return null;
            }
        }

        $blocks = $matches[1];
        usort($blocks, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($blocks as $block) {
            $data = json_decode(html_entity_decode($block, ENT_QUOTES | ENT_HTML5), true);
            if (! is_array($data)) {
                $data = json_decode($block, true);
            }
            if (! is_array($data)) {
                continue;
            }

            $found = $this->searchRatingNode($data);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
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

    /**
     * Fallback from og:description like:
     * "Рейтинг 4,9 на основе 21220 оценок и 5858 отзывов о ..."
     *
     * @return array{name: ?string, average: float, ratings: int, reviews: int}|null
     */
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

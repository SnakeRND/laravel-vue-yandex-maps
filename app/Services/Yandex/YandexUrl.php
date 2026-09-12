<?php

namespace App\Services\Yandex;

use App\Exceptions\YandexParseException;

/**
 * Parses and normalizes a public Yandex Maps organization URL.
 */
class YandexUrl
{
    public function __construct(
        public readonly string $yandexId,
        public readonly ?string $slug = null,
        public readonly string $originalUrl = '',
    ) {}

    public static function parse(string $url): self
    {
        $url = trim($url);

        if ($url === '') {
            throw YandexParseException::invalidUrl();
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw YandexParseException::invalidUrl();
        }

        $host = strtolower($parts['host']);
        if (! preg_match('#(^|\.)yandex\.(ru|com|by|kz|uz)$#', $host)
            && ! preg_match('#(^|\.)ya\.ru$#', $host)) {
            throw YandexParseException::invalidUrl('Ссылка должна вести на Яндекс.Карты (yandex.ru / yandex.com).');
        }

        $path = $parts['path'] ?? '';

        // /maps/org/{slug}/{id}/... or /maps/org/{id}/...
        if (preg_match('#/maps/org/([^/]+)/(\d{6,})#', $path, $m)) {
            return new self(
                yandexId: $m[2],
                slug: ctype_digit($m[1]) ? null : $m[1],
                originalUrl: $url,
            );
        }

        if (preg_match('#/maps/org/(\d{6,})#', $path, $m)) {
            return new self(yandexId: $m[1], originalUrl: $url);
        }

        // Query form: ?oid=... or orgpage[id]=...
        parse_str($parts['query'] ?? '', $query);
        $oid = $query['oid'] ?? $query['orgId'] ?? null;
        if (is_string($oid) && preg_match('#^\d{6,}$#', $oid)) {
            return new self(yandexId: $oid, originalUrl: $url);
        }

        throw YandexParseException::invalidUrl(
            'Не удалось найти ID организации в ссылке. Ожидается вида https://yandex.ru/maps/org/название/1234567890/'
        );
    }

    public function reviewsUrl(string $baseUrl, int $page = 1): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $slug = $this->slug ?: 'org';

        $url = "{$baseUrl}/maps/org/{$slug}/{$this->yandexId}/reviews/";

        $query = ['lang' => 'ru'];
        if ($page > 1) {
            $query['page'] = $page;
        }

        return $url.'?'.http_build_query($query);
    }
}

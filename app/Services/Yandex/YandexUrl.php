<?php

namespace App\Services\Yandex;

use App\Exceptions\YandexParseException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class YandexUrl
{
    public function __construct(
        public readonly string $yandexId,
        public readonly ?string $slug = null,
        public readonly string $originalUrl = '',
    ) {}

    public static function parse(string $url, bool $allowResolve = true): self
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

        if ($allowResolve && preg_match('#/maps/-/[^/?]+#', $path)) {
            $resolved = self::resolveShortLink($url);

            return self::parse($resolved, allowResolve: false);
        }

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

        parse_str($parts['query'] ?? '', $query);

        $poiUri = $query['poi']['uri'] ?? null;
        if (is_string($poiUri) && preg_match('#oid=(\d{6,})#', $poiUri, $m)) {
            return new self(yandexId: $m[1], originalUrl: $url);
        }

        $oid = $query['oid'] ?? $query['orgId'] ?? null;
        if (is_string($oid) && preg_match('#^\d{6,}$#', $oid)) {
            return new self(yandexId: $oid, originalUrl: $url);
        }

        if (preg_match('#[?&]oid=(\d{6,})#', $url, $m)) {
            return new self(yandexId: $m[1], originalUrl: $url);
        }

        throw YandexParseException::invalidUrl(
            'Не удалось найти ID организации. Вставьте ссылку «Поделиться» (https://yandex.com/maps/-/…) или карточку /maps/org/…/ID/'
        );
    }

    private static function resolveShortLink(string $url): string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => config('yandex.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'ru-RU,ru;q=0.9',
            ])
                ->withOptions(['allow_redirects' => false])
                ->timeout((int) config('yandex.http_timeout', 25))
                ->get($url);
        } catch (ConnectionException $e) {
            throw YandexParseException::unreachable();
        }

        $location = $response->header('Location');
        if (! is_string($location) || $location === '') {
            throw YandexParseException::invalidUrl(
                'Не удалось раскрыть короткую ссылку Яндекс.Карт. Попробуйте ссылку на карточку организации.'
            );
        }

        if (str_starts_with($location, '/')) {
            $parts = parse_url($url);
            $location = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? 'yandex.com').$location;
        }

        return $location;
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

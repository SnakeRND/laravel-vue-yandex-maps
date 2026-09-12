<?php

namespace Tests\Unit;

use App\Exceptions\YandexParseException;
use App\Services\Yandex\YandexUrl;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YandexUrlTest extends TestCase
{
    #[DataProvider('validUrls')]
    public function test_parses_valid_urls(string $url, string $id, ?string $slug): void
    {
        $parsed = YandexUrl::parse($url);

        $this->assertSame($id, $parsed->yandexId);
        $this->assertSame($slug, $parsed->slug);
    }

    public static function validUrls(): array
    {
        return [
            'with slug' => [
                'https://yandex.ru/maps/org/yandex/1124715036/',
                '1124715036',
                'yandex',
            ],
            'reviews tab' => [
                'https://yandex.com/maps/org/cafe/123456789012/reviews/',
                '123456789012',
                'cafe',
            ],
            'without scheme' => [
                'yandex.ru/maps/org/shop/998877665544',
                '998877665544',
                'shop',
            ],
            'share redirect with poi uri' => [
                'https://yandex.com/maps/11034/belaya-kalitva/?mode=poi&poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D197699001803&utm_source=share',
                '197699001803',
                null,
            ],
        ];
    }

    public function test_resolves_short_share_link(): void
    {
        Http::fake([
            'https://yandex.com/maps/-/CTtUnHna' => Http::response('', 301, [
                'Location' => '/maps/11034/belaya-kalitva/?mode=poi&poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D197699001803&utm_source=share',
            ]),
        ]);

        $parsed = YandexUrl::parse('https://yandex.com/maps/-/CTtUnHna');

        $this->assertSame('197699001803', $parsed->yandexId);
    }

    public function test_rejects_non_yandex_host(): void
    {
        $this->expectException(YandexParseException::class);
        YandexUrl::parse('https://example.com/maps/org/test/1234567890');
    }

    public function test_rejects_url_without_org_id(): void
    {
        $this->expectException(YandexParseException::class);
        YandexUrl::parse('https://yandex.ru/maps/moscow');
    }

    public function test_builds_reviews_url(): void
    {
        $url = new YandexUrl('1124715036', 'yandex');
        $this->assertSame(
            'https://yandex.com/maps/org/yandex/1124715036/reviews/?lang=ru&page=2',
            $url->reviewsUrl('https://yandex.com', 2)
        );
    }
}

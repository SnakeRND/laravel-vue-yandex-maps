<?php

namespace Tests\Unit;

use App\Services\Yandex\YandexMapsParser;
use ReflectionMethod;
use Tests\TestCase;

class YandexMapsParserTest extends TestCase
{
    public function test_extracts_rating_and_reviews_from_fixture(): void
    {
        $html = file_get_contents(__DIR__.'/../fixtures/yandex_reviews_page.html');

        $parser = new YandexMapsParser;
        $method = new ReflectionMethod(YandexMapsParser::class, 'extract');
        $method->setAccessible(true);

        $result = $method->invoke($parser, $html);

        $this->assertSame('Тестовая Компания', $result['name']);
        $this->assertSame(4.5, $result['average_rating']);
        $this->assertSame(100, $result['ratings_count']);
        $this->assertSame(40, $result['reviews_count']);
        $this->assertCount(2, $result['reviews']);
        $this->assertSame('rev-1', $result['reviews'][0]['yandex_review_id']);
        $this->assertSame('Иван', $result['reviews'][0]['author_name']);
        $this->assertSame(5, $result['reviews'][0]['rating']);
    }

    public function test_detects_not_found_on_empty_shell(): void
    {
        $this->expectExceptionMessage('Организация не найдена');

        $html = <<<'HTML'
<!DOCTYPE html><html><head><title>Яндекс Карты — транспорт, навигация</title></head>
<body><script type="application/json">{"stack":[]}</script></body></html>
HTML;

        $parser = new YandexMapsParser;
        $method = new ReflectionMethod(YandexMapsParser::class, 'extract');
        $method->setAccessible(true);
        $method->invoke($parser, $html);
    }

    public function test_detects_markup_changed_when_json_shape_unknown(): void
    {
        $this->expectExceptionMessage('Разметка или данные карточки');

        $html = <<<'HTML'
<!DOCTYPE html><html><head><title>Отзывы об «Кафе» — Яндекс Карты</title></head>
<body><script type="application/json">{"foo":{"bar":1}}</script></body></html>
HTML;

        $parser = new YandexMapsParser;
        $method = new ReflectionMethod(YandexMapsParser::class, 'extract');
        $method->setAccessible(true);
        $method->invoke($parser, $html);
    }
}

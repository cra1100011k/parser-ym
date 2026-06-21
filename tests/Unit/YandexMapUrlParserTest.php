<?php

namespace Tests\Unit;

use App\Services\YandexMaps\YandexMapUrlParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class YandexMapUrlParserTest extends TestCase
{
    #[DataProvider('validYandexMapUrls')]
    public function test_it_extracts_oid_from_supported_yandex_map_urls(string $url, string $expectedOid): void
    {
        $parser = new YandexMapUrlParser();

        $this->assertSame($expectedOid, $parser->extractOid($url));
    }

    public function test_it_rejects_empty_url(): void
    {
        $parser = new YandexMapUrlParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('не может быть пустой');

        $parser->extractOid(' ');
    }

    public function test_it_rejects_non_url_string(): void
    {
        $parser = new YandexMapUrlParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Некорректная ссылка');

        $parser->extractOid('not-a-url');
    }

    public function test_it_rejects_non_yandex_url(): void
    {
        $parser = new YandexMapUrlParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Яндекс Карты');

        $parser->extractOid('https://google.com/maps/org/planeta/1010306836/reviews/');
    }

    public function test_it_rejects_yandex_maps_url_without_oid(): void
    {
        $parser = new YandexMapUrlParser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('не найден идентификатор');

        $parser->extractOid('https://yandex.ru/maps/22/kaliningrad/');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validYandexMapUrls(): array
    {
        return [
            'poi uri encoded query' => [
                'https://yandex.ru/maps/22/kaliningrad/?ll=20.510138%2C54.710161&mode=poi&poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D1036746148&tab=reviews&z=12',
                '1036746148',
            ],
            'org reviews url' => [
                'https://yandex.ru/maps/org/planeta/1010306836/reviews/?ll=20.507317%2C54.719540&z=16',
                '1010306836',
            ],
            'org url without reviews tab' => [
                'https://yandex.ru/maps/org/planeta/1010306836/',
                '1010306836',
            ],
            'yandex com mirror' => [
                'https://yandex.com/maps/org/planeta/1010306836/reviews/',
                '1010306836',
            ],
            'www yandex host' => [
                'https://www.yandex.ru/maps/org/planeta/1010306836/reviews/',
                '1010306836',
            ],
            'extra query params' => [
                'https://yandex.ru/maps/org/planeta/1010306836/?from=tabbar&ll=20.507317%2C54.719540&z=16',
                '1010306836',
            ],
        ];
    }
}

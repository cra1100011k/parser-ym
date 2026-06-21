<?php

namespace App\Services\YandexMaps;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YandexMapCardMetadataService
{
    public function __construct(
        private readonly YandexMapUrlParser $urlParser,
    ) {
    }

    /**
     * @return array{
     *     oid: string,
     *     ratingValue: float,
     *     ratingCount: int,
     *     reviewCount: int,
     *     requestId: string,
     *     sessionId: string,
     *     locale: string
     * }
     */
    public function fetch(string $url): array
    {
        $oid = $this->urlParser->extractOid($url);
        $html = $this->downloadHtml($url);

        return [
            'oid' => $oid,
            ...$this->extractRatingData($html),
            'requestId' => $this->extractRequestId($html, $oid),
            'sessionId' => $this->extractRequiredString($html, '/"sessionId":"([^"]+)"/', 'sessionId'),
            'locale' => $this->extractRequiredString($html, '/"locale":"([^"]+)"/', 'locale'),
        ];
    }

    private function downloadHtml(string $url): string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'ru,en;q=0.9',
            ])
                ->timeout(20)
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Не удалось подключиться к сервису Яндекс Карты.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Яндекс Карты вернули HTTP {$response->status()}.");
        }

        $html = $response->body();

        if ($html === '') {
            throw new RuntimeException('Яндекс Карты вернули пустой HTML.');
        }

        return $html;
    }

    /**
     * @return array{ratingValue: float, ratingCount: int, reviewCount: int}
     */
    private function extractRatingData(string $html): array
    {
        preg_match(
            '/"ratingData":\{"ratingCount":(\d+),"ratingValue":([0-9.]+),"reviewCount":(\d+)\}/',
            $html,
            $matches,
        );

        if ($matches !== []) {
            return [
                'ratingValue' => (float) $matches[2],
                'ratingCount' => (int) $matches[1],
                'reviewCount' => (int) $matches[3],
            ];
        }

        $ratingValue = $this->extractMetaContent($html, 'ratingValue');
        $ratingCount = $this->extractMetaContent($html, 'ratingCount');
        $reviewCount = $this->extractMetaContent($html, 'reviewCount');

        if ($ratingValue === null || $ratingCount === null || $reviewCount === null) {
            throw new RuntimeException('Не удалось найти рейтинг и счетчики организации в HTML сервиса Яндекс Карты.');
        }

        return [
            'ratingValue' => (float) $ratingValue,
            'ratingCount' => (int) $ratingCount,
            'reviewCount' => (int) $reviewCount,
        ];
    }

    private function extractRequestId(string $html, string $oid): string
    {
        $escapedOid = preg_quote($oid, '/');

        preg_match(
            '/"response":\{.*?"requestId":"([^"]+)".*?"id":"' . $escapedOid . '"/s',
            $html,
            $matches,
        );

        if ($matches !== []) {
            return $matches[1];
        }

        $oidMarker = '"id":"' . $oid . '"';
        $offset = 0;

        while (($idPosition = strpos($html, $oidMarker, $offset)) !== false) {
            $start = max(0, $idPosition - 6000);
            $fragment = substr($html, $start, $idPosition - $start);
            $offset = $idPosition + strlen($oidMarker);

            if (! str_contains($fragment, '"type":"business"')) {
                continue;
            }

            preg_match_all('/"requestId":"([^"]+)"/', $fragment, $requestIdMatches);

            if ($requestIdMatches[1] !== []) {
                return end($requestIdMatches[1]);
            }
        }

        throw new RuntimeException('Не удалось найти requestId организации в HTML сервиса Яндекс Карты.');
    }

    private function extractRequiredString(string $html, string $pattern, string $fieldName): string
    {
        preg_match($pattern, $html, $matches);

        if ($matches === []) {
            throw new RuntimeException("Не удалось найти {$fieldName} в HTML сервиса Яндекс Карты.");
        }

        return $matches[1];
    }

    private function extractMetaContent(string $html, string $itemProp): ?string
    {
        preg_match(
            '/<meta[^>]+itemProp="' . preg_quote($itemProp, '/') . '"[^>]+content="([^"]+)"/i',
            $html,
            $matches,
        );

        return $matches[1] ?? null;
    }
}

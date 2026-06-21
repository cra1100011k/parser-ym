<?php

namespace App\Services\YandexMaps;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class YandexMapReviewsService
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    public function __construct(
        private readonly YandexMapUrlParser $urlParser,
    ) {
    }

    /**
     * @return array{
     *     metadata: array{
     *         oid: string,
     *         name: string|null,
     *         ratingValue: float,
     *         ratingCount: int,
     *         reviewCount: int,
     *         requestId: string,
     *         sessionId: string,
     *         locale: string
     *     },
     *     params: array<string, mixed>,
     *     reviews: array<int, array{
     *         externalId: string,
     *         authorName: string,
     *         text: string,
     *         rating: int,
     *         reviewedAt: string|null,
     *         raw: array<string, mixed>
     *     }>
     * }
     */
    public function fetchPage(string $url, int $page = 1, int $pageSize = 50): array
    {
        $stage = 'validate_pagination';
        $oid = null;

        if ($page < 1) {
            throw new RuntimeException('Номер страницы отзывов должен быть больше 0.');
        }

        if ($pageSize < 1 || $pageSize > 50) {
            throw new RuntimeException('Размер страницы отзывов должен быть от 1 до 50.');
        }

        try {
            $cookieJar = new CookieJar();
            $stage = 'extract_oid';
            $oid = $this->urlParser->extractOid($url);

            $stage = 'download_card_html';
            [$html, $origin, $effectiveUrl] = $this->downloadCardHtml($url, $cookieJar);

            $stage = 'extract_metadata';
            $metadata = [
                'oid' => $oid,
                'name' => $this->extractOrganizationName($html, $oid),
                ...$this->extractRatingData($html),
                'requestId' => $this->extractRequestId($html, $oid),
                'sessionId' => $this->extractRequiredString($html, '/"sessionId":"([^"]+)"/', 'sessionId'),
                'locale' => $this->extractRequiredString($html, '/"locale":"([^"]+)"/', 'locale'),
            ];

            $stage = 'fetch_csrf_token';
            $csrfToken = $this->fetchCsrfToken($origin, $effectiveUrl, $cookieJar, $metadata, $page, $pageSize);

            $stage = 'fetch_reviews_page';
            $payload = $this->fetchReviewsPayload($origin, $effectiveUrl, $cookieJar, $metadata, $csrfToken, $page, $pageSize);

            return [
                'metadata' => $metadata,
                'params' => $payload['params'] ?? [],
                'reviews' => $this->normalizeReviews($payload['reviews'] ?? []),
            ];
        } catch (Throwable $exception) {
            $this->logParserFailure($stage, $url, $oid, $exception);

            throw $exception;
        }
    }

    /**
     * @return array{
     *     metadata: array{
     *         oid: string,
     *         name: string|null,
     *         ratingValue: float,
     *         ratingCount: int,
     *         reviewCount: int,
     *         requestId: string,
     *         sessionId: string,
     *         locale: string
     *     },
     *     pagesLoaded: int,
     *     reviewsLoaded: int,
     *     reviews: array<int, array{
     *         externalId: string,
     *         authorName: string,
     *         text: string,
     *         rating: int,
     *         reviewedAt: string|null,
     *         raw: array<string, mixed>
     *     }>
     * }
     */
    public function fetchLatestReviews(string $url, int $maxReviews = 600, int $pageSize = 50): array
    {
        $stage = 'validate_pagination';
        $oid = null;

        if ($maxReviews < 1) {
            throw new RuntimeException('Количество отзывов для загрузки должно быть больше 0.');
        }

        if ($pageSize < 1 || $pageSize > 50) {
            throw new RuntimeException('Размер страницы отзывов должен быть от 1 до 50.');
        }

        try {
            $maxReviews = min($maxReviews, 600);
            $maxPages = (int) ceil($maxReviews / $pageSize);

            $cookieJar = new CookieJar();
            $stage = 'extract_oid';
            $oid = $this->urlParser->extractOid($url);

            $stage = 'download_card_html';
            [$html, $origin, $effectiveUrl] = $this->downloadCardHtml($url, $cookieJar);

            $stage = 'extract_metadata';
            $metadata = [
                'oid' => $oid,
                'name' => $this->extractOrganizationName($html, $oid),
                ...$this->extractRatingData($html),
                'requestId' => $this->extractRequestId($html, $oid),
                'sessionId' => $this->extractRequiredString($html, '/"sessionId":"([^"]+)"/', 'sessionId'),
                'locale' => $this->extractRequiredString($html, '/"locale":"([^"]+)"/', 'locale'),
            ];

            $stage = 'fetch_csrf_token';
            $csrfToken = $this->fetchCsrfToken($origin, $effectiveUrl, $cookieJar, $metadata, 1, $pageSize);
            $reviews = [];
            $seenExternalIds = [];
            $pagesLoaded = 0;

            for ($page = 1; $page <= $maxPages; $page++) {
                $stage = "fetch_reviews_page_{$page}";
                $payload = $this->fetchReviewsPayload($origin, $effectiveUrl, $cookieJar, $metadata, $csrfToken, $page, $pageSize);
                $pageReviews = $this->normalizeReviews($payload['reviews'] ?? []);
                $pagesLoaded = $page;

                foreach ($pageReviews as $review) {
                    if ($review['externalId'] === '' || isset($seenExternalIds[$review['externalId']])) {
                        continue;
                    }

                    $seenExternalIds[$review['externalId']] = true;
                    $reviews[] = $review;

                    if (count($reviews) >= $maxReviews) {
                        break 2;
                    }
                }

                $totalPages = (int) ($payload['params']['totalPages'] ?? $maxPages);

                if ($page >= $totalPages || $pageReviews === []) {
                    break;
                }

                if ($page < $maxPages) {
                    usleep(random_int(500_000, 1_500_000));
                }
            }

            return [
                'metadata' => $metadata,
                'pagesLoaded' => $pagesLoaded,
                'reviewsLoaded' => count($reviews),
                'reviews' => $reviews,
            ];
        } catch (Throwable $exception) {
            $this->logParserFailure($stage, $url, $oid, $exception);

            throw $exception;
        }
    }

    /**
     * @return array{string, string, string}
     */
    private function downloadCardHtml(string $url, CookieJar $cookieJar): array
    {
        try {
            $response = Http::withHeaders($this->htmlHeaders())
                ->withOptions(['cookies' => $cookieJar])
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

        $effectiveUrl = $response->handlerStats()['url'] ?? $url;
        $origin = $this->originFromUrl($effectiveUrl);

        return [$html, $origin, $effectiveUrl];
    }

    private function fetchCsrfToken(
        string $origin,
        string $referer,
        CookieJar $cookieJar,
        array $metadata,
        int $page,
        int $pageSize,
    ): string {
        $query = $this->buildReviewsQuery($metadata, '', $page, $pageSize);
        $response = $this->getReviewsApi($origin, $referer, $cookieJar, $query);

        if (! isset($response['csrfToken']) || ! is_string($response['csrfToken'])) {
            throw new RuntimeException('Яндекс Карты не вернули csrfToken для запроса отзывов.');
        }

        return $response['csrfToken'];
    }

    private function fetchReviewsPayload(
        string $origin,
        string $referer,
        CookieJar $cookieJar,
        array $metadata,
        string $csrfToken,
        int $page,
        int $pageSize,
    ): array {
        $query = $this->buildReviewsQuery($metadata, $csrfToken, $page, $pageSize);
        $response = $this->getReviewsApi($origin, $referer, $cookieJar, $query);

        if (isset($response['error'])) {
            Log::warning('Яндекс Карты вернули ошибку при загрузке отзывов.', [
                'error' => $response['error'],
                'businessId' => $metadata['oid'],
                'page' => $page,
            ]);

            throw new RuntimeException('Яндекс Карты вернули ошибку при загрузке отзывов.');
        }

        if (! isset($response['data']) || ! is_array($response['data'])) {
            throw new RuntimeException('Яндекс Карты вернули ответ без списка отзывов.');
        }

        return $response['data'];
    }

    private function getReviewsApi(string $origin, string $referer, CookieJar $cookieJar, array $query): array
    {
        try {
            $response = Http::withHeaders($this->apiHeaders($referer))
                ->withOptions(['cookies' => $cookieJar])
                ->timeout(20)
                ->get("{$origin}/maps/api/business/fetchReviews", $this->signQuery($query));
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Не удалось подключиться к API отзывов сервиса Яндекс Карты.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("API отзывов сервиса Яндекс Карты вернул HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('API отзывов сервиса Яндекс Карты вернул некорректный ответ.');
        }

        return $json;
    }

    private function buildReviewsQuery(array $metadata, string $csrfToken, int $page, int $pageSize): array
    {
        return [
            'ajax' => '1',
            'businessId' => $metadata['oid'],
            'csrfToken' => $csrfToken,
            'host_config' => '',
            'host_exp' => '',
            'locale' => $metadata['locale'],
            'page' => (string) $page,
            'pageSize' => (string) $pageSize,
            'ranking' => 'by_relevance_org',
            'reqId' => $metadata['requestId'],
            'sessionId' => $metadata['sessionId'],
        ];
    }

    private function signQuery(array $query): array
    {
        uksort($query, fn (string $left, string $right): int => strcasecmp($left, $right));

        $query['s'] = (string) $this->djb2Hash(
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
        );

        return $query;
    }

    private function djb2Hash(string $value): int
    {
        $hash = 5381;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $hash = (($hash * 33) ^ ord($value[$index])) & 0xffffffff;
        }

        return $hash;
    }

    /**
     * @param array<int, mixed> $reviews
     * @return array<int, array{externalId: string, authorName: string, text: string, rating: int, reviewedAt: string|null, raw: array<string, mixed>}>
     */
    private function normalizeReviews(array $reviews): array
    {
        return array_values(array_map(function (array $review): array {
            return [
                'externalId' => (string) ($review['reviewId'] ?? ''),
                'authorName' => (string) ($review['author']['name'] ?? ''),
                'text' => (string) ($review['text'] ?? ''),
                'rating' => (int) ($review['rating'] ?? 0),
                'reviewedAt' => isset($review['updatedTime']) ? (string) $review['updatedTime'] : null,
                'raw' => $review,
            ];
        }, array_filter($reviews, 'is_array')));
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

    private function extractOrganizationName(string $html, string $oid): ?string
    {
        $nameFromHeading = $this->extractNameFromHeading($html);

        if ($nameFromHeading !== null) {
            return $nameFromHeading;
        }

        $oidMarker = '"id":"' . $oid . '"';
        $offset = 0;

        while (($idPosition = strpos($html, $oidMarker, $offset)) !== false) {
            $start = max(0, $idPosition - 6000);
            $fragment = substr($html, $start, $idPosition - $start);
            $offset = $idPosition + strlen($oidMarker);

            $businessPosition = strrpos($fragment, '"type":"business"');

            if ($businessPosition === false) {
                continue;
            }

            $businessFragment = substr($fragment, $businessPosition);

            preg_match('/"title":"((?:[^"\\\\]|\\\\.)*)"/', $businessFragment, $matches);

            if ($matches !== []) {
                return $this->decodeJsonString($matches[1]);
            }
        }

        return $this->extractNameFromOgTitle($html);
    }

    private function extractNameFromHeading(string $html): ?string
    {
        preg_match('/<h1[^>]+itemProp="name"[^>]*>(.*?)<\/h1>/is', $html, $matches);

        if ($matches === []) {
            return null;
        }

        $name = trim(strip_tags(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $name !== '' ? $name : null;
    }

    private function extractNameFromOgTitle(string $html): ?string
    {
        preg_match('/<meta[^>]+property="og:title"[^>]+content="([^"]+)"/i', $html, $matches);

        if ($matches === []) {
            return null;
        }

        $title = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/^Reviews of\s+/i', '', $title) ?? $title;
        $title = preg_replace('/\s+[—-]\s+Yandex\s+Maps$/iu', '', $title) ?? $title;

        return trim(explode(',', $title)[0]) ?: null;
    }

    private function decodeJsonString(string $value): string
    {
        $decoded = json_decode('"' . $value . '"', true);

        return is_string($decoded) ? $decoded : $value;
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

    private function logParserFailure(string $stage, string $url, ?string $oid, Throwable $exception): void
    {
        Log::warning('Парсер Яндекс Карты завершился с ошибкой.', [
            'stage' => $this->translateStage($stage),
            'oid' => $oid,
            'url' => $url,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    private function translateStage(string $stage): string
    {
        return match (true) {
            $stage === 'validate_pagination' => 'проверка параметров пагинации',
            $stage === 'extract_oid' => 'извлечение идентификатора организации',
            $stage === 'download_card_html' => 'загрузка HTML карточки',
            $stage === 'extract_metadata' => 'извлечение данных организации',
            $stage === 'fetch_csrf_token' => 'получение csrf-токена',
            $stage === 'fetch_reviews_page' => 'загрузка страницы отзывов',
            str_starts_with($stage, 'fetch_reviews_page_') => 'загрузка страницы отзывов',
            default => $stage,
        };
    }

    private function originFromUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Не удалось определить домен сервиса Яндекс Карты после редиректа.');
        }

        return "{$parts['scheme']}://{$parts['host']}";
    }

    /**
     * @return array<string, string>
     */
    private function htmlHeaders(): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'ru,en;q=0.9',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function apiHeaders(string $referer): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'ru,en;q=0.9',
            'Referer' => $referer,
            'X-Retpath-Y' => $referer,
        ];
    }
}

<?php

namespace App\Services\YandexMaps;

use InvalidArgumentException;

class YandexMapUrlParser
{
    public function extractOid(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new InvalidArgumentException('Ссылка на Яндекс Карты не может быть пустой.');
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Некорректная ссылка на Яндекс Карты.');
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('Не удалось разобрать ссылку на Яндекс Карты.');
        }

        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if (! $this->isYandexMapsUrl($host, $path)) {
            throw new InvalidArgumentException('Ссылка должна вести на Яндекс Карты.');
        }

        $oidFromQuery = $this->extractOidFromQuery($query);

        if ($oidFromQuery !== null) {
            return $oidFromQuery;
        }

        $oidFromPath = $this->extractOidFromPath($path);

        if ($oidFromPath !== null) {
            return $oidFromPath;
        }

        throw new InvalidArgumentException('В ссылке не найден идентификатор организации сервиса Яндекс Карты.');
    }

    private function isYandexMapsUrl(string $host, string $path): bool
    {
        $host = strtolower($host);

        $isYandexHost = (bool) preg_match('/(^|\.)yandex\.[a-z.]+$/', $host);
        $isMapsPath = str_starts_with($path, '/maps');

        return $isYandexHost && $isMapsPath;
    }

    private function extractOidFromQuery(string $query): ?string
    {
        if ($query === '') {
            return null;
        }

        parse_str($query, $queryParams);

        $poiUri = $queryParams['poi']['uri'] ?? null;

        if (! is_string($poiUri)) {
            return null;
        }

        if (preg_match('/[?&]oid=(\d+)/', $poiUri, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractOidFromPath(string $path): ?string
    {
        if (preg_match('#/org/[^/]+/(\d+)(?:/|$)#', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

<?php

namespace App\Services\YandexMaps;

use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class YandexOrganizationImportService
{
    public function __construct(
        private readonly YandexMapReviewsService $reviewsService,
        private readonly YandexMapUrlParser $urlParser,
    ) {
    }

    public function startImport(User $user, string $url): Organization
    {
        $oid = $this->urlParser->extractOid($url);

        return Organization::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'yandex_oid' => $oid,
            ],
            [
                'source_url' => $url,
                'parse_status' => 'queued',
                'parse_error' => null,
            ],
        );
    }

    public function import(User $user, string $url, int $maxReviews = 600): Organization
    {
        $oid = $this->urlParser->extractOid($url);

        $organization = Organization::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'yandex_oid' => $oid,
            ],
            [
                'source_url' => $url,
                'parse_status' => 'parsing',
                'parse_error' => null,
            ],
        );

        try {
            $result = $this->reviewsService->fetchLatestReviews($url, $maxReviews);
        } catch (Throwable $exception) {
            $organization->update([
                'parse_status' => 'failed',
                'parse_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $metadata = $result['metadata'];
        $missingReviews = $result['reviewsLoaded'] === 0 && $metadata['reviewCount'] > 0;

        if ($missingReviews) {
            $message = 'Яндекс Карты не вернули отзывы, хотя в карточке указано ненулевое количество отзывов. Старые данные сохранены.';

            $organization->update([
                'parse_status' => 'failed',
                'parse_error' => $message,
            ]);

            throw new RuntimeException($message);
        }

        return DB::transaction(function () use ($organization, $url, $metadata, $result): Organization {
            $organization->update([
                'source_url' => $url,
                'name' => $metadata['name'],
                'rating_value' => $metadata['ratingValue'],
                'rating_count' => $metadata['ratingCount'],
                'review_count' => $metadata['reviewCount'],
                'parsed_reviews_count' => $result['reviewsLoaded'],
                'parse_status' => 'done',
                'parse_error' => null,
                'parsed_at' => now(),
            ]);

            $externalIds = [];

            foreach ($result['reviews'] as $review) {
                $externalIds[] = $review['externalId'];

                Review::query()->updateOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'external_id' => $review['externalId'],
                    ],
                    [
                        'author_name' => $review['authorName'],
                        'text' => $review['text'],
                        'rating' => $review['rating'] ?: null,
                        'reviewed_at' => $this->parseReviewedAt($review['reviewedAt']),
                        'raw' => $review['raw'],
                    ],
                );
            }

            Review::query()
                ->where('organization_id', $organization->id)
                ->whereNotIn('external_id', $externalIds)
                ->delete();

            return $organization->refresh();
        });
    }

    public function markFailed(User $user, string $url, Throwable $exception): Organization
    {
        $oid = $this->urlParser->extractOid($url);

        return Organization::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'yandex_oid' => $oid,
            ],
            [
                'source_url' => $url,
                'parse_status' => 'failed',
                'parse_error' => $exception->getMessage(),
            ],
        );
    }

    private function parseReviewedAt(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}

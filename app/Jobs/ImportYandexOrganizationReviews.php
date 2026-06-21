<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\YandexMaps\YandexOrganizationImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportYandexOrganizationReviews implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(
        public readonly int $userId,
        public readonly string $url,
        public readonly int $maxReviews = 600,
    ) {
    }

    public function handle(YandexOrganizationImportService $importService): void
    {
        $user = User::query()->find($this->userId);

        if (! $user) {
            return;
        }

        $importService->import($user, $this->url, $this->maxReviews);
    }

    public function failed(?Throwable $exception): void
    {
        if (! $exception) {
            return;
        }

        $user = User::query()->find($this->userId);

        if (! $user) {
            return;
        }

        try {
            app(YandexOrganizationImportService::class)->markFailed($user, $this->url, $exception);
        } catch (Throwable $markFailedException) {
            report($markFailedException);
        }
    }
}

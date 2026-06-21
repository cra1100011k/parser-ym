<?php

namespace App\Http\Controllers;

use App\Jobs\ImportYandexOrganizationReviews;
use App\Models\Organization;
use App\Services\YandexMaps\YandexOrganizationImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly YandexOrganizationImportService $importService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $organization = $request->user()
            ->organizations()
            ->latest('updated_at')
            ->first();

        return response()->json([
            'organization' => $organization,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url', 'max:2000'],
        ], [
            'url.required' => 'Вставьте ссылку на карточку организации.',
            'url.url' => 'Введите корректную ссылку.',
            'url.max' => 'Ссылка слишком длинная.',
        ]);

        try {
            $organization = $this->importService->startImport($request->user(), $validated['url']);
            ImportYandexOrganizationReviews::dispatch($request->user()->id, $validated['url']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            throw ValidationException::withMessages([
                'url' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'url' => 'Не удалось загрузить данные организации с сервиса Яндекс Карты.',
            ]);
        }

        return response()->json([
            'organization' => $organization,
        ], 202);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->organizations()->delete();

        return response()->json([
            'message' => 'Данные очищены.',
        ]);
    }

    public function reviews(Request $request, Organization $organization): JsonResponse
    {
        if ($organization->user_id !== $request->user()->id) {
            abort(404);
        }

        $reviews = $organization->reviews()
            ->latest('reviewed_at')
            ->paginate(50);

        return response()->json([
            'organization' => $organization,
            'reviews' => $reviews,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\ImportYandexOrganizationReviews;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AuthAndOrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_organization_api(): void
    {
        $this->getJson('/api/organization')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Сессия истекла. Войдите снова.');
    }

    public function test_user_can_login_with_seed_credentials_format(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        $this->postJson('/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'test@example.com');
    }

    public function test_invalid_yandex_maps_link_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/organization', [
                'url' => 'https://google.com/maps/org/planeta/1010306836/reviews/',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_valid_link_creates_queued_import_and_dispatches_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $url = 'https://yandex.ru/maps/org/planeta/1010306836/reviews/';

        $this->actingAs($user)
            ->postJson('/api/organization', ['url' => $url])
            ->assertStatus(202)
            ->assertJsonPath('organization.yandex_oid', '1010306836')
            ->assertJsonPath('organization.parse_status', 'queued');

        $this->assertDatabaseHas('organizations', [
            'user_id' => $user->id,
            'yandex_oid' => '1010306836',
            'parse_status' => 'queued',
        ]);

        Bus::assertDispatched(
            ImportYandexOrganizationReviews::class,
            fn (ImportYandexOrganizationReviews $job): bool => $job->userId === $user->id && $job->url === $url,
        );
    }

    public function test_reviews_are_paginated_by_50_items(): void
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create([
            'user_id' => $user->id,
            'yandex_oid' => '1010306836',
            'source_url' => 'https://yandex.ru/maps/org/planeta/1010306836/reviews/',
            'name' => 'Планета',
            'rating_value' => 4.7,
            'rating_count' => 55,
            'review_count' => 55,
            'parsed_reviews_count' => 55,
            'parse_status' => 'done',
        ]);

        for ($index = 1; $index <= 55; $index++) {
            Review::query()->create([
                'organization_id' => $organization->id,
                'external_id' => "review-{$index}",
                'author_name' => "Автор {$index}",
                'text' => "Текст отзыва {$index}",
                'rating' => 5,
                'reviewed_at' => now()->subDays($index),
            ]);
        }

        $this->actingAs($user)
            ->getJson("/api/organizations/{$organization->id}/reviews?page=1")
            ->assertOk()
            ->assertJsonCount(50, 'reviews.data')
            ->assertJsonPath('reviews.per_page', 50)
            ->assertJsonPath('reviews.last_page', 2);
    }
}

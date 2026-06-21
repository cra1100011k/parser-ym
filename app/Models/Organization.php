<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'yandex_oid',
    'source_url',
    'name',
    'rating_value',
    'rating_count',
    'review_count',
    'parsed_reviews_count',
    'parse_status',
    'parse_error',
    'parsed_at',
])]
class Organization extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rating_value' => 'float',
            'rating_count' => 'integer',
            'review_count' => 'integer',
            'parsed_reviews_count' => 'integer',
            'parsed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}

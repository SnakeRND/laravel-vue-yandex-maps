<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationSnapshot extends Model
{
    protected $fillable = [
        'organization_id',
        'yandex_url',
        'yandex_id',
        'name',
        'average_rating',
        'ratings_count',
        'reviews_count',
        'stored_reviews_count',
    ];

    protected function casts(): array
    {
        return [
            'average_rating' => 'float',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'stored_reviews_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}

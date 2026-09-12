<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARSING = 'parsing';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'yandex_url',
        'yandex_id',
        'slug',
        'name',
        'average_rating',
        'ratings_count',
        'reviews_count',
        'review_cap',
        'parse_status',
        'parse_progress',
        'parse_message',
        'parse_error',
        'parsed_at',
    ];

    protected function casts(): array
    {
        return [
            'average_rating' => 'float',
            'ratings_count' => 'integer',
            'reviews_count' => 'integer',
            'review_cap' => 'integer',
            'parse_progress' => 'integer',
            'parsed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OrganizationSnapshot::class);
    }
}

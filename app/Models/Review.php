<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'organization_id',
        'organization_snapshot_id',
        'yandex_review_id',
        'author_name',
        'rating',
        'text',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrganizationSnapshot::class, 'organization_snapshot_id');
    }
}

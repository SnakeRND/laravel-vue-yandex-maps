<?php

namespace App\Jobs;

use App\Exceptions\YandexParseException;
use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use App\Models\Review;
use App\Services\Yandex\YandexMapsParser;
use App\Services\Yandex\YandexUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ParseOrganizationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public array $backoff = [30, 90, 180];

    public function __construct(
        public int $organizationId,
    ) {}

    public function handle(YandexMapsParser $parser): void
    {
        $organization = Organization::query()->find($this->organizationId);
        if (! $organization) {
            return;
        }

        $organization->update([
            'parse_status' => Organization::STATUS_PARSING,
            'parse_progress' => $this->attempts() > 1 ? max(1, (int) $organization->parse_progress) : 1,
            'parse_message' => $this->attempts() > 1
                ? 'Повторная попытка: снова обходим несколько выдач Яндекса'
                : 'Загрузка карточки организации…',
            'parse_error' => null,
        ]);

        try {
            $url = new YandexUrl(
                yandexId: $organization->yandex_id,
                slug: $organization->slug,
                originalUrl: $organization->yandex_url,
            );

            $data = $parser->fetchAll(
                $url,
                function (int $progress, string $message) use ($organization) {
                    $current = (int) ($organization->fresh()?->parse_progress ?? 0);
                    $organization->update([
                        'parse_progress' => max($current, min(99, max(1, $progress))),
                        'parse_message' => $message,
                    ]);
                },
                $organization->review_cap,
            );
        } catch (YandexParseException $e) {
            if (in_array($e->errorCode, [
                YandexParseException::MARKUP_CHANGED,
                YandexParseException::NOT_FOUND,
                YandexParseException::INVALID_URL,
                YandexParseException::EMPTY,
            ], true)) {
                $this->fail($e);

                return;
            }

            throw $e;
        }

        DB::transaction(function () use ($organization, $data) {
            $organization->update([
                'name' => $data['name'] ?: $organization->name,
                'average_rating' => $data['average_rating'],
                'ratings_count' => $data['ratings_count'],
                'reviews_count' => $data['reviews_count'],
                'parse_status' => Organization::STATUS_READY,
                'parse_progress' => 100,
                'parse_message' => null,
                'parse_error' => null,
                'parsed_at' => now(),
            ]);

            $snapshot = OrganizationSnapshot::query()->create([
                'organization_id' => $organization->id,
                'yandex_url' => $organization->yandex_url,
                'yandex_id' => $organization->yandex_id,
                'name' => $organization->name,
                'average_rating' => $organization->average_rating,
                'ratings_count' => $organization->ratings_count,
                'reviews_count' => $organization->reviews_count,
                'stored_reviews_count' => count($data['reviews']),
            ]);

            foreach ($data['reviews'] as $review) {
                Review::query()->create([
                    'organization_id' => $organization->id,
                    'organization_snapshot_id' => $snapshot->id,
                    'yandex_review_id' => $review['yandex_review_id'],
                    'author_name' => $review['author_name'],
                    'rating' => $review['rating'],
                    'text' => $review['text'],
                    'reviewed_at' => $review['reviewed_at'],
                ]);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $organization = Organization::query()->find($this->organizationId);
        if (! $organization) {
            return;
        }

        $message = 'Не удалось спарсить организацию.';
        $code = 'failed';

        if ($exception instanceof YandexParseException) {
            $message = $exception->getMessage();
            $code = $exception->errorCode;
        } elseif ($exception) {
            $message = 'Внутренняя ошибка парсера: '.$exception->getMessage();
        }

        Log::error('ParseOrganizationJob failed', [
            'organization_id' => $this->organizationId,
            'code' => $code,
            'error' => $exception?->getMessage(),
        ]);

        $organization->update([
            'parse_status' => Organization::STATUS_FAILED,
            'parse_error' => $message,
            'parse_message' => null,
            'parse_progress' => 0,
        ]);
    }
}

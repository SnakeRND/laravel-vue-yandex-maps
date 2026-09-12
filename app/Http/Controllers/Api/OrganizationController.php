<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\YandexParseException;
use App\Http\Controllers\Controller;
use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use App\Models\Review;
use App\Services\Yandex\YandexUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        return response()->json([
            'organization' => $organization ? $this->serializeOrganization($organization) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'yandex_url' => ['required', 'string', 'max:2000'],
            'review_cap' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        try {
            $parsed = YandexUrl::parse($data['yandex_url']);
        } catch (YandexParseException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['yandex_url' => [$e->getMessage()]],
            ], 422);
        }

        $organization = Organization::query()->updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'yandex_url' => $data['yandex_url'],
                'yandex_id' => $parsed->yandexId,
                'slug' => $parsed->slug,
                'review_cap' => array_key_exists('review_cap', $data) ? $data['review_cap'] : null,
                'parse_status' => Organization::STATUS_PENDING,
                'parse_progress' => 0,
                'parse_message' => null,
                'parse_error' => null,
            ],
        );

        ParseOrganizationJob::dispatch($organization->id);

        return response()->json([
            'organization' => $this->serializeOrganization($organization->fresh()),
            'message' => 'Ссылка сохранена, парсинг запущен.',
        ], 202);
    }

    public function reparse(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        if (! $organization) {
            return response()->json(['message' => 'Организация ещё не подключена.'], 404);
        }

        if ($organization->parse_status === Organization::STATUS_PARSING) {
            return response()->json([
                'organization' => $this->serializeOrganization($organization),
                'message' => 'Парсинг уже выполняется.',
            ]);
        }

        $organization->update([
            'parse_status' => Organization::STATUS_PENDING,
            'parse_progress' => 0,
            'parse_message' => null,
            'parse_error' => null,
        ]);

        ParseOrganizationJob::dispatch($organization->id);

        return response()->json([
            'organization' => $this->serializeOrganization($organization->fresh()),
            'message' => 'Повторный парсинг запущен.',
        ], 202);
    }

    public function snapshots(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        if (! $organization) {
            return response()->json([
                'snapshots' => $this->emptyPage(8),
            ]);
        }

        $snapshots = $organization->snapshots()
            ->latest('id')
            ->paginate(8);

        return response()->json([
            'snapshots' => $snapshots->through(
                fn (OrganizationSnapshot $snapshot) => $this->serializeSnapshot($snapshot)
            ),
        ]);
    }

    public function showSnapshot(Request $request, OrganizationSnapshot $snapshot): JsonResponse
    {
        $this->assertSnapshotOwned($request, $snapshot);

        return response()->json([
            'snapshot' => $this->serializeSnapshot($snapshot),
            'organization' => $this->serializeOrganization($snapshot->organization),
        ]);
    }

    public function snapshotReviews(Request $request, OrganizationSnapshot $snapshot): JsonResponse
    {
        $this->assertSnapshotOwned($request, $snapshot);

        return response()->json([
            'snapshot' => $this->serializeSnapshot($snapshot),
            'reviews' => $this->paginateReviews($snapshot),
        ]);
    }

    public function reviews(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        if (! $organization) {
            return response()->json(['message' => 'Организация ещё не подключена.'], 404);
        }

        $latestSnapshot = $organization->snapshots()->latest('id')->first();

        if (! $latestSnapshot) {
            return response()->json([
                'organization' => $this->serializeOrganization($organization),
                'snapshot' => null,
                'reviews' => $this->emptyPage(50),
            ]);
        }

        return response()->json([
            'organization' => $this->serializeOrganization($organization),
            'snapshot' => $this->serializeSnapshot($latestSnapshot),
            'reviews' => $this->paginateReviews($latestSnapshot),
        ]);
    }

    private function currentOrganization(Request $request): ?Organization
    {
        return Organization::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();
    }

    private function assertSnapshotOwned(Request $request, OrganizationSnapshot $snapshot): void
    {
        $snapshot->loadMissing('organization');

        if (! $snapshot->organization || $snapshot->organization->user_id !== $request->user()->id) {
            abort(404);
        }
    }

    private function paginateReviews(OrganizationSnapshot $snapshot)
    {
        return $snapshot->reviews()
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->through(fn (Review $review) => $this->serializeReview($review));
    }

    private function emptyPage(int $perPage): array
    {
        return [
            'data' => [],
            'current_page' => 1,
            'last_page' => 1,
            'total' => 0,
            'per_page' => $perPage,
        ];
    }

    private function serializeOrganization(Organization $organization): array
    {
        $latestSnapshot = $organization->snapshots()->latest('id')->first();

        return [
            'id' => $organization->id,
            'yandex_url' => $organization->yandex_url,
            'yandex_id' => $organization->yandex_id,
            'slug' => $organization->slug,
            'name' => $organization->name,
            'average_rating' => $organization->average_rating,
            'ratings_count' => $organization->ratings_count,
            'reviews_count' => $organization->reviews_count,
            'review_cap' => $organization->review_cap,
            'stored_reviews_count' => $latestSnapshot?->stored_reviews_count ?? 0,
            'latest_snapshot_id' => $latestSnapshot?->id,
            'parse_status' => $organization->parse_status,
            'parse_progress' => $organization->parse_progress,
            'parse_message' => $organization->parse_message,
            'parse_error' => $organization->parse_error,
            'parsed_at' => optional($organization->parsed_at)?->toIso8601String(),
        ];
    }

    private function serializeSnapshot(OrganizationSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'organization_id' => $snapshot->organization_id,
            'yandex_url' => $snapshot->yandex_url,
            'yandex_id' => $snapshot->yandex_id,
            'name' => $snapshot->name,
            'average_rating' => $snapshot->average_rating,
            'ratings_count' => $snapshot->ratings_count,
            'reviews_count' => $snapshot->reviews_count,
            'stored_reviews_count' => $snapshot->stored_reviews_count,
            'created_at' => optional($snapshot->created_at)?->toIso8601String(),
        ];
    }

    private function serializeReview(Review $review): array
    {
        return [
            'id' => $review->id,
            'author_name' => $review->author_name,
            'rating' => $review->rating,
            'text' => $review->text,
            'reviewed_at' => optional($review->reviewed_at)?->toIso8601String(),
        ];
    }
}

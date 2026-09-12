<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\YandexParseException;
use App\Http\Controllers\Controller;
use App\Jobs\ParseOrganizationJob;
use App\Models\Organization;
use App\Services\Yandex\YandexUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        if (! $organization) {
            return response()->json(['organization' => null]);
        }

        return response()->json([
            'organization' => $this->serialize($organization),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'yandex_url' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $parsed = YandexUrl::parse($data['yandex_url']);
        } catch (YandexParseException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['yandex_url' => [$e->getMessage()]],
            ], 422);
        }

        $user = $request->user();

        // One organization per user for this prototype — replace and re-parse.
        $organization = Organization::query()
            ->where('user_id', $user->id)
            ->first();

        if ($organization) {
            $organization->reviews()->delete();
            $organization->update([
                'yandex_url' => $data['yandex_url'],
                'yandex_id' => $parsed->yandexId,
                'slug' => $parsed->slug,
                'name' => null,
                'average_rating' => null,
                'ratings_count' => null,
                'reviews_count' => null,
                'parse_status' => Organization::STATUS_PENDING,
                'parse_progress' => 0,
                'parse_error' => null,
                'parsed_at' => null,
            ]);
        } else {
            $organization = Organization::query()->create([
                'user_id' => $user->id,
                'yandex_url' => $data['yandex_url'],
                'yandex_id' => $parsed->yandexId,
                'slug' => $parsed->slug,
                'parse_status' => Organization::STATUS_PENDING,
            ]);
        }

        ParseOrganizationJob::dispatch($organization->id);

        return response()->json([
            'organization' => $this->serialize($organization->fresh()),
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
                'organization' => $this->serialize($organization),
                'message' => 'Парсинг уже выполняется.',
            ]);
        }

        $organization->update([
            'parse_status' => Organization::STATUS_PENDING,
            'parse_progress' => 0,
            'parse_error' => null,
        ]);

        ParseOrganizationJob::dispatch($organization->id);

        return response()->json([
            'organization' => $this->serialize($organization->fresh()),
            'message' => 'Повторный парсинг запущен.',
        ], 202);
    }

    public function reviews(Request $request): JsonResponse
    {
        $organization = $this->currentOrganization($request);

        if (! $organization) {
            return response()->json(['message' => 'Организация ещё не подключена.'], 404);
        }

        $perPage = 50;
        $reviews = $organization->reviews()
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'organization' => $this->serialize($organization),
            'reviews' => $reviews->through(fn ($review) => [
                'id' => $review->id,
                'author_name' => $review->author_name,
                'rating' => $review->rating,
                'text' => $review->text,
                'reviewed_at' => optional($review->reviewed_at)?->toIso8601String(),
            ]),
        ]);
    }

    private function currentOrganization(Request $request): ?Organization
    {
        return Organization::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();
    }

    private function serialize(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'yandex_url' => $organization->yandex_url,
            'yandex_id' => $organization->yandex_id,
            'slug' => $organization->slug,
            'name' => $organization->name,
            'average_rating' => $organization->average_rating,
            'ratings_count' => $organization->ratings_count,
            'reviews_count' => $organization->reviews_count,
            'stored_reviews_count' => $organization->reviews()->count(),
            'parse_status' => $organization->parse_status,
            'parse_progress' => $organization->parse_progress,
            'parse_error' => $organization->parse_error,
            'parsed_at' => optional($organization->parsed_at)?->toIso8601String(),
        ];
    }
}

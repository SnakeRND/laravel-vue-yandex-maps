<?php

use App\Models\Organization;
use App\Models\OrganizationSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_snapshots', function (Blueprint $table) {
            $table->string('yandex_url')->nullable()->after('organization_id');
            $table->string('yandex_id')->nullable()->after('yandex_url');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('organization_snapshot_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('organization_snapshots')
                ->cascadeOnDelete();
        });

        $orgIds = DB::table('reviews')
            ->whereNull('organization_snapshot_id')
            ->distinct()
            ->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            $org = Organization::query()->find($orgId);
            if (! $org) {
                continue;
            }

            $snapshotId = OrganizationSnapshot::query()->create([
                'organization_id' => $org->id,
                'yandex_url' => $org->yandex_url,
                'yandex_id' => $org->yandex_id,
                'name' => $org->name,
                'average_rating' => $org->average_rating,
                'ratings_count' => $org->ratings_count,
                'reviews_count' => $org->reviews_count,
                'stored_reviews_count' => $org->reviews()->count(),
                'created_at' => $org->parsed_at ?? $org->updated_at,
                'updated_at' => $org->parsed_at ?? $org->updated_at,
            ])->id;

            DB::table('reviews')
                ->where('organization_id', $orgId)
                ->whereNull('organization_snapshot_id')
                ->update(['organization_snapshot_id' => $snapshotId]);
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'yandex_review_id']);
            $table->unique(['organization_snapshot_id', 'yandex_review_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['organization_snapshot_id', 'yandex_review_id']);
            $table->unique(['organization_id', 'yandex_review_id']);
            $table->dropConstrainedForeignId('organization_snapshot_id');
        });

        Schema::table('organization_snapshots', function (Blueprint $table) {
            $table->dropColumn(['yandex_url', 'yandex_id']);
        });
    }
};

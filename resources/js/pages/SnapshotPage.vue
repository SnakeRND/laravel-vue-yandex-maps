<script setup>
import { onMounted, ref } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { fetchSnapshot, fetchSnapshotReviews } from '../api';

const route = useRoute();

const snapshot = ref(null);
const reviews = ref([]);
const meta = ref(null);
const page = ref(1);
const loading = ref(true);
const loadingReviews = ref(false);
const error = ref('');

async function loadSnapshot() {
    const data = await fetchSnapshot(route.params.id);
    snapshot.value = data.snapshot;
}

async function loadReviews(targetPage = 1) {
    loadingReviews.value = true;
    try {
        const data = await fetchSnapshotReviews(route.params.id, targetPage);
        snapshot.value = data.snapshot;
        reviews.value = data.reviews.data;
        meta.value = {
            current_page: data.reviews.current_page,
            last_page: data.reviews.last_page,
            total: data.reviews.total,
            per_page: data.reviews.per_page,
        };
        page.value = data.reviews.current_page;
    } finally {
        loadingReviews.value = false;
    }
}

async function bootstrap() {
    loading.value = true;
    error.value = '';
    try {
        await loadSnapshot();
        await loadReviews(1);
    } catch (e) {
        error.value = e.response?.data?.message || 'Не удалось загрузить снимок парсинга.';
    } finally {
        loading.value = false;
    }
}

async function goToPage(next) {
    if (!meta.value || next < 1 || next > meta.value.last_page || next === page.value) {
        return;
    }
    error.value = '';
    try {
        await loadReviews(next);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) {
        error.value = e.response?.data?.message || 'Не удалось загрузить страницу отзывов.';
    }
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('ru-RU', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

onMounted(bootstrap);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <RouterLink to="/" class="text-sm text-sky-700 hover:underline">← На главную</RouterLink>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight">Снимок парсинга</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Отзывы, сохранённые в этот прогон.
                </p>
            </div>
        </div>

        <div v-if="loading" class="text-sm text-slate-500">Загрузка…</div>

        <template v-else-if="snapshot">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-semibold">{{ snapshot.name || 'Без названия' }}</h2>
                        <div class="mt-1 text-sm text-slate-500">
                            {{ formatDate(snapshot.created_at) }}
                        </div>
                        <a
                            v-if="snapshot.yandex_url"
                            :href="snapshot.yandex_url"
                            target="_blank"
                            rel="noopener"
                            class="mt-1 block text-sm text-sky-700 hover:underline"
                        >
                            {{ snapshot.yandex_url }}
                        </a>
                    </div>
                    <div class="text-right">
                        <div class="text-3xl font-semibold tabular-nums">
                            {{ snapshot.average_rating ?? '—' }}
                        </div>
                        <div class="text-xs uppercase tracking-wide text-slate-400">средний рейтинг</div>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-slate-50 px-4 py-3">
                        <div class="text-xs text-slate-400">Оценок</div>
                        <div class="text-lg font-semibold tabular-nums">
                            {{ snapshot.ratings_count ?? '—' }}
                        </div>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-4 py-3">
                        <div class="text-xs text-slate-400">Отзывов (у Яндекса)</div>
                        <div class="text-lg font-semibold tabular-nums">
                            {{ snapshot.reviews_count ?? '—' }}
                        </div>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-4 py-3">
                        <div class="text-xs text-slate-400">Сохранено в снимке</div>
                        <div class="text-lg font-semibold tabular-nums">
                            {{ snapshot.stored_reviews_count ?? 0 }}
                        </div>
                    </div>
                </div>
            </section>

            <section class="space-y-3">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium">Отзывы</h3>
                    <span v-if="meta" class="text-sm text-slate-500">
                        стр. {{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }} шт.
                    </span>
                </div>

                <div v-if="loadingReviews" class="text-sm text-slate-500">Загрузка отзывов…</div>

                <div
                    v-else-if="reviews.length === 0"
                    class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500"
                >
                    В этом снимке нет отзывов.
                </div>

                <article
                    v-for="review in reviews"
                    :key="review.id"
                    class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="font-medium text-slate-900">
                            {{ review.author_name || 'Аноним' }}
                        </div>
                        <div class="text-sm text-amber-600">
                            {{ '★'.repeat(review.rating || 0) }}{{ '☆'.repeat(5 - (review.rating || 0)) }}
                            <span class="ml-1 text-slate-500">{{ review.rating || '—' }}</span>
                        </div>
                    </div>
                    <div class="mt-1 text-xs text-slate-400">{{ formatDate(review.reviewed_at) }}</div>
                    <p class="mt-3 whitespace-pre-wrap text-sm leading-relaxed text-slate-700">
                        {{ review.text || 'Без текста' }}
                    </p>
                </article>

                <div v-if="meta && meta.last_page > 1" class="flex items-center justify-center gap-2 pt-2">
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40"
                        :disabled="page <= 1 || loadingReviews"
                        @click="goToPage(page - 1)"
                    >
                        Назад
                    </button>
                    <span class="text-sm text-slate-500">{{ page }} / {{ meta.last_page }}</span>
                    <button
                        type="button"
                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm disabled:opacity-40"
                        :disabled="page >= meta.last_page || loadingReviews"
                        @click="goToPage(page + 1)"
                    >
                        Вперёд
                    </button>
                </div>
            </section>
        </template>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    </div>
</template>

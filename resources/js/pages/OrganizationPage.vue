<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { fetchOrganization, fetchReviews, fetchSnapshots } from '../api';

const organization = ref(null);
const snapshots = ref([]);
const snapshotsMeta = ref(null);
const snapshotsPage = ref(1);
const loadingSnapshots = ref(false);
const reviews = ref([]);
const meta = ref(null);
const page = ref(1);
const loading = ref(true);
const loadingReviews = ref(false);
const error = ref('');
let pollTimer = null;

const isParsing = computed(() =>
    ['pending', 'parsing'].includes(organization.value?.parse_status),
);

const latestSnapshotId = computed(() => organization.value?.latest_snapshot_id ?? null);

const yandexPublicCapHit = computed(() => {
    const claimed = organization.value?.reviews_count;
    const stored = organization.value?.stored_reviews_count;
    return typeof claimed === 'number'
        && typeof stored === 'number'
        && claimed > stored
        && stored > 0;
});

async function loadOrganization() {
    const data = await fetchOrganization();
    organization.value = data.organization;
}

async function loadSnapshots(targetPage = 1) {
    loadingSnapshots.value = true;
    try {
        const data = await fetchSnapshots(targetPage);
        snapshots.value = data.snapshots.data;
        snapshotsMeta.value = {
            current_page: data.snapshots.current_page,
            last_page: data.snapshots.last_page,
            total: data.snapshots.total,
            per_page: data.snapshots.per_page,
        };
        snapshotsPage.value = data.snapshots.current_page;
    } finally {
        loadingSnapshots.value = false;
    }
}

async function loadReviews(targetPage = 1) {
    loadingReviews.value = true;
    try {
        const data = await fetchReviews(targetPage);
        organization.value = data.organization;
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
        await loadOrganization();
        if (!organization.value) {
            return;
        }
        await loadSnapshots(1);
        if (organization.value.parse_status === 'ready' || organization.value.stored_reviews_count > 0) {
            await loadReviews(1);
        }
        maybeStartPolling();
    } catch (e) {
        error.value = e.response?.data?.message || 'Не удалось загрузить данные.';
    } finally {
        loading.value = false;
    }
}

function maybeStartPolling() {
    stopPolling();
    if (!isParsing.value) {
        return;
    }
    pollTimer = setInterval(async () => {
        try {
            const prevStatus = organization.value?.parse_status;
            await loadOrganization();
            if (organization.value?.parse_status === 'ready') {
                stopPolling();
                await Promise.all([loadReviews(1), loadSnapshots(1)]);
            }
            if (organization.value?.parse_status === 'failed') {
                stopPolling();
            }
            if (prevStatus !== 'ready' && organization.value?.parse_status === 'ready') {
                await loadOrganization();
            }
        } catch {
        }
    }, 2000);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
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

async function goToSnapshotsPage(next) {
    if (
        !snapshotsMeta.value
        || next < 1
        || next > snapshotsMeta.value.last_page
        || next === snapshotsPage.value
        || loadingSnapshots.value
    ) {
        return;
    }
    error.value = '';
    try {
        await loadSnapshots(next);
    } catch (e) {
        error.value = e.response?.data?.message || 'Не удалось загрузить историю парсингов.';
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

watch(isParsing, (value) => {
    if (value) {
        maybeStartPolling();
    }
});

onMounted(bootstrap);
onUnmounted(stopPolling);
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">Парсер отзывов</h1>
            </div>
            <RouterLink
                to="/settings"
                class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-white"
            >
                Настройки
            </RouterLink>
        </div>

        <div v-if="loading" class="text-sm text-slate-500">Загрузка…</div>

        <div
            v-else-if="!organization"
            class="rounded-2xl border border-dashed border-slate-300 bg-white/70 p-8 text-center"
        >
            <p class="text-slate-600">Организация ещё не подключена.</p>
            <RouterLink to="/settings" class="mt-3 inline-block text-sm font-medium text-sky-700 hover:underline">
                Добавить ссылку на Яндекс.Карты
            </RouterLink>
        </div>

        <div v-else class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="min-w-0 space-y-6">
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold">
                                {{ organization.name || 'Без названия' }}
                            </h2>
                            <a
                                :href="organization.yandex_url"
                                target="_blank"
                                rel="noopener"
                                class="mt-1 block break-all text-sm text-sky-700 hover:underline"
                            >
                                {{ organization.yandex_url }}
                            </a>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-semibold tabular-nums">
                                {{ organization.average_rating ?? '—' }}
                            </div>
                            <div class="text-xs uppercase tracking-wide text-slate-400">средний рейтинг</div>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl bg-slate-50 px-4 py-3">
                            <div class="text-xs text-slate-400">Оценок</div>
                            <div class="text-lg font-semibold tabular-nums">
                                {{ organization.ratings_count ?? '—' }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-4 py-3">
                            <div class="text-xs text-slate-400">Отзывов (у Яндекса)</div>
                            <div class="text-lg font-semibold tabular-nums">
                                {{ organization.reviews_count ?? '—' }}
                            </div>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-4 py-3">
                            <div class="text-xs text-slate-400">В последнем парсинге</div>
                            <div class="text-lg font-semibold tabular-nums">
                                {{ organization.stored_reviews_count ?? 0 }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="isParsing"
                        class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-sm text-sky-900"
                    >
                        Идёт парсинг… {{ organization.parse_progress }}%
                        <p v-if="organization.parse_message" class="mt-1 text-sm font-normal text-sky-800/80">
                            {{ organization.parse_message }}
                        </p>
                    </div>
                    <div
                        v-else-if="organization.parse_status === 'failed'"
                        class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                    >
                        {{ organization.parse_error || 'Парсинг завершился с ошибкой.' }}
                        <RouterLink to="/settings" class="ml-1 underline">Повторить в настройках</RouterLink>
                    </div>
                    <div
                        v-else-if="yandexPublicCapHit"
                        class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                    >
                        Получено {{ organization.stored_reviews_count }} из {{ organization.reviews_count }} отзывов.
                        Часть отзывов Яндекс не отдаёт публично даже через аспекты и фильтры.
                    </div>
                </section>

                <section class="space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">Отзывы последнего парсинга</h3>
                        <span v-if="meta" class="text-sm text-slate-500">
                            стр. {{ meta.current_page }} / {{ meta.last_page }} · {{ meta.total }} шт.
                        </span>
                    </div>

                    <div v-if="loadingReviews" class="text-sm text-slate-500">Загрузка отзывов…</div>

                    <div
                        v-else-if="reviews.length === 0"
                        class="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500"
                    >
                        {{ isParsing ? 'Ждём первые отзывы…' : 'Отзывов пока нет.' }}
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
            </div>

            <aside class="lg:sticky lg:top-6 lg:self-start">
                <section
                    class="flex max-h-[22rem] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm lg:h-[42rem] lg:max-h-[42rem]"
                >
                    <div class="mb-3 shrink-0">
                        <h3 class="text-lg font-medium">История парсингов</h3>
                    </div>

                    <div
                        v-if="loadingSnapshots && snapshots.length === 0"
                        class="min-h-0 flex-1 rounded-lg bg-slate-50 px-3 py-4 text-sm text-slate-500"
                    >
                        Загрузка…
                    </div>

                    <div
                        v-else-if="snapshots.length === 0"
                        class="min-h-0 flex-1 rounded-lg bg-slate-50 px-3 py-4 text-sm text-slate-500"
                    >
                        {{ isParsing ? 'После завершения здесь появится запись.' : 'Пока нет сохранённых парсингов.' }}
                    </div>

                    <ul
                        v-else
                        class="min-h-0 flex-1 space-y-2 overflow-y-auto overscroll-contain pr-1"
                        :class="{ 'opacity-60': loadingSnapshots }"
                    >
                        <li v-for="item in snapshots" :key="item.id">
                            <RouterLink
                                :to="{ name: 'snapshot', params: { id: item.id } }"
                                class="block rounded-xl border border-slate-200 px-3 py-3 transition hover:border-sky-300 hover:bg-sky-50/50"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="truncate font-medium text-slate-900">
                                            {{ item.name || '—' }}
                                        </div>
                                        <div class="mt-0.5 text-xs text-slate-500">
                                            {{ formatDate(item.created_at) }}
                                        </div>
                                    </div>
                                    <span
                                        v-if="item.id === latestSnapshotId"
                                        class="shrink-0 rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-700"
                                    >
                                        текущий
                                    </span>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
                                    <span>★ {{ item.average_rating ?? '—' }}</span>
                                    <span>{{ item.stored_reviews_count }} отзывов</span>
                                    <span v-if="item.yandex_id" class="text-slate-400">ID {{ item.yandex_id }}</span>
                                </div>
                            </RouterLink>
                        </li>
                    </ul>

                    <div
                        v-if="snapshotsMeta && snapshotsMeta.total > 0"
                        class="mt-3 flex shrink-0 items-center justify-between gap-2 border-t border-slate-100 pt-3"
                    >
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-2.5 py-1 text-xs disabled:opacity-40"
                            :disabled="snapshotsPage <= 1 || loadingSnapshots"
                            @click="goToSnapshotsPage(snapshotsPage - 1)"
                        >
                            Назад
                        </button>
                        <span class="text-xs text-slate-500">
                            {{ snapshotsPage }} / {{ snapshotsMeta.last_page }}
                        </span>
                        <button
                            type="button"
                            class="rounded-md border border-slate-300 px-2.5 py-1 text-xs disabled:opacity-40"
                            :disabled="snapshotsPage >= snapshotsMeta.last_page || loadingSnapshots"
                            @click="goToSnapshotsPage(snapshotsPage + 1)"
                        >
                            Вперёд
                        </button>
                    </div>
                </section>
            </aside>
        </div>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    </div>
</template>

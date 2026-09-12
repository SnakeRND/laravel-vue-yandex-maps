<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { fetchOrganization, fetchReviews } from '../api';

const organization = ref(null);
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

async function loadOrganization() {
    organization.value = await fetchOrganization();
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
            await loadOrganization();
            if (organization.value?.parse_status === 'ready') {
                stopPolling();
                await loadReviews(1);
            }
            if (organization.value?.parse_status === 'failed') {
                stopPolling();
            }
        } catch {
            // ignore
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
                <h1 class="text-2xl font-semibold tracking-tight">Организация</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Рейтинг, счётчики и отзывы из кэша после парсинга.
                </p>
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

        <template v-else>
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
                            class="mt-1 block text-sm text-sky-700 hover:underline"
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
                        <div class="text-xs text-slate-400">Сохранено у нас</div>
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
                </div>
                <div
                    v-else-if="organization.parse_status === 'failed'"
                    class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                >
                    {{ organization.parse_error || 'Парсинг завершился с ошибкой.' }}
                    <RouterLink to="/settings" class="ml-1 underline">Повторить в настройках</RouterLink>
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
        </template>

        <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    </div>
</template>

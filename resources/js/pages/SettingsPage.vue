<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchOrganization, reparseOrganization, saveOrganization } from '../api';

const router = useRouter();

const yandexUrl = ref('');
const reviewCap = ref('');
const organization = ref(null);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const message = ref('');
let pollTimer = null;

function parsedReviewCap() {
    const raw = String(reviewCap.value ?? '').trim();
    if (raw === '') {
        return null;
    }
    const n = Number.parseInt(raw, 10);
    return Number.isFinite(n) && n > 0 ? n : null;
}

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const data = await fetchOrganization();
        organization.value = data.organization;
        if (organization.value?.yandex_url) {
            yandexUrl.value = organization.value.yandex_url;
        }
        reviewCap.value = organization.value?.review_cap
            ? String(organization.value.review_cap)
            : '';
        maybeStartPolling();
    } catch (e) {
        error.value = e.response?.data?.message || 'Не удалось загрузить настройки.';
    } finally {
        loading.value = false;
    }
}

function maybeStartPolling() {
    stopPolling();
    const status = organization.value?.parse_status;
    if (status === 'pending' || status === 'parsing') {
        pollTimer = setInterval(async () => {
            try {
                const data = await fetchOrganization();
                organization.value = data.organization;
                if (!['pending', 'parsing'].includes(organization.value?.parse_status)) {
                    stopPolling();
                }
            } catch {
            }
        }, 2000);
    }
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

async function onSave() {
    saving.value = true;
    error.value = '';
    message.value = '';

    try {
        const data = await saveOrganization(yandexUrl.value.trim(), parsedReviewCap());
        organization.value = data.organization;
        reviewCap.value = organization.value?.review_cap
            ? String(organization.value.review_cap)
            : '';
        message.value = data.message || 'Сохранено.';
        maybeStartPolling();
    } catch (e) {
        error.value = e.response?.data?.errors?.yandex_url?.[0]
            || e.response?.data?.errors?.review_cap?.[0]
            || e.response?.data?.message
            || 'Не удалось сохранить ссылку.';
    } finally {
        saving.value = false;
    }
}

async function onReparse() {
    saving.value = true;
    error.value = '';
    message.value = '';
    try {
        const data = await reparseOrganization();
        organization.value = data.organization;
        message.value = data.message || 'Парсинг запущен.';
        maybeStartPolling();
    } catch (e) {
        error.value = e.response?.data?.message || 'Не удалось запустить парсинг.';
    } finally {
        saving.value = false;
    }
}

onMounted(load);
onUnmounted(stopPolling);
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight">Настройки</h1>
        </div>

        <div v-if="loading" class="text-sm text-slate-500">Загрузка…</div>

        <form v-else class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" @submit.prevent="onSave">
            <label class="block text-sm">
                <span class="mb-1 block text-slate-600">Ссылка на организацию</span>
                <input
                    v-model="yandexUrl"
                    type="url"
                    required
                    placeholder="https://yandex.com/maps/-/short-link"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-sky-500"
                >
            </label>

            <p class="text-xs text-slate-400">
                Подходтя ссылки «Поделиться»: https://yandex.com/maps/-/…
                Или ссылки вида https://yandex.com/maps/org/…/ID/
            </p>

            <label class="block text-sm">
                <span class="mb-1 block text-slate-600">Лимит отзывов при парсинге</span>
                <input
                    v-model="reviewCap"
                    type="number"
                    min="1"
                    max="100000"
                    step="1"
                    placeholder="Без лимита"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-sky-500"
                >
            </label>
            <p class="text-xs text-slate-400">
                Пустое поле — тянем всё доступное через несколько выдач (сортировки, аспекты, звёзды)
            </p>

            <div v-if="organization" class="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                <div>Статус парсинга: <strong>{{ organization.parse_status }}</strong></div>
                <div v-if="organization.parse_status === 'parsing' || organization.parse_status === 'pending'">
                    Прогресс: {{ organization.parse_progress }}%
                </div>
                <div
                    v-if="organization.parse_message && (organization.parse_status === 'parsing' || organization.parse_status === 'pending')"
                    class="mt-1 text-slate-500"
                >
                    {{ organization.parse_message }}
                </div>
                <div v-if="organization.parse_error" class="text-red-600">
                    {{ organization.parse_error }}
                </div>
            </div>

            <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
            <p v-if="message" class="text-sm text-emerald-700">{{ message }}</p>

            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-60"
                    :disabled="saving"
                >
                    {{ saving ? 'Сохраняем…' : 'Сохранить и спарсить' }}
                </button>
                <button
                    v-if="organization"
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                    :disabled="saving || organization.parse_status === 'parsing'"
                    @click="onReparse"
                >
                    Повторить парсинг
                </button>
                <button
                    type="button"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                    @click="router.push({ name: 'organization' })"
                >
                    К отзывам
                </button>
            </div>
        </form>
    </div>
</template>

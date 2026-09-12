<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { login } from '../api';
import { setCachedUser } from '../router';

const router = useRouter();
const route = useRoute();

const email = ref('demo@example.com');
const password = ref('password');
const loading = ref(false);
const error = ref('');

async function onSubmit() {
    loading.value = true;
    error.value = '';

    try {
        const user = await login(email.value, password.value);
        setCachedUser(user);
        router.push(route.query.redirect || { name: 'organization' });
    } catch (e) {
        error.value = e.response?.data?.message
            || e.response?.data?.errors?.email?.[0]
            || 'Не удалось войти.';
    } finally {
        loading.value = false;
    }
}
</script>

<template>
    <div class="mx-auto mt-16 max-w-md">
        <div class="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Вход</h1>
            <p class="mt-2 text-sm text-slate-500">
                Один сид-пользователь. Регистрация не нужна.
            </p>

            <form class="mt-6 space-y-4" @submit.prevent="onSubmit">
                <label class="block text-sm">
                    <span class="mb-1 block text-slate-600">Email</span>
                    <input
                        v-model="email"
                        type="email"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-sky-500"
                    >
                </label>

                <label class="block text-sm">
                    <span class="mb-1 block text-slate-600">Пароль</span>
                    <input
                        v-model="password"
                        type="password"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-sky-500"
                    >
                </label>

                <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-60"
                    :disabled="loading"
                >
                    {{ loading ? 'Входим…' : 'Войти' }}
                </button>
            </form>
        </div>
    </div>
</template>

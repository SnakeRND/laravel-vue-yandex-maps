<script setup>
import { computed } from 'vue';
import { RouterLink, RouterView, useRouter } from 'vue-router';
import { logout } from './api';
import { getCachedUser, setCachedUser } from './router';

const router = useRouter();
const user = computed(() => getCachedUser() || null);

async function onLogout() {
    try {
        await logout();
    } finally {
        setCachedUser(false);
        router.push({ name: 'login' });
    }
}
</script>

<template>
    <div class="min-h-screen">
        <header
            v-if="user"
            class="border-b border-slate-200/80 bg-white/80 backdrop-blur"
        >
            <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3">
                <div class="flex items-center gap-6">
                    <RouterLink to="/" class="text-sm font-semibold tracking-tight text-slate-900">
                        Maps Reviews
                    </RouterLink>
                    <nav class="flex gap-4 text-sm text-slate-600">
                        <RouterLink
                            to="/"
                            class="hover:text-slate-900"
                            active-class="text-slate-900 font-medium"
                        >
                            Организация
                        </RouterLink>
                        <RouterLink
                            to="/settings"
                            class="hover:text-slate-900"
                            active-class="text-slate-900 font-medium"
                        >
                            Настройки
                        </RouterLink>
                    </nav>
                </div>
                <div class="flex items-center gap-3 text-sm text-slate-500">
                    <span>{{ user.email }}</span>
                    <button
                        type="button"
                        class="rounded-md border border-slate-200 px-2.5 py-1 text-slate-700 hover:bg-slate-50"
                        @click="onLogout"
                    >
                        Выйти
                    </button>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-4 py-8">
            <RouterView />
        </main>
    </div>
</template>

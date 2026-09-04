<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from './stores/auth';

const { state, logout } = useAuth();
const router = useRouter();
const route = useRoute();

async function onLogout(): Promise<void> {
    await logout();
    await router.push('/login');
}
</script>

<template>
    <div class="min-h-screen bg-slate-950 text-slate-100 flex flex-col">
        <header
            v-if="!route.meta.hideChrome"
            class="sticky top-0 z-30 border-b border-slate-800/80 bg-slate-950/90 backdrop-blur px-6 py-3.5 flex items-center justify-between"
        >
            <router-link to="/" class="flex items-center gap-2.5 text-base font-semibold tracking-tight">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white text-sm font-bold shadow-sm shadow-indigo-950">
                    V
                </span>
                Video Meeting App
            </router-link>

            <nav v-if="state.user" class="flex items-center gap-1 text-sm">
                <router-link
                    to="/dashboard"
                    class="rounded-md px-3 py-1.5 text-slate-300 transition-colors hover:bg-slate-900 hover:text-slate-100"
                    active-class="!text-white !bg-slate-800"
                >
                    Dashboard
                </router-link>
                <router-link
                    to="/history"
                    class="rounded-md px-3 py-1.5 text-slate-300 transition-colors hover:bg-slate-900 hover:text-slate-100"
                    active-class="!text-white !bg-slate-800"
                >
                    History
                </router-link>
                <router-link
                    to="/profile"
                    class="rounded-md px-3 py-1.5 text-slate-300 transition-colors hover:bg-slate-900 hover:text-slate-100"
                    active-class="!text-white !bg-slate-800"
                >
                    Profile
                </router-link>
                <router-link
                    v-if="state.user.is_admin"
                    to="/admin"
                    class="rounded-md px-3 py-1.5 text-slate-300 transition-colors hover:bg-slate-900 hover:text-slate-100"
                    active-class="!text-white !bg-slate-800"
                >
                    Admin
                </router-link>
                <button
                    type="button"
                    class="ml-2 rounded-md border border-slate-800 px-3 py-1.5 text-slate-300 transition-colors hover:border-slate-700 hover:text-slate-100"
                    @click="onLogout"
                >
                    Log out
                </button>
            </nav>
        </header>

        <main class="flex-1 min-h-0" :class="route.meta.hideChrome ? 'flex flex-col' : ''">
            <router-view />
        </main>
    </div>
</template>

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
    <div
        class="min-h-screen flex flex-col"
        :class="route.meta.hideChrome ? 'bg-slate-950 text-slate-100' : 'bg-slate-50 text-slate-900'"
    >
        <header
            v-if="!route.meta.hideChrome"
            class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur px-6 py-3.5 flex items-center justify-between shadow-sm"
        >
            <router-link to="/" class="flex items-center gap-2.5 text-base font-semibold tracking-tight text-slate-900">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white text-sm font-bold shadow-sm shadow-indigo-200">
                    V
                </span>
                Video Meeting App
            </router-link>

            <nav v-if="state.user" class="flex items-center gap-1 text-sm">
                <router-link
                    to="/dashboard"
                    class="rounded-md px-3 py-1.5 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
                    active-class="!text-indigo-700 !bg-indigo-50"
                >
                    Dashboard
                </router-link>
                <router-link
                    to="/history"
                    class="rounded-md px-3 py-1.5 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
                    active-class="!text-indigo-700 !bg-indigo-50"
                >
                    History
                </router-link>
                <router-link
                    to="/profile"
                    class="rounded-md px-3 py-1.5 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
                    active-class="!text-indigo-700 !bg-indigo-50"
                >
                    Profile
                </router-link>
                <router-link
                    v-if="state.user.is_admin"
                    to="/admin"
                    class="rounded-md px-3 py-1.5 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
                    active-class="!text-indigo-700 !bg-indigo-50"
                >
                    Admin
                </router-link>
                <button
                    type="button"
                    class="ml-2 rounded-md border border-slate-300 px-3 py-1.5 text-slate-600 transition-colors hover:border-slate-400 hover:text-slate-900"
                    @click="onLogout"
                >
                    Log out
                </button>
            </nav>
            <nav v-else-if="state.checked" class="flex items-center gap-2 text-sm">
                <router-link
                    to="/login"
                    class="rounded-md px-3 py-1.5 text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900"
                >
                    Sign in
                </router-link>
                <router-link
                    to="/register"
                    class="rounded-md bg-indigo-600 px-3 py-1.5 text-white transition-colors hover:bg-indigo-500"
                >
                    Sign up
                </router-link>
            </nav>
        </header>

        <main class="flex-1 min-h-0" :class="route.meta.hideChrome ? 'flex flex-col' : ''">
            <router-view />
        </main>
    </div>
</template>

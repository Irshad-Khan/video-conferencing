<script setup lang="ts">
import { useRouter } from 'vue-router';
import { useAuth } from './stores/auth';

const { state, logout } = useAuth();
const router = useRouter();

async function onLogout(): Promise<void> {
    await logout();
    await router.push('/login');
}
</script>

<template>
    <div class="min-h-screen bg-neutral-950 text-neutral-100 flex flex-col">
        <header class="border-b border-neutral-800 px-6 py-4 flex items-center justify-between">
            <router-link to="/" class="text-lg font-semibold">Video Meeting App</router-link>

            <nav v-if="state.user" class="flex items-center gap-4 text-sm">
                <router-link to="/dashboard" class="text-neutral-300 hover:text-neutral-100">Dashboard</router-link>
                <router-link to="/history" class="text-neutral-300 hover:text-neutral-100">History</router-link>
                <router-link to="/profile" class="text-neutral-300 hover:text-neutral-100">Profile</router-link>
                <router-link v-if="state.user.is_admin" to="/admin" class="text-neutral-300 hover:text-neutral-100">Admin</router-link>
                <button type="button" class="text-neutral-300 hover:text-neutral-100" @click="onLogout">
                    Log out
                </button>
            </nav>
        </header>

        <main class="flex-1">
            <router-view />
        </main>
    </div>
</template>

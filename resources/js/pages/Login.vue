<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import { useAuth } from '../stores/auth';

const { login } = useAuth();
const router = useRouter();
const route = useRoute();

const email = ref('');
const password = ref('');
const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);

async function onSubmit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    try {
        await login({ email: email.value, password: password.value });
        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard';
        await router.push(redirect);
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            errors.value = error.response.data.errors ?? {};
        } else {
            errors.value = { email: ['Something went wrong. Please try again.'] };
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900 flex items-center justify-center px-6">
        <div class="w-full max-w-sm space-y-6 bg-white border border-slate-200 shadow-sm rounded-2xl p-8">
            <div>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-white text-sm font-bold shadow-sm shadow-indigo-200 mb-3">V</span>
                <h1 class="text-xl font-semibold">Sign in</h1>
                <p class="text-sm text-slate-500 mt-1">Welcome back to Video Meeting App.</p>
            </div>

            <form class="space-y-4" @submit.prevent="onSubmit">
                <div>
                    <label for="email" class="block text-sm text-slate-600 mb-1">Email</label>
                    <input
                        id="email"
                        v-model="email"
                        type="email"
                        autocomplete="email"
                        required
                        class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                    <p v-if="errors.email" class="text-sm text-red-600 mt-1">{{ errors.email[0] }}</p>
                </div>

                <div>
                    <label for="password" class="block text-sm text-slate-600 mb-1">Password</label>
                    <input
                        id="password"
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                    <p v-if="errors.password" class="text-sm text-red-600 mt-1">{{ errors.password[0] }}</p>
                </div>

                <button
                    type="submit"
                    :disabled="submitting"
                    class="w-full rounded-md bg-indigo-600 text-white hover:bg-indigo-500 text-sm font-medium py-2 disabled:opacity-50 transition-colors"
                >
                    {{ submitting ? 'Signing in…' : 'Sign in' }}
                </button>
            </form>

            <p class="text-sm text-slate-500">
                Don't have an account?
                <router-link to="/register" class="text-indigo-600 hover:text-indigo-500 font-medium">Register</router-link>
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import { useAuth } from '../stores/auth';

const { register } = useAuth();
const router = useRouter();

const name = ref('');
const email = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);

async function onSubmit(): Promise<void> {
    submitting.value = true;
    errors.value = {};

    try {
        await register({
            name: name.value,
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        await router.push('/dashboard');
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            errors.value = error.response.data.errors ?? {};
        } else {
            errors.value = { name: ['Something went wrong. Please try again.'] };
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
                <h1 class="text-xl font-semibold">Create an account</h1>
                <p class="text-sm text-slate-500 mt-1">Start hosting meetings in minutes.</p>
            </div>

            <form class="space-y-4" @submit.prevent="onSubmit">
                <div>
                    <label for="name" class="block text-sm text-slate-600 mb-1">Name</label>
                    <input
                        id="name"
                        v-model="name"
                        type="text"
                        autocomplete="name"
                        required
                        class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                    <p v-if="errors.name" class="text-sm text-red-600 mt-1">{{ errors.name[0] }}</p>
                </div>

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
                        autocomplete="new-password"
                        required
                        class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                    <p v-if="errors.password" class="text-sm text-red-600 mt-1">{{ errors.password[0] }}</p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm text-slate-600 mb-1">Confirm password</label>
                    <input
                        id="password_confirmation"
                        v-model="passwordConfirmation"
                        type="password"
                        autocomplete="new-password"
                        required
                        class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                </div>

                <button
                    type="submit"
                    :disabled="submitting"
                    class="w-full rounded-md bg-indigo-600 text-white hover:bg-indigo-500 text-sm font-medium py-2 disabled:opacity-50 transition-colors"
                >
                    {{ submitting ? 'Creating account…' : 'Create account' }}
                </button>
            </form>

            <p class="text-sm text-slate-500">
                Already have an account?
                <router-link to="/login" class="text-indigo-600 hover:text-indigo-500 font-medium">Sign in</router-link>
            </p>
        </div>
    </div>
</template>

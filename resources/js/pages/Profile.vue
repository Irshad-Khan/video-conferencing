<script setup lang="ts">
import { ref, watchEffect } from 'vue';
import { isAxiosError } from 'axios';
import { useAuth } from '../stores/auth';

const { state, updateProfile } = useAuth();

const name = ref('');
const email = ref('');
const currentPassword = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const errors = ref<Record<string, string[]>>({});
const submitting = ref(false);
const savedMessage = ref('');

watchEffect(() => {
    if (state.user) {
        name.value = state.user.name;
        email.value = state.user.email;
    }
});

async function onSubmit(): Promise<void> {
    submitting.value = true;
    errors.value = {};
    savedMessage.value = '';

    try {
        await updateProfile({
            name: name.value,
            email: email.value,
            ...(password.value
                ? {
                      current_password: currentPassword.value,
                      password: password.value,
                      password_confirmation: passwordConfirmation.value,
                  }
                : {}),
        });

        currentPassword.value = '';
        password.value = '';
        passwordConfirmation.value = '';
        savedMessage.value = 'Profile updated.';
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
    <div class="max-w-md mx-auto px-6 py-12 space-y-6">
        <h1 class="text-xl font-semibold text-slate-100">Profile</h1>

        <form class="space-y-4" @submit.prevent="onSubmit">
            <div>
                <label for="name" class="block text-sm text-slate-300 mb-1">Name</label>
                <input
                    id="name"
                    v-model="name"
                    type="text"
                    required
                    class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                />
                <p v-if="errors.name" class="text-sm text-red-400 mt-1">{{ errors.name[0] }}</p>
            </div>

            <div>
                <label for="email" class="block text-sm text-slate-300 mb-1">Email</label>
                <input
                    id="email"
                    v-model="email"
                    type="email"
                    required
                    class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                />
                <p v-if="errors.email" class="text-sm text-red-400 mt-1">{{ errors.email[0] }}</p>
            </div>

            <fieldset class="border border-slate-800 rounded-md p-4 space-y-4">
                <legend class="text-sm text-slate-400 px-1">Change password (optional)</legend>

                <div>
                    <label for="current_password" class="block text-sm text-slate-300 mb-1">Current password</label>
                    <input
                        id="current_password"
                        v-model="currentPassword"
                        type="password"
                        autocomplete="current-password"
                        class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                    <p v-if="errors.current_password" class="text-sm text-red-400 mt-1">
                        {{ errors.current_password[0] }}
                    </p>
                </div>

                <div>
                    <label for="password" class="block text-sm text-slate-300 mb-1">New password</label>
                    <input
                        id="password"
                        v-model="password"
                        type="password"
                        autocomplete="new-password"
                        class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                    <p v-if="errors.password" class="text-sm text-red-400 mt-1">{{ errors.password[0] }}</p>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm text-slate-300 mb-1">
                        Confirm new password
                    </label>
                    <input
                        id="password_confirmation"
                        v-model="passwordConfirmation"
                        type="password"
                        autocomplete="new-password"
                        class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                </div>
            </fieldset>

            <p v-if="savedMessage" class="text-sm text-green-400">{{ savedMessage }}</p>

            <button
                type="submit"
                :disabled="submitting"
                class="w-full rounded-md bg-indigo-600 text-white hover:bg-indigo-500 text-sm font-medium py-2 disabled:opacity-50"
            >
                {{ submitting ? 'Saving…' : 'Save changes' }}
            </button>
        </form>
    </div>
</template>

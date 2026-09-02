<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { isAxiosError } from 'axios';
import api from '../lib/api';
import { useAuth } from '../stores/auth';
import type { JoinMeetingResponse, MeetingLookup } from '../types';

const route = useRoute();
const { state } = useAuth();
const code = String(route.params.code);
const guestTokenKey = `guest_token_${code}`;

const loading = ref(true);
const lookup = ref<MeetingLookup | null>(null);
const notFound = ref(false);

const password = ref('');
const guestName = ref('');
const errors = ref<Record<string, string[]>>({});
const generalError = ref('');
const joining = ref(false);

const joined = ref<JoinMeetingResponse | null>(null);
const leaving = ref(false);

const isEndedOrCancelled = computed(
    () => lookup.value?.status === 'ended' || lookup.value?.status === 'cancelled',
);
const needsGuestName = computed(() => !state.user && lookup.value?.allow_guests);
const guestsBlocked = computed(() => !state.user && lookup.value && !lookup.value.allow_guests);

async function loadLookup(): Promise<void> {
    loading.value = true;

    try {
        const { data } = await api.get<{ data: MeetingLookup }>(`/meetings/lookup/${code}`);
        lookup.value = data.data;
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 404) {
            notFound.value = true;
        } else {
            generalError.value = 'Could not load this meeting. Please try again.';
        }
    } finally {
        loading.value = false;
    }
}

onMounted(loadLookup);

async function submitJoin(): Promise<void> {
    joining.value = true;
    errors.value = {};
    generalError.value = '';

    try {
        const { data } = await api.post<{ data: JoinMeetingResponse }>(`/meetings/${code}/join`, {
            password: password.value || undefined,
            guest_name: guestName.value || undefined,
        });

        joined.value = data.data;

        if (data.data.guest_token) {
            sessionStorage.setItem(guestTokenKey, data.data.guest_token);
        }
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            errors.value = error.response.data.errors ?? {};
        } else if (isAxiosError(error) && error.response?.data?.message) {
            generalError.value = error.response.data.message;
        } else {
            generalError.value = 'Something went wrong. Please try again.';
        }
    } finally {
        joining.value = false;
    }
}

async function leaveMeeting(): Promise<void> {
    leaving.value = true;

    try {
        const guestToken = sessionStorage.getItem(guestTokenKey);
        await api.post(`/meetings/${code}/leave`, guestToken ? { guest_token: guestToken } : {});
        sessionStorage.removeItem(guestTokenKey);
        joined.value = null;
        await loadLookup();
    } catch {
        generalError.value = 'Could not leave the meeting. Please try again.';
    } finally {
        leaving.value = false;
    }
}
</script>

<template>
    <div class="min-h-screen bg-neutral-950 text-neutral-100 flex items-center justify-center px-6">
        <div class="w-full max-w-sm space-y-6">
            <p v-if="loading" class="text-sm text-neutral-400">Loading…</p>

            <div v-else-if="notFound" class="space-y-2">
                <h1 class="text-xl font-semibold">Meeting not found</h1>
                <p class="text-sm text-neutral-400">Check the meeting code and try again.</p>
            </div>

            <div v-else-if="joined" class="space-y-4">
                <h1 class="text-xl font-semibold">You're in</h1>
                <p class="text-sm text-neutral-400">
                    {{
                        joined.participant.status === 'waiting'
                            ? "You're in the waiting room. The host will let you in shortly."
                            : `Joined "${joined.meeting.title}" as ${joined.participant.role}.`
                    }}
                </p>
                <button
                    type="button"
                    :disabled="leaving"
                    class="w-full rounded-md border border-neutral-800 text-sm font-medium py-2 disabled:opacity-50"
                    @click="leaveMeeting"
                >
                    {{ leaving ? 'Leaving…' : 'Leave meeting' }}
                </button>
            </div>

            <div v-else-if="lookup" class="space-y-6">
                <div>
                    <h1 class="text-xl font-semibold">{{ lookup.title }}</h1>
                    <p class="text-sm text-neutral-400 mt-1">Hosted by {{ lookup.host_name }}</p>
                </div>

                <p v-if="isEndedOrCancelled" class="text-sm text-neutral-400">
                    This meeting has {{ lookup.status }}.
                </p>

                <template v-else>
                    <p v-if="generalError" class="text-sm text-red-400">{{ generalError }}</p>

                    <form v-if="!guestsBlocked" class="space-y-4" @submit.prevent="submitJoin">
                        <div v-if="needsGuestName">
                            <label for="guest_name" class="block text-sm text-neutral-300 mb-1">Your name</label>
                            <input
                                id="guest_name"
                                v-model="guestName"
                                type="text"
                                required
                                class="w-full rounded-md bg-neutral-900 border border-neutral-800 px-3 py-2 text-sm focus:outline-none focus:border-neutral-500"
                            />
                            <p v-if="errors.guest_name" class="text-sm text-red-400 mt-1">{{ errors.guest_name[0] }}</p>
                        </div>

                        <div v-if="lookup.has_password">
                            <label for="password" class="block text-sm text-neutral-300 mb-1">Meeting password</label>
                            <input
                                id="password"
                                v-model="password"
                                type="password"
                                class="w-full rounded-md bg-neutral-900 border border-neutral-800 px-3 py-2 text-sm focus:outline-none focus:border-neutral-500"
                            />
                            <p v-if="errors.password" class="text-sm text-red-400 mt-1">{{ errors.password[0] }}</p>
                        </div>

                        <button
                            type="submit"
                            :disabled="joining"
                            class="w-full rounded-md bg-neutral-100 text-neutral-950 text-sm font-medium py-2 disabled:opacity-50"
                        >
                            {{ joining ? 'Joining…' : 'Join meeting' }}
                        </button>
                    </form>

                    <div v-else class="space-y-3">
                        <p class="text-sm text-neutral-400">This meeting requires you to sign in to join.</p>
                        <router-link
                            :to="{ name: 'login', query: { redirect: route.fullPath } }"
                            class="block w-full text-center rounded-md bg-neutral-100 text-neutral-950 text-sm font-medium py-2"
                        >
                            Sign in
                        </router-link>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

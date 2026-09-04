<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import api from '../lib/api';
import { useAuth } from '../stores/auth';
import type { JoinMeetingResponse, MeetingLookup, WaitingRoomPayload } from '../types';

const route = useRoute();
const router = useRouter();
const { state } = useAuth();
const code = String(route.params.code);
const guestTokenKey = `guest_token_${code}`;
const joinDataKey = `join_data_${code}`;

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
const rejected = ref(false);

let waitingChannel: EchoChannel | null = null;

const isEndedOrCancelled = computed(
    () => lookup.value?.status === 'ended' || lookup.value?.status === 'cancelled',
);
const isHost = computed(() => !!state.user && state.user.id === lookup.value?.host_user_id);
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
            guest_token: sessionStorage.getItem(guestTokenKey) || undefined,
        });

        joined.value = data.data;

        if (data.data.guest_token) {
            sessionStorage.setItem(guestTokenKey, data.data.guest_token);
        }

        if (data.data.participant.status === 'waiting') {
            subscribeToWaitingRoom(data.data);
        } else {
            leaveWaitingChannel();
            sessionStorage.setItem(joinDataKey, JSON.stringify(data.data));
            router.push({ name: 'room', params: { code } });
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

function subscribeToWaitingRoom(joinResponse: JoinMeetingResponse): void {
    if (waitingChannel) return;

    const guestToken = sessionStorage.getItem(guestTokenKey);
    if (guestToken) {
        window.Echo.connector.options.auth.headers['X-Guest-Token'] = guestToken;
    }

    waitingChannel = window.Echo.private(`waiting-room.${joinResponse.meeting.meeting_uuid}`);
    waitingChannel.listen('.waiting-room', (payload: WaitingRoomPayload) => {
        if (payload.target !== joinResponse.presence_id) return;

        if (payload.action === 'admitted') {
            void submitJoin();
        } else if (payload.action === 'rejected') {
            leaveWaitingChannel();
            joined.value = null;
            rejected.value = true;
        }
    });
}

function leaveWaitingChannel(): void {
    if (!waitingChannel) return;
    window.Echo.leave(`waiting-room.${joined.value?.meeting.meeting_uuid}`);
    waitingChannel = null;
}

onBeforeUnmount(leaveWaitingChannel);

async function leaveMeeting(): Promise<void> {
    leaving.value = true;

    try {
        const guestToken = sessionStorage.getItem(guestTokenKey);
        await api.post(`/meetings/${code}/leave`, guestToken ? { guest_token: guestToken } : {});
        sessionStorage.removeItem(guestTokenKey);
        leaveWaitingChannel();
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
    <div class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center px-6">
        <div class="w-full max-w-sm space-y-6">
            <p v-if="loading" class="text-sm text-slate-400">Loading…</p>

            <div v-else-if="notFound" class="space-y-2">
                <h1 class="text-xl font-semibold">Meeting not found</h1>
                <p class="text-sm text-slate-400">Check the meeting code and try again.</p>
            </div>

            <div v-else-if="rejected" class="space-y-2">
                <h1 class="text-xl font-semibold">Not admitted</h1>
                <p class="text-sm text-slate-400">The host did not admit you to this meeting.</p>
            </div>

            <div v-else-if="joined" class="space-y-4">
                <h1 class="text-xl font-semibold">You're in</h1>
                <p class="text-sm text-slate-400">
                    {{
                        joined.participant.status === 'waiting'
                            ? "You're in the waiting room. The host will let you in shortly."
                            : `Joined "${joined.meeting.title}" as ${joined.participant.role}.`
                    }}
                </p>
                <button
                    type="button"
                    :disabled="leaving"
                    class="w-full rounded-md border border-slate-800 text-sm font-medium py-2 disabled:opacity-50"
                    @click="leaveMeeting"
                >
                    {{ leaving ? 'Leaving…' : 'Leave meeting' }}
                </button>
            </div>

            <div v-else-if="lookup" class="space-y-6">
                <div>
                    <h1 class="text-xl font-semibold">{{ lookup.title }}</h1>
                    <p class="text-sm text-slate-400 mt-1">
                        {{ isHost ? "You're the host" : `Hosted by ${lookup.host_name}` }}
                    </p>
                </div>

                <p v-if="isEndedOrCancelled" class="text-sm text-slate-400">
                    This meeting has {{ lookup.status }}.
                </p>

                <template v-else>
                    <p v-if="lookup.is_locked && !isHost" class="text-sm text-amber-400">
                        This meeting is currently locked by the host.
                    </p>
                    <p v-if="generalError" class="text-sm text-red-400">{{ generalError }}</p>

                    <form v-if="!guestsBlocked" class="space-y-4" @submit.prevent="submitJoin">
                        <div v-if="needsGuestName">
                            <label for="guest_name" class="block text-sm text-slate-300 mb-1">Your name</label>
                            <input
                                id="guest_name"
                                v-model="guestName"
                                type="text"
                                required
                                class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                            />
                            <p v-if="errors.guest_name" class="text-sm text-red-400 mt-1">{{ errors.guest_name[0] }}</p>
                        </div>

                        <div v-if="lookup.has_password && !isHost">
                            <label for="password" class="block text-sm text-slate-300 mb-1">Meeting password</label>
                            <input
                                id="password"
                                v-model="password"
                                type="password"
                                class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                            />
                            <p v-if="errors.password" class="text-sm text-red-400 mt-1">{{ errors.password[0] }}</p>
                        </div>

                        <button
                            type="submit"
                            :disabled="joining"
                            class="w-full rounded-md bg-indigo-600 text-white hover:bg-indigo-500 text-sm font-medium py-2 disabled:opacity-50"
                        >
                            {{ joining ? 'Joining…' : 'Join meeting' }}
                        </button>
                    </form>

                    <div v-else class="space-y-3">
                        <p class="text-sm text-slate-400">This meeting requires you to sign in to join.</p>
                        <router-link
                            :to="{ name: 'login', query: { redirect: route.fullPath } }"
                            class="block w-full text-center rounded-md bg-indigo-600 text-white hover:bg-indigo-500 text-sm font-medium py-2"
                        >
                            Sign in
                        </router-link>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

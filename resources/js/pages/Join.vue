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

// Zoom-style pre-join: camera preview + remembered guest name. Preview is purely
// cosmetic (the Room acquires its own stream), so any failure is silently ignored.
const guestNameKey = 'guest_display_name';
guestName.value = localStorage.getItem(guestNameKey) ?? '';
const previewVideo = ref<HTMLVideoElement | null>(null);
const previewOn = ref(true);
const previewError = ref(false);
let previewStream: MediaStream | null = null;

async function startPreview(): Promise<void> {
    try {
        previewStream = await navigator.mediaDevices.getUserMedia({ video: true });
        previewError.value = false;
        if (previewVideo.value) previewVideo.value.srcObject = previewStream;
    } catch {
        previewError.value = true;
    }
}

function stopPreview(): void {
    previewStream?.getTracks().forEach((track) => track.stop());
    previewStream = null;
}

async function togglePreview(): Promise<void> {
    previewOn.value = !previewOn.value;
    if (previewOn.value) await startPreview();
    else stopPreview();
}

function setPreviewEl(el: Element | null): void {
    previewVideo.value = el as HTMLVideoElement | null;
    if (previewVideo.value && previewStream && previewVideo.value.srcObject !== previewStream) {
        previewVideo.value.srcObject = previewStream;
    }
}

const guestInitial = computed(() => (guestName.value.trim()[0] ?? state.user?.name?.[0] ?? '?').toUpperCase());

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

onMounted(async () => {
    await loadLookup();
    void startPreview();
});

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

        if (guestName.value.trim()) localStorage.setItem(guestNameKey, guestName.value.trim());

        if (data.data.guest_token) {
            sessionStorage.setItem(guestTokenKey, data.data.guest_token);
        }

        if (data.data.participant.status === 'waiting') {
            subscribeToWaitingRoom(data.data);
        } else {
            leaveWaitingChannel();
            sessionStorage.setItem(joinDataKey, JSON.stringify(data.data));
            stopPreview();
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

onBeforeUnmount(() => {
    leaveWaitingChannel();
    stopPreview();
});

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
    <div class="min-h-[calc(100vh-4rem)] bg-gradient-to-br from-slate-50 via-white to-indigo-50 text-slate-900 flex items-center justify-center px-4 py-10">
        <p v-if="loading" class="text-sm text-slate-500">Loading…</p>

        <div v-else-if="notFound" class="w-full max-w-sm bg-white border border-slate-200 shadow-sm rounded-2xl p-8 space-y-2 text-center">
            <h1 class="text-xl font-semibold">Meeting not found</h1>
            <p class="text-sm text-slate-500">Check the meeting code or link and try again.</p>
            <router-link to="/" class="inline-block mt-3 text-sm text-indigo-600 hover:text-indigo-500">Go home</router-link>
        </div>

        <div v-else-if="rejected" class="w-full max-w-sm bg-white border border-slate-200 shadow-sm rounded-2xl p-8 space-y-2 text-center">
            <h1 class="text-xl font-semibold">Not admitted</h1>
            <p class="text-sm text-slate-500">The host did not admit you to this meeting.</p>
        </div>

        <div v-else-if="joined" class="w-full max-w-sm bg-white border border-slate-200 shadow-sm rounded-2xl p-8 space-y-5 text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-indigo-50">
                <span class="h-3 w-3 rounded-full bg-indigo-500 animate-pulse"></span>
            </div>
            <div class="space-y-1">
                <h1 class="text-xl font-semibold">
                    {{ joined.participant.status === 'waiting' ? 'Waiting for the host' : "You're in" }}
                </h1>
                <p class="text-sm text-slate-500">
                    {{
                        joined.participant.status === 'waiting'
                            ? 'The host will let you in shortly. Keep this page open.'
                            : `Joined "${joined.meeting.title}" as ${joined.participant.role}.`
                    }}
                </p>
            </div>
            <button
                type="button"
                :disabled="leaving"
                class="w-full rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-100 transition-colors text-sm font-medium py-2.5 disabled:opacity-50"
                @click="leaveMeeting"
            >
                {{ leaving ? 'Leaving…' : 'Leave meeting' }}
            </button>
        </div>

        <div v-else-if="lookup" class="w-full max-w-4xl bg-white border border-slate-200 shadow-lg shadow-indigo-100/50 rounded-3xl overflow-hidden grid md:grid-cols-2">
            <!-- camera preview -->
            <div class="bg-slate-900 relative min-h-64 md:min-h-[26rem] flex items-center justify-center">
                <video
                    v-show="previewOn && !previewError"
                    :ref="setPreviewEl"
                    autoplay
                    muted
                    playsinline
                    class="absolute inset-0 w-full h-full object-cover -scale-x-100"
                ></video>
                <div v-if="!previewOn || previewError" class="flex flex-col items-center gap-3 text-slate-400">
                    <span class="flex h-20 w-20 items-center justify-center rounded-full bg-indigo-600 text-white text-3xl font-semibold">
                        {{ guestInitial }}
                    </span>
                    <span class="text-sm">{{ previewError ? 'Camera unavailable' : 'Camera is off' }}</span>
                </div>
                <button
                    type="button"
                    class="absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full px-4 py-2 text-xs font-medium backdrop-blur transition-colors"
                    :class="previewOn && !previewError ? 'bg-white/90 text-slate-800 hover:bg-white' : 'bg-red-500/90 text-white hover:bg-red-500'"
                    @click="togglePreview"
                >
                    {{ previewOn ? 'Turn camera off' : 'Turn camera on' }}
                </button>
            </div>

            <!-- join form -->
            <div class="p-8 flex flex-col justify-center space-y-6">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-indigo-600">Ready to join?</p>
                    <h1 class="text-2xl font-semibold mt-1">{{ lookup.title }}</h1>
                    <p class="text-sm text-slate-500 mt-1">
                        {{ isHost ? "You're the host" : `Hosted by ${lookup.host_name}` }}
                    </p>
                </div>

                <p v-if="isEndedOrCancelled" class="text-sm text-slate-500">This meeting has {{ lookup.status }}.</p>

                <template v-else>
                    <p v-if="lookup.is_locked && !isHost" class="text-sm text-amber-600">
                        This meeting is currently locked by the host.
                    </p>
                    <p v-if="generalError" class="text-sm text-red-600">{{ generalError }}</p>

                    <form v-if="!guestsBlocked" class="space-y-4" @submit.prevent="submitJoin">
                        <div v-if="needsGuestName">
                            <label for="guest_name" class="block text-sm text-slate-600 mb-1">Your name</label>
                            <input
                                id="guest_name"
                                v-model="guestName"
                                type="text"
                                required
                                maxlength="100"
                                placeholder="Enter your name to join as a guest"
                                class="w-full rounded-lg bg-white border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                            />
                            <p v-if="errors.guest_name" class="text-sm text-red-600 mt-1">{{ errors.guest_name[0] }}</p>
                        </div>
                        <p v-else-if="state.user" class="text-sm text-slate-600">
                            Joining as <span class="font-medium text-slate-900">{{ state.user.name }}</span>
                        </p>

                        <div v-if="lookup.has_password && !isHost">
                            <label for="password" class="block text-sm text-slate-600 mb-1">Meeting password</label>
                            <input
                                id="password"
                                v-model="password"
                                type="password"
                                class="w-full rounded-lg bg-white border border-slate-300 px-3 py-2.5 text-sm focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                            />
                            <p v-if="errors.password" class="text-sm text-red-600 mt-1">{{ errors.password[0] }}</p>
                        </div>

                        <button
                            type="submit"
                            :disabled="joining"
                            class="w-full rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition-colors text-sm font-medium py-2.5 shadow-sm disabled:opacity-50"
                        >
                            {{ joining ? 'Joining…' : 'Join meeting' }}
                        </button>

                        <p v-if="needsGuestName" class="text-xs text-slate-500 text-center">
                            No account needed.
                            <router-link :to="{ name: 'login', query: { redirect: route.fullPath } }" class="text-indigo-600 hover:text-indigo-500">
                                Sign in instead
                            </router-link>
                        </p>
                    </form>

                    <div v-else class="space-y-3">
                        <p class="text-sm text-slate-500">This meeting requires you to sign in to join.</p>
                        <router-link
                            :to="{ name: 'login', query: { redirect: route.fullPath } }"
                            class="block w-full text-center rounded-lg bg-indigo-600 text-white hover:bg-indigo-500 transition-colors text-sm font-medium py-2.5"
                        >
                            Sign in
                        </router-link>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

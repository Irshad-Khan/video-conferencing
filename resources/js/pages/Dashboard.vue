<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { isAxiosError } from 'axios';
import { useRouter } from 'vue-router';
import api from '../lib/api';
import { useAuth } from '../stores/auth';
import type { Meeting, MeetingFormPayload } from '../types';

const { state } = useAuth();
const router = useRouter();

const joinCode = ref('');

function goToJoin(): void {
    const code = joinCode.value.trim();
    if (code) router.push(`/meeting/${code}`);
}

function enterMeeting(meeting: Meeting): void {
    router.push(`/meeting/${meeting.meeting_code}`);
}

const meetings = ref<Meeting[]>([]);
const loading = ref(true);
const loadError = ref('');

const showForm = ref(false);
const editingMeeting = ref<Meeting | null>(null);
const title = ref('');
const description = ref('');
const password = ref('');
const scheduledAt = ref('');
const durationMinutes = ref('');
const waitingRoomEnabled = ref(false);
const allowGuests = ref(true);
const formErrors = ref<Record<string, string[]>>({});
const submitting = ref(false);

const actionError = ref('');
const actionMeetingId = ref<number | null>(null);

function startOfDay(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

/** Calendar-day offset of `iso` from today (0 = today, 1 = tomorrow, negative = past). */
function dayOffset(iso: string): number {
    const diffMs = startOfDay(new Date(iso)).getTime() - startOfDay(new Date()).getTime();
    return Math.round(diffMs / 86_400_000);
}

function byScheduledAt(a: Meeting, b: Meeting): number {
    return (a.scheduled_at ?? '').localeCompare(b.scheduled_at ?? '');
}

const live = computed(() => meetings.value.filter((m: Meeting) => m.status === 'live'));
const scheduled = computed(() => meetings.value.filter((m: Meeting) => m.status === 'scheduled'));

// Instant meetings (no scheduled_at) are meant to start right away, so they group under Today.
const today = computed(() =>
    scheduled.value.filter((m: Meeting) => !m.scheduled_at || dayOffset(m.scheduled_at) <= 0).sort(byScheduledAt),
);
const tomorrow = computed(() =>
    scheduled.value.filter((m: Meeting) => m.scheduled_at && dayOffset(m.scheduled_at) === 1).sort(byScheduledAt),
);
const upcoming = computed(() =>
    scheduled.value.filter((m: Meeting) => m.scheduled_at && dayOffset(m.scheduled_at) > 1).sort(byScheduledAt),
);
const past = computed(() =>
    meetings.value
        .filter((m: Meeting) => m.status === 'ended' || m.status === 'cancelled')
        .sort((a: Meeting, b: Meeting) => b.updated_at.localeCompare(a.updated_at)),
);

function formatDuration(minutes: number | null): string | null {
    if (!minutes) return null;
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    if (hours === 0) return `${mins} min`;
    if (mins === 0) return `${hours}h`;
    return `${hours}h ${mins}m`;
}

async function loadMeetings(): Promise<void> {
    loading.value = true;
    loadError.value = '';

    try {
        const { data } = await api.get<{ data: Meeting[] }>('/meetings');
        meetings.value = data.data;
    } catch {
        loadError.value = 'Could not load your meetings. Please refresh the page.';
    } finally {
        loading.value = false;
    }
}

onMounted(loadMeetings);

function resetForm(): void {
    title.value = '';
    description.value = '';
    password.value = '';
    scheduledAt.value = '';
    durationMinutes.value = '';
    waitingRoomEnabled.value = false;
    allowGuests.value = true;
    formErrors.value = {};
}

function openCreateForm(): void {
    editingMeeting.value = null;
    resetForm();
    showForm.value = true;
}

function openEditForm(meeting: Meeting): void {
    editingMeeting.value = meeting;
    title.value = meeting.title;
    description.value = meeting.description ?? '';
    password.value = '';
    scheduledAt.value = meeting.scheduled_at ? toDatetimeLocal(meeting.scheduled_at) : '';
    durationMinutes.value = meeting.duration_minutes ? String(meeting.duration_minutes) : '';
    waitingRoomEnabled.value = meeting.waiting_room_enabled;
    allowGuests.value = meeting.allow_guests;
    formErrors.value = {};
    showForm.value = true;
}

function closeForm(): void {
    showForm.value = false;
    editingMeeting.value = null;
}

function toDatetimeLocal(iso: string): string {
    const date = new Date(iso);
    const offset = date.getTimezoneOffset();
    return new Date(date.getTime() - offset * 60_000).toISOString().slice(0, 16);
}

async function submitForm(): Promise<void> {
    submitting.value = true;
    formErrors.value = {};

    const payload: MeetingFormPayload = {
        title: title.value,
        description: description.value || null,
        scheduled_at: scheduledAt.value ? new Date(scheduledAt.value).toISOString() : null,
        duration_minutes: durationMinutes.value ? Number(durationMinutes.value) : null,
        waiting_room_enabled: waitingRoomEnabled.value,
        allow_guests: allowGuests.value,
    };

    if (password.value) {
        payload.password = password.value;
    }

    try {
        if (editingMeeting.value) {
            const { data } = await api.put<{ data: Meeting }>(`/meetings/${editingMeeting.value.meeting_uuid}`, payload);
            const index = meetings.value.findIndex((m: Meeting) => m.id === data.data.id);
            if (index !== -1) meetings.value[index] = data.data;
        } else {
            const { data } = await api.post<{ data: Meeting }>('/meetings', payload);
            meetings.value.unshift(data.data);
        }

        closeForm();
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            formErrors.value = error.response.data.errors ?? {};
        } else {
            formErrors.value = { title: ['Something went wrong. Please try again.'] };
        }
    } finally {
        submitting.value = false;
    }
}

async function runAction(meeting: Meeting, action: 'start' | 'end' | 'cancel'): Promise<void> {
    actionError.value = '';
    actionMeetingId.value = meeting.id;

    try {
        const { data } = await api.post<{ data: Meeting }>(`/meetings/${meeting.meeting_uuid}/${action}`);
        const index = meetings.value.findIndex((m: Meeting) => m.id === data.data.id);
        if (index !== -1) meetings.value[index] = data.data;
    } catch (error) {
        actionError.value = isAxiosError(error) && error.response?.data?.message
            ? error.response.data.message
            : 'Something went wrong. Please try again.';
    } finally {
        actionMeetingId.value = null;
    }
}

async function deleteMeeting(meeting: Meeting): Promise<void> {
    if (!confirm(`Delete "${meeting.title}"? This can't be undone.`)) return;

    actionError.value = '';
    actionMeetingId.value = meeting.id;

    try {
        await api.delete(`/meetings/${meeting.meeting_uuid}`);
        meetings.value = meetings.value.filter((m: Meeting) => m.id !== meeting.id);
    } catch (error) {
        actionError.value = isAxiosError(error) && error.response?.data?.message
            ? error.response.data.message
            : 'Something went wrong. Please try again.';
    } finally {
        actionMeetingId.value = null;
    }
}

function formatDate(iso: string | null): string {
    if (!iso) return 'Not scheduled';
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <div class="max-w-5xl mx-auto px-6 py-10 space-y-8">
        <div class="rounded-2xl border border-slate-800 bg-gradient-to-br from-slate-900 via-slate-900 to-indigo-950/40 p-6 sm:p-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-indigo-400">Dashboard</p>
                <h1 class="text-2xl font-semibold mt-1">Welcome back, {{ state.user?.name }}</h1>
                <p class="text-slate-400 mt-1 text-sm">Here's what's happening with your meetings.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <form class="flex items-center gap-2" @submit.prevent="goToJoin">
                    <input
                        v-model="joinCode"
                        type="text"
                        placeholder="Enter a meeting code"
                        class="rounded-md bg-slate-950/60 border border-slate-700 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                    <button
                        type="submit"
                        class="rounded-md border border-slate-700 bg-slate-950/40 text-sm font-medium px-4 py-2 hover:bg-slate-800 transition-colors"
                    >
                        Join
                    </button>
                </form>
                <button
                    type="button"
                    class="rounded-md bg-indigo-600 text-white hover:bg-indigo-500 transition-colors text-sm font-medium px-4 py-2 shadow-sm shadow-indigo-950"
                    @click="openCreateForm"
                >
                    + New meeting
                </button>
            </div>
        </div>

        <p v-if="loadError" class="text-sm text-red-400">{{ loadError }}</p>
        <p v-if="actionError" class="text-sm text-red-400">{{ actionError }}</p>

        <div v-if="showForm" class="border border-slate-800 bg-slate-900/40 rounded-xl p-5 space-y-4">
            <h2 class="text-sm font-medium text-slate-200">
                {{ editingMeeting ? 'Edit meeting' : 'Schedule a new meeting' }}
            </h2>

            <form class="space-y-4" @submit.prevent="submitForm">
                <div>
                    <label for="title" class="block text-sm text-slate-300 mb-1">Title</label>
                    <input
                        id="title"
                        v-model="title"
                        type="text"
                        required
                        class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                    <p v-if="formErrors.title" class="text-sm text-red-400 mt-1">{{ formErrors.title[0] }}</p>
                </div>

                <div>
                    <label for="description" class="block text-sm text-slate-300 mb-1">Description</label>
                    <textarea
                        id="description"
                        v-model="description"
                        rows="2"
                        class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    ></textarea>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label for="scheduled_at" class="block text-sm text-slate-300 mb-1">
                            Scheduled for (leave blank for instant)
                        </label>
                        <input
                            id="scheduled_at"
                            v-model="scheduledAt"
                            type="datetime-local"
                            class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                        />
                        <p v-if="formErrors.scheduled_at" class="text-sm text-red-400 mt-1">
                            {{ formErrors.scheduled_at[0] }}
                        </p>
                    </div>

                    <div>
                        <label for="duration_minutes" class="block text-sm text-slate-300 mb-1">
                            Duration (minutes, optional)
                        </label>
                        <input
                            id="duration_minutes"
                            v-model="durationMinutes"
                            type="number"
                            min="1"
                            max="1440"
                            placeholder="60"
                            class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                        />
                        <p v-if="formErrors.duration_minutes" class="text-sm text-red-400 mt-1">
                            {{ formErrors.duration_minutes[0] }}
                        </p>
                    </div>

                    <div>
                        <label for="password" class="block text-sm text-slate-300 mb-1">
                            {{ editingMeeting?.has_password ? 'New password (leave blank to keep)' : 'Password (optional)' }}
                        </label>
                        <input
                            id="password"
                            v-model="password"
                            type="password"
                            class="w-full rounded-md bg-slate-900 border border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                        />
                        <p v-if="formErrors.password" class="text-sm text-red-400 mt-1">{{ formErrors.password[0] }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-6 text-sm text-slate-300">
                    <label class="flex items-center gap-2">
                        <input v-model="waitingRoomEnabled" type="checkbox" />
                        Waiting room
                    </label>
                    <label class="flex items-center gap-2">
                        <input v-model="allowGuests" type="checkbox" />
                        Allow guests
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        :disabled="submitting"
                        class="rounded-md bg-indigo-600 text-white hover:bg-indigo-500 text-sm font-medium px-4 py-2 disabled:opacity-50"
                    >
                        {{ submitting ? 'Saving…' : editingMeeting ? 'Save changes' : 'Create meeting' }}
                    </button>
                    <button type="button" class="text-sm text-slate-400 hover:text-slate-200" @click="closeForm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <p v-if="loading" class="text-sm text-slate-400">Loading…</p>

        <template v-else>
            <section v-if="live.length" class="space-y-3">
                <h2 class="flex items-center gap-2 text-sm font-medium text-slate-400 uppercase tracking-wide">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    Live now
                </h2>
                <div
                    v-for="meeting in live"
                    :key="meeting.id"
                    class="border border-emerald-900 bg-emerald-950/30 rounded-xl p-4 flex items-center justify-between"
                >
                    <div>
                        <p class="font-medium">{{ meeting.title }}</p>
                        <p class="text-xs text-slate-400 mt-1">Code: {{ meeting.meeting_code }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            type="button"
                            class="text-sm rounded-md bg-indigo-600 text-white hover:bg-indigo-500 transition-colors px-3 py-1.5"
                            @click="enterMeeting(meeting)"
                        >
                            Join meeting
                        </button>
                        <button
                            type="button"
                            :disabled="actionMeetingId === meeting.id"
                            class="text-sm rounded-md border border-slate-700 px-3 py-1.5 hover:bg-slate-800 transition-colors disabled:opacity-50"
                            @click="runAction(meeting, 'end')"
                        >
                            End meeting
                        </button>
                    </div>
                </div>
            </section>

            <section v-for="group in [
                { label: 'Today', items: today, empty: 'No meetings scheduled for today.' },
                { label: 'Tomorrow', items: tomorrow, empty: 'No meetings scheduled for tomorrow.' },
                { label: 'Upcoming', items: upcoming, empty: 'No later meetings scheduled.' },
            ]" :key="group.label" class="space-y-3">
                <h2 class="text-sm font-medium text-slate-400 uppercase tracking-wide">{{ group.label }}</h2>
                <p v-if="!group.items.length" class="text-sm text-slate-500">{{ group.empty }}</p>
                <div
                    v-for="meeting in group.items"
                    :key="meeting.id"
                    class="border border-slate-800 bg-slate-900/30 rounded-xl p-4 flex items-center justify-between gap-4 hover:bg-slate-900/60 transition-colors"
                >
                    <div>
                        <p class="font-medium">{{ meeting.title }}</p>
                        <p class="text-xs text-slate-400 mt-1">
                            {{ formatDate(meeting.scheduled_at) }}
                            <template v-if="formatDuration(meeting.duration_minutes)"> · {{ formatDuration(meeting.duration_minutes) }}</template>
                            · Code: {{ meeting.meeting_code }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            type="button"
                            :disabled="actionMeetingId === meeting.id"
                            class="text-sm rounded-md bg-indigo-600 text-white hover:bg-indigo-500 transition-colors px-3 py-1.5 disabled:opacity-50"
                            @click="runAction(meeting, 'start')"
                        >
                            Start
                        </button>
                        <button
                            type="button"
                            class="text-sm text-slate-400 hover:text-slate-200"
                            @click="openEditForm(meeting)"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            :disabled="actionMeetingId === meeting.id"
                            class="text-sm text-slate-400 hover:text-slate-200 disabled:opacity-50"
                            @click="runAction(meeting, 'cancel')"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="actionMeetingId === meeting.id"
                            class="text-sm text-red-400 hover:text-red-300 disabled:opacity-50"
                            @click="deleteMeeting(meeting)"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-medium text-slate-400 uppercase tracking-wide">Past</h2>
                <p v-if="!past.length" class="text-sm text-slate-500">No past meetings yet.</p>
                <div
                    v-for="meeting in past"
                    :key="meeting.id"
                    class="border border-slate-800 bg-slate-900/20 rounded-xl p-4 flex items-center justify-between gap-4 opacity-75"
                >
                    <div>
                        <p class="font-medium">{{ meeting.title }}</p>
                        <p class="text-xs text-slate-400 mt-1 capitalize">
                            {{ meeting.status }} · {{ formatDate(meeting.ended_at ?? meeting.updated_at) }}
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="actionMeetingId === meeting.id"
                        class="text-sm text-red-400 hover:text-red-300 disabled:opacity-50"
                        @click="deleteMeeting(meeting)"
                    >
                        Delete
                    </button>
                </div>
            </section>
        </template>
    </div>
</template>

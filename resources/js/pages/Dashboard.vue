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
const copiedCode = ref('');

const initials = computed(() => {
    const name = state.user?.name?.trim() ?? '';
    if (!name) return '?';
    const parts = name.split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
});

async function copyCode(code: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(code);
        copiedCode.value = code;
        setTimeout(() => {
            if (copiedCode.value === code) copiedCode.value = '';
        }, 1500);
    } catch {
        // clipboard permission denied or unsupported — the code is still visible to copy manually
    }
}

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

const upcomingCount = computed(() => today.value.length + tomorrow.value.length + upcoming.value.length);
const allScheduledEmpty = computed(() => upcomingCount.value === 0);

const scheduleGroups = computed(() => [
    { label: 'Today', items: today.value, empty: 'Nothing on your schedule today — enjoy the calm, or start an instant meeting.', dot: 'bg-indigo-400', accent: 'border-l-indigo-300 hover:border-l-indigo-500' },
    { label: 'Tomorrow', items: tomorrow.value, empty: 'Nothing lined up for tomorrow yet.', dot: 'bg-violet-400', accent: 'border-l-violet-300 hover:border-l-violet-500' },
    { label: 'Upcoming', items: upcoming.value, empty: 'No later meetings scheduled.', dot: 'bg-sky-400', accent: 'border-l-sky-300 hover:border-l-sky-500' },
]);

function dayBadge(iso: string | null): { dow: string; day: string } | null {
    if (!iso) return null;
    const date = new Date(iso);
    return { dow: date.toLocaleDateString(undefined, { weekday: 'short' }).toUpperCase(), day: String(date.getDate()) };
}

function formatDuration(minutes: number | null): string | null {
    if (!minutes) return null;
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    if (hours === 0) return `${mins} min`;
    if (mins === 0) return `${hours}h`;
    return `${hours}h ${mins}m`;
}

/** Actual elapsed time a past meeting ran for, from real start/end timestamps — not the planned `duration_minutes`. */
function actualDuration(meeting: Meeting): string | null {
    if (!meeting.started_at || !meeting.ended_at) return null;
    const seconds = Math.round((new Date(meeting.ended_at).getTime() - new Date(meeting.started_at).getTime()) / 1000);
    if (seconds <= 0) return null;
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.round((seconds % 3600) / 60);
    if (hours === 0) return `${minutes} min`;
    if (minutes === 0) return `${hours}h`;
    return `${hours}h ${minutes}m`;
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
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm p-6 sm:p-8">
            <div class="pointer-events-none absolute -top-16 -right-16 h-56 w-56 rounded-full bg-gradient-to-br from-indigo-200 via-fuchsia-100 to-transparent opacity-60 blur-2xl"></div>
            <div class="pointer-events-none absolute -bottom-20 -left-10 h-48 w-48 rounded-full bg-gradient-to-tr from-sky-100 to-transparent opacity-50 blur-2xl"></div>

            <div class="relative flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5">
                <div class="flex items-center gap-4">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-indigo-500 to-fuchsia-500 text-white text-base font-semibold shadow-sm shadow-indigo-200">
                        {{ initials }}
                    </span>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-indigo-600">Dashboard</p>
                        <h1 class="text-2xl font-semibold mt-0.5 text-slate-900">Welcome back, {{ state.user?.name }}</h1>
                        <p class="text-slate-500 mt-0.5 text-sm">Here's what's happening with your meetings.</p>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <form class="flex items-center gap-2" @submit.prevent="goToJoin">
                        <input
                            v-model="joinCode"
                            type="text"
                            placeholder="Enter a meeting code"
                            class="rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                        />
                        <button
                            type="submit"
                            class="rounded-md border border-slate-300 bg-white text-sm font-medium px-4 py-2 text-slate-700 hover:bg-slate-100 transition-colors"
                        >
                            Join
                        </button>
                    </form>
                    <button
                        type="button"
                        class="rounded-md bg-indigo-600 text-white hover:bg-indigo-500 transition-colors text-sm font-medium px-4 py-2 shadow-sm shadow-indigo-200"
                        @click="openCreateForm"
                    >
                        + New meeting
                    </button>
                </div>
            </div>

            <div v-if="!loading" class="relative mt-6 grid grid-cols-3 gap-3">
                <div class="flex items-center gap-3 rounded-xl bg-indigo-50/70 px-3.5 py-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-indigo-600 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                    </span>
                    <div>
                        <p class="text-lg font-semibold text-indigo-900 leading-none">{{ meetings.length }}</p>
                        <p class="text-xs text-indigo-700/70 mt-1">Total meetings</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-xl bg-violet-50/70 px-3.5 py-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-violet-600 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    </span>
                    <div>
                        <p class="text-lg font-semibold text-violet-900 leading-none">{{ upcomingCount }}</p>
                        <p class="text-xs text-violet-700/70 mt-1">Upcoming</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-xl bg-emerald-50/70 px-3.5 py-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-emerald-600 shadow-sm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3" fill="currentColor" stroke="none"/></svg>
                    </span>
                    <div>
                        <p class="text-lg font-semibold text-emerald-900 leading-none">{{ live.length }}</p>
                        <p class="text-xs text-emerald-700/70 mt-1">Live now</p>
                    </div>
                </div>
            </div>
        </div>

        <p v-if="loadError" class="text-sm text-red-600">{{ loadError }}</p>
        <p v-if="actionError" class="text-sm text-red-600">{{ actionError }}</p>

        <div v-if="showForm" class="border border-slate-200 bg-white shadow-sm rounded-xl p-5 space-y-4">
            <h2 class="text-sm font-medium text-slate-800">
                {{ editingMeeting ? 'Edit meeting' : 'Schedule a new meeting' }}
            </h2>

            <form class="space-y-4" @submit.prevent="submitForm">
                <div>
                    <label for="title" class="block text-sm text-slate-600 mb-1">Title</label>
                    <input
                        id="title"
                        v-model="title"
                        type="text"
                        required
                        class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    />
                    <p v-if="formErrors.title" class="text-sm text-red-600 mt-1">{{ formErrors.title[0] }}</p>
                </div>

                <div>
                    <label for="description" class="block text-sm text-slate-600 mb-1">Description</label>
                    <textarea
                        id="description"
                        v-model="description"
                        rows="2"
                        class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                    ></textarea>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <label for="scheduled_at" class="block text-sm text-slate-600 mb-1">
                            Scheduled for (leave blank for instant)
                        </label>
                        <input
                            id="scheduled_at"
                            v-model="scheduledAt"
                            type="datetime-local"
                            class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                        />
                        <p v-if="formErrors.scheduled_at" class="text-sm text-red-600 mt-1">
                            {{ formErrors.scheduled_at[0] }}
                        </p>
                    </div>

                    <div>
                        <label for="duration_minutes" class="block text-sm text-slate-600 mb-1">
                            Duration (minutes, optional)
                        </label>
                        <input
                            id="duration_minutes"
                            v-model="durationMinutes"
                            type="number"
                            min="1"
                            max="1440"
                            placeholder="60"
                            class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                        />
                        <p v-if="formErrors.duration_minutes" class="text-sm text-red-600 mt-1">
                            {{ formErrors.duration_minutes[0] }}
                        </p>
                    </div>

                    <div>
                        <label for="password" class="block text-sm text-slate-600 mb-1">
                            {{ editingMeeting?.has_password ? 'New password (leave blank to keep)' : 'Password (optional)' }}
                        </label>
                        <input
                            id="password"
                            v-model="password"
                            type="password"
                            class="w-full rounded-md bg-white border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                        />
                        <p v-if="formErrors.password" class="text-sm text-red-600 mt-1">{{ formErrors.password[0] }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-6 text-sm text-slate-600">
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
                    <button type="button" class="text-sm text-slate-500 hover:text-slate-800" @click="closeForm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <p v-if="loading" class="text-sm text-slate-500">Loading…</p>

        <template v-else>
            <section v-if="live.length" class="space-y-3">
                <h2 class="flex items-center gap-2 text-sm font-medium text-slate-500 uppercase tracking-wide">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                    Live now
                </h2>
                <div
                    v-for="meeting in live"
                    :key="meeting.id"
                    class="border border-emerald-200 border-l-4 border-l-emerald-400 bg-emerald-50 rounded-xl p-4 flex items-center justify-between"
                >
                    <div>
                        <p class="font-medium text-slate-900">{{ meeting.title }}</p>
                        <button
                            type="button"
                            class="mt-1.5 inline-flex items-center gap-1 rounded font-mono text-xs bg-white border border-emerald-200 text-slate-600 px-1.5 py-0.5 hover:border-emerald-400 transition-colors"
                            @click="copyCode(meeting.meeting_code)"
                        >
                            {{ meeting.meeting_code }}
                            <svg v-if="copiedCode === meeting.meeting_code" xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        </button>
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
                            class="text-sm rounded-md border border-slate-300 bg-white px-3 py-1.5 text-slate-700 hover:bg-slate-100 transition-colors disabled:opacity-50"
                            @click="runAction(meeting, 'end')"
                        >
                            End meeting
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="allScheduledEmpty" class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-slate-200 bg-white/60 px-6 py-10 text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-indigo-50 to-violet-50 text-indigo-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M9 16l2 2 4-4"/></svg>
                </span>
                <div>
                    <p class="font-medium text-slate-800">You're all caught up</p>
                    <p class="text-sm text-slate-500 mt-1">Nothing scheduled — start an instant meeting or plan one for later.</p>
                </div>
                <button
                    type="button"
                    class="mt-1 rounded-md bg-indigo-600 text-white hover:bg-indigo-500 transition-colors text-sm font-medium px-4 py-2"
                    @click="openCreateForm"
                >
                    + New meeting
                </button>
            </section>

            <template v-else>
                <section v-for="group in scheduleGroups" :key="group.label" v-show="group.items.length" class="space-y-3">
                    <h2 class="flex items-center gap-2 text-sm font-medium text-slate-500 uppercase tracking-wide">
                        <span class="h-1.5 w-1.5 rounded-full" :class="group.dot"></span>
                        {{ group.label }}
                        <span class="text-slate-300 normal-case font-normal">· {{ group.items.length }}</span>
                    </h2>
                    <div
                        v-for="meeting in group.items"
                        :key="meeting.id"
                        class="border border-slate-200 border-l-4 bg-white rounded-xl p-4 flex items-center gap-4 hover:shadow-sm transition-all"
                        :class="group.accent"
                    >
                        <div v-if="dayBadge(meeting.scheduled_at)" class="flex shrink-0 flex-col items-center justify-center rounded-lg bg-slate-50 border border-slate-200 w-11 h-11 leading-none">
                            <span class="text-[9px] font-medium text-slate-400">{{ dayBadge(meeting.scheduled_at)?.dow }}</span>
                            <span class="text-sm font-semibold text-slate-700 mt-0.5">{{ dayBadge(meeting.scheduled_at)?.day }}</span>
                        </div>
                        <div class="flex-1 min-w-0 flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <p class="font-medium text-slate-900 truncate">{{ meeting.title }}</p>
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1.5 text-xs text-slate-500">
                                    <span class="inline-flex items-center gap-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                        {{ formatDate(meeting.scheduled_at) }}
                                    </span>
                                    <template v-if="formatDuration(meeting.duration_minutes)">
                                        <span class="inline-flex items-center gap-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                                            {{ formatDuration(meeting.duration_minutes) }}
                                        </span>
                                    </template>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded font-mono bg-slate-50 border border-slate-200 text-slate-600 px-1.5 py-0.5 hover:border-indigo-300 transition-colors"
                                        @click="copyCode(meeting.meeting_code)"
                                    >
                                        {{ meeting.meeting_code }}
                                        <svg v-if="copiedCode === meeting.meeting_code" xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                        <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    </button>
                                </div>
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
                                    class="text-sm text-slate-500 hover:text-slate-800"
                                    @click="openEditForm(meeting)"
                                >
                                    Edit
                                </button>
                                <button
                                    type="button"
                                    :disabled="actionMeetingId === meeting.id"
                                    class="text-sm text-slate-500 hover:text-slate-800 disabled:opacity-50"
                                    @click="runAction(meeting, 'cancel')"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    :disabled="actionMeetingId === meeting.id"
                                    class="text-sm text-red-600 hover:text-red-700 disabled:opacity-50"
                                    @click="deleteMeeting(meeting)"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </template>

            <section class="space-y-3">
                <h2 class="flex items-center gap-2 text-sm font-medium text-slate-500 uppercase tracking-wide">
                    Past
                    <span v-if="past.length" class="text-slate-300 normal-case font-normal">· {{ past.length }}</span>
                </h2>
                <div v-if="!past.length" class="flex items-center gap-3 rounded-xl border border-dashed border-slate-200 px-4 py-4 text-sm text-slate-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                    No past meetings yet — your finished meetings will show up here.
                </div>
                <div
                    v-for="meeting in past"
                    :key="meeting.id"
                    class="border border-slate-200 bg-white rounded-xl p-4 flex items-center gap-4 hover:shadow-sm transition-shadow"
                >
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                        :class="meeting.status === 'cancelled' ? 'bg-red-50 text-red-500' : 'bg-slate-100 text-slate-400'"
                    >
                        <svg v-if="meeting.status === 'cancelled'" xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="m9 12 2 2 4-4"/></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="font-medium text-slate-900 truncate">{{ meeting.title }}</p>
                            <span
                                class="shrink-0 text-[10px] font-medium uppercase tracking-wide px-1.5 py-0.5 rounded-full"
                                :class="meeting.status === 'cancelled' ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500'"
                            >
                                {{ meeting.status }}
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1.5 text-xs text-slate-500">
                            <span class="inline-flex items-center gap-1">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                {{ formatDate(meeting.ended_at ?? meeting.updated_at) }}
                            </span>
                            <span v-if="actualDuration(meeting)" class="inline-flex items-center gap-1">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                                {{ actualDuration(meeting) }}
                            </span>
                        </div>
                    </div>
                    <button
                        type="button"
                        :disabled="actionMeetingId === meeting.id"
                        class="text-sm text-red-600 hover:text-red-700 disabled:opacity-50"
                        @click="deleteMeeting(meeting)"
                    >
                        Delete
                    </button>
                </div>
            </section>
        </template>
    </div>
</template>

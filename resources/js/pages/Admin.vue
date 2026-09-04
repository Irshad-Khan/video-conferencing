<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { isAxiosError } from 'axios';
import api from '../lib/api';
import type { AdminMeetingRow, AdminStats, AdminUserRow, ParticipantHistoryEntry } from '../types';

const tab = ref<'overview' | 'users' | 'meetings'>('overview');

const stats = ref<AdminStats | null>(null);
const statsError = ref('');

const users = ref<AdminUserRow[]>([]);
const usersError = ref('');
const userActionId = ref<number | null>(null);

const meetings = ref<AdminMeetingRow[]>([]);
const meetingsError = ref('');
const meetingsLoading = ref(true);
const meetingFilter = ref<'all' | 'active' | 'history'>('all');
const meetingActionId = ref<number | null>(null);

const expandedMeetingId = ref<number | null>(null);
const participants = ref<Record<number, ParticipantHistoryEntry[]>>({});
const participantsLoading = ref<number | null>(null);

const filteredMeetings = computed(() => {
    if (meetingFilter.value === 'active') return meetings.value.filter((m) => m.status === 'live');
    if (meetingFilter.value === 'history') return meetings.value.filter((m) => m.status === 'ended' || m.status === 'cancelled');
    return meetings.value;
});

async function loadStats(): Promise<void> {
    statsError.value = '';
    try {
        const { data } = await api.get<{ data: AdminStats }>('/admin/stats');
        stats.value = data.data;
    } catch {
        statsError.value = 'Could not load system stats.';
    }
}

async function loadUsers(): Promise<void> {
    usersError.value = '';
    try {
        const { data } = await api.get<{ data: AdminUserRow[] }>('/admin/users');
        users.value = data.data;
    } catch {
        usersError.value = 'Could not load users.';
    }
}

async function loadMeetings(): Promise<void> {
    meetingsLoading.value = true;
    meetingsError.value = '';
    try {
        const { data } = await api.get<{ data: AdminMeetingRow[] }>('/admin/meetings');
        meetings.value = data.data;
    } catch {
        meetingsError.value = 'Could not load meetings.';
    } finally {
        meetingsLoading.value = false;
    }
}

onMounted(() => {
    loadStats();
    loadUsers();
    loadMeetings();
});

async function toggleUserDisabled(user: AdminUserRow): Promise<void> {
    usersError.value = '';
    userActionId.value = user.id;
    try {
        const action = user.is_disabled ? 'enable' : 'disable';
        const { data } = await api.post<{ data: AdminUserRow }>(`/admin/users/${user.id}/${action}`);
        const index = users.value.findIndex((u) => u.id === user.id);
        if (index !== -1) users.value[index] = { ...users.value[index], is_disabled: data.data.is_disabled };
    } catch (error) {
        usersError.value = isAxiosError(error) && error.response?.data?.message
            ? error.response.data.message
            : 'Something went wrong. Please try again.';
    } finally {
        userActionId.value = null;
    }
}

async function deleteMeeting(meeting: AdminMeetingRow): Promise<void> {
    if (!confirm(`Delete "${meeting.title}"? This can't be undone.`)) return;

    meetingsError.value = '';
    meetingActionId.value = meeting.id;
    try {
        await api.delete(`/admin/meetings/${meeting.meeting_uuid}`);
        meetings.value = meetings.value.filter((m) => m.id !== meeting.id);
    } catch (error) {
        meetingsError.value = isAxiosError(error) && error.response?.data?.message
            ? error.response.data.message
            : 'Something went wrong. Please try again.';
    } finally {
        meetingActionId.value = null;
    }
}

async function toggleExpanded(meeting: AdminMeetingRow): Promise<void> {
    if (expandedMeetingId.value === meeting.id) {
        expandedMeetingId.value = null;
        return;
    }

    expandedMeetingId.value = meeting.id;
    if (participants.value[meeting.id]) return;

    participantsLoading.value = meeting.id;
    try {
        const { data } = await api.get<{ data: ParticipantHistoryEntry[] }>(`/admin/meetings/${meeting.meeting_uuid}/participants`);
        participants.value[meeting.id] = data.data;
    } finally {
        participantsLoading.value = null;
    }
}

function formatDate(iso: string | null): string {
    if (!iso) return 'Not scheduled';
    return new Date(iso).toLocaleString();
}

function formatSeconds(seconds: number): string {
    if (seconds <= 0) return '0 min';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.round((seconds % 3600) / 60);
    if (hours === 0) return `${minutes} min`;
    if (minutes === 0) return `${hours}h`;
    return `${hours}h ${minutes}m`;
}

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}
</script>

<template>
    <div class="max-w-4xl mx-auto px-6 py-12 space-y-6">
        <div>
            <h1 class="text-xl font-semibold">Admin panel</h1>
            <p class="text-slate-400 mt-1 text-sm">Users, meetings, and system stats</p>
        </div>

        <div class="flex items-center gap-2 border-b border-slate-800 pb-2">
            <button
                v-for="t in (['overview', 'users', 'meetings'] as const)"
                :key="t"
                type="button"
                class="text-sm px-3 py-1.5 rounded-md capitalize"
                :class="tab === t ? 'bg-indigo-600 text-white hover:bg-indigo-500' : 'text-slate-400 hover:text-slate-100'"
                @click="tab = t"
            >
                {{ t }}
            </button>
        </div>

        <section v-if="tab === 'overview'" class="space-y-4">
            <p v-if="statsError" class="text-sm text-red-400">{{ statsError }}</p>
            <div v-if="stats" class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                <div class="border border-slate-800 rounded-md p-4">
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Users</p>
                    <p class="text-2xl font-semibold mt-1">{{ stats.users.total }}</p>
                    <p class="text-xs text-slate-500 mt-1">{{ stats.users.admins }} admin(s) · {{ stats.users.disabled }} disabled</p>
                </div>
                <div class="border border-slate-800 rounded-md p-4">
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Meetings</p>
                    <p class="text-2xl font-semibold mt-1">{{ stats.meetings.total }}</p>
                    <p class="text-xs text-slate-500 mt-1">{{ stats.meetings.live }} live now</p>
                </div>
                <div class="border border-slate-800 rounded-md p-4">
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Total participants</p>
                    <p class="text-2xl font-semibold mt-1">{{ stats.participants_total }}</p>
                </div>
                <div class="border border-slate-800 rounded-md p-4">
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Scheduled</p>
                    <p class="text-2xl font-semibold mt-1">{{ stats.meetings.scheduled }}</p>
                </div>
                <div class="border border-slate-800 rounded-md p-4">
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Ended</p>
                    <p class="text-2xl font-semibold mt-1">{{ stats.meetings.ended }}</p>
                </div>
                <div class="border border-slate-800 rounded-md p-4">
                    <p class="text-xs text-slate-400 uppercase tracking-wide">Cancelled</p>
                    <p class="text-2xl font-semibold mt-1">{{ stats.meetings.cancelled }}</p>
                </div>
            </div>
        </section>

        <section v-else-if="tab === 'users'" class="space-y-3">
            <p v-if="usersError" class="text-sm text-red-400">{{ usersError }}</p>
            <div
                v-for="user in users"
                :key="user.id"
                class="border border-slate-800 rounded-md p-4 flex items-center justify-between gap-4"
            >
                <div>
                    <p class="font-medium flex items-center gap-2">
                        {{ user.name }}
                        <span v-if="user.is_admin" class="text-xs text-slate-400">Admin</span>
                        <span v-if="user.is_disabled" class="text-xs text-red-400">Disabled</span>
                    </p>
                    <p class="text-xs text-slate-400 mt-1">{{ user.email }} · {{ user.meetings_count }} meeting(s)</p>
                </div>
                <button
                    type="button"
                    :disabled="userActionId === user.id"
                    class="text-sm rounded-md border border-slate-700 px-3 py-1.5 hover:bg-slate-800 disabled:opacity-50 shrink-0"
                    @click="toggleUserDisabled(user)"
                >
                    {{ user.is_disabled ? 'Enable' : 'Disable' }}
                </button>
            </div>
        </section>

        <section v-else class="space-y-3">
            <p v-if="meetingsError" class="text-sm text-red-400">{{ meetingsError }}</p>
            <div class="flex items-center gap-2">
                <button
                    v-for="f in (['all', 'active', 'history'] as const)"
                    :key="f"
                    type="button"
                    class="text-xs px-3 py-1 rounded-full capitalize"
                    :class="meetingFilter === f ? 'bg-indigo-600 text-white hover:bg-indigo-500' : 'border border-slate-800 text-slate-400 hover:text-slate-100'"
                    @click="meetingFilter = f"
                >
                    {{ f }}
                </button>
            </div>

            <p v-if="meetingsLoading" class="text-sm text-slate-400">Loading…</p>
            <p v-else-if="!filteredMeetings.length" class="text-sm text-slate-500">No meetings in this filter.</p>

            <div v-for="meeting in filteredMeetings" :key="meeting.id" class="border border-slate-800 rounded-md overflow-hidden">
                <div class="p-4 flex items-center justify-between gap-4">
                    <button type="button" class="text-left flex-1" @click="toggleExpanded(meeting)">
                        <p class="font-medium">{{ meeting.title }}</p>
                        <p class="text-xs text-slate-400 mt-1">
                            {{ capitalize(meeting.status) }} · Host: {{ meeting.host.name }} · {{ formatDate(meeting.scheduled_at) }}
                        </p>
                    </button>
                    <button
                        type="button"
                        :disabled="meetingActionId === meeting.id"
                        class="text-sm text-red-400 hover:text-red-300 disabled:opacity-50 shrink-0"
                        @click="deleteMeeting(meeting)"
                    >
                        Delete
                    </button>
                </div>

                <div v-if="expandedMeetingId === meeting.id" class="border-t border-slate-800 p-4 space-y-2">
                    <p v-if="participantsLoading === meeting.id" class="text-sm text-slate-400">Loading participants…</p>
                    <p v-else-if="!participants[meeting.id]?.length" class="text-sm text-slate-500">No one joined this meeting.</p>
                    <div
                        v-for="p in participants[meeting.id]"
                        :key="p.id"
                        class="flex items-center justify-between text-sm py-1.5 border-b border-slate-900 last:border-b-0"
                    >
                        <span>{{ p.name ?? 'Unknown' }}</span>
                        <div class="text-xs text-slate-400 flex items-center gap-3">
                            <span class="capitalize">{{ p.status }}</span>
                            <span>{{ formatSeconds(p.duration_seconds) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>

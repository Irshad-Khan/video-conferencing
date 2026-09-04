<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import api from '../lib/api';
import type { Meeting, ParticipantHistoryEntry } from '../types';

const meetings = ref<Meeting[]>([]);
const loading = ref(true);
const loadError = ref('');

const expandedId = ref<number | null>(null);
const participants = ref<Record<number, ParticipantHistoryEntry[]>>({});
const participantsLoading = ref<number | null>(null);
const participantsError = ref('');

const history = computed(() =>
    meetings.value
        .filter((m: Meeting) => m.status === 'ended' || m.status === 'cancelled')
        .sort((a: Meeting, b: Meeting) => (b.ended_at ?? b.updated_at).localeCompare(a.ended_at ?? a.updated_at)),
);

async function loadMeetings(): Promise<void> {
    loading.value = true;
    loadError.value = '';

    try {
        const { data } = await api.get<{ data: Meeting[] }>('/meetings');
        meetings.value = data.data;
    } catch {
        loadError.value = 'Could not load your meeting history. Please refresh the page.';
    } finally {
        loading.value = false;
    }
}

onMounted(loadMeetings);

async function toggleExpanded(meeting: Meeting): Promise<void> {
    if (expandedId.value === meeting.id) {
        expandedId.value = null;
        return;
    }

    expandedId.value = meeting.id;
    participantsError.value = '';

    if (participants.value[meeting.id]) return;

    participantsLoading.value = meeting.id;
    try {
        const { data } = await api.get<{ data: ParticipantHistoryEntry[] }>(`/meetings/${meeting.meeting_uuid}/participants`);
        participants.value[meeting.id] = data.data;
    } catch {
        participantsError.value = 'Could not load participants for this meeting.';
    } finally {
        participantsLoading.value = null;
    }
}

function meetingDuration(meeting: Meeting): string {
    if (!meeting.started_at || !meeting.ended_at) return '—';
    const seconds = Math.round((new Date(meeting.ended_at).getTime() - new Date(meeting.started_at).getTime()) / 1000);
    return formatSeconds(seconds);
}

function formatSeconds(seconds: number): string {
    if (seconds <= 0) return '0 min';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.round((seconds % 3600) / 60);
    if (hours === 0) return `${minutes} min`;
    if (minutes === 0) return `${hours}h`;
    return `${hours}h ${minutes}m`;
}

function formatDate(iso: string | null): string {
    if (!iso) return 'Unknown date';
    return new Date(iso).toLocaleString();
}

function roleLabel(role: string): string {
    return { host: 'Host', co_host: 'Co-host', guest: 'Guest', participant: 'Participant' }[role] ?? role;
}

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function statusLabel(status: string): string {
    return { left: 'Left', removed: 'Removed', rejected: 'Rejected', joined: 'Still joined', admitted: 'Admitted', waiting: 'Never joined' }[status] ?? status;
}
</script>

<template>
    <div class="max-w-3xl mx-auto px-6 py-12 space-y-6">
        <div>
            <h1 class="text-xl font-semibold">Meeting history</h1>
            <p class="text-slate-400 mt-1 text-sm">Past meetings and who attended</p>
        </div>

        <p v-if="loadError" class="text-sm text-red-400">{{ loadError }}</p>
        <p v-if="loading" class="text-sm text-slate-400">Loading…</p>

        <template v-else>
            <p v-if="!history.length" class="text-sm text-slate-500">No past meetings yet.</p>

            <div v-for="meeting in history" :key="meeting.id" class="border border-slate-800 rounded-md overflow-hidden">
                <button
                    type="button"
                    class="w-full p-4 flex items-center justify-between gap-4 text-left hover:bg-slate-900"
                    @click="toggleExpanded(meeting)"
                >
                    <div>
                        <p class="font-medium">{{ meeting.title }}</p>
                        <p class="text-xs text-slate-400 mt-1">
                            {{ capitalize(meeting.status) }} · {{ formatDate(meeting.ended_at ?? meeting.updated_at) }} · Duration: {{ meetingDuration(meeting) }}
                        </p>
                    </div>
                    <span class="text-xs text-slate-500 shrink-0">{{ expandedId === meeting.id ? 'Hide' : 'Show' }} participants</span>
                </button>

                <div v-if="expandedId === meeting.id" class="border-t border-slate-800 p-4 space-y-2">
                    <p v-if="participantsLoading === meeting.id" class="text-sm text-slate-400">Loading participants…</p>
                    <p v-else-if="participantsError" class="text-sm text-red-400">{{ participantsError }}</p>
                    <p v-else-if="!participants[meeting.id]?.length" class="text-sm text-slate-500">No one joined this meeting.</p>
                    <div
                        v-for="p in participants[meeting.id]"
                        :key="p.id"
                        class="flex items-center justify-between text-sm py-1.5 border-b border-slate-900 last:border-b-0"
                    >
                        <div class="flex items-center gap-2">
                            <span>{{ p.name ?? 'Unknown' }}</span>
                            <span class="text-xs text-slate-500">{{ roleLabel(p.role) }}</span>
                        </div>
                        <div class="text-xs text-slate-400 flex items-center gap-3">
                            <span>{{ statusLabel(p.status) }}</span>
                            <span>{{ formatSeconds(p.duration_seconds) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { isAxiosError } from 'axios';
import api from '../lib/api';
import { useAuth } from '../stores/auth';
import type { Meeting, MeetingFormPayload } from '../types';

const { state } = useAuth();

const meetings = ref<Meeting[]>([]);
const loading = ref(true);
const loadError = ref('');

const showForm = ref(false);
const editingMeeting = ref<Meeting | null>(null);
const title = ref('');
const description = ref('');
const password = ref('');
const scheduledAt = ref('');
const waitingRoomEnabled = ref(false);
const allowGuests = ref(true);
const formErrors = ref<Record<string, string[]>>({});
const submitting = ref(false);

const actionError = ref('');
const actionMeetingId = ref<number | null>(null);

const live = computed(() => meetings.value.filter((m: Meeting) => m.status === 'live'));
const upcoming = computed(() =>
    meetings.value
        .filter((m: Meeting) => m.status === 'scheduled')
        .sort((a: Meeting, b: Meeting) => (a.scheduled_at ?? '').localeCompare(b.scheduled_at ?? '')),
);
const past = computed(() =>
    meetings.value
        .filter((m: Meeting) => m.status === 'ended' || m.status === 'cancelled')
        .sort((a: Meeting, b: Meeting) => b.updated_at.localeCompare(a.updated_at)),
);

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
    <div class="max-w-3xl mx-auto px-6 py-12 space-y-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold">Welcome, {{ state.user?.name }}</h1>
                <p class="text-neutral-400 mt-1 text-sm">My meetings</p>
            </div>
            <button
                type="button"
                class="rounded-md bg-neutral-100 text-neutral-950 text-sm font-medium px-4 py-2"
                @click="openCreateForm"
            >
                New meeting
            </button>
        </div>

        <p v-if="loadError" class="text-sm text-red-400">{{ loadError }}</p>
        <p v-if="actionError" class="text-sm text-red-400">{{ actionError }}</p>

        <div v-if="showForm" class="border border-neutral-800 rounded-md p-5 space-y-4">
            <h2 class="text-sm font-medium text-neutral-200">
                {{ editingMeeting ? 'Edit meeting' : 'Schedule a new meeting' }}
            </h2>

            <form class="space-y-4" @submit.prevent="submitForm">
                <div>
                    <label for="title" class="block text-sm text-neutral-300 mb-1">Title</label>
                    <input
                        id="title"
                        v-model="title"
                        type="text"
                        required
                        class="w-full rounded-md bg-neutral-900 border border-neutral-800 px-3 py-2 text-sm focus:outline-none focus:border-neutral-500"
                    />
                    <p v-if="formErrors.title" class="text-sm text-red-400 mt-1">{{ formErrors.title[0] }}</p>
                </div>

                <div>
                    <label for="description" class="block text-sm text-neutral-300 mb-1">Description</label>
                    <textarea
                        id="description"
                        v-model="description"
                        rows="2"
                        class="w-full rounded-md bg-neutral-900 border border-neutral-800 px-3 py-2 text-sm focus:outline-none focus:border-neutral-500"
                    ></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="scheduled_at" class="block text-sm text-neutral-300 mb-1">
                            Scheduled for (leave blank for instant)
                        </label>
                        <input
                            id="scheduled_at"
                            v-model="scheduledAt"
                            type="datetime-local"
                            class="w-full rounded-md bg-neutral-900 border border-neutral-800 px-3 py-2 text-sm focus:outline-none focus:border-neutral-500"
                        />
                        <p v-if="formErrors.scheduled_at" class="text-sm text-red-400 mt-1">
                            {{ formErrors.scheduled_at[0] }}
                        </p>
                    </div>

                    <div>
                        <label for="password" class="block text-sm text-neutral-300 mb-1">
                            {{ editingMeeting?.has_password ? 'New password (leave blank to keep)' : 'Password (optional)' }}
                        </label>
                        <input
                            id="password"
                            v-model="password"
                            type="password"
                            class="w-full rounded-md bg-neutral-900 border border-neutral-800 px-3 py-2 text-sm focus:outline-none focus:border-neutral-500"
                        />
                        <p v-if="formErrors.password" class="text-sm text-red-400 mt-1">{{ formErrors.password[0] }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-6 text-sm text-neutral-300">
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
                        class="rounded-md bg-neutral-100 text-neutral-950 text-sm font-medium px-4 py-2 disabled:opacity-50"
                    >
                        {{ submitting ? 'Saving…' : editingMeeting ? 'Save changes' : 'Create meeting' }}
                    </button>
                    <button type="button" class="text-sm text-neutral-400 hover:text-neutral-200" @click="closeForm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <p v-if="loading" class="text-sm text-neutral-400">Loading…</p>

        <template v-else>
            <section v-if="live.length" class="space-y-3">
                <h2 class="text-sm font-medium text-neutral-400 uppercase tracking-wide">Live now</h2>
                <div
                    v-for="meeting in live"
                    :key="meeting.id"
                    class="border border-emerald-900 bg-emerald-950/30 rounded-md p-4 flex items-center justify-between"
                >
                    <div>
                        <p class="font-medium">{{ meeting.title }}</p>
                        <p class="text-xs text-neutral-400 mt-1">Code: {{ meeting.meeting_code }}</p>
                    </div>
                    <button
                        type="button"
                        :disabled="actionMeetingId === meeting.id"
                        class="text-sm rounded-md border border-neutral-700 px-3 py-1.5 hover:bg-neutral-800 disabled:opacity-50"
                        @click="runAction(meeting, 'end')"
                    >
                        End meeting
                    </button>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-medium text-neutral-400 uppercase tracking-wide">Upcoming</h2>
                <p v-if="!upcoming.length" class="text-sm text-neutral-500">No upcoming meetings.</p>
                <div
                    v-for="meeting in upcoming"
                    :key="meeting.id"
                    class="border border-neutral-800 rounded-md p-4 flex items-center justify-between gap-4"
                >
                    <div>
                        <p class="font-medium">{{ meeting.title }}</p>
                        <p class="text-xs text-neutral-400 mt-1">
                            {{ formatDate(meeting.scheduled_at) }} · Code: {{ meeting.meeting_code }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            type="button"
                            :disabled="actionMeetingId === meeting.id"
                            class="text-sm rounded-md bg-neutral-100 text-neutral-950 px-3 py-1.5 disabled:opacity-50"
                            @click="runAction(meeting, 'start')"
                        >
                            Start
                        </button>
                        <button
                            type="button"
                            class="text-sm text-neutral-400 hover:text-neutral-200"
                            @click="openEditForm(meeting)"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            :disabled="actionMeetingId === meeting.id"
                            class="text-sm text-neutral-400 hover:text-neutral-200 disabled:opacity-50"
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
                <h2 class="text-sm font-medium text-neutral-400 uppercase tracking-wide">Past</h2>
                <p v-if="!past.length" class="text-sm text-neutral-500">No past meetings yet.</p>
                <div
                    v-for="meeting in past"
                    :key="meeting.id"
                    class="border border-neutral-800 rounded-md p-4 flex items-center justify-between gap-4 opacity-75"
                >
                    <div>
                        <p class="font-medium">{{ meeting.title }}</p>
                        <p class="text-xs text-neutral-400 mt-1 capitalize">
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

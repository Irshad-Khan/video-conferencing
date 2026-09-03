<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../lib/api';
import { useAuth } from '../stores/auth';
import type { HostControlPayload, JoinMeetingResponse, MediaStatePayload } from '../types';

const route = useRoute();
const router = useRouter();
const { state } = useAuth();
const code = String(route.params.code);
const joinDataKey = `join_data_${code}`;
const guestTokenKey = `guest_token_${code}`;

const STUN_URLS = (import.meta.env.VITE_STUN_URLS ?? 'stun:stun.l.google.com:19302')
    .split(',')
    .map((url) => url.trim())
    .filter(Boolean);

type ConnectionState = 'connecting' | 'connected' | 'failed' | 'disconnected';

interface PeerTile {
    id: string;
    name: string;
    participantId: number | null;
    stream: MediaStream | null;
    state: ConnectionState;
    micOn: boolean;
    cameraOn: boolean;
    screenSharing: boolean;
}

const error = ref('');
const notice = ref('');
const micOn = ref(true);
const cameraOn = ref(true);
const localVideo = ref<HTMLVideoElement | null>(null);
const peers = reactive<Record<string, PeerTile>>({});
const isHost = ref(false);
const meetingLocked = ref(false);
const guestsAllowed = ref(true);
const hostActionError = ref('');
const hostActionBusy = ref(false);
const screenSharing = ref(false);
const screenShareBusy = ref(false);
const screenShareError = ref('');
const screenShareSupported = !!navigator.mediaDevices?.getDisplayMedia;

let joinData: JoinMeetingResponse | null = null;
let localStream: MediaStream | null = null;
let screenStream: MediaStream | null = null;
let myId = '';
let myName = 'Participant';
let myParticipantId: number | null = null;
let channel: EchoChannel | null = null;
const connections: Record<string, RTCPeerConnection> = {};

function currentVideoTrack(): MediaStreamTrack | null {
    return screenStream?.getVideoTracks()[0] ?? localStream?.getVideoTracks()[0] ?? null;
}

function iceConfig(): RTCConfiguration {
    return { iceServers: [{ urls: STUN_URLS }] };
}

function connectionUiState(state: RTCPeerConnectionState): ConnectionState {
    if (state === 'connected') return 'connected';
    if (state === 'failed') return 'failed';
    if (state === 'disconnected' || state === 'closed') return 'disconnected';
    return 'connecting';
}

function ensurePeer(id: string, name: string, participantId: number | null = null): RTCPeerConnection {
    if (connections[id]) return connections[id];

    peers[id] = peers[id] ?? { id, name, participantId, stream: null, state: 'connecting', micOn: true, cameraOn: true, screenSharing: false };

    const pc = new RTCPeerConnection(iceConfig());

    localStream?.getAudioTracks().forEach((track) => pc.addTrack(track, localStream as MediaStream));
    const videoTrack = currentVideoTrack();
    if (videoTrack) {
        pc.addTrack(videoTrack, (screenStream ?? localStream) as MediaStream);
    }

    pc.onicecandidate = (event) => {
        if (event.candidate) {
            channel?.whisper('webrtc-ice-candidate', { from: myId, target: id, candidate: event.candidate });
        }
    };

    pc.ontrack = (event) => {
        peers[id].stream = event.streams[0] ?? null;
    };

    pc.onconnectionstatechange = () => {
        peers[id].state = connectionUiState(pc.connectionState);
    };

    connections[id] = pc;

    return pc;
}

async function createOfferTo(id: string, name: string): Promise<void> {
    const pc = ensurePeer(id, name);
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    channel?.whisper('webrtc-offer', { from: myId, target: id, name: myName, participantId: myParticipantId, sdp: offer });
}

function teardownPeer(id: string): void {
    connections[id]?.close();
    delete connections[id];
    delete peers[id];
}

function attachSignalingListeners(): void {
    if (!channel) return;

    channel
        .listenForWhisper('participant.announce', (payload: { id: string; name: string; participantId?: number }) => {
            if (payload.id === myId || connections[payload.id]) return;
            peers[payload.id] = {
                id: payload.id,
                name: payload.name,
                participantId: payload.participantId ?? null,
                stream: null,
                state: 'connecting',
                micOn: true,
                cameraOn: true,
                screenSharing: false,
            };
            void createOfferTo(payload.id, payload.name);
        })
        .listenForWhisper('participant.bye', (payload: { id: string }) => {
            teardownPeer(payload.id);
        })
        .listenForWhisper('webrtc-offer', async (payload: { from: string; target: string; name?: string; participantId?: number; sdp: RTCSessionDescriptionInit }) => {
            if (payload.target !== myId) return;
            const pc = ensurePeer(payload.from, peers[payload.from]?.name ?? payload.name ?? 'Participant', payload.participantId ?? null);
            await pc.setRemoteDescription(new RTCSessionDescription(payload.sdp));
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            channel?.whisper('webrtc-answer', { from: myId, target: payload.from, sdp: answer });
        })
        .listenForWhisper('webrtc-answer', async (payload: { from: string; target: string; sdp: RTCSessionDescriptionInit }) => {
            if (payload.target !== myId) return;
            await connections[payload.from]?.setRemoteDescription(new RTCSessionDescription(payload.sdp));
        })
        .listenForWhisper('webrtc-ice-candidate', async (payload: { from: string; target: string; candidate: RTCIceCandidateInit }) => {
            if (payload.target !== myId) return;
            try {
                await connections[payload.from]?.addIceCandidate(new RTCIceCandidate(payload.candidate));
            } catch {
                // candidate arrived before/after a renegotiation window closed — harmless, ICE keeps trying others
            }
        })
        .listenForWhisper('media-state', (payload: MediaStatePayload) => {
            if (peers[payload.from]) {
                peers[payload.from].micOn = payload.mic;
                peers[payload.from].cameraOn = payload.camera;
                peers[payload.from].screenSharing = payload.screenSharing ?? false;
            }
        })
        .listen('.host.control', (payload: HostControlPayload) => {
            handleHostControl(payload);
        });
}

function handleHostControl(payload: HostControlPayload): void {
    switch (payload.action) {
        case 'mute':
            if (payload.target === myId) {
                micOn.value = false;
                localStream?.getAudioTracks().forEach((track) => (track.enabled = false));
                channel?.whisper('media-state', { from: myId, mic: false, camera: cameraOn.value, screenSharing: screenSharing.value });
                notice.value = 'The host muted your microphone.';
            }
            break;
        case 'remove':
            if (payload.target === myId) {
                notice.value = 'You were removed from this meeting by the host.';
                teardown();
                sessionStorage.removeItem(joinDataKey);
                sessionStorage.removeItem(guestTokenKey);
                router.push({ name: 'join', params: { code } });
            } else if (payload.target) {
                teardownPeer(payload.target);
            }
            break;
        case 'end':
            notice.value = 'This meeting was ended by the host.';
            teardown();
            sessionStorage.removeItem(joinDataKey);
            sessionStorage.removeItem(guestTokenKey);
            router.push({ name: 'join', params: { code } });
            break;
        case 'lock':
            meetingLocked.value = true;
            break;
        case 'unlock':
            meetingLocked.value = false;
            break;
        case 'guests-enabled':
            guestsAllowed.value = true;
            break;
        case 'guests-disabled':
            guestsAllowed.value = false;
            break;
    }
}

function teardown(): void {
    Object.keys(connections).forEach(teardownPeer);
    localStream?.getTracks().forEach((track) => track.stop());
    screenStream?.getTracks().forEach((track) => track.stop());
    screenStream = null;

    if (joinData) {
        channel?.whisper('participant.bye', { id: myId });
        window.Echo.leave(`meeting.${joinData.meeting.meeting_uuid}`);
    }

    channel = null;
}

onMounted(async () => {
    const raw = sessionStorage.getItem(joinDataKey);

    if (!raw) {
        router.replace({ name: 'join', params: { code } });
        return;
    }

    joinData = JSON.parse(raw) as JoinMeetingResponse;
    myId = joinData.presence_id;
    myName = joinData.participant.guest_name ?? state.user?.name ?? 'Participant';
    myParticipantId = joinData.participant.id;
    isHost.value = joinData.participant.role === 'host';
    meetingLocked.value = joinData.meeting.is_locked;
    guestsAllowed.value = joinData.meeting.allow_guests;

    try {
        localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: true });
    } catch {
        error.value = 'Camera and microphone access is required to join this call.';
        return;
    }

    if (localVideo.value) {
        localVideo.value.srcObject = localStream;
    }

    const guestToken = sessionStorage.getItem(guestTokenKey);
    if (guestToken) {
        window.Echo.connector.options.auth.headers['X-Guest-Token'] = guestToken;
    }

    channel = window.Echo.private(`meeting.${joinData.meeting.meeting_uuid}`);
    attachSignalingListeners();
    channel
        .subscribed(() => {
            channel?.whisper('participant.announce', { id: myId, name: myName, participantId: myParticipantId });
        })
        .error(() => {
            error.value = 'Could not connect to the meeting room. Please try again.';
        });
});

onBeforeUnmount(() => {
    teardown();
});

function toggleMic(): void {
    micOn.value = !micOn.value;
    localStream?.getAudioTracks().forEach((track) => (track.enabled = micOn.value));
    channel?.whisper('media-state', { from: myId, mic: micOn.value, camera: cameraOn.value, screenSharing: screenSharing.value });
}

function toggleCamera(): void {
    cameraOn.value = !cameraOn.value;
    localStream?.getVideoTracks().forEach((track) => (track.enabled = cameraOn.value));
    channel?.whisper('media-state', { from: myId, mic: micOn.value, camera: cameraOn.value, screenSharing: screenSharing.value });
}

async function startScreenShare(): Promise<void> {
    if (!screenShareSupported || screenSharing.value || screenShareBusy.value) return;
    screenShareBusy.value = true;
    screenShareError.value = '';

    let stream: MediaStream;
    try {
        stream = await navigator.mediaDevices.getDisplayMedia({ video: true });
    } catch {
        screenShareError.value = 'Screen share permission was denied.';
        screenShareBusy.value = false;
        return;
    }

    screenStream = stream;
    const screenTrack = stream.getVideoTracks()[0];
    // Fires when the share ends via the browser/OS's own "Stop sharing" control,
    // not just our button — must revert to the camera either way.
    screenTrack.onended = () => {
        void stopScreenShare();
    };

    Object.values(connections).forEach((pc) => {
        const sender = pc.getSenders().find((s) => s.track?.kind === 'video');
        void sender?.replaceTrack(screenTrack);
    });

    if (localVideo.value) {
        localVideo.value.srcObject = screenStream;
    }

    screenSharing.value = true;
    screenShareBusy.value = false;
    channel?.whisper('media-state', { from: myId, mic: micOn.value, camera: cameraOn.value, screenSharing: true });
}

async function stopScreenShare(): Promise<void> {
    if (!screenStream) return;

    screenStream.getTracks().forEach((track) => track.stop());
    screenStream = null;

    const cameraTrack = localStream?.getVideoTracks()[0] ?? null;
    Object.values(connections).forEach((pc) => {
        const sender = pc.getSenders().find((s) => s.track?.kind === 'video');
        void sender?.replaceTrack(cameraTrack);
    });

    if (localVideo.value) {
        localVideo.value.srcObject = localStream;
    }

    screenSharing.value = false;
    channel?.whisper('media-state', { from: myId, mic: micOn.value, camera: cameraOn.value, screenSharing: false });
}

async function leaveRoom(): Promise<void> {
    const guestToken = sessionStorage.getItem(guestTokenKey);

    try {
        await api.post(`/meetings/${code}/leave`, guestToken ? { guest_token: guestToken } : {});
    } catch {
        // best-effort: still tear down and navigate away locally even if this fails
    }

    teardown();
    sessionStorage.removeItem(joinDataKey);
    sessionStorage.removeItem(guestTokenKey);
    router.push({ name: 'join', params: { code } });
}

async function toggleLock(): Promise<void> {
    if (!joinData || hostActionBusy.value) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    // Capture the target value before awaiting: the host's own client also
    // receives its own 'lock'/'unlock' broadcast (often before this request
    // resolves), so inverting the *current* ref afterwards could double-flip it.
    const wasLocked = meetingLocked.value;

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/${wasLocked ? 'unlock' : 'lock'}`);
        meetingLocked.value = !wasLocked;
    } catch {
        hostActionError.value = 'Could not update the lock. Please try again.';
    } finally {
        hostActionBusy.value = false;
    }
}

async function toggleGuests(): Promise<void> {
    if (!joinData || hostActionBusy.value) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    // See toggleLock() above for why this captures the target value up front
    // instead of inverting the live ref after the request resolves.
    const wereAllowed = guestsAllowed.value;

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/guests/${wereAllowed ? 'disable' : 'enable'}`);
        guestsAllowed.value = !wereAllowed;
    } catch {
        hostActionError.value = 'Could not update guest access. Please try again.';
    } finally {
        hostActionBusy.value = false;
    }
}

async function endMeetingForAll(): Promise<void> {
    if (!joinData || hostActionBusy.value) return;
    if (!confirm('End this meeting for everyone?')) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/end`);
        teardown();
        sessionStorage.removeItem(joinDataKey);
        sessionStorage.removeItem(guestTokenKey);
        router.push({ name: 'join', params: { code } });
    } catch {
        hostActionError.value = 'Could not end the meeting. Please try again.';
        hostActionBusy.value = false;
    }
}

async function muteParticipant(peer: PeerTile): Promise<void> {
    if (!joinData || !peer.participantId || hostActionBusy.value) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/participants/${peer.participantId}/mute`);
    } catch {
        hostActionError.value = `Could not mute ${peer.name}. Please try again.`;
    } finally {
        hostActionBusy.value = false;
    }
}

async function removeParticipant(peer: PeerTile): Promise<void> {
    if (!joinData || !peer.participantId || hostActionBusy.value) return;
    if (!confirm(`Remove ${peer.name} from this meeting?`)) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/participants/${peer.participantId}/remove`);
        teardownPeer(peer.id);
    } catch {
        hostActionError.value = `Could not remove ${peer.name}. Please try again.`;
    } finally {
        hostActionBusy.value = false;
    }
}

function setPeerVideoEl(id: string, el: Element | null): void {
    const video = el as HTMLVideoElement | null;
    if (video && peers[id]?.stream) {
        video.srcObject = peers[id].stream;
    }
}
</script>

<template>
    <div class="min-h-screen bg-neutral-950 text-neutral-100 flex flex-col">
        <div v-if="error" class="flex-1 flex items-center justify-center px-6">
            <p class="text-sm text-red-400">{{ error }}</p>
        </div>

        <template v-else>
            <p v-if="notice" class="text-xs text-center bg-amber-950/50 text-amber-300 py-1.5">{{ notice }}</p>
            <p v-if="hostActionError" class="text-xs text-center bg-red-950/50 text-red-300 py-1.5">{{ hostActionError }}</p>
            <p v-if="screenShareError" class="text-xs text-center bg-red-950/50 text-red-300 py-1.5">{{ screenShareError }}</p>

            <div v-if="isHost" class="flex items-center justify-center gap-2 px-4 py-2 border-b border-neutral-800 text-xs">
                <button
                    type="button"
                    :disabled="hostActionBusy"
                    class="rounded-full px-3 py-1 border border-neutral-800 disabled:opacity-50"
                    :class="meetingLocked ? 'bg-red-900/50 border-red-800' : 'bg-neutral-900'"
                    @click="toggleLock"
                >
                    {{ meetingLocked ? 'Unlock meeting' : 'Lock meeting' }}
                </button>
                <button
                    type="button"
                    :disabled="hostActionBusy"
                    class="rounded-full px-3 py-1 border border-neutral-800 disabled:opacity-50"
                    :class="guestsAllowed ? 'bg-neutral-900' : 'bg-red-900/50 border-red-800'"
                    @click="toggleGuests"
                >
                    {{ guestsAllowed ? 'Disallow guests' : 'Allow guests' }}
                </button>
                <button
                    type="button"
                    :disabled="hostActionBusy"
                    class="rounded-full px-3 py-1 bg-red-700 disabled:opacity-50"
                    @click="endMeetingForAll"
                >
                    End meeting for all
                </button>
            </div>

            <div class="flex-1 grid gap-2 p-2 auto-rows-fr" :style="{ gridTemplateColumns: `repeat(${Math.max(1, Math.ceil(Math.sqrt(Object.keys(peers).length + 1)))}, minmax(0, 1fr))` }">
                <div class="relative bg-neutral-900 rounded-lg overflow-hidden">
                    <video ref="localVideo" autoplay muted playsinline class="w-full h-full object-cover"></video>
                    <div class="absolute bottom-2 left-2 text-xs bg-black/60 rounded px-2 py-1 flex items-center gap-1">
                        You
                        <span v-if="!micOn">· muted</span>
                        <span v-if="!cameraOn">· camera off</span>
                        <span v-if="screenSharing">· presenting</span>
                    </div>
                </div>

                <div v-for="peer in peers" :key="peer.id" class="relative bg-neutral-900 rounded-lg overflow-hidden">
                    <video
                        v-if="peer.stream"
                        :ref="(el) => setPeerVideoEl(peer.id, el)"
                        autoplay
                        playsinline
                        class="w-full h-full object-cover"
                    ></video>
                    <div v-else class="w-full h-full flex items-center justify-center text-sm text-neutral-500">
                        {{ peer.state === 'connecting' ? 'Connecting…' : peer.state }}
                    </div>
                    <div class="absolute bottom-2 left-2 text-xs bg-black/60 rounded px-2 py-1 flex items-center gap-1">
                        {{ peer.name }}
                        <span v-if="!peer.micOn">· muted</span>
                        <span v-if="!peer.cameraOn">· camera off</span>
                        <span v-if="peer.screenSharing">· presenting</span>
                        <span v-if="peer.state !== 'connected'">· {{ peer.state }}</span>
                    </div>
                    <div v-if="isHost && peer.participantId" class="absolute top-2 right-2 flex items-center gap-1">
                        <button
                            type="button"
                            :disabled="hostActionBusy"
                            class="text-xs bg-black/60 hover:bg-black/80 rounded px-2 py-1 disabled:opacity-50"
                            @click="muteParticipant(peer)"
                        >
                            Mute
                        </button>
                        <button
                            type="button"
                            :disabled="hostActionBusy"
                            class="text-xs bg-black/60 hover:bg-red-900/80 rounded px-2 py-1 disabled:opacity-50"
                            @click="removeParticipant(peer)"
                        >
                            Remove
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-center gap-3 p-4 border-t border-neutral-800">
                <button
                    type="button"
                    class="rounded-full px-4 py-2 text-sm font-medium border border-neutral-800"
                    :class="micOn ? 'bg-neutral-900' : 'bg-red-900/50 border-red-800'"
                    @click="toggleMic"
                >
                    {{ micOn ? 'Mute' : 'Unmute' }}
                </button>
                <button
                    type="button"
                    class="rounded-full px-4 py-2 text-sm font-medium border border-neutral-800"
                    :class="cameraOn ? 'bg-neutral-900' : 'bg-red-900/50 border-red-800'"
                    @click="toggleCamera"
                >
                    {{ cameraOn ? 'Stop camera' : 'Start camera' }}
                </button>
                <button
                    v-if="screenShareSupported"
                    type="button"
                    :disabled="screenShareBusy"
                    class="rounded-full px-4 py-2 text-sm font-medium border border-neutral-800 disabled:opacity-50"
                    :class="screenSharing ? 'bg-blue-900/50 border-blue-800' : 'bg-neutral-900'"
                    @click="screenSharing ? stopScreenShare() : startScreenShare()"
                >
                    {{ screenSharing ? 'Stop sharing' : 'Share screen' }}
                </button>
                <button type="button" class="rounded-full px-4 py-2 text-sm font-medium bg-red-700" @click="leaveRoom">
                    Leave
                </button>
            </div>
        </template>
    </div>
</template>

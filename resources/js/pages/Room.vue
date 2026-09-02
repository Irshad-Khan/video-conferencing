<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import api from '../lib/api';
import { useAuth } from '../stores/auth';
import type { JoinMeetingResponse } from '../types';

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
    stream: MediaStream | null;
    state: ConnectionState;
    micOn: boolean;
    cameraOn: boolean;
}

const error = ref('');
const micOn = ref(true);
const cameraOn = ref(true);
const localVideo = ref<HTMLVideoElement | null>(null);
const peers = reactive<Record<string, PeerTile>>({});

let joinData: JoinMeetingResponse | null = null;
let localStream: MediaStream | null = null;
let myId = '';
let myName = 'Participant';
let channel: EchoChannel | null = null;
const connections: Record<string, RTCPeerConnection> = {};

function iceConfig(): RTCConfiguration {
    return { iceServers: [{ urls: STUN_URLS }] };
}

function connectionUiState(state: RTCPeerConnectionState): ConnectionState {
    if (state === 'connected') return 'connected';
    if (state === 'failed') return 'failed';
    if (state === 'disconnected' || state === 'closed') return 'disconnected';
    return 'connecting';
}

function ensurePeer(id: string, name: string): RTCPeerConnection {
    if (connections[id]) return connections[id];

    peers[id] = peers[id] ?? { id, name, stream: null, state: 'connecting', micOn: true, cameraOn: true };

    const pc = new RTCPeerConnection(iceConfig());

    localStream?.getTracks().forEach((track) => pc.addTrack(track, localStream as MediaStream));

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
    channel?.whisper('webrtc-offer', { from: myId, target: id, name: myName, sdp: offer });
}

function teardownPeer(id: string): void {
    connections[id]?.close();
    delete connections[id];
    delete peers[id];
}

function attachSignalingListeners(): void {
    if (!channel) return;

    channel
        .listenForWhisper('participant.announce', (payload: { id: string; name: string }) => {
            if (payload.id === myId || connections[payload.id]) return;
            peers[payload.id] = { id: payload.id, name: payload.name, stream: null, state: 'connecting', micOn: true, cameraOn: true };
            void createOfferTo(payload.id, payload.name);
        })
        .listenForWhisper('participant.bye', (payload: { id: string }) => {
            teardownPeer(payload.id);
        })
        .listenForWhisper('webrtc-offer', async (payload: { from: string; target: string; name?: string; sdp: RTCSessionDescriptionInit }) => {
            if (payload.target !== myId) return;
            const pc = ensurePeer(payload.from, peers[payload.from]?.name ?? payload.name ?? 'Participant');
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
        .listenForWhisper('media-state', (payload: { from: string; mic: boolean; camera: boolean }) => {
            if (peers[payload.from]) {
                peers[payload.from].micOn = payload.mic;
                peers[payload.from].cameraOn = payload.camera;
            }
        });
}

function teardown(): void {
    Object.keys(connections).forEach(teardownPeer);
    localStream?.getTracks().forEach((track) => track.stop());

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
            channel?.whisper('participant.announce', { id: myId, name: myName });
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
    channel?.whisper('media-state', { from: myId, mic: micOn.value, camera: cameraOn.value });
}

function toggleCamera(): void {
    cameraOn.value = !cameraOn.value;
    localStream?.getVideoTracks().forEach((track) => (track.enabled = cameraOn.value));
    channel?.whisper('media-state', { from: myId, mic: micOn.value, camera: cameraOn.value });
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
            <div class="flex-1 grid gap-2 p-2 auto-rows-fr" :style="{ gridTemplateColumns: `repeat(${Math.max(1, Math.ceil(Math.sqrt(Object.keys(peers).length + 1)))}, minmax(0, 1fr))` }">
                <div class="relative bg-neutral-900 rounded-lg overflow-hidden">
                    <video ref="localVideo" autoplay muted playsinline class="w-full h-full object-cover"></video>
                    <div class="absolute bottom-2 left-2 text-xs bg-black/60 rounded px-2 py-1 flex items-center gap-1">
                        You
                        <span v-if="!micOn">· muted</span>
                        <span v-if="!cameraOn">· camera off</span>
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
                        <span v-if="peer.state !== 'connected'">· {{ peer.state }}</span>
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
                <button type="button" class="rounded-full px-4 py-2 text-sm font-medium bg-red-700" @click="leaveRoom">
                    Leave
                </button>
            </div>
        </template>
    </div>
</template>

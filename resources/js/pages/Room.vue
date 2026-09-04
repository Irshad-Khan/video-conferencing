<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { isAxiosError } from 'axios';
import api from '../lib/api';
import { useAuth } from '../stores/auth';
import type { ChatMessage, HandRaisePayload, HostControlPayload, JoinMeetingResponse, MediaStatePayload, ParticipantRole, ReactionPayload, WaitingParticipant, WaitingRoomPayload } from '../types';

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
    role: ParticipantRole;
    stream: MediaStream | null;
    state: ConnectionState;
    micOn: boolean;
    cameraOn: boolean;
    screenSharing: boolean;
    handRaised: boolean;
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
const chatOpen = ref(true);
const chatMessages = ref<ChatMessage[]>([]);
const chatDraft = ref('');
const chatAttachment = ref<File | null>(null);
const chatAttachmentInput = ref<HTMLInputElement | null>(null);
const chatAttachmentError = ref('');
const chatError = ref('');
const chatSending = ref(false);
const unreadCount = ref(0);
const participantsOpen = ref(true);
const myRole = ref<ParticipantRole>('participant');
const waitingRoomEnabled = ref(false);
const waitingRoomOpen = ref(false);
const waitingParticipants = ref<WaitingParticipant[]>([]);
const waitingActionBusy = ref(false);
const reactionPickerOpen = ref(false);
const myHandRaised = ref(false);
const viewMode = ref<'gallery' | 'speaker'>('gallery');
const activeSpeakerId = ref('');
const pinnedId = ref(''); // local-only viewer preference, not synced to anyone else
const spotlightedId = ref(''); // host-set, broadcast to and forced for every participant

// Theme: local-only per-device preference (localStorage), default light — the video
// tiles themselves always stay dark regardless (standard video-call convention), only
// the surrounding chrome (panels, bars, buttons) switches.
const roomTheme = ref<'light' | 'dark'>(localStorage.getItem('room_theme') === 'dark' ? 'dark' : 'light');

function toggleRoomTheme(): void {
    roomTheme.value = roomTheme.value === 'light' ? 'dark' : 'light';
    localStorage.setItem('room_theme', roomTheme.value);
}

// Live clock + call duration, shown in the room's top bar for every participant.
const now = ref(new Date());
const callStartedAt = ref<number | null>(null);
let clockTimer: ReturnType<typeof setInterval> | null = null;

const callDuration = computed(() => {
    if (!callStartedAt.value) return '00:00';
    const totalSeconds = Math.max(0, Math.floor((now.value.getTime() - callStartedAt.value) / 1000));
    const pad = (n: number) => String(n).padStart(2, '0');
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return hours > 0 ? `${pad(hours)}:${pad(minutes)}:${pad(seconds)}` : `${pad(minutes)}:${pad(seconds)}`;
});

const currentDateTime = computed(() =>
    now.value.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }),
);

// Personal notes: local-only scratchpad per meeting, never sent to the server or other
// participants — just a convenience saved in this browser via localStorage.
const notesOpen = ref(false);
const notesKey = `meeting_notes_${code}`;
const notesText = ref(localStorage.getItem(notesKey) ?? '');
watch(notesText, (value) => {
    if (value) localStorage.setItem(notesKey, value);
    else localStorage.removeItem(notesKey);
});

// Client-side call recording (see docs/RECORDING.md): composites every visible video
// tile onto a canvas and mixes every participant's audio via Web Audio, records that
// with MediaRecorder, and saves the result straight to this device — nothing is
// uploaded or stored server-side.
const recordingSupported = typeof MediaRecorder !== 'undefined' && typeof HTMLCanvasElement.prototype.captureStream === 'function';
const recording = ref(false);
const recordingSeconds = ref(0);
const recordingError = ref('');
const remoteRecording = ref<{ active: boolean; name: string }>({ active: false, name: '' });
const videoEls: Record<string, HTMLVideoElement> = {};
let mediaRecorder: MediaRecorder | null = null;
let recordedChunks: Blob[] = [];
let recordingCanvas: HTMLCanvasElement | null = null;
let recordingRAF: number | null = null;
let recordingTimer: ReturnType<typeof setInterval> | null = null;
let recordingDestination: MediaStreamAudioDestinationNode | null = null;
const recordingConnectedAudio = new Set<string>();

function formatRecordingTime(totalSeconds: number): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return `${pad(minutes)}:${pad(seconds)}`;
}

function drawRecordingFrame(): void {
    if (!recordingCanvas) return;
    const ctx = recordingCanvas.getContext('2d');
    if (ctx) {
        const tiles = Object.values(videoEls).filter((video) => video.readyState >= 2 && video.videoWidth > 0);
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(0, 0, recordingCanvas.width, recordingCanvas.height);
        if (tiles.length) {
            const cols = Math.ceil(Math.sqrt(tiles.length));
            const rows = Math.ceil(tiles.length / cols);
            const cellW = recordingCanvas.width / cols;
            const cellH = recordingCanvas.height / rows;
            tiles.forEach((video, i) => {
                const x = (i % cols) * cellW;
                const y = Math.floor(i / cols) * cellH;
                const videoRatio = video.videoWidth / video.videoHeight;
                const cellRatio = cellW / cellH;
                let sx = 0;
                let sy = 0;
                let sw = video.videoWidth;
                let sh = video.videoHeight;
                if (videoRatio > cellRatio) {
                    sw = sh * cellRatio;
                    sx = (video.videoWidth - sw) / 2;
                } else {
                    sh = sw / cellRatio;
                    sy = (video.videoHeight - sh) / 2;
                }
                ctx.drawImage(video, sx, sy, sw, sh, x, y, cellW, cellH);
            });
        }
    }
    recordingRAF = requestAnimationFrame(drawRecordingFrame);
}

function connectRecordingAudio(id: string, stream: MediaStream): void {
    if (!recording.value || !audioContext || !recordingDestination || recordingConnectedAudio.has(id)) return;
    audioContext.createMediaStreamSource(stream).connect(recordingDestination);
    recordingConnectedAudio.add(id);
}

async function startRecording(): Promise<void> {
    if (recording.value || !recordingSupported) return;
    recordingError.value = '';

    try {
        audioContext ??= new AudioContext();
        recordingDestination = audioContext.createMediaStreamDestination();
        recordingConnectedAudio.clear();
        recording.value = true; // set before connecting audio — connectRecordingAudio() guards on this being true

        if (localStream) connectRecordingAudio('__local__', localStream);
        Object.entries(peers).forEach(([id, peer]) => {
            if (peer.stream) connectRecordingAudio(id, peer.stream);
        });

        recordingCanvas = document.createElement('canvas');
        recordingCanvas.width = 1280;
        recordingCanvas.height = 720;
        drawRecordingFrame();

        const canvasStream = recordingCanvas.captureStream(15);
        const combined = new MediaStream([...canvasStream.getVideoTracks(), ...recordingDestination.stream.getAudioTracks()]);
        const mimeType = MediaRecorder.isTypeSupported('video/webm;codecs=vp9,opus') ? 'video/webm;codecs=vp9,opus' : 'video/webm';

        recordedChunks = [];
        mediaRecorder = new MediaRecorder(combined, { mimeType });
        mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) recordedChunks.push(event.data);
        };
        mediaRecorder.onstop = saveRecording;
        mediaRecorder.start();

        recordingSeconds.value = 0;
        recordingTimer = setInterval(() => {
            recordingSeconds.value += 1;
        }, 1000);
        channel?.whisper('recording-state', { active: true, name: myName });
    } catch {
        recordingError.value = 'Could not start recording in this browser.';
        stopRecordingCleanup();
    }
}

function stopRecording(): void {
    if (!recording.value) return;
    mediaRecorder?.stop();
    channel?.whisper('recording-state', { active: false, name: myName });
    stopRecordingCleanup();
}

function stopRecordingCleanup(): void {
    recording.value = false;
    if (recordingRAF) cancelAnimationFrame(recordingRAF);
    recordingRAF = null;
    if (recordingTimer) clearInterval(recordingTimer);
    recordingTimer = null;
    recordingCanvas = null;
    recordingDestination = null;
    recordingConnectedAudio.clear();
}

function saveRecording(): void {
    if (!recordedChunks.length) return;
    const blob = new Blob(recordedChunks, { type: 'video/webm' });
    recordedChunks = [];
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `meeting-${code}-${new Date().toISOString().replace(/[:.]/g, '-')}.webm`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 5000);
}

const REACTION_EMOJIS = ['👍', '❤️', '😂', '👏', '🎉'];

interface FloatingReaction {
    id: number;
    emoji: string;
    name: string;
    left: number;
}

const floatingReactions = ref<FloatingReaction[]>([]);
let reactionSeq = 0;

let joinData: JoinMeetingResponse | null = null;
let localStream: MediaStream | null = null;
let screenStream: MediaStream | null = null;
let myId = '';
let myName = 'Participant';
let myParticipantId: number | null = null;
let channel: EchoChannel | null = null;
const connections: Record<string, RTCPeerConnection> = {};
let audioContext: AudioContext | null = null;
const analysers: Record<string, { analyser: AnalyserNode; data: Uint8Array }> = {};
let speakerDetectionTimer: ReturnType<typeof setInterval> | null = null;

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

function ensurePeer(id: string, name: string, participantId: number | null = null, role: ParticipantRole = 'participant'): RTCPeerConnection {
    if (connections[id]) return connections[id];

    peers[id] = peers[id] ?? { id, name, participantId, role, stream: null, state: 'connecting', micOn: true, cameraOn: true, screenSharing: false, handRaised: false };

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
        attachAnalyser(id, peers[id].stream);
        if (peers[id].stream) connectRecordingAudio(id, peers[id].stream as MediaStream);
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
    channel?.whisper('webrtc-offer', {
        from: myId,
        target: id,
        name: myName,
        participantId: myParticipantId,
        role: joinData?.participant.role,
        sdp: offer,
    });
}

function teardownPeer(id: string): void {
    connections[id]?.close();
    delete connections[id];
    delete peers[id];
    delete analysers[id];
    delete videoEls[id];
    recordingConnectedAudio.delete(id);
}

/**
 * Taps a stream's audio track with a Web Audio AnalyserNode so detectActiveSpeaker()
 * can compare real volume levels — this is genuine level detection, not a stand-in;
 * it just can't be meaningfully live-verified in this headless-Chrome test harness,
 * since --use-fake-device-for-media-stream produces identical synthetic audio for
 * every participant, so no one is ever actually "louder" than anyone else there.
 */
function attachAnalyser(id: string, stream: MediaStream | null): void {
    if (!stream || stream.getAudioTracks().length === 0) return;
    try {
        audioContext ??= new AudioContext();
        const source = audioContext.createMediaStreamSource(stream);
        const analyser = audioContext.createAnalyser();
        analyser.fftSize = 256;
        source.connect(analyser);
        analysers[id] = { analyser, data: new Uint8Array(analyser.frequencyBinCount) };
    } catch {
        // stream not ready yet, or already tapped — detection just skips this id
    }
}

function detectActiveSpeaker(): void {
    let loudestId = '';
    let loudestLevel = 20; // silence threshold, above typical ambient/encoding noise floor
    for (const id in analysers) {
        const entry = analysers[id];
        entry.analyser.getByteFrequencyData(entry.data);
        let sum = 0;
        for (let i = 0; i < entry.data.length; i++) sum += entry.data[i];
        const avg = sum / entry.data.length;
        if (avg > loudestLevel) {
            loudestLevel = avg;
            loudestId = id;
        }
    }
    if (loudestId) activeSpeakerId.value = loudestId;
}

function toggleViewMode(): void {
    viewMode.value = viewMode.value === 'gallery' ? 'speaker' : 'gallery';
}

function setLocalVideoEl(el: Element | null): void {
    localVideo.value = el as HTMLVideoElement | null;
    if (localVideo.value) {
        localVideo.value.srcObject = screenStream ?? localStream;
        videoEls.__local__ = localVideo.value;
    } else {
        delete videoEls.__local__;
    }
}

// Speaker view's main tile: the loudest peer detected so far, falling back to the
// first peer in the roster (a stable, deterministic choice) until detection kicks in
// or if no one has crossed the silence threshold. Local video never becomes the main
// tile — it stays in the thumbnail strip, the same self-view-stays-small convention
// most video-call apps use — except when there are no peers at all (solo in the call).
const activePeerTile = computed(() => {
    const peerIds = Object.keys(peers);
    if (peerIds.length === 0) return null;
    // Priority: host spotlight (forced for everyone) > this viewer's own pin (local-only)
    // > detected active speaker > first peer, as a stable deterministic fallback.
    for (const id of [spotlightedId.value, pinnedId.value, activeSpeakerId.value]) {
        if (id && peers[id]) return peers[id];
    }
    return peers[peerIds[0]];
});

// manageSettings/manageParticipants-level abilities (lock/guests/waiting-room toggle,
// mute/remove/spotlight/admit/reject) extend to a promoted co-host, matching the
// server-side MeetingPolicy — but NOT manageLifecycle (end meeting) or manageCoHosts
// (promote/demote), which stay strictly isHost-gated below, on purpose.
const canManage = computed(() => isHost.value || myRole.value === 'co_host');

function attachSignalingListeners(): void {
    if (!channel) return;

    channel
        .listenForWhisper('participant.announce', (payload: { id: string; name: string; participantId?: number; role?: ParticipantRole }) => {
            if (payload.id === myId || connections[payload.id]) return;
            peers[payload.id] = {
                id: payload.id,
                name: payload.name,
                participantId: payload.participantId ?? null,
                role: payload.role ?? 'participant',
                stream: null,
                state: 'connecting',
                micOn: true,
                cameraOn: true,
                screenSharing: false,
                handRaised: false,
            };
            void createOfferTo(payload.id, payload.name);
            // A whisper has no history — a participant joining after recording already
            // started would never see the consent banner otherwise, so re-announce it.
            if (recording.value) {
                channel?.whisper('recording-state', { active: true, name: myName });
            }
        })
        .listenForWhisper('participant.bye', (payload: { id: string }) => {
            teardownPeer(payload.id);
        })
        .listenForWhisper('webrtc-offer', async (payload: { from: string; target: string; name?: string; participantId?: number; role?: ParticipantRole; sdp: RTCSessionDescriptionInit }) => {
            if (payload.target !== myId) return;
            const pc = ensurePeer(
                payload.from,
                peers[payload.from]?.name ?? payload.name ?? 'Participant',
                payload.participantId ?? null,
                payload.role ?? 'participant',
            );
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
        .listenForWhisper('reaction', (payload: ReactionPayload) => {
            spawnReaction(payload.emoji, peers[payload.from]?.name ?? 'Someone');
        })
        .listenForWhisper('hand-raise', (payload: HandRaisePayload) => {
            if (peers[payload.from]) {
                peers[payload.from].handRaised = payload.raised;
            }
        })
        .listenForWhisper('recording-state', (payload: { active: boolean; name: string }) => {
            remoteRecording.value = payload.active ? { active: true, name: payload.name } : { active: false, name: '' };
        })
        .listen('.host.control', (payload: HostControlPayload) => {
            handleHostControl(payload);
        })
        .listen('.chat.message', (payload: ChatMessage) => {
            chatMessages.value.push(payload);
            if (!chatOpen.value) {
                unreadCount.value += 1;
            }
        })
        .listen('.waiting-room', (payload: WaitingRoomPayload) => {
            if (!canManage.value) return;
            if (payload.action === 'participant-waiting' && payload.participant) {
                waitingParticipants.value.push(payload.participant);
            } else if (payload.action === 'participant-left-waiting' && payload.id !== undefined) {
                waitingParticipants.value = waitingParticipants.value.filter((p) => p.id !== payload.id);
            }
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
        case 'waiting-room-enabled':
            waitingRoomEnabled.value = true;
            break;
        case 'waiting-room-disabled':
            waitingRoomEnabled.value = false;
            break;
        case 'spotlight':
            spotlightedId.value = payload.target ?? '';
            break;
        case 'spotlight-cleared':
            spotlightedId.value = '';
            break;
        case 'co-host-promoted':
            if (payload.target === myId) {
                myRole.value = 'co_host';
                // Also update the join snapshot itself, not just the reactive ref —
                // createOfferTo() reads joinData.participant.role fresh for every new
                // peer's offer, so without this, anyone connecting to us *after* this
                // promotion would still see us announced as a plain 'participant'.
                if (joinData) joinData.participant.role = 'co_host';
                notice.value = 'The host made you a co-host.';
            }
            if (payload.target && peers[payload.target]) {
                peers[payload.target].role = 'co_host';
            }
            break;
        case 'co-host-demoted':
            if (payload.target === myId) {
                myRole.value = 'participant';
                if (joinData) joinData.participant.role = 'participant';
                notice.value = 'The host removed your co-host access.';
            }
            if (payload.target && peers[payload.target]) {
                peers[payload.target].role = 'participant';
            }
            break;
    }
}

function spawnReaction(emoji: string, name: string): void {
    const id = ++reactionSeq;
    const left = 10 + Math.random() * 80;
    floatingReactions.value.push({ id, emoji, name, left });
    setTimeout(() => {
        floatingReactions.value = floatingReactions.value.filter((r) => r.id !== id);
    }, 2500);
}

function toggleHand(): void {
    myHandRaised.value = !myHandRaised.value;
    channel?.whisper('hand-raise', { from: myId, raised: myHandRaised.value });
}

function sendReaction(emoji: string): void {
    // Whispers aren't echoed back to the sender by Reverb/Pusher's protocol, so the
    // sender spawns their own copy locally rather than waiting for a round-trip.
    channel?.whisper('reaction', { from: myId, emoji });
    spawnReaction(emoji, 'You');
    reactionPickerOpen.value = false;
}

function teardown(): void {
    if (recording.value) stopRecording();

    Object.keys(connections).forEach(teardownPeer);
    localStream?.getTracks().forEach((track) => track.stop());
    screenStream?.getTracks().forEach((track) => track.stop());
    screenStream = null;

    if (speakerDetectionTimer) {
        clearInterval(speakerDetectionTimer);
        speakerDetectionTimer = null;
    }
    if (clockTimer) {
        clearInterval(clockTimer);
        clockTimer = null;
    }
    delete analysers[myId];
    void audioContext?.close();
    audioContext = null;

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
    myRole.value = joinData.participant.role;
    meetingLocked.value = joinData.meeting.is_locked;
    guestsAllowed.value = joinData.meeting.allow_guests;
    waitingRoomEnabled.value = joinData.meeting.waiting_room_enabled;

    try {
        localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: true });
    } catch {
        error.value = 'Camera and microphone access is required to join this call.';
        return;
    }

    if (localVideo.value) {
        localVideo.value.srcObject = localStream;
    }
    attachAnalyser(myId, localStream);
    speakerDetectionTimer = setInterval(detectActiveSpeaker, 700);

    callStartedAt.value = Date.now();
    clockTimer = setInterval(() => {
        now.value = new Date();
    }, 1000);

    const guestToken = sessionStorage.getItem(guestTokenKey);
    if (guestToken) {
        window.Echo.connector.options.auth.headers['X-Guest-Token'] = guestToken;
    }

    channel = window.Echo.private(`meeting.${joinData.meeting.meeting_uuid}`);
    attachSignalingListeners();
    channel
        .subscribed(() => {
            channel?.whisper('participant.announce', {
                id: myId,
                name: myName,
                participantId: myParticipantId,
                role: joinData?.participant.role,
            });
        })
        .error(() => {
            error.value = 'Could not connect to the meeting room. Please try again.';
        });

    void loadChatHistory();
    void loadWaitingParticipants();
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

async function loadChatHistory(): Promise<void> {
    const guestToken = sessionStorage.getItem(guestTokenKey);

    try {
        const { data } = await api.get<{ data: ChatMessage[] }>(`/meetings/${code}/messages`, {
            params: guestToken ? { guest_token: guestToken } : {},
        });
        chatMessages.value = data.data;
    } catch {
        // chat history is a nice-to-have on entry — the live channel still delivers new messages either way
    }
}

function toggleChat(): void {
    chatOpen.value = !chatOpen.value;
    if (chatOpen.value) {
        unreadCount.value = 0;
    }
}

function toggleParticipants(): void {
    participantsOpen.value = !participantsOpen.value;
}

function roleLabel(role: ParticipantRole): string {
    if (role === 'host') return 'Host';
    if (role === 'co_host') return 'Co-host';
    if (role === 'guest') return 'Guest';
    return 'Participant';
}

function onAttachmentSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    chatAttachmentError.value = '';
    chatAttachment.value = input.files?.[0] ?? null;
}

function clearAttachment(): void {
    chatAttachment.value = null;
    if (chatAttachmentInput.value) chatAttachmentInput.value.value = '';
}

function attachmentUrl(attachment: { url: string }): string {
    const guestToken = sessionStorage.getItem(guestTokenKey);
    return guestToken ? `${attachment.url}?guest_token=${encodeURIComponent(guestToken)}` : attachment.url;
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

async function sendChatMessage(): Promise<void> {
    const text = chatDraft.value.trim();
    if ((!text && !chatAttachment.value) || chatSending.value) return;

    chatSending.value = true;
    chatError.value = '';
    chatAttachmentError.value = '';
    const guestToken = sessionStorage.getItem(guestTokenKey);

    const form = new FormData();
    if (text) form.append('message', text);
    if (chatAttachment.value) form.append('attachment', chatAttachment.value);
    if (guestToken) form.append('guest_token', guestToken);

    try {
        await api.post(`/meetings/${code}/messages`, form);
        chatDraft.value = '';
        clearAttachment();
    } catch (error) {
        if (isAxiosError(error) && error.response?.status === 422) {
            chatAttachmentError.value = error.response.data?.errors?.attachment?.[0] ?? 'Could not send your message. Please try again.';
        } else {
            chatError.value = 'Could not send your message. Please try again.';
        }
    } finally {
        chatSending.value = false;
    }
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

async function toggleWaitingRoom(): Promise<void> {
    if (!joinData || hostActionBusy.value) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    // See toggleLock() above for why this captures the target value up front
    // instead of inverting the live ref after the request resolves.
    const wasEnabled = waitingRoomEnabled.value;

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/waiting-room/${wasEnabled ? 'disable' : 'enable'}`);
        waitingRoomEnabled.value = !wasEnabled;
    } catch {
        hostActionError.value = 'Could not update the waiting room. Please try again.';
    } finally {
        hostActionBusy.value = false;
    }
}

async function loadWaitingParticipants(): Promise<void> {
    if (!joinData || !canManage.value) return;

    try {
        const { data } = await api.get<{ data: WaitingParticipant[] }>(`/meetings/${joinData.meeting.meeting_uuid}/participants/waiting`);
        waitingParticipants.value = data.data;
    } catch {
        // the live .waiting-room channel event still delivers new waiters either way
    }
}

function toggleWaitingRoomPanel(): void {
    waitingRoomOpen.value = !waitingRoomOpen.value;
}

async function admitParticipant(participant: WaitingParticipant): Promise<void> {
    if (!joinData || waitingActionBusy.value) return;
    waitingActionBusy.value = true;
    hostActionError.value = '';

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/participants/${participant.id}/admit`);
        waitingParticipants.value = waitingParticipants.value.filter((p) => p.id !== participant.id);
    } catch {
        hostActionError.value = `Could not admit ${participant.name}. Please try again.`;
    } finally {
        waitingActionBusy.value = false;
    }
}

async function rejectParticipant(participant: WaitingParticipant): Promise<void> {
    if (!joinData || waitingActionBusy.value) return;
    waitingActionBusy.value = true;
    hostActionError.value = '';

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/participants/${participant.id}/reject`);
        waitingParticipants.value = waitingParticipants.value.filter((p) => p.id !== participant.id);
    } catch {
        hostActionError.value = `Could not reject ${participant.name}. Please try again.`;
    } finally {
        waitingActionBusy.value = false;
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

async function promoteToCoHost(peer: PeerTile): Promise<void> {
    if (!joinData || !peer.participantId || hostActionBusy.value) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/participants/${peer.participantId}/promote`);
    } catch {
        hostActionError.value = `Could not make ${peer.name} a co-host. Please try again.`;
    } finally {
        hostActionBusy.value = false;
    }
}

async function demoteFromCoHost(peer: PeerTile): Promise<void> {
    if (!joinData || !peer.participantId || hostActionBusy.value) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/participants/${peer.participantId}/demote`);
    } catch {
        hostActionError.value = `Could not remove ${peer.name}'s co-host access. Please try again.`;
    } finally {
        hostActionBusy.value = false;
    }
}

async function spotlightParticipant(peer: PeerTile): Promise<void> {
    if (!joinData || !peer.participantId || hostActionBusy.value) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/participants/${peer.participantId}/spotlight`);
    } catch {
        hostActionError.value = `Could not spotlight ${peer.name}. Please try again.`;
    } finally {
        hostActionBusy.value = false;
    }
}

async function clearSpotlight(): Promise<void> {
    if (!joinData || hostActionBusy.value) return;
    hostActionBusy.value = true;
    hostActionError.value = '';

    try {
        await api.post(`/meetings/${joinData.meeting.meeting_uuid}/spotlight/clear`);
    } catch {
        hostActionError.value = 'Could not clear the spotlight. Please try again.';
    } finally {
        hostActionBusy.value = false;
    }
}

function togglePin(peerId: string): void {
    pinnedId.value = pinnedId.value === peerId ? '' : peerId;
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
        videoEls[id] = video;
    } else if (!video) {
        delete videoEls[id];
    }
}
</script>


<template>
    <div class="h-screen bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 flex flex-col overflow-hidden" :class="{ dark: roomTheme === 'dark' }">
        <div v-if="error" class="flex-1 flex items-center justify-center px-6">
            <p class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        </div>

        <template v-else>
            <div class="flex items-center justify-between gap-3 px-3 sm:px-4 py-2 border-b border-slate-200 dark:border-slate-800/80 bg-white dark:bg-slate-900/40 text-xs shrink-0">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 shrink-0"></span>
                    <p class="font-medium text-slate-700 dark:text-slate-300 truncate">{{ joinData?.meeting.title ?? 'Meeting' }}</p>
                </div>

                <div class="hidden sm:flex items-center gap-3 text-slate-500 dark:text-slate-400 shrink-0">
                    <span>{{ currentDateTime }}</span>
                    <span class="tabular-nums">⏱ {{ callDuration }}</span>
                </div>

                <div class="flex items-center gap-1.5 shrink-0">
                    <button
                        type="button"
                        v-if="recordingSupported"
                        class="flex items-center gap-1.5 rounded-full px-2.5 py-1 border border-slate-300 dark:border-slate-800 transition-colors"
                        :class="recording ? 'bg-red-50 border-red-300 text-red-700 dark:bg-red-900/50 dark:border-red-800 dark:text-red-300' : 'bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800'"
                        @click="recording ? stopRecording() : startRecording()"
                    >
                        <span class="h-2 w-2 rounded-full bg-red-500" :class="recording ? 'animate-pulse' : ''"></span>
                        {{ recording ? formatRecordingTime(recordingSeconds) : 'Record' }}
                    </button>
                    <button
                        type="button"
                        class="relative rounded-full p-1.5 border border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        :class="notesText ? 'text-amber-600 dark:text-amber-400' : ''"
                        title="Sticky notes (saved on this device only)"
                        @click="notesOpen = !notesOpen"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M9 3v4h6V3"/><path d="M9 13h6M9 17h4"/></svg>
                    </button>
                    <button
                        type="button"
                        class="rounded-full p-1.5 border border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
                        title="Switch theme"
                        @click="toggleRoomTheme"
                    >
                        <svg v-if="roomTheme === 'light'" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
                    </button>
                </div>
            </div>

            <p v-if="recording" class="text-xs text-center bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300 py-1.5">
                🔴 You're recording this meeting — it will save to your device when you stop.
            </p>
            <p v-else-if="remoteRecording.active" class="text-xs text-center bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300 py-1.5">
                🔴 {{ remoteRecording.name }} is recording this meeting.
            </p>
            <p v-if="recordingError" class="text-xs text-center bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300 py-1.5">{{ recordingError }}</p>
            <p v-if="notice" class="text-xs text-center bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300 py-1.5">{{ notice }}</p>
            <p v-if="hostActionError" class="text-xs text-center bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300 py-1.5">{{ hostActionError }}</p>
            <p v-if="screenShareError" class="text-xs text-center bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300 py-1.5">{{ screenShareError }}</p>

            <div
                v-if="notesOpen"
                class="fixed top-14 right-3 z-40 w-64 rounded-lg bg-amber-100 dark:bg-amber-200 border border-amber-300 shadow-lg p-3 rotate-[0.3deg]"
            >
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-xs font-medium text-amber-900 flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M9 3v4h6V3"/><path d="M9 13h6M9 17h4"/></svg>
                        Sticky notes
                    </p>
                    <button type="button" class="text-amber-700 hover:text-amber-900 text-xs" @click="notesOpen = false">Close</button>
                </div>
                <textarea
                    v-model="notesText"
                    rows="8"
                    placeholder="Jot anything down — only visible to you, saved on this device."
                    class="w-full resize-none rounded-md bg-amber-50 border border-amber-200 px-2.5 py-2 text-sm text-amber-950 placeholder:text-amber-700/60 focus:outline-none focus:border-amber-400"
                ></textarea>
            </div>

            <div v-if="canManage" class="flex flex-wrap items-center justify-center gap-1.5 px-3 py-1.5 border-b border-slate-200 dark:border-slate-800/80 bg-slate-100/70 dark:bg-slate-900/40 text-xs shrink-0">
                <button
                    type="button"
                    :disabled="hostActionBusy"
                    class="rounded-full px-3 py-1 border border-slate-300 dark:border-slate-800 disabled:opacity-50"
                    :class="meetingLocked ? 'bg-red-50 border-red-300 dark:bg-red-900/50 dark:border-red-800' : 'bg-white dark:bg-slate-900'"
                    @click="toggleLock"
                >
                    {{ meetingLocked ? 'Unlock meeting' : 'Lock meeting' }}
                </button>
                <button
                    type="button"
                    :disabled="hostActionBusy"
                    class="rounded-full px-3 py-1 border border-slate-300 dark:border-slate-800 disabled:opacity-50"
                    :class="guestsAllowed ? 'bg-white dark:bg-slate-900' : 'bg-red-50 border-red-300 dark:bg-red-900/50 dark:border-red-800'"
                    @click="toggleGuests"
                >
                    {{ guestsAllowed ? 'Disallow guests' : 'Allow guests' }}
                </button>
                <button
                    type="button"
                    :disabled="hostActionBusy"
                    class="rounded-full px-3 py-1 border border-slate-300 dark:border-slate-800 disabled:opacity-50"
                    :class="waitingRoomEnabled ? 'bg-red-50 border-red-300 dark:bg-red-900/50 dark:border-red-800' : 'bg-white dark:bg-slate-900'"
                    @click="toggleWaitingRoom"
                >
                    {{ waitingRoomEnabled ? 'Disable waiting room' : 'Enable waiting room' }}
                </button>
                <button
                    v-if="isHost"
                    type="button"
                    :disabled="hostActionBusy"
                    class="rounded-full px-3 py-1 bg-red-700 disabled:opacity-50"
                    @click="endMeetingForAll"
                >
                    End meeting for all
                </button>
            </div>

            <div class="flex-1 flex min-h-0 relative">
                <div class="absolute inset-x-0 bottom-0 h-2/3 pointer-events-none overflow-hidden z-20">
                    <div
                        v-for="r in floatingReactions"
                        :key="r.id"
                        class="absolute bottom-0 flex flex-col items-center reaction-float"
                        :style="{ left: r.left + '%' }"
                    >
                        <span class="text-3xl leading-none">{{ r.emoji }}</span>
                        <span class="text-[10px] text-slate-300 bg-slate-950/70 rounded px-1 mt-1">{{ r.name }}</span>
                    </div>
                </div>
                <div v-if="viewMode === 'gallery'" class="flex-1 grid gap-2 p-2 auto-rows-fr" :style="{ gridTemplateColumns: `repeat(${Math.max(1, Math.ceil(Math.sqrt(Object.keys(peers).length + 1)))}, minmax(0, 1fr))` }">
                    <div class="relative bg-slate-900 rounded-lg overflow-hidden">
                        <video :ref="setLocalVideoEl" autoplay muted playsinline class="w-full h-full object-cover"></video>
                        <div v-if="myHandRaised" class="absolute top-2 left-2 text-lg bg-black/60 rounded px-1.5 py-0.5">✋</div>
                        <div class="absolute bottom-2 left-2 text-xs bg-black/60 rounded px-2 py-1 flex items-center gap-1">
                            You
                            <span v-if="!micOn">· muted</span>
                            <span v-if="!cameraOn">· camera off</span>
                            <span v-if="screenSharing">· presenting</span>
                            <span v-if="spotlightedId === myId">· spotlighted</span>
                        </div>
                    </div>

                    <div v-for="peer in peers" :key="peer.id" class="relative bg-slate-900 rounded-lg overflow-hidden">
                        <video
                            v-if="peer.stream"
                            :ref="(el) => setPeerVideoEl(peer.id, el)"
                            autoplay
                            playsinline
                            class="w-full h-full object-cover"
                        ></video>
                        <div v-else class="w-full h-full flex items-center justify-center text-sm text-slate-500">
                            {{ peer.state === 'connecting' ? 'Connecting…' : peer.state }}
                        </div>
                        <div v-if="peer.handRaised" class="absolute top-2 left-2 text-lg bg-black/60 rounded px-1.5 py-0.5">✋</div>
                        <div class="absolute bottom-2 left-2 text-xs bg-black/60 rounded px-2 py-1 flex items-center gap-1">
                            {{ peer.name }}
                            <span v-if="!peer.micOn">· muted</span>
                            <span v-if="!peer.cameraOn">· camera off</span>
                            <span v-if="peer.screenSharing">· presenting</span>
                            <span v-if="spotlightedId === peer.id">· spotlighted</span>
                            <span v-else-if="pinnedId === peer.id">· pinned</span>
                            <span v-if="peer.state !== 'connected'">· {{ peer.state }}</span>
                        </div>
                        <div v-if="canManage && peer.participantId" class="absolute top-2 right-2 flex items-center gap-1">
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

                <div v-else class="flex-1 flex flex-col gap-2 p-2 min-h-0">
                    <div class="flex-1 relative bg-slate-900 rounded-lg overflow-hidden">
                        <template v-if="activePeerTile">
                            <video
                                v-if="activePeerTile.stream"
                                :ref="(el) => activePeerTile && setPeerVideoEl(activePeerTile.id, el)"
                                autoplay
                                playsinline
                                class="w-full h-full object-cover"
                            ></video>
                            <div v-else class="w-full h-full flex items-center justify-center text-sm text-slate-500">
                                {{ activePeerTile.state === 'connecting' ? 'Connecting…' : activePeerTile.state }}
                            </div>
                            <div v-if="activePeerTile.handRaised" class="absolute top-2 left-2 text-lg bg-black/60 rounded px-1.5 py-0.5">✋</div>
                            <div class="absolute bottom-2 left-2 text-xs bg-black/60 rounded px-2 py-1 flex items-center gap-1">
                                {{ activePeerTile.name }}
                                <span v-if="!activePeerTile.micOn">· muted</span>
                                <span v-if="!activePeerTile.cameraOn">· camera off</span>
                                <span v-if="activePeerTile.screenSharing">· presenting</span>
                                <span v-if="spotlightedId === activePeerTile.id">· spotlighted</span>
                                <span v-else-if="pinnedId === activePeerTile.id">· pinned</span>
                            </div>
                        </template>
                        <template v-else>
                            <video :ref="setLocalVideoEl" autoplay muted playsinline class="w-full h-full object-cover"></video>
                            <div v-if="myHandRaised" class="absolute top-2 left-2 text-lg bg-black/60 rounded px-1.5 py-0.5">✋</div>
                            <div class="absolute bottom-2 left-2 text-xs bg-black/60 rounded px-2 py-1 flex items-center gap-1">
                                You
                                <span v-if="!micOn">· muted</span>
                                <span v-if="!cameraOn">· camera off</span>
                                <span v-if="spotlightedId === myId">· spotlighted</span>
                            </div>
                        </template>
                    </div>

                    <div class="flex gap-2 h-24 shrink-0 overflow-x-auto">
                        <div v-if="activePeerTile" class="relative w-32 h-full shrink-0 bg-slate-900 rounded-lg overflow-hidden">
                            <video :ref="setLocalVideoEl" autoplay muted playsinline class="w-full h-full object-cover"></video>
                            <div v-if="myHandRaised" class="absolute top-1 left-1 text-sm bg-black/60 rounded px-1">✋</div>
                            <div class="absolute bottom-1 left-1 text-[10px] bg-black/60 rounded px-1">
                                You<span v-if="spotlightedId === myId"> · spotlighted</span>
                            </div>
                        </div>
                        <div
                            v-for="peer in peers"
                            v-show="!activePeerTile || peer.id !== activePeerTile.id"
                            :key="peer.id"
                            class="relative w-32 h-full shrink-0 bg-slate-900 rounded-lg overflow-hidden"
                        >
                            <video
                                v-if="peer.stream"
                                :ref="(el) => setPeerVideoEl(peer.id, el)"
                                autoplay
                                playsinline
                                class="w-full h-full object-cover"
                            ></video>
                            <div v-if="peer.handRaised" class="absolute top-1 left-1 text-sm bg-black/60 rounded px-1">✋</div>
                            <div class="absolute bottom-1 left-1 text-[10px] bg-black/60 rounded px-1 truncate max-w-[90%]">{{ peer.name }}</div>
                        </div>
                    </div>
                </div>

                <aside v-if="chatOpen" class="w-72 shrink-0 border-l border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900/30 flex flex-col">
                    <div class="px-3 py-2 border-b border-slate-300 dark:border-slate-800 text-sm font-medium flex items-center justify-between">
                        Chat
                        <button type="button" class="text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 text-xs" @click="toggleChat">Close</button>
                    </div>
                    <div class="flex-1 overflow-y-auto px-3 py-2 space-y-3">
                        <p v-if="!chatMessages.length" class="text-xs text-slate-500">No messages yet.</p>
                        <div v-for="msg in chatMessages" :key="msg.id">
                            <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1">
                                <span class="font-medium text-slate-600 dark:text-slate-300">{{ msg.presence_id === myId ? 'You' : msg.sender_name }}</span>
                                <span>{{ new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
                            </div>
                            <p v-if="msg.message" class="text-sm text-slate-900 dark:text-slate-100 break-words whitespace-pre-wrap">{{ msg.message }}</p>
                            <a
                                v-if="msg.attachment && msg.attachment.is_image"
                                :href="attachmentUrl(msg.attachment)"
                                target="_blank"
                                class="block mt-1"
                            >
                                <img :src="attachmentUrl(msg.attachment)" class="max-w-full max-h-40 rounded border border-slate-300 dark:border-slate-800" />
                            </a>
                            <a
                                v-else-if="msg.attachment"
                                :href="attachmentUrl(msg.attachment)"
                                target="_blank"
                                class="mt-1 flex items-center gap-1.5 text-xs bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-md px-2 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 w-fit"
                            >
                                📎 {{ msg.attachment.name }}
                                <span class="text-slate-500">({{ formatFileSize(msg.attachment.size) }})</span>
                            </a>
                        </div>
                    </div>
                    <p v-if="chatError" class="text-xs text-red-400 px-3">{{ chatError }}</p>
                    <p v-if="chatAttachmentError" class="text-xs text-red-400 px-3">{{ chatAttachmentError }}</p>
                    <div v-if="chatAttachment" class="px-3 pt-2 flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                        📎 {{ chatAttachment.name }}
                        <button type="button" class="text-slate-500 hover:text-slate-600 dark:hover:text-slate-300" @click="clearAttachment">✕</button>
                    </div>
                    <form class="p-3 border-t border-slate-300 dark:border-slate-800 flex items-center gap-2" @submit.prevent="sendChatMessage">
                        <input
                            ref="chatAttachmentInput"
                            type="file"
                            class="hidden"
                            accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.zip"
                            @change="onAttachmentSelected"
                        />
                        <button
                            type="button"
                            class="shrink-0 rounded-md border border-slate-300 dark:border-slate-800 px-2 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-900"
                            title="Attach a file"
                            @click="chatAttachmentInput?.click()"
                        >
                            📎
                        </button>
                        <input
                            v-model="chatDraft"
                            type="text"
                            maxlength="2000"
                            placeholder="Message everyone"
                            class="flex-1 rounded-md bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 px-3 py-2 text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30"
                        />
                        <button
                            type="submit"
                            :disabled="chatSending || (!chatDraft.trim() && !chatAttachment)"
                            class="rounded-md bg-indigo-600 text-white hover:bg-indigo-500 text-sm font-medium px-3 py-2 disabled:opacity-50"
                        >
                            Send
                        </button>
                    </form>
                </aside>

                <aside v-if="participantsOpen" class="w-72 shrink-0 border-l border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900/30 flex flex-col">
                    <div class="px-3 py-2 border-b border-slate-300 dark:border-slate-800 text-sm font-medium flex items-center justify-between">
                        Participants ({{ Object.keys(peers).length + 1 }})
                        <button type="button" class="text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 text-xs" @click="toggleParticipants">Close</button>
                    </div>
                    <div class="flex-1 overflow-y-auto px-3 py-2 space-y-1">
                        <div class="flex items-center justify-between gap-2 py-2 border-b border-slate-100 dark:border-slate-900">
                            <div class="min-w-0">
                                <p class="text-sm text-slate-900 dark:text-slate-100 truncate">
                                    You
                                    <span class="text-xs text-slate-500">({{ roleLabel(myRole) }})</span>
                                    <span v-if="myHandRaised">✋</span>
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ micOn ? 'Mic on' : 'Mic off' }} · {{ cameraOn ? 'Camera on' : 'Camera off' }}
                                    <span v-if="spotlightedId === myId">· spotlighted</span>
                                </p>
                            </div>
                        </div>

                        <div v-for="peer in peers" :key="peer.id" class="flex items-center justify-between gap-2 py-2 border-b border-slate-100 dark:border-slate-900">
                            <div class="min-w-0">
                                <p class="text-sm text-slate-900 dark:text-slate-100 truncate">
                                    {{ peer.name }}
                                    <span class="text-xs text-slate-500">({{ roleLabel(peer.role) }})</span>
                                    <span v-if="peer.handRaised">✋</span>
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ peer.micOn ? 'Mic on' : 'Mic off' }} · {{ peer.cameraOn ? 'Camera on' : 'Camera off' }}
                                    <span v-if="peer.state !== 'connected'">· {{ peer.state }}</span>
                                </p>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button
                                    type="button"
                                    class="text-xs rounded px-2 py-1"
                                    :class="pinnedId === peer.id ? 'bg-indigo-600/30 hover:bg-indigo-600/50' : 'bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                    @click="togglePin(peer.id)"
                                >
                                    {{ pinnedId === peer.id ? 'Unpin' : 'Pin' }}
                                </button>
                                <button
                                    v-if="isHost && peer.participantId && peer.role !== 'guest'"
                                    type="button"
                                    :disabled="hostActionBusy"
                                    class="text-xs rounded px-2 py-1 disabled:opacity-50"
                                    :class="peer.role === 'co_host' ? 'bg-purple-100 hover:bg-purple-200 dark:bg-purple-900/60 dark:hover:bg-purple-900/80' : 'bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                    @click="peer.role === 'co_host' ? demoteFromCoHost(peer) : promoteToCoHost(peer)"
                                >
                                    {{ peer.role === 'co_host' ? 'Remove co-host' : 'Make co-host' }}
                                </button>
                                <template v-if="canManage && peer.participantId">
                                    <button
                                        type="button"
                                        :disabled="hostActionBusy"
                                        class="text-xs bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800 rounded px-2 py-1 disabled:opacity-50"
                                        @click="muteParticipant(peer)"
                                    >
                                        Mute
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="hostActionBusy"
                                        class="text-xs rounded px-2 py-1 disabled:opacity-50"
                                        :class="spotlightedId === peer.id ? 'bg-amber-100 hover:bg-amber-200 dark:bg-amber-900/60 dark:hover:bg-amber-900/80' : 'bg-white dark:bg-slate-900 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                        @click="spotlightedId === peer.id ? clearSpotlight() : spotlightParticipant(peer)"
                                    >
                                        {{ spotlightedId === peer.id ? 'Unspotlight' : 'Spotlight' }}
                                    </button>
                                    <button
                                        type="button"
                                        :disabled="hostActionBusy"
                                        class="text-xs bg-white dark:bg-slate-900 hover:bg-red-900/80 rounded px-2 py-1 disabled:opacity-50"
                                        @click="removeParticipant(peer)"
                                    >
                                        Remove
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </aside>

                <aside v-if="canManage && waitingRoomOpen" class="w-72 shrink-0 border-l border-slate-300 dark:border-slate-800 bg-white dark:bg-slate-900/30 flex flex-col">
                    <div class="px-3 py-2 border-b border-slate-300 dark:border-slate-800 text-sm font-medium flex items-center justify-between">
                        Waiting Room ({{ waitingParticipants.length }})
                        <button type="button" class="text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-200 text-xs" @click="toggleWaitingRoomPanel">Close</button>
                    </div>
                    <div class="flex-1 overflow-y-auto px-3 py-2 space-y-1">
                        <p v-if="!waitingParticipants.length" class="text-xs text-slate-500">No one is waiting.</p>
                        <div
                            v-for="participant in waitingParticipants"
                            :key="participant.id"
                            class="flex items-center justify-between gap-2 py-2 border-b border-slate-100 dark:border-slate-900"
                        >
                            <p class="text-sm text-slate-900 dark:text-slate-100 truncate">{{ participant.name }}</p>
                            <div class="flex items-center gap-1 shrink-0">
                                <button
                                    type="button"
                                    :disabled="waitingActionBusy"
                                    class="text-xs bg-indigo-600 text-white hover:bg-indigo-500 rounded px-2 py-1 disabled:opacity-50"
                                    @click="admitParticipant(participant)"
                                >
                                    Admit
                                </button>
                                <button
                                    type="button"
                                    :disabled="waitingActionBusy"
                                    class="text-xs bg-white dark:bg-slate-900 hover:bg-red-900/80 rounded px-2 py-1 disabled:opacity-50"
                                    @click="rejectParticipant(participant)"
                                >
                                    Reject
                                </button>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>

            <div class="flex flex-wrap items-center justify-center gap-1.5 sm:gap-2 px-2 sm:px-4 py-2.5 border-t border-slate-200 dark:border-slate-800/80 bg-slate-100/70 dark:bg-slate-900/40 shrink-0">
                <button
                    type="button"
                    class="rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs sm:text-sm font-medium border border-slate-300 dark:border-slate-800 transition-colors"
                    :class="micOn ? 'bg-white dark:bg-slate-900' : 'bg-red-50 border-red-300 dark:bg-red-900/50 dark:border-red-800'"
                    @click="toggleMic"
                >
                    {{ micOn ? 'Mute' : 'Unmute' }}
                </button>
                <button
                    type="button"
                    class="rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs sm:text-sm font-medium border border-slate-300 dark:border-slate-800 transition-colors"
                    :class="cameraOn ? 'bg-white dark:bg-slate-900' : 'bg-red-50 border-red-300 dark:bg-red-900/50 dark:border-red-800'"
                    @click="toggleCamera"
                >
                    {{ cameraOn ? 'Stop camera' : 'Start camera' }}
                </button>
                <button
                    v-if="screenShareSupported"
                    type="button"
                    :disabled="screenShareBusy"
                    class="rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs sm:text-sm font-medium border border-slate-300 dark:border-slate-800 transition-colors disabled:opacity-50"
                    :class="screenSharing ? 'bg-indigo-600/20 border-indigo-500 text-indigo-700 dark:text-indigo-300' : 'bg-white dark:bg-slate-900'"
                    @click="screenSharing ? stopScreenShare() : startScreenShare()"
                >
                    {{ screenSharing ? 'Stop sharing' : 'Share screen' }}
                </button>
                <button
                    type="button"
                    class="rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs sm:text-sm font-medium border border-slate-300 dark:border-slate-800 transition-colors"
                    :class="participantsOpen ? 'bg-indigo-600/20 border-indigo-500 text-indigo-700 dark:text-indigo-300' : 'bg-white dark:bg-slate-900'"
                    @click="toggleParticipants"
                >
                    Participants ({{ Object.keys(peers).length + 1 }})
                </button>
                <button
                    v-if="canManage"
                    type="button"
                    class="relative rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs sm:text-sm font-medium border border-slate-300 dark:border-slate-800 transition-colors"
                    :class="waitingRoomOpen ? 'bg-indigo-600/20 border-indigo-500 text-indigo-700 dark:text-indigo-300' : 'bg-white dark:bg-slate-900'"
                    @click="toggleWaitingRoomPanel"
                >
                    Waiting Room
                    <span
                        v-if="waitingParticipants.length > 0"
                        class="absolute -top-1.5 -right-1.5 min-w-[1.1rem] h-[1.1rem] px-1 rounded-full bg-amber-600 text-white text-[10px] leading-[1.1rem]"
                    >
                        {{ waitingParticipants.length }}
                    </span>
                </button>
                <button
                    type="button"
                    class="rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs sm:text-sm font-medium border border-slate-300 dark:border-slate-800 transition-colors"
                    :class="myHandRaised ? 'bg-amber-50 border-amber-300 dark:bg-amber-900/50 dark:border-amber-800' : 'bg-white dark:bg-slate-900'"
                    @click="toggleHand"
                >
                    {{ myHandRaised ? 'Lower ✋' : 'Raise ✋' }}
                </button>
                <button
                    type="button"
                    class="rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs sm:text-sm font-medium border border-slate-300 dark:border-slate-800 transition-colors bg-white dark:bg-slate-900"
                    @click="toggleViewMode"
                >
                    {{ viewMode === 'gallery' ? 'Speaker' : 'Gallery' }}
                </button>
                <div class="relative">
                    <div
                        v-if="reactionPickerOpen"
                        class="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 flex items-center gap-1 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-800 rounded-full px-2 py-1.5"
                    >
                        <button
                            v-for="emoji in REACTION_EMOJIS"
                            :key="emoji"
                            type="button"
                            class="text-xl hover:scale-125 transition-transform px-1"
                            @click="sendReaction(emoji)"
                        >
                            {{ emoji }}
                        </button>
                    </div>
                    <button
                        type="button"
                        class="rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs sm:text-sm font-medium border border-slate-300 dark:border-slate-800 transition-colors"
                        :class="reactionPickerOpen ? 'bg-indigo-600/20 border-indigo-500 text-indigo-700 dark:text-indigo-300' : 'bg-white dark:bg-slate-900'"
                        @click="reactionPickerOpen = !reactionPickerOpen"
                    >
                        React
                    </button>
                </div>
                <button
                    type="button"
                    class="relative rounded-full px-2.5 sm:px-3.5 py-1.5 text-xs sm:text-sm font-medium border border-slate-300 dark:border-slate-800 transition-colors"
                    :class="chatOpen ? 'bg-indigo-600/20 border-indigo-500 text-indigo-700 dark:text-indigo-300' : 'bg-white dark:bg-slate-900'"
                    @click="toggleChat"
                >
                    Chat
                    <span
                        v-if="unreadCount > 0"
                        class="absolute -top-1.5 -right-1.5 min-w-[1.1rem] h-[1.1rem] px-1 rounded-full bg-red-600 text-white text-[10px] leading-[1.1rem]"
                    >
                        {{ unreadCount }}
                    </span>
                </button>
                <button type="button" class="rounded-full px-3.5 sm:px-4 py-1.5 text-xs sm:text-sm font-medium bg-red-600 hover:bg-red-500 transition-colors" @click="leaveRoom">
                    Leave
                </button>
            </div>
        </template>
    </div>
</template>

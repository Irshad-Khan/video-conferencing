export interface User {
    id: number;
    name: string;
    email: string;
    created_at: string;
    updated_at: string;
}

export interface ValidationErrorResponse {
    message: string;
    errors: Record<string, string[]>;
}

export type MeetingStatus = 'scheduled' | 'waiting' | 'live' | 'ended' | 'cancelled';

export interface Meeting {
    id: number;
    meeting_uuid: string;
    meeting_code: string;
    host_user_id: number;
    title: string;
    description: string | null;
    has_password: boolean;
    status: MeetingStatus;
    waiting_room_enabled: boolean;
    allow_guests: boolean;
    is_locked: boolean;
    scheduled_at: string | null;
    duration_minutes: number | null;
    started_at: string | null;
    ended_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface MeetingFormPayload {
    title: string;
    description?: string | null;
    password?: string | null;
    scheduled_at?: string | null;
    duration_minutes?: number | null;
    waiting_room_enabled?: boolean;
    allow_guests?: boolean;
}

export interface MeetingLookup {
    meeting_code: string;
    title: string;
    host_name: string;
    status: MeetingStatus;
    has_password: boolean;
    allow_guests: boolean;
    is_locked: boolean;
}

export type ParticipantRole = 'host' | 'co_host' | 'participant' | 'guest';
export type ParticipantStatus = 'waiting' | 'admitted' | 'joined' | 'left' | 'removed' | 'rejected';

export interface MeetingParticipant {
    id: number;
    meeting_id: number;
    user_id: number | null;
    guest_name: string | null;
    role: ParticipantRole;
    status: ParticipantStatus;
    joined_at: string | null;
    left_at: string | null;
    duration_seconds: number;
}

export interface ParticipantHistoryEntry {
    id: number;
    name: string | null;
    role: ParticipantRole;
    status: ParticipantStatus;
    joined_at: string | null;
    left_at: string | null;
    duration_seconds: number;
}

export interface JoinMeetingResponse {
    participant: MeetingParticipant;
    meeting: Pick<Meeting, 'meeting_uuid' | 'meeting_code' | 'title' | 'status' | 'is_locked' | 'allow_guests' | 'waiting_room_enabled'>;
    guest_token: string | null;
    presence_id: string;
}

export type HostControlAction =
    | 'mute'
    | 'remove'
    | 'lock'
    | 'unlock'
    | 'end'
    | 'guests-enabled'
    | 'guests-disabled'
    | 'waiting-room-enabled'
    | 'waiting-room-disabled';

export interface HostControlPayload {
    action: HostControlAction;
    target?: string;
}

export interface MediaStatePayload {
    from: string;
    mic: boolean;
    camera: boolean;
    screenSharing?: boolean;
}

export interface ChatMessage {
    id: number;
    message: string;
    sender_name: string;
    sender_role: ParticipantRole | null;
    presence_id: string | null;
    created_at: string;
}

export interface WaitingParticipant {
    id: number;
    name: string;
    role: ParticipantRole;
}

export type WaitingRoomAction = 'participant-waiting' | 'participant-left-waiting' | 'admitted' | 'rejected';

export interface WaitingRoomPayload {
    action: WaitingRoomAction;
    participant?: WaitingParticipant;
    id?: number;
    target?: string;
}

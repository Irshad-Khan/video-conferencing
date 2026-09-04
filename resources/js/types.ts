export interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    is_disabled: boolean;
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
    host_user_id: number;
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
    | 'waiting-room-disabled'
    | 'spotlight'
    | 'spotlight-cleared'
    | 'co-host-promoted'
    | 'co-host-demoted';

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

export interface ReactionPayload {
    from: string;
    emoji: string;
}

export interface HandRaisePayload {
    from: string;
    raised: boolean;
}

export interface ChatAttachment {
    url: string;
    name: string;
    mime_type: string;
    size: number;
    is_image: boolean;
}

export interface ChatMessage {
    id: number;
    message: string | null;
    sender_name: string;
    sender_role: ParticipantRole | null;
    presence_id: string | null;
    created_at: string;
    attachment: ChatAttachment | null;
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

export interface AdminStats {
    users: { total: number; admins: number; disabled: number };
    meetings: { total: number; scheduled: number; live: number; ended: number; cancelled: number };
    participants_total: number;
}

export interface AdminUserRow extends Pick<User, 'id' | 'name' | 'email' | 'is_admin' | 'is_disabled' | 'created_at'> {
    meetings_count: number;
}

export interface AdminMeetingRow extends Meeting {
    host: Pick<User, 'id' | 'name' | 'email'>;
}

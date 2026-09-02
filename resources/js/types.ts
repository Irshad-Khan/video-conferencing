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
    waiting_room_enabled?: boolean;
    allow_guests?: boolean;
}

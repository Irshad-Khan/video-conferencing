import { reactive, readonly } from 'vue';
import api, { ensureCsrfCookie } from '../lib/api';
import type { User } from '../types';

interface AuthState {
    user: User | null;
    checked: boolean;
}

const state = reactive<AuthState>({
    user: null,
    checked: false,
});

async function fetchUser(): Promise<User | null> {
    try {
        const { data } = await api.get<{ data: User }>('/profile');
        state.user = data.data;
    } catch {
        state.user = null;
    } finally {
        state.checked = true;
    }

    return state.user;
}

async function register(payload: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}): Promise<User> {
    await ensureCsrfCookie();
    const { data } = await api.post<{ data: User }>('/auth/register', payload);
    state.user = data.data;
    state.checked = true;

    return data.data;
}

async function login(payload: { email: string; password: string }): Promise<User> {
    await ensureCsrfCookie();
    const { data } = await api.post<{ data: User }>('/auth/login', payload);
    state.user = data.data;
    state.checked = true;

    return data.data;
}

async function logout(): Promise<void> {
    await api.post('/auth/logout');
    state.user = null;
}

async function updateProfile(payload: {
    name: string;
    email: string;
    current_password?: string;
    password?: string;
    password_confirmation?: string;
}): Promise<User> {
    const { data } = await api.put<{ data: User }>('/profile', payload);
    state.user = data.data;

    return data.data;
}

export function useAuth() {
    return {
        state: readonly(state),
        fetchUser,
        register,
        login,
        logout,
        updateProfile,
    };
}

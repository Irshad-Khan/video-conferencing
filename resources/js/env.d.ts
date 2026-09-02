/// <reference types="vite/client" />

declare module '*.vue' {
    import type { DefineComponent } from 'vue';
    const component: DefineComponent<{}, {}, unknown>;
    export default component;
}

interface ViteTypeOptions {
    strictImportMetaEnv: unknown;
}

interface ImportMetaEnv {
    readonly VITE_STUN_URLS?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}

declare global {
    interface Window {
        Pusher: unknown;
        Echo: EchoInstance;
    }
}

interface EchoChannel {
    listen(event: string, callback: (payload: any) => void): EchoChannel;
    listenForWhisper(event: string, callback: (payload: any) => void): EchoChannel;
    whisper(event: string, payload: unknown): EchoChannel;
    subscribed(callback: () => void): EchoChannel;
    error(callback: (error: unknown) => void): EchoChannel;
}

interface EchoInstance {
    private(channel: string): EchoChannel;
    leave(channel: string): void;
    connector: {
        options: { auth: { headers: Record<string, string> } };
    };
}

export {};

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import axios from 'axios';

const apiStatus = ref<'checking' | 'ok' | 'error'>('checking');

onMounted(async () => {
    try {
        const { data } = await axios.get('/api/v1/health');
        apiStatus.value = data.status === 'ok' ? 'ok' : 'error';
    } catch {
        apiStatus.value = 'error';
    }
});
</script>

<template>
    <div class="min-h-screen bg-neutral-950 text-neutral-100 flex flex-col">
        <header class="border-b border-neutral-800 px-6 py-4">
            <h1 class="text-lg font-semibold">Video Meeting App</h1>
        </header>

        <main class="flex-1 flex items-center justify-center px-6">
            <div class="text-center space-y-2">
                <p class="text-neutral-400">Self-hosted video meeting platform — foundation running.</p>
                <p class="text-sm">
                    API status:
                    <span
                        :class="{
                            'text-yellow-400': apiStatus === 'checking',
                            'text-green-400': apiStatus === 'ok',
                            'text-red-400': apiStatus === 'error',
                        }"
                    >{{ apiStatus }}</span>
                </p>
            </div>
        </main>
    </div>
</template>

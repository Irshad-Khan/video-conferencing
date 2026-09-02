<script setup lang="ts">
import { useAuth } from '../stores/auth';

const { state } = useAuth();
</script>

<template>
    <div class="max-w-2xl mx-auto px-6 py-12">
        <h1 class="text-xl font-semibold">Welcome, {{ state.user?.name }}</h1>
        <p class="text-neutral-400 mt-2">
            Meeting scheduling and management land in the next phase. For now, this dashboard confirms you're signed
            in.
        </p>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    name: { type: String, required: true },
    tier: { type: String, default: 'free' },
    size: { type: String, default: 'md' }, // 'md' (default, unchanged) | 'lg' (profile banner)
    avatarUrl: { type: String, default: null },
})

const TIER_BG = {
    free:       'tl-avatar--neutral',
    pro:        'tl-avatar--brand',
    team:       'tl-avatar--info',
    enterprise: 'tl-avatar--warn',
}

const initials = computed(() => {
    const words = props.name.trim().split(/\s+/)
    return words
        .slice(0, 2)
        .map(w => w[0]?.toUpperCase() ?? '')
        .join('')
})

const bgClass = computed(() => TIER_BG[props.tier] ?? 'tl-avatar--neutral')
</script>

<template>
    <img
        v-if="avatarUrl"
        :src="avatarUrl"
        :alt="name"
        :title="name"
        class="tl-avatar tl-avatar-img"
        :class="size === 'lg' ? 'tl-avatar--lg' : ''"
    >
    <span v-else class="tl-avatar" :class="[bgClass, size === 'lg' ? 'tl-avatar--lg' : '']" :title="name">{{ initials }}</span>
</template>

<style scoped>
.tl-avatar {
    display:         inline-flex;
    align-items:     center;
    justify-content: center;
    width:           2rem;
    height:          2rem;
    border-radius:   9999px;
    font-size:       0.75rem;
    font-weight:     700;
    flex-shrink:     0;
}
.tl-avatar--lg {
    width:       5.5rem;
    height:      5.5rem;
    font-size:   2rem;
    border:      3px solid var(--tl-card);
}
.tl-avatar--neutral { background: var(--tl-surface-muted); color: var(--tl-text-muted); }
.tl-avatar--brand   { background: var(--tl-brand);         color: #fff; }
.tl-avatar--info    { background: var(--tl-info);          color: #fff; }
.tl-avatar--warn    { background: var(--tl-warn);          color: #fff; }
</style>

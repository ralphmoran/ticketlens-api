<script setup>
import TlIcon from '@/Components/TlIcon.vue'
import TlPagination from '@/Components/TlPagination.vue'

const props = defineProps({
    loading:  { type: Boolean, default: false },
    paginator:{ type: Object,  default: null  },
    perPage:  { type: Number,  default: 20    },
    title:    { type: String,  default: ''    },
})

const emit = defineEmits(['update:perPage', 'page'])
</script>

<template>
    <slot name="header" />
    <div class="relative">
        <div v-if="loading" class="tl-loading-overlay">
            <TlIcon name="spinner" class="tl-ic tl-ic--lg tl-spin tl-legend-ic" />
        </div>
        <div class="tl-card tl-card--flush" :class="{ 'tl-inert': loading }">
            <div v-if="title || $slots.title" class="tl-table-header">
                <slot name="title">
                    <span class="tl-title">{{ title }}</span>
                </slot>
            </div>
            <div class="tl-table-scroll">
                <table class="tl-table">
                    <slot />
                </table>
            </div>
            <p v-if="$slots.footnote" class="tl-table-footnote">
                <slot name="footnote" />
            </p>
        </div>
    </div>
    <TlPagination
        v-if="paginator"
        :paginator="paginator"
        :perPage="perPage"
        @update:perPage="emit('update:perPage', $event)"
        @page="emit('page', $event)"
    />
</template>

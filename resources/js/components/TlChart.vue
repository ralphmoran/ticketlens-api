<script setup>
/**
 * TlChart — themed Chart.js wrapper. The single chart definition site for
 * the Console: colors, grid, tooltips, and legend are read from the tl-*
 * design tokens (tokens.css) so every chart follows the active theme and a
 * palette change is a one-file edit. Re-reads tokens when data-theme flips.
 *
 * Props:
 *  - type:     'line' | 'area' | 'bar' | 'donut'
 *  - labels:   x-axis labels (or slice labels for donut)
 *  - datasets: [{ label, data, color?, fill? }] — color accepts a semantic
 *              name ('brand'|'success'|'warn'|'danger'|'info') or is assigned
 *              from the chart palette by index.
 *  - options:  Chart.js options deep-merged over the themed defaults
 *  - legend:   false | 'bottom' | 'top' (default: 'bottom' when >1 dataset)
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js'
import { Line, Bar, Doughnut } from 'vue-chartjs'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, Tooltip, Legend, Filler)

const props = defineProps({
    type:     { type: String, required: true, validator: v => ['line', 'area', 'bar', 'donut'].includes(v) },
    labels:   { type: Array, default: () => [] },
    datasets: { type: Array, default: () => [] },
    options:  { type: Object, default: () => ({}) },
    legend:   { type: [String, Boolean], default: null },
})

// ── Token reads (theme-reactive) ─────────────────────────────────────────────
function readTokens() {
    const cs  = getComputedStyle(document.documentElement)
    const get = name => cs.getPropertyValue(name).trim()
    return {
        palette: [1, 2, 3, 4, 5, 6].map(i => get(`--tl-chart-${i}`)),
        semantic: {
            brand:   get('--tl-chart-1'),
            warn:    get('--tl-warn'),
            danger:  get('--tl-danger'),
            success: get('--tl-success'),
            info:    get('--tl-info'),
        },
        grid:          get('--tl-chart-grid'),
        tick:          get('--tl-chart-tick'),
        tooltipBg:     get('--tl-chart-tooltip-bg'),
        tooltipFg:     get('--tl-chart-tooltip-fg'),
        tooltipBorder: get('--tl-chart-tooltip-border'),
        cardBg:        get('--tl-card'),
    }
}

const tokens = ref(readTokens())
let themeObserver = null

onMounted(() => {
    themeObserver = new MutationObserver(() => { tokens.value = readTokens() })
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] })
})
onUnmounted(() => themeObserver?.disconnect())

// Append alpha to a plain `oklch(L C H)` token string.
const withAlpha = (color, alpha) => color.replace(/\)\s*$/, ` / ${alpha})`)

function resolveColor(dataset, index) {
    const named = tokens.value.semantic[dataset.color]
    return named ?? tokens.value.palette[index % tokens.value.palette.length]
}

const hasData = computed(() =>
    props.datasets.some(d => (d.data ?? []).some(v => v !== null && v !== undefined))
)

// ── Data ─────────────────────────────────────────────────────────────────────
const chartData = computed(() => {
    if (props.type === 'donut') {
        const first = props.datasets[0] ?? { data: [] }
        return {
            labels: props.labels,
            datasets: [{
                data: first.data,
                backgroundColor: props.labels.map((_, i) => tokens.value.palette[i % tokens.value.palette.length]),
                borderColor: tokens.value.cardBg,
                borderWidth: 2,
                hoverOffset: 6,
            }],
        }
    }

    return {
        labels: props.labels,
        datasets: props.datasets.map((d, i) => {
            const color  = resolveColor(d, i)
            const filled = props.type === 'area' || d.fill === true
            const base = {
                label: d.label,
                data: d.data,
                borderColor: color,
                backgroundColor: filled ? withAlpha(color, 0.15) : color,
            }
            if (props.type === 'bar') {
                return { ...base, borderRadius: 6, borderSkipped: false, maxBarThickness: 28, borderWidth: 0 }
            }
            return {
                ...base,
                fill: filled,
                tension: 0.35,
                borderWidth: 2,
                pointRadius: 2.5,
                pointHoverRadius: 4,
                pointBackgroundColor: color,
            }
        }),
    }
})

// ── Options ──────────────────────────────────────────────────────────────────
function deepMerge(target, source) {
    const out = { ...target }
    for (const [key, value] of Object.entries(source)) {
        out[key] = value && typeof value === 'object' && !Array.isArray(value) && typeof target[key] === 'object'
            ? deepMerge(target[key], value)
            : value
    }
    return out
}

const legendPosition = computed(() => {
    if (props.legend === false) return null
    if (typeof props.legend === 'string') return props.legend
    return (props.type === 'donut' || props.datasets.length > 1) ? 'bottom' : null
})

const mergedOptions = computed(() => {
    const t = tokens.value
    const defaults = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: props.type === 'donut' ? 'nearest' : 'index', intersect: false },
        plugins: {
            legend: legendPosition.value
                ? {
                    display: true,
                    position: legendPosition.value,
                    labels: { color: t.tick, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8, padding: 16, font: { size: 11 } },
                }
                : { display: false },
            tooltip: {
                backgroundColor: t.tooltipBg,
                titleColor: t.tooltipFg,
                bodyColor: t.tooltipFg,
                borderColor: t.tooltipBorder,
                borderWidth: 1,
                cornerRadius: 8,
                padding: 10,
                boxWidth: 8,
                boxHeight: 8,
                usePointStyle: true,
            },
        },
    }

    if (props.type !== 'donut') {
        defaults.scales = {
            x: {
                grid: { display: false },
                border: { display: false },
                ticks: { color: t.tick, maxTicksLimit: 10, font: { size: 11 } },
            },
            y: {
                beginAtZero: true,
                grid: { color: t.grid },
                border: { display: false },
                ticks: { color: t.tick, precision: 0, font: { size: 11 } },
            },
        }
    } else {
        defaults.cutout = '68%'
    }

    return deepMerge(defaults, props.options)
})

const component = computed(() => ({ line: Line, area: Line, bar: Bar, donut: Doughnut }[props.type]))
</script>

<template>
    <component :is="component" v-if="hasData" :data="chartData" :options="mergedOptions" />
    <div v-else class="tl-chart-empty">
        <slot name="empty">No data to display yet.</slot>
    </div>
</template>

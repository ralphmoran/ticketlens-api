<script setup>
import ConsoleLayout from '@/Layouts/ConsoleLayout.vue'
import TlIcon from '@/components/TlIcon.vue'

defineOptions({ layout: ConsoleLayout })

const props = defineProps({
    exports: { type: Array, default: () => [] },
})
</script>

<template>
    <div class="tl-page">

        <!-- Page header -->
        <div class="mb-6">
            <h1 class="tl-heading">Export</h1>
            <p class="tl-subtext">Download your ticket data as CSV or JSON</p>
        </div>

        <!-- Feature description -->
        <div class="mb-8 rounded-xl border border-slate-800 bg-slate-900/60 p-5 space-y-3">
            <p class="text-sm text-slate-300 leading-relaxed">
                <strong class="text-slate-100">What it does:</strong>
                Exports ticket briefs — including summaries, token costs, and metadata — to CSV or JSON. Use it for reporting, auditing, or feeding data into other tools.
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">CLI command:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens --export PROJ-123</code>
                or
                <code class="tl-kbd tl-kbd--brand">ticketlens --export --format=csv</code>
            </p>
            <p class="text-sm text-slate-400 leading-relaxed">
                <strong class="text-slate-300">Expected result:</strong>
                A structured file containing all ticket data from your last session, including briefs, token usage, and timestamps.
            </p>
        </div>

        <!-- Empty state -->
        <div class="tl-empty-state">
            <TlIcon name="arrow-down-tray" class="w-10 h-10 text-slate-700 mb-4" />
            <p class="text-slate-300 font-medium mb-1">No exports yet.</p>
            <p class="text-slate-500 text-sm">
                Run <code class="tl-kbd tl-kbd--brand">ticketlens --export</code> from your terminal to create your first export.
            </p>
        </div>

    </div>
</template>

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
        <div class="tl-page-header">
            <div>
                <h1 class="tl-heading">Export</h1>
                <p class="tl-subtext">Download your ticket data as CSV or JSON</p>
            </div>
        </div>

        <!-- Feature description -->
        <div class="tl-info-box tl-section-gap">
            <p class="tl-body--secondary">
                <strong class="tl-value">What it does:</strong>
                Exports ticket briefs — including summaries, token costs, and metadata — to CSV or JSON. Use it for reporting, auditing, or feeding data into other tools.
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">CLI command:</strong>
                <code class="tl-kbd tl-kbd--brand">ticketlens --export PROJ-123</code>
                or
                <code class="tl-kbd tl-kbd--brand">ticketlens --export --format=csv</code>
            </p>
            <p class="tl-body--muted">
                <strong class="tl-value">Expected result:</strong>
                A structured file containing all ticket data from your last session, including briefs, token usage, and timestamps.
            </p>
        </div>

        <!-- Empty state -->
        <div class="tl-empty-state">
            <TlIcon name="download" class="tl-empty-icon" />
            <p class="tl-body">No exports yet.</p>
            <p class="tl-subtext">
                Run <code class="tl-kbd tl-kbd--brand">ticketlens --export</code> from your terminal to create your first export.
            </p>
        </div>

    </div>
</template>

<script setup>
/**
 * TlIcon — single <svg> definition site for standard icons across the Console.
 * Backed by Lucide (`@lucide/vue`). Name registry matches AUDIT §4.
 *
 * Usage: <TlIcon name="chart-bar" class="w-4 h-4 shrink-0" />
 *        <TlIcon name="close" class="w-4 h-4" :stroke-width="2" />
 *
 * Classes and stroke-width pass through to the underlying <svg>.
 */
import { computed } from 'vue'
import {
  LayoutDashboard, BarChart3, CircleUser, Calendar, Inbox, FileText, ShieldCheck,
  Users, UsersRound, Key, DollarSign,
  Menu, X, LogOut, TriangleAlert,
  ArrowRight, BadgeCheck, Ban, Check, CircleCheck, CircleX,
  Clock, CodeXml, Eye, EyeOff, LoaderCircle, Lock, LockOpen,
  RefreshCw, Sparkles, TrendingUp,
} from '@lucide/vue'

const ICONS = {
  // Navigation
  'dashboard': LayoutDashboard,
  'chart-bar': BarChart3,
  'user-circle': CircleUser,
  'calendar': Calendar,
  'inbox': Inbox,
  'document-text': FileText,
  'shield-check': ShieldCheck,
  'users': Users,
  'user-group': UsersRound,
  'key': Key,
  'currency-dollar': DollarSign,
  // Layout / controls
  'menu': Menu,
  'close': X,
  'logout': LogOut,
  'warning-triangle': TriangleAlert,
  // Feature icons (alphabetical)
  'arrow-right': ArrowRight,
  'badge-check': BadgeCheck,
  'ban': Ban,
  'check': Check,
  'check-circle': CircleCheck,
  'clock': Clock,
  'code': CodeXml,
  'eye': Eye,
  'eye-slash': EyeOff,
  'lock-closed': Lock,
  'lock-open': LockOpen,
  'refresh': RefreshCw,
  'sparkles': Sparkles,
  'spinner': LoaderCircle,
  'trending-up': TrendingUp,
  'x-circle': CircleX,
}

const props = defineProps({
  name: { type: String, required: true },
  strokeWidth: { type: [Number, String], default: 1.5 },
})

const Component = computed(() => ICONS[props.name])

// Explicit fallthrough so the inline `github` <svg> branch stays a clean
// single-root for `class`/`style` propagation. Vue's auto-fallthrough already
// works for this v-if/v-else-if chain, but explicit is safer if a third
// branch is ever added.
defineOptions({ inheritAttrs: false })
</script>

<template>
  <!-- Brand mark: GitHub Octocat — kept inline because Lucide does not export brand logos. -->
  <svg v-if="name === 'github'" v-bind="$attrs" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
    <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/>
  </svg>
  <component
    v-else-if="Component"
    v-bind="$attrs"
    :is="Component"
    :stroke-width="strokeWidth"
    aria-hidden="true"
  />
</template>

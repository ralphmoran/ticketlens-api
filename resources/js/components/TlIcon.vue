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
  Clock, RefreshCw, ArrowRight, CircleCheck, CircleX,
  LockOpen, Lock, Eye, EyeOff, LoaderCircle, Sparkles,
  TrendingUp, CodeXml, BadgeCheck,
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
  // Feature icons
  'clock': Clock,
  'refresh': RefreshCw,
  'arrow-right': ArrowRight,
  'check-circle': CircleCheck,
  'x-circle': CircleX,
  'lock-open': LockOpen,
  'lock-closed': Lock,
  'eye': Eye,
  'eye-slash': EyeOff,
  'spinner': LoaderCircle,
  'sparkles': Sparkles,
  'trending-up': TrendingUp,
  'code': CodeXml,
  'badge-check': BadgeCheck,
}

const props = defineProps({
  name: { type: String, required: true },
  strokeWidth: { type: [Number, String], default: 1.5 },
})

const Component = computed(() => ICONS[props.name])
</script>

<template>
  <component
    :is="Component"
    v-if="Component"
    :stroke-width="strokeWidth"
    aria-hidden="true"
  />
</template>

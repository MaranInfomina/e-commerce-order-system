<script setup lang="ts">
import type { ToastType } from '~/composables/useToast'

const { toasts, dismiss } = useToast()

const VARIANTS: Record<ToastType, { title: string, box: string, iconBg: string, iconColor: string }> = {
  success: {
    title: 'Success',
    box: 'border-emerald-200 bg-white',
    iconBg: 'bg-emerald-100',
    iconColor: 'text-emerald-600',
  },
  error: {
    title: 'Error',
    box: 'border-red-200 bg-white',
    iconBg: 'bg-red-100',
    iconColor: 'text-red-600',
  },
  info: {
    title: 'Info',
    box: 'border-blue-200 bg-white',
    iconBg: 'bg-blue-100',
    iconColor: 'text-blue-600',
  },
}
</script>

<template>
  <div
    class="pointer-events-none fixed inset-x-0 top-4 z-50 flex flex-col items-center gap-3 px-4 sm:inset-x-auto sm:right-4 sm:items-end"
    aria-live="polite"
    role="status"
  >
    <TransitionGroup name="toast">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border bg-white p-4 shadow-lg"
        :class="VARIANTS[toast.type].box"
        :data-test="`toast-${toast.type}`"
      >
        <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full" :class="VARIANTS[toast.type].iconBg">
          <svg
            v-if="toast.type === 'success'"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            class="h-5 w-5"
            :class="VARIANTS[toast.type].iconColor"
          >
            <path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <svg
            v-else-if="toast.type === 'error'"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            class="h-5 w-5"
            :class="VARIANTS[toast.type].iconColor"
          >
            <path d="M6 18 18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          <svg
            v-else
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            class="h-5 w-5"
            :class="VARIANTS[toast.type].iconColor"
          >
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5" stroke-linecap="round" />
            <circle cx="12" cy="8" r="0.75" fill="currentColor" stroke="none" />
          </svg>
        </span>

        <div class="min-w-0 flex-1 pt-0.5">
          <p class="text-sm font-semibold text-slate-900">{{ VARIANTS[toast.type].title }}</p>
          <p class="mt-0.5 text-sm text-slate-600">{{ toast.message }}</p>
        </div>

        <button
          type="button"
          class="flex-none rounded-md p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
          aria-label="Dismiss"
          @click="dismiss(toast.id)"
        >
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
            <path d="M6 18 18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.2s ease;
}

.toast-enter-from {
  opacity: 0;
  transform: translateY(-0.5rem) scale(0.97);
}

.toast-leave-to {
  opacity: 0;
  transform: translateX(0.5rem);
}

.toast-leave-active {
  position: absolute;
}
</style>

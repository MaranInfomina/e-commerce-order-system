<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  currentPage: number
  lastPage: number
}>()

const emit = defineEmits<{ change: [page: number] }>()

// The API happily reports a current_page beyond last_page (e.g. a stale or
// hand-edited ?page= URL past the end of the result set), which would
// otherwise show a status line like "Page 999 of 4" next to an empty table.
// Clamp only what is displayed here — the disable logic below still uses the
// raw currentPage, and stays correct on both sides of the range.
const displayPage = computed(() => {
  const clampedLastPage = Math.max(props.lastPage, 1)
  return Math.min(Math.max(props.currentPage, 1), clampedLastPage)
})
</script>

<template>
  <nav class="mt-8 flex items-center justify-center gap-4" aria-label="Pagination">
    <button
      data-test="prev"
      type="button"
      :disabled="props.currentPage <= 1"
      class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-white"
      @click="emit('change', props.currentPage - 1)"
    >
      Previous
    </button>

    <span data-test="status" class="text-sm text-slate-500">
      Page {{ displayPage }} of {{ Math.max(props.lastPage, 1) }}
    </span>

    <button
      data-test="next"
      type="button"
      :disabled="props.currentPage >= props.lastPage"
      class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-white"
      @click="emit('change', props.currentPage + 1)"
    >
      Next
    </button>
  </nav>
</template>

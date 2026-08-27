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
  <nav class="pagination" aria-label="Pagination">
    <button
      data-test="prev"
      type="button"
      :disabled="props.currentPage <= 1"
      @click="emit('change', props.currentPage - 1)"
    >
      Previous
    </button>

    <span data-test="status">
      Page {{ displayPage }} of {{ Math.max(props.lastPage, 1) }}
    </span>

    <button
      data-test="next"
      type="button"
      :disabled="props.currentPage >= props.lastPage"
      @click="emit('change', props.currentPage + 1)"
    >
      Next
    </button>
  </nav>
</template>

<style scoped>
.pagination {
  display: flex;
  gap: var(--space-3);
  align-items: center;
  margin-top: var(--space-3);
}

button {
  padding: var(--space-1) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-bg);
  cursor: pointer;
}

button:disabled {
  color: var(--color-muted);
  cursor: not-allowed;
}
</style>

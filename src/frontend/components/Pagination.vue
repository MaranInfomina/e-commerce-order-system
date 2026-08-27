<script setup lang="ts">
const props = defineProps<{
  currentPage: number
  lastPage: number
}>()

const emit = defineEmits<{ change: [page: number] }>()
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
      Page {{ props.currentPage }} of {{ Math.max(props.lastPage, 1) }}
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

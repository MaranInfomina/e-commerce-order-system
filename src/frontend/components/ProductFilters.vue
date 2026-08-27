<script setup lang="ts">
import { ref, watch } from 'vue'
import type { Category } from '~/utils/api'

const props = defineProps<{
  categories: Category[]
  search: string
  category: string
  sort: string
}>()

const emit = defineEmits<{
  update: [value: { search: string, category: string, sort: string }]
}>()

const search = ref(props.search)
const category = ref(props.category)
const sort = ref(props.sort)

// The URL is the source of truth. Nuxt reuses this component instance across
// query-only navigations (its route key never includes the query string), so
// without this watcher the form would keep showing stale values after Back
// or Forward moves the URL without remounting the component.
watch(
  () => [props.search, props.category, props.sort] as const,
  ([nextSearch, nextCategory, nextSort]) => {
    search.value = nextSearch
    category.value = nextCategory
    sort.value = nextSort
  },
)

function submit() {
  emit('update', {
    search: search.value,
    category: category.value,
    sort: sort.value,
  })
}
</script>

<template>
  <form class="filters" @submit.prevent="submit">
    <label>
      Search
      <input v-model="search" type="search" placeholder="name or description">
    </label>

    <label>
      Category
      <select v-model="category">
        <option value="">All</option>
        <option v-for="option in props.categories" :key="option.id" :value="option.slug">
          {{ option.name }}
        </option>
      </select>
    </label>

    <label>
      Sort
      <select v-model="sort">
        <option value="">Newest first</option>
        <option value="name">Name A-Z</option>
        <option value="-name">Name Z-A</option>
        <option value="price">Price low to high</option>
        <option value="-price">Price high to low</option>
        <option value="created_at">Oldest first</option>
      </select>
    </label>

    <button type="submit">Apply</button>
  </form>
</template>

<style scoped>
.filters {
  display: flex;
  gap: var(--space-3);
  align-items: flex-end;
  flex-wrap: wrap;
  margin-bottom: var(--space-4);
}

label {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  font-size: 0.875rem;
  color: var(--color-muted);
}

input, select {
  padding: var(--space-1) var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  font-size: 1rem;
}

button {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-accent);
  border-radius: var(--radius);
  background: var(--color-accent);
  color: white;
  cursor: pointer;
}
</style>

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
  <form
    class="mb-8 flex flex-wrap items-end gap-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm"
    @submit.prevent="submit"
  >
    <label class="flex min-w-48 flex-1 flex-col gap-1 text-sm font-medium text-slate-600">
      Search
      <div class="relative">
        <svg
          class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
          viewBox="0 0 20 20"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
        >
          <circle cx="9" cy="9" r="6" />
          <path d="m17 17-4-4" stroke-linecap="round" />
        </svg>
        <input
          v-model="search"
          type="search"
          placeholder="Name or description"
          class="w-full rounded-md border border-slate-300 py-1.5 pl-9 pr-3 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
        >
      </div>
    </label>

    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
      Category
      <select
        v-model="category"
        class="rounded-md border border-slate-300 py-1.5 pl-3 pr-8 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      >
        <option value="">All</option>
        <option v-for="option in props.categories" :key="option.id" :value="option.slug">
          {{ option.name }}
        </option>
      </select>
    </label>

    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
      Sort
      <select
        v-model="sort"
        class="rounded-md border border-slate-300 py-1.5 pl-3 pr-8 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      >
        <option value="">Newest first</option>
        <option value="name">Name A-Z</option>
        <option value="-name">Name Z-A</option>
        <option value="price">Price low to high</option>
        <option value="-price">Price high to low</option>
        <option value="created_at">Oldest first</option>
      </select>
    </label>

    <button
      type="submit"
      class="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-indigo-500"
    >
      Apply
    </button>
  </form>
</template>

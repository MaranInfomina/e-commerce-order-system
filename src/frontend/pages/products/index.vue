<script setup lang="ts">
import { parseApiError, type ProductListState } from '~/utils/api'

const route = useRoute()
const router = useRouter()
const { listProducts, listCategories } = useProductsApi()

const state = computed<ProductListState>(() => ({
  page: Number(route.query.page ?? 1),
  perPage: Number(route.query.per_page ?? 15),
  search: String(route.query.search ?? ''),
  category: String(route.query.category ?? ''),
  sort: String(route.query.sort ?? ''),
}))

const { data: categoriesResponse } = await useAsyncData(
  'categories',
  () => listCategories(),
)

const { data: productsResponse, error } = await useAsyncData(
  () => `products:${JSON.stringify(state.value)}`,
  () => listProducts(state.value),
  { watch: [state] },
)

// parseApiError never throws, so this always resolves to a renderable
// message — the API's own wording (e.g. the 422 for an unknown category
// slug) rather than a single generic string for every failure.
const apiError = computed(() => (error.value ? parseApiError(error.value) : null))

function applyFilters(update: { search: string, category: string, sort: string }) {
  // Any filter change resets to page 1 — page 4 of a new result set is meaningless.
  router.push({ query: pruneQuery({ ...update, page: undefined }) })
}

function goToPage(page: number) {
  router.push({ query: pruneQuery({ ...route.query, page: page > 1 ? String(page) : undefined }) })
}

function pruneQuery(query: Record<string, unknown>): Record<string, string> {
  return Object.fromEntries(
    Object.entries(query)
      .filter(([, value]) => value !== undefined && value !== null && value !== '')
      .map(([key, value]) => [key, String(value)]),
  )
}
</script>

<template>
  <section>
    <header class="head">
      <h1>Products</h1>
      <NuxtLink to="/products/new">New product</NuxtLink>
    </header>

    <ProductFilters
      :categories="categoriesResponse?.data ?? []"
      :search="state.search"
      :category="state.category"
      :sort="state.sort"
      @update="applyFilters"
    />

    <p v-if="apiError" class="error">{{ apiError.message }}</p>

    <template v-else>
      <p class="count">{{ productsResponse?.meta.total ?? 0 }} products</p>

      <ProductTable :products="productsResponse?.data ?? []" />

      <Pagination
        :current-page="productsResponse?.meta.current_page ?? 1"
        :last-page="productsResponse?.meta.last_page ?? 1"
        @change="goToPage"
      />
    </template>
  </section>
</template>

<style scoped>
.head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: var(--space-3);
}

.count {
  color: var(--color-muted);
  font-size: 0.875rem;
}

.error {
  color: var(--color-error);
}
</style>

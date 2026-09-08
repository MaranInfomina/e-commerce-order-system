<script setup lang="ts">
import { formatCents, orderStatusClass, parseApiError } from '~/utils/api'

definePageMeta({
  middleware: [
    () => {
      const { isAuthenticated } = useAuth()

      if (!isAuthenticated.value) {
        const route = useRoute()

        return navigateTo(`/login?redirect=${route.fullPath}`)
      }
    },
  ],
})

const route = useRoute()
const { getOrder } = useOrdersApi()

const { data: orderResponse, error } = await useAsyncData(
  () => `order:${route.params.id}`,
  () => getOrder(route.params.id as string),
)

const apiError = computed(() => (error.value ? parseApiError(error.value) : null))
</script>

<template>
  <section class="mx-auto max-w-3xl">
    <header class="mb-6 flex items-baseline justify-between">
      <h1 class="text-2xl font-bold tracking-tight text-slate-900">Order #{{ route.params.id }}</h1>
      <NuxtLink to="/orders" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
        &larr; Back to orders
      </NuxtLink>
    </header>

    <p v-if="apiError" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ apiError.message }}
    </p>

    <template v-else-if="orderResponse">
      <div class="mb-6 flex items-center justify-between rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <span
          class="w-fit rounded-full px-3 py-1 text-sm font-medium"
          :class="orderStatusClass(orderResponse.data.status)"
          :data-status="orderResponse.data.status"
        >
          {{ orderResponse.data.status }}
        </span>
        <span class="text-lg font-bold text-slate-900">
          Total: {{ formatCents(orderResponse.data.total_cents) }}
        </span>
      </div>

      <div class="mb-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">Items</h2>
        <OrderItemsList :items="orderResponse.data.items" />
      </div>

      <div class="mb-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-slate-400">Shipping address</h2>
          <p class="text-sm text-slate-900">{{ orderResponse.data.shipping_address }}</p>
          <p v-if="orderResponse.data.notes" class="mt-2 text-sm text-slate-500">{{ orderResponse.data.notes }}</p>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-400">Status history</h2>
          <ul class="flex flex-col gap-3">
            <li
              v-for="(entry, index) in orderResponse.data.status_history"
              :key="index"
              class="flex items-start gap-3 text-sm"
            >
              <span class="mt-1.5 h-2 w-2 flex-none rounded-full bg-indigo-500" />
              <span>
                <span class="font-medium capitalize text-slate-900">{{ entry.status }}</span>
                <span class="block text-xs text-slate-400">{{ new Date(entry.created_at).toLocaleString() }}</span>
              </span>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </section>
</template>

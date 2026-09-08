<script setup lang="ts">
import { formatCents, orderStatusClass, parseApiError } from '~/utils/api'

definePageMeta({
  middleware: [
    () => {
      const { isAuthenticated } = useAuth()

      if (!isAuthenticated.value) {
        return navigateTo('/login?redirect=/orders')
      }
    },
  ],
})

const { listOrders } = useOrdersApi()

const { data: ordersResponse, error } = await useAsyncData('orders', () => listOrders())

const apiError = computed(() => (error.value ? parseApiError(error.value) : null))
</script>

<template>
  <section>
    <header class="mb-6">
      <h1 class="text-2xl font-bold tracking-tight text-slate-900">Your orders</h1>
    </header>

    <p v-if="apiError" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ apiError.message }}
    </p>

    <template v-else>
      <div
        v-if="(ordersResponse?.data.length ?? 0) === 0"
        class="rounded-lg border border-dashed border-slate-300 bg-white py-16 text-center"
      >
        <p class="text-sm text-slate-500">You have no orders yet.</p>
      </div>

      <ul v-else class="flex flex-col gap-3">
        <li v-for="order in ordersResponse?.data ?? []" :key="order.id">
          <NuxtLink
            :to="`/orders/${order.id}`"
            class="grid grid-cols-[4rem_8rem_1fr_auto] items-center gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md"
            :data-test="`order-${order.id}`"
          >
            <span class="font-medium text-slate-900">#{{ order.id }}</span>
            <span
              class="w-fit rounded-full px-2 py-0.5 text-xs font-medium"
              :class="orderStatusClass(order.status)"
              :data-status="order.status"
            >
              {{ order.status }}
            </span>
            <span class="text-sm font-semibold text-slate-900">{{ formatCents(order.total_cents) }}</span>
            <span class="text-sm text-slate-400">{{ new Date(order.created_at).toLocaleDateString() }}</span>
          </NuxtLink>
        </li>
      </ul>
    </template>
  </section>
</template>

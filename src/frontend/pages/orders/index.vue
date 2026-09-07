<script setup lang="ts">
import { formatCents, parseApiError } from '~/utils/api'

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
    <header class="head">
      <h1>Your orders</h1>
    </header>

    <p v-if="apiError" class="error">{{ apiError.message }}</p>

    <template v-else>
      <p v-if="(ordersResponse?.data.length ?? 0) === 0" class="empty">You have no orders yet.</p>

      <ul v-else class="orders">
        <li v-for="order in ordersResponse?.data ?? []" :key="order.id">
          <NuxtLink :to="`/orders/${order.id}`" class="row" :data-test="`order-${order.id}`">
            <span class="id">#{{ order.id }}</span>
            <span class="status" :data-status="order.status">{{ order.status }}</span>
            <span class="total">{{ formatCents(order.total_cents) }}</span>
            <span class="date">{{ new Date(order.created_at).toLocaleDateString() }}</span>
          </NuxtLink>
        </li>
      </ul>
    </template>
  </section>
</template>

<style scoped>
.head {
  margin-bottom: var(--space-3);
}

.orders {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.row {
  display: grid;
  grid-template-columns: 4rem 8rem 1fr auto;
  gap: var(--space-2);
  padding: var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  text-decoration: none;
  color: inherit;
}

.error {
  color: var(--color-error);
}

.empty {
  color: var(--color-muted);
}
</style>

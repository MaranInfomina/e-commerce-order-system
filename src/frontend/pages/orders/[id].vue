<script setup lang="ts">
import { formatCents, parseApiError } from '~/utils/api'

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
  <section>
    <header class="head">
      <h1>Order #{{ route.params.id }}</h1>
      <NuxtLink to="/orders">Back to orders</NuxtLink>
    </header>

    <p v-if="apiError" class="error">{{ apiError.message }}</p>

    <template v-else-if="orderResponse">
      <p class="status" :data-status="orderResponse.data.status">{{ orderResponse.data.status }}</p>

      <OrderItemsList :items="orderResponse.data.items" />

      <p class="total">Total: {{ formatCents(orderResponse.data.total_cents) }}</p>

      <section class="address">
        <h2>Shipping address</h2>
        <p>{{ orderResponse.data.shipping_address }}</p>
        <p v-if="orderResponse.data.notes" class="notes">{{ orderResponse.data.notes }}</p>
      </section>

      <section class="timeline">
        <h2>Status history</h2>
        <ul>
          <li v-for="(entry, index) in orderResponse.data.status_history" :key="index">
            {{ entry.status }} — {{ new Date(entry.created_at).toLocaleString() }}
          </li>
        </ul>
      </section>
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

.error {
  color: var(--color-error);
}

.notes {
  color: var(--color-muted);
}
</style>

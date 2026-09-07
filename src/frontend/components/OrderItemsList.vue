<script setup lang="ts">
import { formatCents, type OrderItem } from '~/utils/api'

defineProps<{ items: OrderItem[] }>()
</script>

<template>
  <ul class="items">
    <li
      v-for="item in items"
      :key="item.product_id"
      class="item"
      :data-test="`item-${item.product_id}`"
    >
      <img v-if="item.image_url" :src="item.image_url" :alt="item.product_name" class="thumb">
      <div v-else class="thumb placeholder" data-test="thumb-placeholder" />

      <div class="details">
        <p class="name">{{ item.product_name }}</p>
        <p class="sku">{{ item.product_sku }}</p>
      </div>

      <p class="qty">× {{ item.quantity }}</p>
      <p class="total">{{ formatCents(item.line_total_cents) }}</p>
    </li>
  </ul>
</template>

<style scoped>
.items {
  list-style: none;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.item {
  display: grid;
  grid-template-columns: 3rem 1fr auto auto;
  gap: var(--space-2);
  align-items: center;
}

.thumb {
  width: 3rem;
  height: 3rem;
  object-fit: cover;
  border-radius: var(--radius);
}

.thumb.placeholder {
  background: var(--color-border);
}

.name {
  font-weight: 600;
  margin: 0;
}

.sku {
  color: var(--color-muted);
  font-size: 0.8rem;
  margin: 0;
}
</style>

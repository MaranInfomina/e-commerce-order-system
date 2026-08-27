<script setup lang="ts">
import { formatCents, type Product } from '~/utils/api'

const props = defineProps<{ products: Product[] }>()
</script>

<template>
  <table class="products">
    <thead>
      <tr>
        <th>Name</th>
        <th>SKU</th>
        <th>Category</th>
        <th class="numeric">Price</th>
        <th class="numeric">Stock</th>
        <th>Active</th>
      </tr>
    </thead>
    <tbody>
      <tr v-if="props.products.length === 0">
        <td colspan="6" class="empty">No products match these filters.</td>
      </tr>
      <tr v-for="product in props.products" :key="product.id">
        <td>{{ product.name }}</td>
        <td>{{ product.sku }}</td>
        <td>{{ product.category?.name ?? '—' }}</td>
        <td class="numeric">{{ formatCents(product.price_cents) }}</td>
        <td class="numeric">{{ product.stock_quantity }}</td>
        <td>{{ product.is_active ? 'Yes' : 'No' }}</td>
      </tr>
    </tbody>
  </table>
</template>

<style scoped>
.products {
  width: 100%;
  border-collapse: collapse;
}

th, td {
  padding: var(--space-2);
  border-bottom: 1px solid var(--color-border);
  text-align: left;
}

th {
  font-size: 0.875rem;
  color: var(--color-muted);
}

.numeric {
  text-align: right;
}

.empty {
  color: var(--color-muted);
  text-align: center;
  padding: var(--space-4);
}
</style>

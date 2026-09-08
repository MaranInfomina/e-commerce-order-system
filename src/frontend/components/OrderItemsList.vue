<script setup lang="ts">
import { formatCents, type OrderItem } from '~/utils/api'

defineProps<{ items: OrderItem[] }>()
</script>

<template>
  <ul class="divide-y divide-slate-100">
    <li
      v-for="item in items"
      :key="item.product_id"
      class="grid grid-cols-[3rem_1fr_auto_auto] items-center gap-3 py-3"
      :data-test="`item-${item.product_id}`"
    >
      <img
        v-if="item.image_url"
        :src="item.image_url"
        :alt="item.product_name"
        class="h-12 w-12 rounded-md object-cover"
      >
      <div v-else class="flex h-12 w-12 items-center justify-center rounded-md bg-slate-100 text-slate-300" data-test="thumb-placeholder">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
          <rect x="3" y="3" width="18" height="18" rx="2" />
          <circle cx="8.5" cy="8.5" r="1.5" />
          <path d="m21 15-5-5L5 21" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </div>

      <div class="min-w-0">
        <p class="truncate font-medium text-slate-900">{{ item.product_name }}</p>
        <p class="text-xs text-slate-400">{{ item.product_sku }}</p>
      </div>

      <p class="text-sm text-slate-500">× {{ item.quantity }}</p>
      <p class="font-medium text-slate-900">{{ formatCents(item.line_total_cents) }}</p>
    </li>
  </ul>
</template>

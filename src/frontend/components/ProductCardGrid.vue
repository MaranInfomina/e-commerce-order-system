<script setup lang="ts">
import { formatCents, parseApiError, type Product } from '~/utils/api'

const props = defineProps<{ products: Product[] }>()

const { isAuthenticated } = useAuth()
const { addItem } = useCartApi()
const toast = useToast()

// Tracks which cards have a request in flight, so a click on one product
// disables only that card's button rather than every card on the page.
const addingIds = ref<Set<number>>(new Set())

async function add(product: Product) {
  if (addingIds.value.has(product.id)) return

  addingIds.value = new Set(addingIds.value).add(product.id)

  try {
    await addItem(product.id, 1)
    toast.success(`Added "${product.name}" to your cart.`)
  }
  catch (error) {
    toast.error(parseApiError(error).message)
  }
  finally {
    const next = new Set(addingIds.value)
    next.delete(product.id)
    addingIds.value = next
  }
}
</script>

<template>
  <div v-if="props.products.length === 0" class="rounded-lg border border-dashed border-slate-300 bg-white py-16 text-center">
    <p class="text-sm text-slate-500">No products match these filters.</p>
  </div>

  <div v-else class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
    <article
      v-for="product in props.products"
      :key="product.id"
      class="group flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm transition-shadow hover:shadow-md"
    >
      <div class="aspect-square w-full overflow-hidden bg-slate-100">
        <img
          v-if="product.image_url"
          :src="product.image_url"
          :alt="product.name"
          class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105"
        >
        <div v-else class="flex h-full w-full items-center justify-center text-slate-300">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-12 w-12">
            <rect x="3" y="3" width="18" height="18" rx="2" />
            <circle cx="8.5" cy="8.5" r="1.5" />
            <path d="m21 15-5-5L5 21" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </div>
      </div>

      <div class="flex flex-1 flex-col gap-1 p-4">
        <p v-if="product.category" class="text-xs font-medium uppercase tracking-wide text-indigo-600">
          {{ product.category.name }}
        </p>
        <h3 class="font-semibold text-slate-900">{{ product.name }}</h3>
        <p class="text-xs text-slate-400">{{ product.sku }}</p>

        <div class="mt-auto flex items-center justify-between pt-3">
          <span class="text-lg font-bold text-slate-900">{{ formatCents(product.price_cents) }}</span>

          <span
            v-if="!product.is_active"
            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500"
          >
            Inactive
          </span>
          <span
            v-else-if="product.stock_quantity === 0"
            class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700"
          >
            Out of stock
          </span>
          <span
            v-else-if="product.stock_quantity <= 5"
            class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700"
          >
            {{ product.stock_quantity }} left
          </span>
          <span v-else class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
            In stock
          </span>
        </div>

        <NuxtLink
          v-if="!isAuthenticated"
          to="/login"
          class="mt-3 block rounded-md border border-slate-300 px-3 py-1.5 text-center text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50"
        >
          Sign in to buy
        </NuxtLink>
        <button
          v-else
          type="button"
          :disabled="!product.is_active || product.stock_quantity === 0 || addingIds.has(product.id)"
          :data-test="`add-to-cart-${product.id}`"
          class="mt-3 rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-400"
          @click="add(product)"
        >
          {{ addingIds.has(product.id) ? 'Adding…' : 'Add to cart' }}
        </button>
      </div>
    </article>
  </div>
</template>

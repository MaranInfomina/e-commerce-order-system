<script setup lang="ts">
import { formatCents, parseApiError } from '~/utils/api'

definePageMeta({
  middleware: [
    () => {
      const { isAuthenticated } = useAuth()

      if (!isAuthenticated.value) {
        return navigateTo('/login?redirect=/cart')
      }
    },
  ],
})

const { getCart, updateItem, removeItem } = useCartApi()
const { createOrder } = useOrdersApi()
const toast = useToast()
const router = useRouter()

const { data: cartResponse, error, refresh } = await useAsyncData('cart', () => getCart())

const apiError = computed(() => (error.value ? parseApiError(error.value) : null))

// Per-line "this line has a request in flight" flags, keyed by product id,
// so editing one line's quantity does not disable every other line's
// controls while it saves.
const savingIds = ref<Set<number>>(new Set())

function isSaving(productId: number): boolean {
  return savingIds.value.has(productId)
}

function setSaving(productId: number, saving: boolean) {
  const next = new Set(savingIds.value)
  saving ? next.add(productId) : next.delete(productId)
  savingIds.value = next
}

async function changeQuantity(productId: number, quantity: number) {
  if (quantity < 1 || isSaving(productId)) return

  setSaving(productId, true)

  try {
    await updateItem(productId, quantity)
    await refresh()
  }
  catch (thrown) {
    toast.error(parseApiError(thrown).message)
  }
  finally {
    setSaving(productId, false)
  }
}

async function remove(productId: number) {
  if (isSaving(productId)) return

  setSaving(productId, true)

  try {
    await removeItem(productId)
    await refresh()
  }
  catch (thrown) {
    toast.error(parseApiError(thrown).message)
    setSaving(productId, false)
  }
}

const shippingAddress = ref('')
const notes = ref('')
const placingOrder = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})

async function placeOrder() {
  if (placingOrder.value) return

  placingOrder.value = true
  fieldErrors.value = {}

  try {
    const created = await createOrder({
      shipping_address: shippingAddress.value,
      notes: notes.value || null,
    })

    toast.success(`Order #${created.data.id} placed.`)
    await router.push(`/orders/${created.data.id}`)
  }
  catch (thrown) {
    const parsed = parseApiError(thrown)
    fieldErrors.value = parsed.fields
    toast.error(parsed.message)
  }
  finally {
    placingOrder.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-3xl">
    <header class="mb-6">
      <h1 class="text-2xl font-bold tracking-tight text-slate-900">Your cart</h1>
    </header>

    <p v-if="apiError" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ apiError.message }}
    </p>

    <template v-else-if="cartResponse">
      <div
        v-if="cartResponse.data.items.length === 0"
        class="rounded-lg border border-dashed border-slate-300 bg-white py-16 text-center"
      >
        <p class="mb-4 text-sm text-slate-500">Your cart is empty.</p>
        <NuxtLink to="/products" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
          Browse products
        </NuxtLink>
      </div>

      <template v-else>
        <div class="mb-6 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <ul class="divide-y divide-slate-100">
            <li
              v-for="line in cartResponse.data.items"
              :key="line.product.id"
              class="grid grid-cols-[3rem_1fr_auto_auto] items-center gap-3 py-3"
              :data-test="`cart-item-${line.product.id}`"
            >
              <img
                v-if="line.product.image_url"
                :src="line.product.image_url"
                :alt="line.product.name"
                class="h-12 w-12 rounded-md object-cover"
              >
              <div v-else class="flex h-12 w-12 items-center justify-center rounded-md bg-slate-100 text-slate-300">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5">
                  <rect x="3" y="3" width="18" height="18" rx="2" />
                  <circle cx="8.5" cy="8.5" r="1.5" />
                  <path d="m21 15-5-5L5 21" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
              </div>

              <div class="min-w-0">
                <p class="truncate font-medium text-slate-900">{{ line.product.name }}</p>
                <p class="text-xs text-slate-400">{{ formatCents(line.product.price_cents) }} each</p>
              </div>

              <input
                type="number"
                min="1"
                :value="line.quantity"
                :disabled="isSaving(line.product.id)"
                :data-test="`quantity-${line.product.id}`"
                class="w-16 rounded-md border border-slate-300 px-2 py-1 text-center text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:opacity-60"
                @change="changeQuantity(line.product.id, Number(($event.target as HTMLInputElement).value))"
              >

              <div class="flex items-center gap-3">
                <p class="w-20 text-right font-medium text-slate-900">{{ formatCents(line.line_total_cents) }}</p>
                <button
                  type="button"
                  :disabled="isSaving(line.product.id)"
                  :data-test="`remove-${line.product.id}`"
                  class="text-slate-400 transition-colors hover:text-red-600 disabled:cursor-not-allowed disabled:opacity-40"
                  aria-label="Remove"
                  @click="remove(line.product.id)"
                >
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                    <path d="M6 18 18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" />
                  </svg>
                </button>
              </div>
            </li>
          </ul>

          <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
            <span class="text-sm text-slate-500">{{ cartResponse.data.item_count }} item(s)</span>
            <span class="text-lg font-bold text-slate-900">{{ formatCents(cartResponse.data.total_cents) }}</span>
          </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-400">Shipping details</h2>

          <form class="flex flex-col gap-4" @submit.prevent="placeOrder">
            <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
              Shipping address
              <textarea
                v-model="shippingAddress"
                data-test="field-shipping-address"
                rows="3"
                required
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              />
              <span v-if="fieldErrors.shipping_address" class="text-xs text-red-600">
                {{ fieldErrors.shipping_address[0] }}
              </span>
            </label>

            <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
              Notes (optional)
              <textarea
                v-model="notes"
                data-test="field-notes"
                rows="2"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              />
            </label>

            <button
              type="submit"
              :disabled="placingOrder"
              data-test="place-order"
              class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {{ placingOrder ? 'Placing order…' : `Place order — ${formatCents(cartResponse.data.total_cents)}` }}
            </button>
          </form>
        </div>
      </template>
    </template>
  </section>
</template>

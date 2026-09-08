<script setup lang="ts">
import { reactive, ref } from 'vue'
import type { Category, ProductInput } from '~/utils/api'

const props = defineProps<{
  categories: Category[]
  errors: Record<string, string[]>
  submitting: boolean
}>()

const emit = defineEmits<{ submit: [payload: ProductInput, image: File | null] }>()

const form = reactive({
  category_id: '',
  name: '',
  slug: '',
  sku: '',
  description: '',
  price_cents: '',
  stock_quantity: '',
  is_active: true,
})

// The image is a separate upload (POST /products/{id}/image) that needs a
// real product id to target, so it can't be part of the ProductInput JSON
// body above - the caller creates the product first, then uploads this file
// against the id it gets back.
const imageFile = ref<File | null>(null)

function selectImage(event: Event) {
  imageFile.value = (event.target as HTMLInputElement).files?.[0] ?? null
}

function submit() {
  emit('submit', {
    category_id: form.category_id === '' ? null : Number(form.category_id),
    name: form.name,
    slug: form.slug,
    sku: form.sku,
    description: form.description,
    price_cents: form.price_cents === '' ? null : Number(form.price_cents),
    stock_quantity: form.stock_quantity === '' ? null : Number(form.stock_quantity),
    is_active: form.is_active,
  }, imageFile.value)
}
</script>

<template>
  <form
    class="grid max-w-2xl grid-cols-2 gap-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm"
    @submit.prevent="submit"
  >
    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
      Name
      <input
        v-model="form.name"
        data-test="field-name"
        type="text"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      >
      <span v-if="props.errors.name" data-test="error-name" class="text-xs text-red-600">
        {{ props.errors.name.join(' ') }}
      </span>
    </label>

    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
      Slug
      <input
        v-model="form.slug"
        data-test="field-slug"
        type="text"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      >
      <span v-if="props.errors.slug" data-test="error-slug" class="text-xs text-red-600">
        {{ props.errors.slug.join(' ') }}
      </span>
    </label>

    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
      SKU
      <input
        v-model="form.sku"
        data-test="field-sku"
        type="text"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      >
      <span v-if="props.errors.sku" data-test="error-sku" class="text-xs text-red-600">
        {{ props.errors.sku.join(' ') }}
      </span>
    </label>

    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
      Category
      <select
        v-model="form.category_id"
        data-test="field-category"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      >
        <option value="">Select a category</option>
        <option v-for="option in props.categories" :key="option.id" :value="String(option.id)">
          {{ option.name }}
        </option>
      </select>
      <span v-if="props.errors.category_id" data-test="error-category_id" class="text-xs text-red-600">
        {{ props.errors.category_id.join(' ') }}
      </span>
    </label>

    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
      Price (in cents)
      <input
        v-model="form.price_cents"
        data-test="field-price"
        type="number"
        step="1"
        min="0"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      >
      <span v-if="props.errors.price_cents" data-test="error-price_cents" class="text-xs text-red-600">
        {{ props.errors.price_cents.join(' ') }}
      </span>
    </label>

    <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
      Stock quantity
      <input
        v-model="form.stock_quantity"
        data-test="field-stock"
        type="number"
        step="1"
        min="0"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      >
      <span v-if="props.errors.stock_quantity" data-test="error-stock_quantity" class="text-xs text-red-600">
        {{ props.errors.stock_quantity.join(' ') }}
      </span>
    </label>

    <label class="col-span-2 flex flex-col gap-1 text-sm font-medium text-slate-600">
      Description
      <textarea
        v-model="form.description"
        data-test="field-description"
        rows="3"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
      />
      <span v-if="props.errors.description" data-test="error-description" class="text-xs text-red-600">
        {{ props.errors.description.join(' ') }}
      </span>
    </label>

    <label class="col-span-2 flex flex-col gap-1 text-sm font-medium text-slate-600">
      Product image (optional)
      <input
        data-test="field-image"
        type="file"
        accept="image/jpeg,image/png,image/webp"
        class="text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
        @change="selectImage"
      >
      <span class="text-xs font-normal text-slate-400">JPEG, PNG, or WebP, up to 2MB.</span>
    </label>

    <label class="col-span-2 flex flex-row items-center gap-2 text-sm font-medium text-slate-600">
      <input v-model="form.is_active" data-test="field-active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
      Active
    </label>

    <button
      data-test="submit"
      type="submit"
      :disabled="props.submitting"
      class="col-span-2 w-fit rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
    >
      {{ props.submitting ? 'Saving…' : 'Create product' }}
    </button>
  </form>
</template>

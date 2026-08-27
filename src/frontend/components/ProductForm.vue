<script setup lang="ts">
import { reactive } from 'vue'
import type { Category, ProductInput } from '~/utils/api'

const props = defineProps<{
  categories: Category[]
  errors: Record<string, string[]>
  submitting: boolean
}>()

const emit = defineEmits<{ submit: [payload: ProductInput] }>()

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
  })
}
</script>

<template>
  <form class="product-form" @submit.prevent="submit">
    <label>
      Name
      <input v-model="form.name" data-test="field-name" type="text">
      <span v-if="props.errors.name" data-test="error-name" class="error">
        {{ props.errors.name.join(' ') }}
      </span>
    </label>

    <label>
      Slug
      <input v-model="form.slug" data-test="field-slug" type="text">
      <span v-if="props.errors.slug" data-test="error-slug" class="error">
        {{ props.errors.slug.join(' ') }}
      </span>
    </label>

    <label>
      SKU
      <input v-model="form.sku" data-test="field-sku" type="text">
      <span v-if="props.errors.sku" data-test="error-sku" class="error">
        {{ props.errors.sku.join(' ') }}
      </span>
    </label>

    <label>
      Category
      <select v-model="form.category_id" data-test="field-category">
        <option value="">Select a category</option>
        <option v-for="option in props.categories" :key="option.id" :value="String(option.id)">
          {{ option.name }}
        </option>
      </select>
      <span v-if="props.errors.category_id" data-test="error-category_id" class="error">
        {{ props.errors.category_id.join(' ') }}
      </span>
    </label>

    <label>
      Price (in cents)
      <input v-model="form.price_cents" data-test="field-price" type="number" step="1" min="0">
      <span v-if="props.errors.price_cents" data-test="error-price_cents" class="error">
        {{ props.errors.price_cents.join(' ') }}
      </span>
    </label>

    <label>
      Stock quantity
      <input v-model="form.stock_quantity" data-test="field-stock" type="number" step="1" min="0">
      <span v-if="props.errors.stock_quantity" data-test="error-stock_quantity" class="error">
        {{ props.errors.stock_quantity.join(' ') }}
      </span>
    </label>

    <label class="wide">
      Description
      <textarea v-model="form.description" data-test="field-description" rows="3" />
      <span v-if="props.errors.description" data-test="error-description" class="error">
        {{ props.errors.description.join(' ') }}
      </span>
    </label>

    <label class="inline">
      <input v-model="form.is_active" data-test="field-active" type="checkbox">
      Active
    </label>

    <button data-test="submit" type="submit" :disabled="props.submitting">
      {{ props.submitting ? 'Saving…' : 'Create product' }}
    </button>
  </form>
</template>

<style scoped>
.product-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-3);
  max-width: 42rem;
}

label {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  font-size: 0.875rem;
  color: var(--color-muted);
}

label.wide {
  grid-column: 1 / -1;
}

label.inline {
  flex-direction: row;
  align-items: center;
  gap: var(--space-2);
}

input, select, textarea {
  padding: var(--space-1) var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  font-size: 1rem;
  font-family: inherit;
}

.error {
  color: var(--color-error);
  font-size: 0.8125rem;
}

button {
  grid-column: 1 / -1;
  justify-self: start;
  padding: var(--space-2) var(--space-4);
  border: 1px solid var(--color-accent);
  border-radius: var(--radius);
  background: var(--color-accent);
  color: white;
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>

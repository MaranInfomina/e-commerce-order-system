<script setup lang="ts">
import { computed, ref } from 'vue'
import { parseApiError, type ProductInput } from '~/utils/api'

const router = useRouter()
const { createProduct, listCategories } = useProductsApi()

const { data: categoriesResponse, error: categoriesError } = await useAsyncData('categories', () => listCategories())

// Mirrors pages/products/index.vue's apiError pattern: parseApiError is total,
// so this always resolves to a renderable message instead of leaving the
// category select silently empty with no explanation for the 422 that follows.
const categoriesLoadError = computed(() => (categoriesError.value ? parseApiError(categoriesError.value) : null))

const fieldErrors = ref<Record<string, string[]>>({})
const formError = ref('')
const submitting = ref(false)

async function submit(payload: ProductInput) {
  // The :disabled attribute already blocks a second click, but that guarantee
  // is incidental to the DOM patch timing rather than structural — make it
  // unconditional on the one action that creates data.
  if (submitting.value) return

  submitting.value = true
  fieldErrors.value = {}
  formError.value = ''

  try {
    const created = await createProduct(payload)
    await router.push(`/products?search=${encodeURIComponent(created.data.name)}`)
  }
  catch (error) {
    const parsed = parseApiError(error)
    fieldErrors.value = parsed.fields
    // Only show the banner when no field owns the problem, so the user is
    // not told twice about the same thing.
    formError.value = Object.keys(parsed.fields).length > 0 ? '' : parsed.message
  }
  finally {
    submitting.value = false
  }
}
</script>

<template>
  <section>
    <header class="head">
      <h1>New product</h1>
      <NuxtLink to="/products">Back to products</NuxtLink>
    </header>

    <p v-if="categoriesLoadError" class="banner">{{ categoriesLoadError.message }}</p>
    <p v-if="formError" class="banner">{{ formError }}</p>

    <ProductForm
      :categories="categoriesResponse?.data ?? []"
      :errors="fieldErrors"
      :submitting="submitting"
      @submit="submit"
    />
  </section>
</template>

<style scoped>
.head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: var(--space-3);
}

.banner {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-error);
  border-radius: var(--radius);
  color: var(--color-error);
}
</style>

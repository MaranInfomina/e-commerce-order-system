<script setup lang="ts">
import { computed, ref } from 'vue'
import { parseApiError, type ProductInput } from '~/utils/api'

// definePageMeta middleware, not a bare navigateTo in setup: navigateTo does
// NOT abort the rest of setup on the server — confirmed live, a plain
// `curl /products/new` as an anonymous visitor still fires a real
// GET /api/v1/categories from Nuxt before the redirect response is sent, on
// a page that is being redirected away from. Middleware runs and can return
// before any of that executes.
definePageMeta({
  middleware: [
    () => {
      const { isAuthenticated, isAdmin } = useAuth()

      // Two distinct outcomes, because the API has two. An anonymous POST is
      // a 401 (auth:api rejects before the form request runs); an
      // authenticated CUSTOMER's POST is a 403 that
      // ProductStoreRequest::authorize() returns before validation, so it
      // carries no per-field details and the form has nothing useful to
      // render. Neither should reach the form. Gating on isAuthenticated
      // alone hands a customer the whole form and a generic permission
      // banner on submit — and it disagrees with Step 9, which hides the
      // create control from that same customer.
      if (!isAuthenticated.value) {
        // The redirect query is what the login page reads to send them back.
        return navigateTo('/login?redirect=/products/new')
      }

      if (!isAdmin.value) {
        // Signing in is not the remedy — they already are. Bouncing a
        // customer to /login would loop them straight back here.
        return navigateTo('/products')
      }
    },
  ],
})

// **This is convenience, not authorization.** The real control is
// ProductPolicy (Task 6): the server authorizes every write against the
// database row, so a caller who navigates here directly, disables
// JavaScript, or uses the API by hand is stopped there, not here.

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

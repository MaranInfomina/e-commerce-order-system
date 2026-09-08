<script setup lang="ts">
import { parseApiError } from '~/utils/api'

const { register } = useAuth()
const route = useRoute()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)
const formError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

// Registration never signs the new account in - carry the redirect straight
// through to /login so a completed sign-in still lands where the visitor was
// originally headed.
const loginHref = computed(() => {
  const raw = route.query.redirect
  return typeof raw === 'string' && raw
    ? `/login?redirect=${encodeURIComponent(raw)}`
    : '/login'
})

async function submit() {
  if (submitting.value) return

  submitting.value = true
  formError.value = null
  fieldErrors.value = {}

  try {
    await register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })

    await navigateTo(loginHref.value)
  }
  catch (error) {
    const parsed = parseApiError(error)
    formError.value = parsed.message
    fieldErrors.value = parsed.fields
  }
  finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="mx-auto flex max-w-sm flex-col gap-6 py-12">
    <h1 class="text-center text-2xl font-bold tracking-tight text-slate-900">Create an account</h1>

    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
      <p
        v-if="formError"
        class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        data-test="form-error"
      >
        {{ formError }}
      </p>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
        <label for="name" class="flex flex-col gap-1 text-sm font-medium text-slate-600">
          Name
          <input
            id="name"
            v-model="name"
            type="text"
            data-test="field-name"
            required
            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          >
          <span v-if="fieldErrors.name" class="text-xs text-red-600">{{ fieldErrors.name[0] }}</span>
        </label>

        <label for="email" class="flex flex-col gap-1 text-sm font-medium text-slate-600">
          Email
          <input
            id="email"
            v-model="email"
            type="email"
            data-test="field-email"
            required
            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          >
          <span v-if="fieldErrors.email" class="text-xs text-red-600">{{ fieldErrors.email[0] }}</span>
        </label>

        <label for="password" class="flex flex-col gap-1 text-sm font-medium text-slate-600">
          Password
          <input
            id="password"
            v-model="password"
            type="password"
            data-test="field-password"
            required
            minlength="12"
            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          >
          <span v-if="fieldErrors.password" class="text-xs text-red-600">{{ fieldErrors.password[0] }}</span>
          <span v-else class="text-xs text-slate-400">At least 12 characters.</span>
        </label>

        <label for="password_confirmation" class="flex flex-col gap-1 text-sm font-medium text-slate-600">
          Confirm password
          <input
            id="password_confirmation"
            v-model="passwordConfirmation"
            type="password"
            data-test="field-password-confirmation"
            required
            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          >
        </label>

        <button
          type="submit"
          :disabled="submitting"
          data-test="submit"
          class="mt-2 w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ submitting ? 'Creating account…' : 'Create account' }}
        </button>
      </form>
    </div>

    <p class="text-center text-sm text-slate-500">
      Already have an account?
      <NuxtLink :to="loginHref" class="font-medium text-indigo-600 hover:text-indigo-500">Sign in</NuxtLink>
    </p>
  </section>
</template>

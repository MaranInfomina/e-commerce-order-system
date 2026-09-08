<script setup lang="ts">
import { parseApiError } from '~/utils/api'

definePageMeta({
  middleware: [
    () => {
      const { isAuthenticated } = useAuth()

      if (!isAuthenticated.value) {
        return navigateTo('/login?redirect=/profile')
      }
    },
  ],
})

const { getMe, updateProfile } = useAuth()

const { data: meResponse, error } = await useAsyncData('me', () => getMe())

const apiError = computed(() => (error.value ? parseApiError(error.value) : null))

const name = ref(meResponse.value?.data.name ?? '')
const email = ref(meResponse.value?.data.email ?? '')
const password = ref('')
const passwordConfirmation = ref('')
const submitting = ref(false)
const formError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

async function submit() {
  if (submitting.value) return

  submitting.value = true
  formError.value = null
  fieldErrors.value = {}

  // Only sent when the visitor actually typed a new one - UpdateProfileRequest
  // treats every field as optional, but an empty string would still fail its
  // min:12 rule if the key were present at all.
  const payload: Record<string, string> = { name: name.value, email: email.value }

  if (password.value) {
    payload.password = password.value
    payload.password_confirmation = passwordConfirmation.value
  }

  try {
    const updated = await updateProfile(payload)
    name.value = updated.name
    email.value = updated.email
    password.value = ''
    passwordConfirmation.value = ''
  }
  catch (thrown) {
    const parsed = parseApiError(thrown)
    formError.value = parsed.message
    fieldErrors.value = parsed.fields
  }
  finally {
    submitting.value = false
  }
}
</script>

<template>
  <section class="mx-auto max-w-sm">
    <header class="mb-6">
      <h1 class="text-2xl font-bold tracking-tight text-slate-900">Your profile</h1>
    </header>

    <p v-if="apiError" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      {{ apiError.message }}
    </p>

    <template v-else-if="meResponse">
      <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between text-xs text-slate-400">
          <span class="rounded-full bg-slate-100 px-2 py-0.5 font-medium uppercase tracking-wide text-slate-500">
            {{ meResponse.data.role }}
          </span>
          <span>Member since {{ new Date(meResponse.data.created_at).toLocaleDateString() }}</span>
        </div>

        <p
          v-if="formError"
          class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
          data-test="form-error"
        >
          {{ formError }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
            Name
            <input
              v-model="name"
              type="text"
              data-test="field-name"
              required
              class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            >
            <span v-if="fieldErrors.name" class="text-xs text-red-600">{{ fieldErrors.name[0] }}</span>
          </label>

          <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
            Email
            <input
              v-model="email"
              type="email"
              data-test="field-email"
              required
              class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
            >
            <span v-if="fieldErrors.email" class="text-xs text-red-600">{{ fieldErrors.email[0] }}</span>
          </label>

          <div class="border-t border-slate-100 pt-4">
            <p class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">
              Change password (optional)
            </p>

            <label class="flex flex-col gap-1 text-sm font-medium text-slate-600">
              New password
              <input
                v-model="password"
                type="password"
                data-test="field-password"
                minlength="12"
                placeholder="Leave blank to keep your current password"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              >
              <span v-if="fieldErrors.password" class="text-xs text-red-600">{{ fieldErrors.password[0] }}</span>
            </label>

            <label v-if="password" class="mt-3 flex flex-col gap-1 text-sm font-medium text-slate-600">
              Confirm new password
              <input
                v-model="passwordConfirmation"
                type="password"
                data-test="field-password-confirmation"
                class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
              >
            </label>
          </div>

          <button
            type="submit"
            :disabled="submitting"
            data-test="submit"
            class="mt-2 w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {{ submitting ? 'Saving…' : 'Save changes' }}
          </button>
        </form>
      </div>
    </template>
  </section>
</template>

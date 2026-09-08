<script setup lang="ts">
import { parseApiError } from '~/utils/api'

const { login } = useAuth()
const route = useRoute()

const email = ref('')
const password = ref('')
const submitting = ref(false)
const formError = ref<string | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})

async function submit() {
  if (submitting.value) return

  submitting.value = true
  formError.value = null
  fieldErrors.value = {}

  try {
    await login(email.value, password.value)

    // Return the user where they were headed, defaulting to the catalog.
    // Validated: the query string is attacker-controlled. Only a same-origin
    // absolute path is legal — not an absolute URL, not protocol-relative
    // `//evil.com`, not the backslash forms `/\evil.com` or `\/evil.com` that
    // browsers normalise to it, and not a leading whitespace/control
    // character (`/\t/evil.com`, `/\n/evil.com`) — neither `/` nor `\`, so it
    // slipped past an earlier version of this guard. navigateTo's own
    // internal check (ufo's protocol-relative regex, which does include
    // `\s*`) would have caught it anyway, but only by THROWING — which the
    // catch below turns into a "FAILED LOGIN" banner after the token has
    // already been set. This guard must be the one that stops it, not a
    // transitive dependency's side effect.
    const raw = route.query.redirect
    const intended = typeof raw === 'string' && /^\/(?![/\\\s])/.test(raw) ? raw : '/products'
    await navigateTo(intended)
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
    <h1 class="text-center text-2xl font-bold tracking-tight text-slate-900">Sign in</h1>

    <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
      <p
        v-if="formError"
        class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        data-test="form-error"
      >
        {{ formError }}
      </p>

      <form class="flex flex-col gap-4" @submit.prevent="submit">
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
            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
          >
          <span v-if="fieldErrors.password" class="text-xs text-red-600">{{ fieldErrors.password[0] }}</span>
        </label>

        <button
          type="submit"
          :disabled="submitting"
          data-test="submit"
          class="mt-2 w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ submitting ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>
    </div>
  </section>
</template>

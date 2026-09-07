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
    // `//evil.com`, and not the backslash forms `/\evil.com` or `\/evil.com`
    // that browsers normalise to it and that a naive "starts with /" check
    // waves through. navigateTo happens to refuse externals too, but that is
    // a transitive dependency's regex, and its failure mode is a thrown error
    // that the catch below reports as a FAILED LOGIN after the token has
    // already been set.
    const raw = route.query.redirect
    const intended = typeof raw === 'string' && /^\/(?![/\\])/.test(raw) ? raw : '/products'
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
  <section class="login">
    <h1>Sign in</h1>

    <p v-if="formError" class="banner" data-test="form-error">{{ formError }}</p>

    <form @submit.prevent="submit">
      <label for="email">Email</label>
      <input id="email" v-model="email" type="email" data-test="field-email" required>
      <p v-if="fieldErrors.email" class="field-error">{{ fieldErrors.email[0] }}</p>

      <label for="password">Password</label>
      <input id="password" v-model="password" type="password" data-test="field-password" required>
      <p v-if="fieldErrors.password" class="field-error">{{ fieldErrors.password[0] }}</p>

      <button type="submit" :disabled="submitting" data-test="submit">
        {{ submitting ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>
  </section>
</template>

<style scoped>
.login {
  max-width: 22rem;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

form {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

label {
  font-weight: 600;
}

input {
  padding: var(--space-2);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-bg);
  color: inherit;
}

.banner,
.field-error {
  color: var(--color-error);
  margin: 0;
}

button {
  padding: var(--space-2) var(--space-3);
  border: 0;
  border-radius: var(--radius);
  background: var(--color-accent);
  color: var(--color-bg);
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>

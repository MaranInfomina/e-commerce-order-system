<script setup lang="ts">
const { isAuthenticated, logout } = useAuth()
const route = useRoute()

// Never point sign-in back at itself.
const signInHref = computed(() =>
  route.path === '/login'
    ? '/login'
    : `/login?redirect=${encodeURIComponent(route.fullPath)}`,
)

async function signOut() {
  // logout() revokes the jti server-side and clears the cookie either way.
  await logout()
  await navigateTo('/login')
}
</script>

<template>
  <NuxtRouteAnnouncer />

  <header class="site">
    <NuxtLink to="/products" class="brand">Catalog</NuxtLink>

    <button v-if="isAuthenticated" type="button" data-test="sign-out" @click="signOut">
      Sign out
    </button>
    <NuxtLink v-else :to="signInHref" data-test="sign-in">Sign in</NuxtLink>
  </header>

  <NuxtPage />
</template>

<style scoped>
.site {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: var(--space-3);
  padding-bottom: var(--space-2);
  border-bottom: 1px solid var(--color-border);
  margin-bottom: var(--space-3);
}

.brand {
  font-weight: 600;
}

button {
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--color-border);
  border-radius: var(--radius);
  background: var(--color-bg);
  color: inherit;
  cursor: pointer;
}
</style>

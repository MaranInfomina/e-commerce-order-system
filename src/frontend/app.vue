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

  <ToastStack />

  <div class="flex min-h-screen flex-col">
    <header class="sticky top-0 z-10 border-b border-slate-200 bg-white/80 backdrop-blur">
      <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
        <NuxtLink to="/products" class="text-lg font-bold tracking-tight text-slate-900 hover:text-indigo-600">
          COE&nbsp;Store
        </NuxtLink>

        <nav class="flex items-center gap-4">
          <template v-if="isAuthenticated">
            <NuxtLink
              to="/cart"
              class="hidden text-sm font-medium text-slate-600 hover:text-indigo-600 sm:block"
            >
              Cart
            </NuxtLink>
            <NuxtLink
              to="/orders"
              class="hidden text-sm font-medium text-slate-600 hover:text-indigo-600 sm:block"
            >
              Your orders
            </NuxtLink>
          </template>

          <button
            v-if="isAuthenticated"
            type="button"
            data-test="sign-out"
            class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition-colors hover:border-red-200 hover:bg-red-50 hover:text-red-600"
            @click="signOut"
          >
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" stroke-linecap="round" stroke-linejoin="round" />
              <path d="M16 17l5-5-5-5M21 12H9" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            Sign out
          </button>
          <NuxtLink
            v-else
            :to="signInHref"
            data-test="sign-in"
            class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition-colors hover:bg-indigo-500"
          >
            Sign in
          </NuxtLink>
        </nav>
      </div>
    </header>

    <main class="mx-auto w-full max-w-7xl flex-1 px-4 py-8 sm:px-6 lg:px-8">
      <NuxtPage />
    </main>
  </div>
</template>

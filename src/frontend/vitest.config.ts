import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    // `~` is a Nuxt alias, and Nuxt is not running here. Without it, any test
    // that imports a composable — which is how the token-attachment tests
    // reach useProductsApi — fails to resolve `~/utils/api`.
    alias: {
      '~': fileURLToPath(new URL('.', import.meta.url)),
    },
  },
  test: {
    environment: 'happy-dom',
    include: ['tests/**/*.spec.ts'],
  },
})

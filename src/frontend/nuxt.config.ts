export default defineNuxtConfig({
  devtools: { enabled: true },

  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    // Server-side only: a relative URL has no host inside the container.
    apiBaseServer: 'http://nginx/api/v1',
    public: {
      // Browser-side: same origin through nginx, so no CORS.
      apiBase: '/api/v1',
    },
  },

  devServer: {
    host: '0.0.0.0',
    port: 3000,
  },

  vite: {
    server: {
      hmr: {
        // The browser talks to nginx on APP_PORT, not directly to 3000.
        clientPort: 8080,
      },
    },
  },
})

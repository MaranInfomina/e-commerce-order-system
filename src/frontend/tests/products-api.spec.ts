import { afterEach, describe, expect, it, vi } from 'vitest'
import { useProductsApi } from '../composables/useProductsApi'

interface Call { url: string, options: Record<string, unknown> }

const calls: Call[] = []

/** Stand in for the Nuxt auto-imports the composable resolves as globals. */
function install(token: string | null | undefined) {
  calls.length = 0
  vi.stubGlobal('useRuntimeConfig', () => ({
    apiBaseServer: 'http://nginx/api/v1',
    public: { apiBase: '/api/v1' },
  }))
  vi.stubGlobal('useCookie', () => ({ value: token }))
  // useProductsApi reads the token through useAuth() rather than its own
  // useCookie('coe_token') call (a review fix — two independent refs for the
  // same cookie name is a trap for a future write), so that global needs
  // stubbing too, matching the shape useAuth() actually returns.
  vi.stubGlobal('useAuth', () => ({ token: { value: token } }))
  vi.stubGlobal('$fetch', (url: string, options: Record<string, unknown> = {}) => {
    calls.push({ url, options })
    return Promise.resolve({ data: {} })
  })
}

afterEach(() => vi.unstubAllGlobals())

describe('useProductsApi', () => {
  it('sends the bearer token on the write', async () => {
    install('tok-123')
    await useProductsApi().createProduct({} as never)

    expect(calls[0].options.headers).toEqual({ Authorization: 'Bearer tok-123' })
  })

  it('sends no Authorization header when the cookie is unset', async () => {
    // undefined, not null: that is what useCookie yields for an absent cookie.
    install(undefined)
    await useProductsApi().createProduct({} as never)

    expect(calls[0].options.headers).toEqual({})
  })

  it('never sends the token on the public reads', async () => {
    // Reads are public (FR-18). A token here would leak it to any cache and
    // imply the endpoint is authenticated.
    install('tok-123')
    const api = useProductsApi()
    await api.listProducts({ page: 1, perPage: 15, search: '', category: '', sort: '' })
    await api.listCategories()

    expect(calls).toHaveLength(2)
    expect(calls.every(c => c.options.headers === undefined)).toBe(true)
  })
})

import { afterEach, describe, expect, it, vi } from 'vitest'
import { useOrdersApi } from '../composables/useOrdersApi'

interface Call { url: string, options: Record<string, unknown> }

const calls: Call[] = []

function install(token: string | null | undefined) {
  calls.length = 0
  vi.stubGlobal('useRuntimeConfig', () => ({
    apiBaseServer: 'http://nginx/api/v1',
    public: { apiBase: '/api/v1' },
  }))
  vi.stubGlobal('useAuth', () => ({ token: { value: token } }))
  vi.stubGlobal('$fetch', (url: string, options: Record<string, unknown> = {}) => {
    calls.push({ url, options })
    return Promise.resolve({ data: {} })
  })
}

afterEach(() => vi.unstubAllGlobals())

describe('useOrdersApi', () => {
  it('attaches the bearer token on listOrders', async () => {
    install('tok-123')
    await useOrdersApi().listOrders()

    expect(calls[0].url).toBe('/api/v1/orders')
    expect(calls[0].options.headers).toEqual({ Authorization: 'Bearer tok-123' })
  })

  it('attaches the bearer token on getOrder', async () => {
    install('tok-123')
    await useOrdersApi().getOrder(42)

    expect(calls[0].url).toBe('/api/v1/orders/42')
    expect(calls[0].options.headers).toEqual({ Authorization: 'Bearer tok-123' })
  })

  it('sends no Authorization header when the cookie is unset', async () => {
    install(undefined)
    await useOrdersApi().listOrders()

    expect(calls[0].options.headers).toEqual({})
  })
})

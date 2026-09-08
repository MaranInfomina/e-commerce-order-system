import { authHeaders, resolveApiBase, type Order, type OrderInput } from '~/utils/api'

export function useOrdersApi() {
  const config = useRuntimeConfig()
  const { token } = useAuth()

  const base = resolveApiBase(import.meta.server, {
    apiBaseServer: config.apiBaseServer as string,
    public: { apiBase: config.public.apiBase as string },
  })

  function listOrders() {
    return $fetch<{ data: Order[] }>(`${base}/orders`, {
      headers: authHeaders(token.value),
    })
  }

  function getOrder(id: number | string) {
    return $fetch<{ data: Order }>(`${base}/orders/${id}`, {
      headers: authHeaders(token.value),
    })
  }

  /** Converts the caller's cart into an order - see POST /orders. The API clears the cart server-side on success. */
  function createOrder(payload: OrderInput) {
    return $fetch<{ data: Order }>(`${base}/orders`, {
      method: 'POST',
      headers: authHeaders(token.value),
      body: payload,
    })
  }

  return { listOrders, getOrder, createOrder }
}

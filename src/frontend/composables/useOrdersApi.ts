import { authHeaders, resolveApiBase, type Order } from '~/utils/api'

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

  return { listOrders, getOrder }
}

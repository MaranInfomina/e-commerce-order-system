import { authHeaders, resolveApiBase, type Cart } from '~/utils/api'

export function useCartApi() {
  const config = useRuntimeConfig()
  const { token } = useAuth()

  const base = resolveApiBase(import.meta.server, {
    apiBaseServer: config.apiBaseServer as string,
    public: { apiBase: config.public.apiBase as string },
  })

  function getCart() {
    return $fetch<{ data: Cart }>(`${base}/cart`, {
      headers: authHeaders(token.value),
    })
  }

  /** Increments an existing line rather than replacing it - see POST /cart/items. */
  function addItem(productId: number, quantity: number) {
    return $fetch<{ data: Cart }>(`${base}/cart/items`, {
      method: 'POST',
      headers: authHeaders(token.value),
      body: { product_id: productId, quantity },
    })
  }

  /** Sets the line to an exact quantity - see PATCH /cart/items/{product}. */
  function updateItem(productId: number, quantity: number) {
    return $fetch<{ data: Cart }>(`${base}/cart/items/${productId}`, {
      method: 'PATCH',
      headers: authHeaders(token.value),
      body: { quantity },
    })
  }

  function removeItem(productId: number) {
    return $fetch<null>(`${base}/cart/items/${productId}`, {
      method: 'DELETE',
      headers: authHeaders(token.value),
    })
  }

  return { getCart, addItem, updateItem, removeItem }
}

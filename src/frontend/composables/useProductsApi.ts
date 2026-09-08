import {
  authHeaders,
  buildProductQuery,
  resolveApiBase,
  type Category,
  type Paginated,
  type Product,
  type ProductInput,
  type ProductListState,
} from '~/utils/api'

export function useProductsApi() {
  const config = useRuntimeConfig()
  // Read through useAuth() rather than a second useCookie('coe_token') call.
  // This composable never writes the cookie, so the two refs can't race
  // today — but a second declaration with no options object is a trap for
  // whoever adds a write later (e.g. clearing it on a 401): it would land
  // with the framework defaults instead of the sameSite/secure/maxAge
  // contract useAuth() declares, silently downgrading the cookie.
  const { token } = useAuth()

  const base = resolveApiBase(import.meta.server, {
    apiBaseServer: config.apiBaseServer as string,
    public: { apiBase: config.public.apiBase as string },
  })

  function listProducts(state: ProductListState) {
    return $fetch<Paginated<Product>>(`${base}/products`, {
      query: buildProductQuery(state),
    })
  }

  function createProduct(payload: ProductInput) {
    return $fetch<{ data: Product }>(`${base}/products`, {
      method: 'POST',
      body: payload,
      // Writes are admin-only from Milestone 2 onward; reads stay public
      // and deliberately send no header.
      headers: authHeaders(token.value),
    })
  }

  function listCategories() {
    return $fetch<{ data: Category[] }>(`${base}/categories`)
  }

  /**
   * multipart/form-data, not JSON - a File can't be serialized into a JSON
   * body. $fetch/ofetch sets the correct Content-Type (with boundary) itself
   * for a FormData body, so authHeaders' Authorization header is the only
   * one this needs to add by hand.
   */
  function uploadProductImage(productId: number, image: File) {
    const formData = new FormData()
    formData.append('image', image)

    return $fetch<{ data: Product }>(`${base}/products/${productId}/image`, {
      method: 'POST',
      body: formData,
      headers: authHeaders(token.value),
    })
  }

  return { listProducts, createProduct, listCategories, uploadProductImage }
}

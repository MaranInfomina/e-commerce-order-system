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
  const token = useCookie<string | null>('coe_token')

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

  return { listProducts, createProduct, listCategories }
}

import {
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
    })
  }

  function listCategories() {
    return $fetch<{ data: Category[] }>(`${base}/categories`)
  }

  return { listProducts, createProduct, listCategories }
}

export interface Category {
  id: number
  name: string
  slug: string
}

export interface Product {
  id: number
  name: string
  slug: string
  sku: string
  description: string | null
  price_cents: number
  stock_quantity: number
  is_active: boolean
  category: Category | null
  created_at: string
  updated_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface ProductListState {
  page: number
  perPage: number
  search: string
  category: string
  sort: string
}

export interface ProductInput {
  category_id: number | null
  name: string
  slug: string
  sku: string
  description: string
  price_cents: number
  stock_quantity: number
  is_active: boolean
}

export interface ParsedApiError {
  message: string
  fields: Record<string, string[]>
}

interface RuntimeConfigShape {
  apiBaseServer: string
  public: { apiBase: string }
}

const DEFAULT_PER_PAGE = 15

/**
 * A relative URL has no host inside the container, so server-side rendering
 * must use the absolute internal address. The browser uses the same-origin
 * relative path, which needs no CORS.
 */
export function resolveApiBase(isServer: boolean, config: RuntimeConfigShape): string {
  return isServer ? config.apiBaseServer : config.public.apiBase
}

/** Only non-default values reach the URL, keeping shared links readable. */
export function buildProductQuery(state: ProductListState): Record<string, string> {
  const query: Record<string, string> = {}

  if (state.page > 1) query.page = String(state.page)
  if (state.perPage && state.perPage !== DEFAULT_PER_PAGE) query.per_page = String(state.perPage)
  if (state.search) query.search = state.search
  if (state.category) query.category = state.category
  if (state.sort) query.sort = state.sort

  return query
}

/** Turns the API's error envelope into something a form can render. */
export function parseApiError(error: unknown): ParsedApiError {
  const envelope = (error as { data?: { error?: { message?: string, details?: Record<string, string[]> } } })?.data?.error

  if (envelope?.message) {
    return {
      message: envelope.message,
      fields: envelope.details ?? {},
    }
  }

  return {
    message: 'Something went wrong. Please try again.',
    fields: {},
  }
}

/** Integer cents to a display string. Money never becomes a float. */
export function formatCents(cents: number): string {
  const whole = Math.floor(Math.abs(cents) / 100)
  const fraction = String(Math.abs(cents) % 100).padStart(2, '0')
  const sign = cents < 0 ? '-' : ''

  return `${sign}${whole}.${fraction}`
}

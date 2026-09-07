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
  image_url: string | null
  price_cents: number
  stock_quantity: number
  is_active: boolean
  category: Category | null
  created_at: string
  updated_at: string
}

export interface Paginated<T> {
  data: T[]
  links: {
    first: string
    last: string
    prev: string | null
    next: string | null
  }
  meta: {
    current_page: number
    from: number | null
    last_page: number
    path: string
    per_page: number
    to: number | null
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
  price_cents: number | null
  stock_quantity: number | null
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

/**
 * Read the role claim without verifying the signature. This is safe here
 * because it decides only what the UI renders — the server authorizes
 * against the database row, never this claim. Total by design: it runs on
 * every render decision, so a malformed token must return null rather than
 * throw and blank the page.
 */
export function decodeJwtRole(token: string | null | undefined): string | null {
  // The signature accepts null|undefined because useCookie yields undefined
  // for an absent cookie; splitting that would throw the very TypeError this
  // function exists to avoid.
  if (!token) return null

  const parts = token.split('.')

  if (parts.length !== 3) return null

  try {
    const padded = parts[1].replace(/-/g, '+').replace(/_/g, '/')
    const payload = JSON.parse(atob(padded)) as { role?: unknown }

    return typeof payload.role === 'string' ? payload.role : null
  }
  catch {
    return null
  }
}

/** An empty object rather than an empty header: the API rejects `Bearer `. */
export function authHeaders(token: string | null | undefined): Record<string, string> {
  return token ? { Authorization: `Bearer ${token}` } : {}
}

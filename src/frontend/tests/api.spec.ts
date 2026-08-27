import { describe, expect, it } from 'vitest'
import { buildProductQuery, parseApiError, resolveApiBase } from '../utils/api'

const config = {
  apiBaseServer: 'http://nginx/api/v1',
  public: { apiBase: '/api/v1' },
}

describe('resolveApiBase', () => {
  it('uses the absolute internal URL during server-side rendering', () => {
    expect(resolveApiBase(true, config)).toBe('http://nginx/api/v1')
  })

  it('uses the same-origin relative URL in the browser', () => {
    expect(resolveApiBase(false, config)).toBe('/api/v1')
  })
})

describe('buildProductQuery', () => {
  it('omits empty and default values', () => {
    expect(buildProductQuery({ page: 1, perPage: 15, search: '', category: '', sort: '' }))
      .toEqual({})
  })

  it('serializes every populated field', () => {
    expect(buildProductQuery({
      page: 3,
      perPage: 25,
      search: 'kettle',
      category: 'kitchen',
      sort: '-price',
    })).toEqual({
      page: '3',
      per_page: '25',
      search: 'kettle',
      category: 'kitchen',
      sort: '-price',
    })
  })

  it('drops page one so the first page has a clean URL', () => {
    const query = buildProductQuery({ page: 1, perPage: 15, search: 'x', category: '', sort: '' })
    expect(query.page).toBeUndefined()
    expect(query.search).toBe('x')
  })
})

describe('parseApiError', () => {
  it('extracts message and field details from the API envelope', () => {
    const result = parseApiError({
      data: {
        error: {
          code: 'VALIDATION_FAILED',
          message: 'The given data was invalid.',
          details: { price_cents: ['The price cents field must be an integer.'] },
        },
      },
    })

    expect(result.message).toBe('The given data was invalid.')
    expect(result.fields.price_cents).toEqual(['The price cents field must be an integer.'])
  })

  it('falls back to a generic message for an unrecognized shape', () => {
    const result = parseApiError(new Error('network down'))

    expect(result.message).toBe('Something went wrong. Please try again.')
    expect(result.fields).toEqual({})
  })
})

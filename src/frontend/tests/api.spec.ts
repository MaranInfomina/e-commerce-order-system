import { describe, expect, it } from 'vitest'
import { buildProductQuery, formatCents, parseApiError, resolveApiBase } from '../utils/api'

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

  it('defaults fields to an empty object when the envelope has no details, as with NOT_FOUND', () => {
    const result = parseApiError({
      data: {
        error: {
          code: 'NOT_FOUND',
          message: 'The requested resource could not be found.',
        },
      },
    })

    expect(result.message).toBe('The requested resource could not be found.')
    expect(result.fields).toEqual({})
  })
})

describe('formatCents', () => {
  it('formats a normal value with a dollar sign', () => {
    expect(formatCents(204010)).toBe('$2,040.10')
  })

  it('formats zero', () => {
    expect(formatCents(0)).toBe('$0.00')
  })

  it('pads a fractional part under ten cents', () => {
    expect(formatCents(5)).toBe('$0.05')
  })

  it('formats a value under a full unit but at least ten cents', () => {
    expect(formatCents(50)).toBe('$0.50')
  })

  it('formats a value one cent under a whole unit', () => {
    expect(formatCents(1999)).toBe('$19.99')
  })

  it('formats an exact multiple of a whole unit with no fractional remainder', () => {
    expect(formatCents(500)).toBe('$5.00')
  })

  it('formats a negative value with the sign before the dollar sign', () => {
    expect(formatCents(-150)).toBe('-$1.50')
  })

  it('adds thousands separators to large whole-dollar amounts', () => {
    expect(formatCents(123456789)).toBe('$1,234,567.89')
  })
})

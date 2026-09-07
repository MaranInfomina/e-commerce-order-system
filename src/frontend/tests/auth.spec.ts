import { describe, expect, it } from 'vitest'
import { authHeaders, decodeJwtRole } from '../utils/api'

/** Build a JWT-shaped string with the given payload. Signature is not checked client-side. */
function fakeJwt(payload: Record<string, unknown>): string {
  const b64 = (o: unknown) =>
    btoa(JSON.stringify(o)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')

  return `${b64({ alg: 'HS256', typ: 'JWT' })}.${b64(payload)}.signature`
}

describe('decodeJwtRole', () => {
  it('reads the role claim', () => {
    expect(decodeJwtRole(fakeJwt({ sub: '1', role: 'admin' }))).toBe('admin')
    expect(decodeJwtRole(fakeJwt({ sub: '2', role: 'customer' }))).toBe('customer')
  })

  it('returns null for a token with no role claim', () => {
    expect(decodeJwtRole(fakeJwt({ sub: '1' }))).toBeNull()
  })

  it('returns null rather than throwing on a malformed token', () => {
    // This runs on every render decision, so it must be total — a throw
    // here would blank the page.
    expect(decodeJwtRole('not-a-jwt')).toBeNull()
    expect(decodeJwtRole('')).toBeNull()
    expect(decodeJwtRole('a.b')).toBeNull()
    expect(decodeJwtRole('a.!!!not-base64!!!.c')).toBeNull()
  })

  it('returns null for an absent cookie value', () => {
    // useCookie yields undefined, not null, when the cookie is not set —
    // the distinction that would otherwise TypeError on every anonymous
    // SSR render of /products.
    expect(decodeJwtRole(undefined)).toBeNull()
    expect(decodeJwtRole(null)).toBeNull()
  })
})

describe('authHeaders', () => {
  it('attaches the bearer token when one exists', () => {
    expect(authHeaders('abc123')).toEqual({ Authorization: 'Bearer abc123' })
  })

  it('attaches nothing when there is no token', () => {
    // Both sides: an empty object, not a header with an empty value, which
    // the API would reject as malformed rather than treat as anonymous.
    expect(authHeaders(null)).toEqual({})
    expect(authHeaders('')).toEqual({})
  })
})

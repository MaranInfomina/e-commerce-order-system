import { authHeaders, decodeJwtRole, resolveApiBase, type ProfileUpdateInput, type User } from '~/utils/api'

interface LoginResponse {
  token: string
  token_type: string
  expires_in: number
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
}

export function useAuth() {
  // A cookie, not localStorage: SSR must be able to read the token to
  // render an authenticated page on the server, and localStorage does not
  // exist there.
  //
  // `lax`, not `strict`. `strict` withholds the cookie on a cross-site
  // top-level navigation — a link from an email or a chat client — which is
  // exactly the case SSR readability exists for: the server would render a
  // signed-in admin's page as anonymous, bounce them off /products/new, and
  // then disagree with the client on hydration. Nothing is given up, because
  // this cookie is NOT an authenticator: JwtGuard reads the Authorization
  // header and never a cookie, so no cross-site request is authenticated by
  // it and there is no cookie-CSRF surface to protect. Do not move to
  // cookie-based auth without adding CSRF protection.
  //
  // Not httpOnly, necessarily: the JS that builds the Authorization header
  // has to read this value, so an XSS steals it exactly as it would from
  // localStorage. The cookie is chosen for SSR readability, not as a defence.
  const token = useCookie<string | null>('coe_token', {
    sameSite: 'lax',
    // Milestone 4 added HTTPS (nginx redirects every plain-HTTP browser page
    // to it), so the cookie can require it too — a plain-HTTP request (an
    // old bookmark, a typed http:// URL) would otherwise still present the
    // token in the clear on that one request before the redirect ever fires.
    secure: true,
    maxAge: 60 * 60,
  })

  const toast = useToast()
  const config = useRuntimeConfig()

  const base = resolveApiBase(import.meta.server, {
    apiBaseServer: config.apiBaseServer as string,
    public: { apiBase: config.public.apiBase as string },
  })

  const isAuthenticated = computed(() => Boolean(token.value))
  // No `!== null` guard. Nuxt's useCookie does `ref(cookies[name] ?? default)`
  // and no default is supplied, so an ABSENT cookie yields `undefined`, not
  // null — `undefined !== null` is true, and decodeJwtRole would then run
  // .split() on undefined. That is a TypeError on every anonymous SSR render
  // of /products, the app's main screen, and the explicit <string | null>
  // type parameter hides it from TypeScript. Boolean-coerce instead.
  const isAdmin = computed(() => decodeJwtRole(token.value) === 'admin')

  async function login(email: string, password: string): Promise<void> {
    const response = await $fetch<LoginResponse>(`${base}/auth/login`, {
      method: 'POST',
      body: { email, password },
    })

    token.value = response.token
    toast.success('Signed in.')
  }

  // Registration never returns a token (POST /auth/register always creates a
  // customer and responds with just the created user - see
  // AuthController::register), so this only creates the account. The caller
  // sends the user to /login afterwards.
  async function register(payload: RegisterPayload): Promise<void> {
    await $fetch(`${base}/auth/register`, {
      method: 'POST',
      body: payload,
    })

    toast.success('Account created. Sign in to continue.')
  }

  function getMe() {
    return $fetch<{ data: User }>(`${base}/auth/me`, {
      headers: authHeaders(token.value),
    })
  }

  async function updateProfile(payload: ProfileUpdateInput): Promise<User> {
    const response = await $fetch<{ data: User }>(`${base}/auth/me`, {
      method: 'PATCH',
      headers: authHeaders(token.value),
      body: payload,
    })

    toast.success('Profile updated.')

    return response.data
  }

  async function logout(): Promise<void> {
    if (token.value) {
      try {
        await $fetch(`${base}/auth/logout`, {
          method: 'POST',
          headers: authHeaders(token.value),
        })
      }
      catch {
        // The server-side revocation is best-effort: if it fails, clearing
        // the cookie still logs the user out of this browser, and the token
        // expires on its own within the hour.
      }
    }

    token.value = null
    toast.success('Signed out.')
  }

  return { token, isAuthenticated, isAdmin, login, register, getMe, updateProfile, logout }
}

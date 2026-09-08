export type ToastType = 'success' | 'error' | 'info'

export interface Toast {
  id: number
  type: ToastType
  message: string
}

let nextId = 0

/**
 * Shared across every caller via useState — a toast pushed from useAuth
 * (login/logout) needs to render in the same stack as one pushed from a
 * page's own submit handler.
 */
export function useToast() {
  const toasts = useState<Toast[]>('toasts', () => [])

  function dismiss(id: number) {
    toasts.value = toasts.value.filter(toast => toast.id !== id)
  }

  function push(type: ToastType, message: string, durationMs = 4000) {
    const id = ++nextId
    toasts.value = [...toasts.value, { id, type, message }]

    // Toasts are only ever pushed from a client-side event handler (a submit,
    // a click) — never during SSR — but guard anyway rather than rely on that.
    if (durationMs > 0 && import.meta.client) {
      setTimeout(() => dismiss(id), durationMs)
    }
  }

  return {
    toasts,
    dismiss,
    success: (message: string) => push('success', message),
    error: (message: string) => push('error', message),
    info: (message: string) => push('info', message),
  }
}

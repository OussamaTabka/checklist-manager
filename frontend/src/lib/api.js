import axios from 'axios'

const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])

function resolveApiBaseUrl() {
  const configured = import.meta.env.VITE_API_URL

  if (!configured) {
    if (typeof window !== 'undefined') {
      return `${window.location.protocol}//${window.location.hostname}:8000/api`
    }

    return 'http://127.0.0.1:8000/api'
  }

  try {
    const parsed = new URL(configured)

    // Keep loopback hosts aligned with the current page host so XSRF cookies
    // are readable and axios can send X-XSRF-TOKEN correctly.
    if (typeof window !== 'undefined') {
      const pageHost = window.location.hostname
      const configuredHost = parsed.hostname

      if (
        LOOPBACK_HOSTS.has(pageHost) &&
        LOOPBACK_HOSTS.has(configuredHost) &&
        pageHost !== configuredHost
      ) {
        parsed.hostname = pageHost
      }
    }

    return parsed.toString().replace(/\/$/, '')
  } catch {
    return configured
  }
}

const API_BASE_URL = resolveApiBaseUrl()

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

const APP_BASE_URL = API_BASE_URL.replace(/\/api\/?$/, '')
const PUBLIC_AUTH_PATHS = new Set(['/login', '/forgot-password', '/reset-password', '/invitations/validate', '/invitations/accept'])

let unauthorizedEventQueued = false

function queueUnauthorizedEvent() {
  if (unauthorizedEventQueued || typeof window === 'undefined') {
    return
  }

  unauthorizedEventQueued = true
  window.dispatchEvent(new CustomEvent('auth:unauthorized'))

  setTimeout(() => {
    unauthorizedEventQueued = false
  }, 250)
}

export async function ensureCsrfCookie() {
  await axios.get(`${APP_BASE_URL}/sanctum/csrf-cookie`, {
    withCredentials: true,
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  })
}

export async function apiRequest(path, options = {}, token = null) {
  try {
    const savedToken = typeof window !== 'undefined' ? localStorage.getItem('auth_token') : null
    const effectiveToken = token || savedToken
    const currentLanguage =
      typeof document !== 'undefined' && document.documentElement?.lang
        ? document.documentElement.lang
        : 'fr'

    const response = await apiClient.request({
      url: path,
      method: options.method || 'GET',
      data: options.body,
      headers: {
        ...(effectiveToken
          ? {
              Authorization: `Bearer ${effectiveToken}`,
            }
          : {}),
        'X-App-Language': currentLanguage,
      },
    })

    return response.data
  } catch (axiosError) {
    const status = axiosError.response?.status
    if (status === 401 && !PUBLIC_AUTH_PATHS.has(path)) {
      queueUnauthorizedEvent()
    }

    const backendMessage = axiosError.response?.data?.message
    const backendError = axiosError.response?.data?.error
    const composedMessage = backendError
      ? `${backendMessage || 'Request failed'}: ${backendError}`
      : backendMessage || axiosError.message || 'Request failed'

    const error = new Error(composedMessage)
    error.status = status
    error.data = axiosError.response?.data
    throw error
  }
}

export function withQuery(path, query = {}) {
  const url = new URL(path, 'http://placeholder.local')

  Object.entries(query).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, value)
    }
  })

  return `${url.pathname}${url.search}`
}

export { API_BASE_URL }

import axios from 'axios'
import { getCurrentLanguage, localizeMessage, tr } from '@/lib/localization'

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
const inflightGetRequests = new Map()

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
const PUBLIC_AUTH_PATHS = new Set(['/login', '/forgot-password', '/reset-password', '/set-password', '/invitations/validate', '/invitations/accept'])

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
  const currentLanguage = getCurrentLanguage()
  const method = String(options.method || 'GET').toUpperCase()
  const savedToken = typeof window !== 'undefined' ? localStorage.getItem('auth_token') : null
  const effectiveToken = token || savedToken
  const dedupeKey = method === 'GET'
    ? JSON.stringify({
        method,
        path,
        token: effectiveToken || null,
        language: currentLanguage,
      })
    : null

  try {
    if (dedupeKey && inflightGetRequests.has(dedupeKey)) {
      return await inflightGetRequests.get(dedupeKey)
    }

    const requestPromise = apiClient.request({
      url: path,
      method,
      data: options.body,
      headers: {
        ...(effectiveToken
          ? {
              Authorization: `Bearer ${effectiveToken}`,
            }
          : {}),
        'X-App-Language': currentLanguage,
      },
    }).then((response) => response.data)

    if (dedupeKey) {
      inflightGetRequests.set(dedupeKey, requestPromise)
    }

    return await requestPromise
  } catch (axiosError) {
    const status = axiosError.response?.status
    if (status === 401 && !PUBLIC_AUTH_PATHS.has(path)) {
      queueUnauthorizedEvent()
    }

    const backendMessage = axiosError.response?.data?.message
    const backendError = axiosError.response?.data?.error
    const fallback = tr('request_failed', {}, currentLanguage)
    const composedMessage = backendError
      ? `${backendMessage || fallback}: ${backendError}`
      : backendMessage || axiosError.message || fallback

    const error = new Error(localizeMessage(composedMessage, currentLanguage))
    error.status = status
    error.data = axiosError.response?.data
    throw error
  } finally {
    if (dedupeKey) {
      inflightGetRequests.delete(dedupeKey)
    }
  }
}

function extractFilename(contentDisposition, fallback = 'download') {
  if (!contentDisposition) {
    return fallback
  }

  const utf8Match = contentDisposition.match(/filename\*\s*=\s*UTF-8''([^;]+)/i)
  if (utf8Match?.[1]) {
    return decodeURIComponent(utf8Match[1])
  }

  const filenameMatch = contentDisposition.match(/filename\s*=\s*"([^"]+)"/i) || contentDisposition.match(/filename\s*=\s*([^;]+)/i)
  if (filenameMatch?.[1]) {
    return filenameMatch[1].trim()
  }

  return fallback
}

export async function apiDownload(path, options = {}, token = null) {
  const currentLanguage = getCurrentLanguage()

  try {
    const savedToken = typeof window !== 'undefined' ? localStorage.getItem('auth_token') : null
    const effectiveToken = token || savedToken
    const url = withQuery(path, options.query || {})

    const response = await apiClient.request({
      url,
      method: options.method || 'GET',
      responseType: 'blob',
      headers: {
        ...(effectiveToken
          ? {
              Authorization: `Bearer ${effectiveToken}`,
            }
          : {}),
        'X-App-Language': currentLanguage,
      },
    })

    return {
      blob: response.data,
      filename: extractFilename(response.headers['content-disposition'], options.fallbackFilename || 'download'),
      contentType: response.headers['content-type'] || response.data?.type || 'application/octet-stream',
    }
  } catch (axiosError) {
    const status = axiosError.response?.status
    if (status === 401 && !PUBLIC_AUTH_PATHS.has(path)) {
      queueUnauthorizedEvent()
    }

    let backendMessage = axiosError.response?.data?.message
    let backendError = axiosError.response?.data?.error

    if (axiosError.response?.data instanceof Blob) {
      try {
        const text = await axiosError.response.data.text()
        const parsed = JSON.parse(text)
        backendMessage = parsed.message || backendMessage
        backendError = parsed.error || backendError
      } catch {
      }
    }

    const fallback = tr('request_failed', {}, currentLanguage)
    const composedMessage = backendError
      ? `${backendMessage || fallback}: ${backendError}`
      : backendMessage || axiosError.message || fallback

    const error = new Error(localizeMessage(composedMessage, currentLanguage))
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

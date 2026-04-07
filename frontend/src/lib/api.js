import axios from 'axios'

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://127.0.0.1:8000/api'

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
    const response = await apiClient.request({
      url: path,
      method: options.method || 'GET',
      data: options.body,
      headers: token
        ? {
            Authorization: `Bearer ${token}`,
          }
        : undefined,
    })

    return response.data
  } catch (axiosError) {
    const backendMessage = axiosError.response?.data?.message
    const backendError = axiosError.response?.data?.error
    const composedMessage = backendError
      ? `${backendMessage || 'Request failed'}: ${backendError}`
      : backendMessage || axiosError.message || 'Request failed'

    const error = new Error(composedMessage)
    error.status = axiosError.response?.status
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
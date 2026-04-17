import { apiRequest } from '@/lib/api'

export async function analyzePage(url, token = null) {
  return apiRequest(
    '/analyze',
    {
      method: 'POST',
      body: {
        url,
      },
    },
    token,
  )
}

import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiRequest, ensureCsrfCookie } from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref('')
  const user = ref(null)
  const roles = ref([])
  const isBootstrapped = ref(false)

  const isAuthenticated = computed(() => Boolean(user.value))
  const isAdmin = computed(() => roles.value.includes('admin'))
  const canManageProjects = computed(() => roles.value.includes('admin') || roles.value.includes('chef'))
  const canTest = computed(() => canManageProjects.value || roles.value.includes('testeur'))

  function clear() {
    token.value = ''
    user.value = null
    roles.value = []
  }

  function hydrate() {
    if (isBootstrapped.value) {
      return
    }

    isBootstrapped.value = true
  }

  async function login(credentials) {
    await ensureCsrfCookie()
    const data = await apiRequest('/login', { method: 'POST', body: credentials })
    user.value = data.user
    roles.value = data.roles || []
    return data
  }

  async function fetchMe() {
    try {
      const data = await apiRequest('/me')
      user.value = data.user
      roles.value = data.roles || []
      return data
    } catch {
      clear()
      return null
    }
  }

  async function logout() {
    try {
      await apiRequest('/logout', { method: 'POST' })
    } catch {
    }

    clear()
  }

  function hasAnyRole(allowedRoles = []) {
    return allowedRoles.length === 0 || allowedRoles.some((role) => roles.value.includes(role))
  }

  return {
    token,
    user,
    roles,
    isAuthenticated,
    isAdmin,
    canManageProjects,
    canTest,
    isBootstrapped,
    hydrate,
    login,
    fetchMe,
    logout,
    hasAnyRole,
  }
})
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiRequest } from '@/lib/api'

const STORAGE_KEY = 'checklist-manager-auth'

export const useAuthStore = defineStore('auth', () => {
  const token = ref('')
  const user = ref(null)
  const roles = ref([])
  const isBootstrapped = ref(false)

  const isAuthenticated = computed(() => Boolean(token.value))
  const isAdmin = computed(() => roles.value.includes('admin'))
  const canManageProjects = computed(() => roles.value.includes('admin') || roles.value.includes('chef'))
  const canTest = computed(() => canManageProjects.value || roles.value.includes('testeur'))

  function persist() {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        token: token.value,
        user: user.value,
        roles: roles.value,
      }),
    )
  }

  function clear() {
    token.value = ''
    user.value = null
    roles.value = []
    localStorage.removeItem(STORAGE_KEY)
  }

  function hydrate() {
    if (isBootstrapped.value) {
      return
    }

    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) {
      isBootstrapped.value = true
      return
    }

    try {
      const parsed = JSON.parse(raw)
      token.value = parsed.token || ''
      user.value = parsed.user || null
      roles.value = parsed.roles || []
    } catch {
      clear()
    } finally {
      isBootstrapped.value = true
    }
  }

  async function login(credentials) {
    const data = await apiRequest('/login', { method: 'POST', body: credentials })
    token.value = data.token
    user.value = data.user
    roles.value = data.roles || []
    persist()
    return data
  }

  async function fetchMe() {
    if (!token.value) {
      return null
    }

    try {
      const data = await apiRequest('/me', {}, token.value)
      user.value = data.user
      roles.value = data.roles || []
      persist()
      return data
    } catch {
      clear()
      return null
    }
  }

  async function logout() {
    if (token.value) {
      try {
        await apiRequest('/logout', { method: 'POST' }, token.value)
      } catch {
      }
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
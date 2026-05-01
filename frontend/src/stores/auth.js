import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiRequest, ensureCsrfCookie } from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref('')
  const user = ref(null)
  const roles = ref([])
  const isBootstrapped = ref(false)

  const isAuthenticated = computed(() => Boolean(user.value && token.value))
  const isSystemAdmin = computed(() => roles.value.includes('admin'))
  const isProjectManager = computed(() => roles.value.includes('chef'))
  const isTester = computed(() => roles.value.includes('testeur'))

  const canManageProjects = computed(() => roles.value.includes('chef'))
  const canManageChecklists = computed(() => roles.value.includes('testeur'))
  const canManageStories = computed(() => roles.value.includes('chef'))
  const canCurateStoryChecklists = computed(() => roles.value.includes('testeur'))
  const canManageUsers = computed(() => roles.value.includes('admin'))
  const canTest = computed(() => roles.value.includes('testeur'))

  const isAdmin = computed(() => isSystemAdmin.value)
  const primaryRole = computed(() => {
    if (roles.value.includes('admin')) return 'admin'
    if (roles.value.includes('chef')) return 'chef'
    if (roles.value.includes('testeur')) return 'testeur'
    return 'user'
  })

  function clear() {
    token.value = ''
    user.value = null
    roles.value = []
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth_user')
  }

  function hydrate() {
    const savedToken = localStorage.getItem('auth_token')
    token.value = ''

    if (savedToken) {
      token.value = savedToken
    }

    const savedUser = localStorage.getItem('auth_user')

    if (savedUser && savedToken) {
      try {
        const userData = JSON.parse(savedUser)
        user.value = userData.user
        roles.value = userData.roles || []
      } catch (e) {
        console.error('[Auth] Failed to parse saved user data:', e)
      }
    } else if (savedUser && !savedToken) {
      clear()
    }

    isBootstrapped.value = true
  }

  async function login(credentials) {
    await ensureCsrfCookie()
    let data

    try {
      data = await apiRequest('/login', { method: 'POST', body: credentials })
    } catch (error) {
      if (error?.status === 419) {
        await ensureCsrfCookie()
        data = await apiRequest('/login', { method: 'POST', body: credentials })
      } else {
        throw error
      }
    }

    user.value = data.user
    roles.value = data.roles || []

    if (data.token) {
      token.value = data.token
      localStorage.setItem('auth_token', data.token)
    }

    localStorage.setItem('auth_user', JSON.stringify({ user: data.user, roles: data.roles }))

    return data
  }

  async function requestPasswordReset(email) {
    await ensureCsrfCookie()
    return await apiRequest('/forgot-password', {
      method: 'POST',
      body: { email },
    })
  }

  async function resetPassword(payload) {
    await ensureCsrfCookie()
    return await apiRequest('/reset-password', {
      method: 'POST',
      body: payload,
    })
  }

  async function fetchMe() {
    try {
      const data = await apiRequest('/me', {}, token.value)
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
      await apiRequest('/logout', { method: 'POST' }, token.value)
    } catch {
    }

    clear()
  }

  function hasAnyRole(allowedRoles = []) {
    return allowedRoles.length === 0 || allowedRoles.some((role) => roles.value.includes(role))
  }

  hydrate()

  return {
    token,
    user,
    roles,
    isBootstrapped,
    isAuthenticated,
    isSystemAdmin,
    isProjectManager,
    isTester,
    isAdmin,
    primaryRole,
    canManageProjects,
    canManageChecklists,
    canManageStories,
    canCurateStoryChecklists,
    canManageUsers,
    canTest,
    hydrate,
    clear,
    login,
    requestPasswordReset,
    resetPassword,
    fetchMe,
    logout,
    hasAnyRole,
  }
})

import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiRequest, ensureCsrfCookie } from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref('')
  const user = ref(null)
  const roles = ref([])
  const isBootstrapped = ref(false)

  const isAuthenticated = computed(() => Boolean(user.value && token.value))
  
  // ADMINISTRATEUR SYSTÈME - Gestion des utilisateurs et du système
  const isSystemAdmin = computed(() => roles.value.includes('admin'))
  
  // CHEF DE PROJET - Gestion complète des projets et checklists
  const isProjectManager = computed(() => roles.value.includes('chef'))
  
  // TESTEUR - Exécution des tests
  const isTester = computed(() => roles.value.includes('testeur'))
  
  // ========== PERMISSIONS DÉRIVÉES ==========
  // Peut gérer les projets (Chef uniquement)
  const canManageProjects = computed(() => roles.value.includes('chef'))
  
  // Peut gérer les checklists (Chef et Admin)
  const canManageChecklists = computed(
    () => roles.value.includes('chef') || roles.value.includes('admin')
  )
  
  // Peut gérer les utilisateurs (Admin Système uniquement)
  const canManageUsers = computed(() => roles.value.includes('admin'))
  
  // Peut exécuter les tests (Chef, Admin et Testeur)
  const canTest = computed(
    () => roles.value.includes('chef') || 
           roles.value.includes('admin') || 
           roles.value.includes('testeur')
  )
  
  // Ancien alias - garder pour compatibilité
  const isAdmin = computed(() => isSystemAdmin.value)

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

    // Load user from localStorage if available
    const savedUser = localStorage.getItem('auth_user')

    if (savedUser && savedToken) {
      try {
        const userData = JSON.parse(savedUser)
        user.value = userData.user
        roles.value = userData.roles || []
        console.log('[Auth] Hydrated user from localStorage:', userData.user?.name)
      } catch (e) {
        console.error('[Auth] Failed to parse saved user data:', e)
      }
    } else if (savedUser && !savedToken) {
      // Prevent stale UI auth state when token was lost/expired.
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
      // A stale/missing CSRF cookie can happen after server restarts or host changes.
      // Refresh once and retry before surfacing the error.
      if (error?.status === 419) {
        await ensureCsrfCookie()
        data = await apiRequest('/login', { method: 'POST', body: credentials })
      } else {
        throw error
      }
    }

    user.value = data.user
    roles.value = data.roles || []
    
    // Store token if provided (for API usage)
    if (data.token) {
      token.value = data.token
      localStorage.setItem('auth_token', data.token)
    }
    
    // Always store user data
    localStorage.setItem('auth_user', JSON.stringify({ user: data.user, roles: data.roles }))
    
    console.log('[Auth] Login successful. User:', user.value?.name)
    
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
      localStorage.setItem('auth_user', JSON.stringify({ user: user.value, roles: roles.value }))
      return data
    } catch {
      clear()
      return null
    }
  }

  async function updateProfile(payload) {
    const data = await apiRequest('/me', {
      method: 'PUT',
      body: payload,
    }, token.value)

    user.value = data.user
    roles.value = data.roles || roles.value
    localStorage.setItem('auth_user', JSON.stringify({ user: user.value, roles: roles.value }))

    return data
  }

  async function changePassword(payload) {
    return await apiRequest('/me/password', {
      method: 'PUT',
      body: payload,
    }, token.value)
  }

  async function logout() {
    try {
      await apiRequest('/logout', { method: 'POST' }, token.value)
    } catch {
      // The local session is cleared even if the API call fails.
    }

    clear()
  }

  function hasAnyRole(allowedRoles = []) {
    return allowedRoles.length === 0 || allowedRoles.some((role) => roles.value.includes(role))
  }

  // Hydrate on store initialization
  hydrate()

  return {
    // State
    token,
    user,
    roles,
    isBootstrapped,
    
    // Computed Properties - Authentification
    isAuthenticated,
    
    // Computed Properties - Rôles
    isSystemAdmin,
    isProjectManager,
    isTester,
    isAdmin, // Alias pour compatibilité
    
    // Computed Properties - Permissions
    canManageProjects,
    canManageChecklists,
    canManageUsers,
    canTest,
    
    // Methods
    hydrate,
    clear,
    login,
    requestPasswordReset,
    resetPassword,
    fetchMe,
    updateProfile,
    changePassword,
    logout,
    hasAnyRole,
  }
})
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { apiRequest, ensureCsrfCookie } from '@/lib/api'

export const useAuthStore = defineStore('auth', () => {
  const token = ref('')
  const user = ref(null)
  const roles = ref([])
  const isBootstrapped = ref(false)

  const isAuthenticated = computed(() => Boolean(user.value))
  
  // ADMINISTRATEUR SYSTÈME - Gestion des utilisateurs et du système
  const isSystemAdmin = computed(() => roles.value.includes('admin'))
  
  // CHEF DE PROJET - Gestion complète des projets et checklists
  const isProjectManager = computed(() => roles.value.includes('chef'))
  
  // ADMINISTRATEUR CONTENUS - Gestion qualité des checklists
  const isContentAdmin = computed(() => roles.value.includes('admin_contenus'))
  
  // TESTEUR - Exécution des tests
  const isTester = computed(() => roles.value.includes('testeur'))
  
  // ========== PERMISSIONS DÉRIVÉES ==========
  // Peut gérer les projets (Chef uniquement)
  const canManageProjects = computed(() => roles.value.includes('chef'))
  
  // Peut gérer les checklists (Chef et Admin Contenus)
  const canManageChecklists = computed(
    () => roles.value.includes('chef') || roles.value.includes('admin_contenus')
  )
  
  // Peut gérer les utilisateurs (Admin Système uniquement)
  const canManageUsers = computed(() => roles.value.includes('admin'))
  
  // Peut exécuter les tests (Tous sauf Admin Système)
  const canTest = computed(
    () => roles.value.includes('chef') || 
           roles.value.includes('admin_contenus') || 
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
    // Load user from localStorage if available
    const savedUser = localStorage.getItem('auth_user')
    
    if (savedUser) {
      try {
        const userData = JSON.parse(savedUser)
        user.value = userData.user
        roles.value = userData.roles || []
        console.log('[Auth] Hydrated user from localStorage:', userData.user?.name)
      } catch (e) {
        console.error('[Auth] Failed to parse saved user data:', e)
      }
    }
    
    isBootstrapped.value = true
  }

  async function login(credentials) {
    await ensureCsrfCookie()
    const data = await apiRequest('/login', { method: 'POST', body: credentials })
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
    isContentAdmin,
    isTester,
    isAdmin, // Alias pour compatibilité
    
    // Computed Properties - Permissions
    canManageProjects,
    canManageChecklists,
    canManageUsers,
    canTest,
    
    // Methods
    hydrate,
    login,
    requestPasswordReset,
    resetPassword,
    fetchMe,
    logout,
    hasAnyRole,
  }
})
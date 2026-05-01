<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import {
  Bell,
  ChevronDown,
  ClipboardCheck,
  FolderKanban,
  Gauge,
  Home,
  Menu,
  NotebookPen,
  Plus,
  Search,
  Sparkles,
  TriangleAlert,
  UsersRound,
} from 'lucide-vue-next'
import { apiRequest, withQuery } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { translatePhrase } from '@/lib/runtimeTranslations'
import LogoHeader from '@/components/LogoHeader.vue'

const SIDEBAR_STORAGE_KEY = 'ui_sidebar_collapsed'

const auth = useAuthStore()
const settings = useSettingsStore()
const router = useRouter()
const route = useRoute()

function tr(text) {
  return translatePhrase(text, settings.language)
}

const roleLabel = computed(() =>
  auth.roles
    .map((role) => {
      if (role === 'admin') return 'Administrateur'
      if (role === 'chef') return 'Chef de projet'
      if (role === 'testeur') return 'Testeur'
      return role
    })
    .join(', ')
)
const workspaceLabel = computed(() => {
  if (auth.roles.includes('chef')) return 'Pilotage projet'
  if (auth.roles.includes('admin')) return 'Administration'
  if (auth.roles.includes('testeur')) return 'Espace d’exécution'
  return 'Espace de travail'
})
const profileInitial = computed(() => {
  const source = String(auth.user?.name || 'U').trim()
  return source ? source[0].toUpperCase() : 'U'
})

const isSidebarCollapsed = ref(localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1')
const showProfileMenu = ref(false)
const showSearchMenu = ref(false)
const showNotificationPanel = ref(false)
const globalQuery = ref('')

const profileMenuRef = ref(null)
const searchMenuRef = ref(null)
const notificationsRef = ref(null)

const headerMetrics = reactive({
  testsFailed: 0,
  failedCriticalItems: 0,
})

const searchIndex = reactive({
  projects: [],
  checklists: [],
  users: [],
})

const sidebarSections = computed(() => {
  const sections = [
    {
      title: 'Principal',
      items: [
        { label: 'Dashboard', route: { name: 'dashboard' }, icon: Home, show: true },
        { label: 'Projets', route: { name: 'projects' }, icon: FolderKanban, show: true },
      ],
    },
    {
      title: 'Gestion des tests',
      items: [
        {
          label: 'User Stories',
          route: { name: 'stories' },
          icon: NotebookPen,
          show: auth.hasAnyRole(['admin', 'chef', 'testeur']),
        },
        {
          label: 'Checklists',
          route: { name: 'checklists' },
          icon: ClipboardCheck,
          show: auth.canManageChecklists || auth.canTest,
        },
      ],
    },
    {
      title: 'Administration',
      items: [
        {
          label: 'Utilisateurs et rôles',
          route: { name: 'users' },
          icon: UsersRound,
          show: auth.canManageUsers,
        },
      ],
    },
  ]

  return sections
    .map((section) => ({
      ...section,
      items: section.items.filter((item) => item.show),
    }))
    .filter((section) => section.items.length > 0)
})

const quickActions = computed(() => {
  const actions = []
  const currentProjectId = String(route.query.projectId || route.params.id || '').trim()

  if (auth.canManageProjects) {
    actions.push({
      label: 'Créer un projet',
      route: { name: 'projects', query: { create: '1' } },
    })
  }

  if (auth.canManageStories) {
    actions.push({
      label: 'Ajouter une User Story',
      route: currentProjectId
        ? { name: 'story-create', query: { projectId: currentProjectId } }
        : { name: 'stories' },
    })
  }

  return actions
})

const failedAlertCount = computed(() => {
  const count = Number(headerMetrics.testsFailed || 0)
  return Number.isFinite(count) ? Math.max(0, count) : 0
})

const notificationBadge = computed(() => {
  if (failedAlertCount.value > 99) {
    return '99+'
  }

  return String(failedAlertCount.value)
})

const notificationItems = computed(() => {
  const items = []

  if (headerMetrics.testsFailed > 0) {
    items.push({
      key: 'tests-failed',
      tone: 'danger',
      text: `${headerMetrics.testsFailed} test(s) ont échoué récemment`,
    })
  }

  if (headerMetrics.failedCriticalItems > 0) {
    items.push({
      key: 'critical-failed',
      tone: 'warning',
      text: `${headerMetrics.failedCriticalItems} élément(s) critique(s) sont en échec`,
    })
  }

  if (items.length === 0) {
    items.push({
      key: 'all-good',
      tone: 'info',
      text: 'Aucune alerte active pour le moment',
    })
  }

  return items
})

const workspaceSignals = computed(() => [
  {
    label: 'Alertes',
    value: notificationBadge.value,
    tone: failedAlertCount.value > 0 ? 'danger' : 'neutral',
    icon: TriangleAlert,
  },
  {
    label: 'Critiques',
    value: String(headerMetrics.failedCriticalItems || 0),
    tone: Number(headerMetrics.failedCriticalItems || 0) > 0 ? 'warning' : 'neutral',
    icon: Gauge,
  },
  {
    label: 'Recherche',
    value: `${searchIndex.projects.length + searchIndex.checklists.length}`,
    tone: 'neutral',
    icon: Sparkles,
  },
])

const globalSearchResults = computed(() => {
  const q = globalQuery.value.trim().toLowerCase()

  if (!q) {
    return []
  }

  const scoped = []

  sidebarSections.value.forEach((section) => {
    section.items.forEach((item) => {
      if (item.label.toLowerCase().includes(q)) {
        scoped.push({
          key: `nav-${item.label}`,
          type: 'Navigation',
          label: item.label,
          subtitle: section.title,
          route: item.route,
        })
      }
    })
  })

  const projectMatches = searchIndex.projects
    .filter((item) => item.name.toLowerCase().includes(q))
    .map((item) => ({
      key: `project-${item.id}`,
      type: 'Projet',
      label: item.name,
      subtitle: item.subtitle,
      route: { name: 'project-detail', params: { id: item.id } },
    }))

  const checklistMatches = searchIndex.checklists
    .filter((item) => item.name.toLowerCase().includes(q))
    .map((item) => ({
      key: `checklist-${item.id}`,
      type: tr('Checklist'),
      label: item.name,
      subtitle: item.subtitle,
      route: { name: 'checklists' },
    }))

  const userMatches = searchIndex.users
    .filter((item) => item.name.toLowerCase().includes(q) || item.email.toLowerCase().includes(q))
    .map((item) => ({
      key: `user-${item.id}`,
      type: 'Utilisateur',
      label: item.name,
      subtitle: item.email,
      route: { name: 'users' },
    }))

  return [...scoped, ...projectMatches, ...checklistMatches, ...userMatches].slice(0, 12)
})

function normalizeListPayload(payload) {
  if (Array.isArray(payload)) {
    return payload
  }

  if (Array.isArray(payload?.data)) {
    return payload.data
  }

  return []
}

async function loadHeaderMetrics() {
  if (!auth.isAuthenticated) {
    return
  }

  try {
    const summary = await apiRequest('/dashboard/summary', {}, auth.token)
    headerMetrics.testsFailed = Number(summary.testsFailed || 0)
    headerMetrics.failedCriticalItems = Number(summary.failedCriticalItems || 0)
  } catch {
    headerMetrics.testsFailed = 0
    headerMetrics.failedCriticalItems = 0
  }
}

async function loadGlobalSearchIndex() {
  if (!auth.isAuthenticated) {
    return
  }

  const tasks = [
    apiRequest(withQuery('/projects', { page: 1 }), {}, auth.token),
    auth.canManageChecklists
      ? apiRequest(withQuery('/checklists', { page: 1 }), {}, auth.token)
      : Promise.resolve([]),
    auth.canManageUsers
      ? apiRequest(withQuery('/users', { page: 1 }), {}, auth.token)
      : Promise.resolve([]),
  ]

  const [projectsResponse, checklistsResponse, usersResponse] = await Promise.allSettled(tasks)

  if (projectsResponse.status === 'fulfilled') {
    const list = normalizeListPayload(projectsResponse.value)
    searchIndex.projects = list.map((item) => ({
      id: item.id,
      name: String(item.name || `Project #${item.id}`),
      subtitle: String(item.app_url || item.description || 'Projet'),
    }))
  } else {
    searchIndex.projects = []
  }

  if (checklistsResponse.status === 'fulfilled') {
    const list = normalizeListPayload(checklistsResponse.value)
    searchIndex.checklists = list.map((item) => ({
      id: item.id,
      name: String(item.name || `Checklist #${item.id}`),
      subtitle: String(item.category || tr('Checklist')),
    }))
  } else {
    searchIndex.checklists = []
  }

  if (usersResponse.status === 'fulfilled') {
    const list = normalizeListPayload(usersResponse.value)
    searchIndex.users = list.map((item) => ({
      id: item.id,
      name: String(item.name || `User #${item.id}`),
      email: String(item.email || ''),
    }))
  } else {
    searchIndex.users = []
  }
}

function toggleSidebar() {
  isSidebarCollapsed.value = !isSidebarCollapsed.value
  localStorage.setItem(SIDEBAR_STORAGE_KEY, isSidebarCollapsed.value ? '1' : '0')
}

function toggleProfileMenu() {
  showProfileMenu.value = !showProfileMenu.value
  showNotificationPanel.value = false
}

function toggleNotifications() {
  showNotificationPanel.value = !showNotificationPanel.value
  showProfileMenu.value = false
}

function handleGlobalSearchSubmit() {
  const first = globalSearchResults.value[0]
  if (first) {
    selectSearchResult(first)
  }
}

async function selectSearchResult(result) {
  globalQuery.value = ''
  showSearchMenu.value = false
  await router.push(result.route)
}

async function goToProfile() {
  showProfileMenu.value = false
  await router.push({ name: 'dashboard' })
}

async function goToSettings() {
  showProfileMenu.value = false
  await router.push({ name: 'settings' })
}

async function handleLogout() {
  showProfileMenu.value = false
  await auth.logout()
  await router.push({ name: 'login' })
}

function onGlobalSearchFocus() {
  showSearchMenu.value = true
}

function closePanelsOnRouteChange() {
  showProfileMenu.value = false
  showNotificationPanel.value = false
  showSearchMenu.value = false
}

function handleDocumentClick(event) {
  const target = event.target

  if (profileMenuRef.value && !profileMenuRef.value.contains(target)) {
    showProfileMenu.value = false
  }

  if (notificationsRef.value && !notificationsRef.value.contains(target)) {
    showNotificationPanel.value = false
  }

  if (searchMenuRef.value && !searchMenuRef.value.contains(target)) {
    showSearchMenu.value = false
  }
}

onMounted(async () => {
  document.addEventListener('click', handleDocumentClick)

  if (auth.isAuthenticated) {
    await Promise.all([loadHeaderMetrics(), loadGlobalSearchIndex()])
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleDocumentClick)
})

watch(
  () => auth.isAuthenticated,
  async (isAuthenticated) => {
    if (isAuthenticated) {
      await Promise.all([loadHeaderMetrics(), loadGlobalSearchIndex()])
      return
    }

    globalQuery.value = ''
    searchIndex.projects = []
    searchIndex.checklists = []
    searchIndex.users = []
  },
)

watch(
  () => route.fullPath,
  () => {
    closePanelsOnRouteChange()
  },
)
</script>

<template>
  <div class="portal-shell">
    <template v-if="auth.isAuthenticated">
      <header class="topbar">
        <div class="topbar-left">
          <LogoHeader />
        </div>

        <div class="topbar-search" ref="searchMenuRef">
          <form class="topbar-search-input" @submit.prevent="handleGlobalSearchSubmit">
            <Search :size="16" :stroke-width="2.2" />
            <input
              v-model="globalQuery"
              type="text"
              placeholder="Rechercher un projet, un utilisateur ou une checklist..."
              @focus="onGlobalSearchFocus"
            />
          </form>

          <div v-if="showSearchMenu && globalQuery.trim()" class="topbar-search-results">
            <button
              v-for="result in globalSearchResults"
              :key="result.key"
              type="button"
              class="search-result-item"
              @click="selectSearchResult(result)"
            >
              <span class="search-result-type">{{ result.type }}</span>
              <span class="search-result-main">{{ result.label }}</span>
              <span class="search-result-sub">{{ result.subtitle }}</span>
            </button>

            <p v-if="globalSearchResults.length === 0" class="search-result-empty">
              Aucun résultat correspondant.
            </p>
          </div>
        </div>

        <div class="topbar-right">
          <div class="notifications-wrap" ref="notificationsRef">
            <button class="icon-btn" type="button" @click="toggleNotifications" title="Notifications">
              <Bell :size="18" :stroke-width="2.2" />
              <span v-if="failedAlertCount > 0" class="icon-badge">{{ notificationBadge }}</span>
            </button>

            <div v-if="showNotificationPanel" class="notifications-panel">
              <div class="notifications-title">Alertes</div>
              <div class="notifications-list">
                <div
                  v-for="item in notificationItems"
                  :key="item.key"
                  class="notification-item"
                  :class="`notification-${item.tone}`"
                >
                  {{ item.text }}
                </div>
              </div>
            </div>
          </div>

          <div class="profile-wrap" ref="profileMenuRef">
            <button class="profile-trigger" type="button" @click="toggleProfileMenu">
              <div class="profile-avatar">{{ profileInitial }}</div>
              <div class="profile-meta">
                <strong>{{ auth.user?.name }}</strong>
                <span>{{ roleLabel }}</span>
              </div>
              <ChevronDown :size="16" :stroke-width="2.2" />
            </button>

            <div v-if="showProfileMenu" class="profile-menu">
              <button type="button" class="profile-menu-item" @click="goToProfile">Profil</button>
              <button type="button" class="profile-menu-item" @click="goToSettings">Paramètres</button>
              <button type="button" class="profile-menu-item danger" @click="handleLogout">Déconnexion</button>
            </div>
          </div>
        </div>
      </header>

      <div class="portal-layout" :class="{ 'sidebar-collapsed': isSidebarCollapsed }">
        <aside class="sidebar">
          <div class="sidebar-head">
            <div class="sidebar-title" v-show="!isSidebarCollapsed">Navigation</div>
            <button
              class="sidebar-toggle"
              type="button"
              @click="toggleSidebar"
              :title="isSidebarCollapsed ? 'Développer' : 'Réduire'"
            >
              <Menu :size="16" />
            </button>
          </div>

          <div class="sidebar-workspace-card" v-show="!isSidebarCollapsed">
            <div class="sidebar-workspace-top">
              <div class="sidebar-workspace-avatar">{{ profileInitial }}</div>
              <div class="sidebar-workspace-copy">
                <p class="sidebar-workspace-label">{{ workspaceLabel }}</p>
                <strong>{{ auth.user?.name }}</strong>
                <span>{{ roleLabel }}</span>
              </div>
            </div>

            <div class="sidebar-workspace-signals">
              <div
                v-for="signal in workspaceSignals"
                :key="signal.label"
                class="workspace-signal"
                :class="`workspace-signal-${signal.tone}`"
              >
                <component :is="signal.icon" :size="14" />
                <div class="workspace-signal-copy">
                  <span>{{ signal.label }}</span>
                  <strong>{{ signal.value }}</strong>
                </div>
              </div>
            </div>
          </div>

          <nav class="sidebar-sections">
            <section v-for="section in sidebarSections" :key="section.title" class="sidebar-section">
              <p class="sidebar-group" v-show="!isSidebarCollapsed">{{ section.title }}</p>
              <RouterLink
                v-for="item in section.items"
                :key="item.label"
                :to="item.route"
                class="sidebar-item"
                :title="item.label"
              >
                <component :is="item.icon" :size="17" :stroke-width="2.2" />
                <span v-show="!isSidebarCollapsed">{{ item.label }}</span>
              </RouterLink>
            </section>
          </nav>

          <div class="sidebar-quick" v-if="quickActions.length > 0">
            <p class="sidebar-group" v-show="!isSidebarCollapsed">Actions rapides</p>
            <RouterLink
              v-for="action in quickActions"
              :key="action.label"
              :to="action.route"
              class="sidebar-quick-item"
              :title="action.label"
            >
              <Plus :size="16" />
              <span v-show="!isSidebarCollapsed">{{ action.label }}</span>
            </RouterLink>
          </div>
        </aside>

        <main class="portal-content">
          <RouterView />
        </main>
      </div>
    </template>

    <main v-else class="auth-content">
      <RouterView />
    </main>
  </div>
</template>

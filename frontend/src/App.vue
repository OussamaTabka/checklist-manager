<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import {
  Bell,
  ChevronDown,
  ClipboardCheck,
  FolderKanban,
  Home,
  Menu,
  NotebookPen,
  Plus,
  Search,
  UsersRound,
} from 'lucide-vue-next'
import { apiRequest, withQuery } from '@/lib/api'
import { t } from '@/lib/translations'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import LogoHeader from '@/components/LogoHeader.vue'

const SIDEBAR_STORAGE_KEY = 'ui_sidebar_collapsed'

const auth = useAuthStore()
const settingsStore = useSettingsStore()
const router = useRouter()
const route = useRoute()

const roleLabel = computed(() => auth.roles.join(', '))
const profileInitial = computed(() => {
  const source = String(auth.user?.name || 'U').trim()
  return source ? source[0].toUpperCase() : 'U'
})
const primaryRoleLabel = computed(() => String(auth.roles?.[0] || 'user').toUpperCase())

const isSidebarCollapsed = ref(localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1')
const showProfileMenu = ref(false)
const showInlineSettings = ref(false)
const showProfileEditPanel = ref(false)
const showPasswordPanel = ref(false)
const showSearchMenu = ref(false)
const showNotificationPanel = ref(false)
const globalQuery = ref('')

const profileFeedback = ref('')
const profileError = ref('')

const profileMenuRef = ref(null)
const searchMenuRef = ref(null)
const notificationsRef = ref(null)

const headerMetrics = reactive({
  totalProjects: 0,
  testsFailed: 0,
  failedCriticalItems: 0,
  testsRun: 0,
})

const entityTotals = reactive({
  users: 0,
  projects: 0,
})

const searchIndex = reactive({
  projects: [],
  checklists: [],
  users: [],
  availableTesters: [],
})

const profileForm = reactive({
  name: '',
  email: '',
})

const passwordForm = reactive({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const sidebarSections = computed(() => {
  const sections = [
    {
      title: 'MAIN',
      items: [
        { label: 'Dashboard', route: { name: 'dashboard' }, icon: Home, show: true },
        { label: 'Projects', route: { name: 'projects' }, icon: FolderKanban, show: true },
      ],
    },
    {
      title: 'TEST MANAGEMENT',
      items: [
        {
          label: 'User Stories',
          route: { name: 'stories' },
          icon: NotebookPen,
          show: auth.hasAnyRole(['admin', 'chef']),
        },
        {
          label: 'Checklist Templates',
          route: { name: 'checklists' },
          icon: ClipboardCheck,
          show: auth.canManageChecklists,
        },
      ],
    },
    {
      title: 'ADMIN',
      items: [
        {
          label: 'Users & Roles',
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

  if (auth.canManageProjects) {
    actions.push({
      label: 'New Project',
      route: { name: 'projects', query: { create: '1' } },
    })
  }

  if (auth.canManageChecklists) {
    actions.push({
      label: 'New Checklist',
      route: { name: 'checklists', query: { create: '1' } },
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
      text: `${headerMetrics.testsFailed} test(s) failed recently`,
    })
  }

  if (headerMetrics.failedCriticalItems > 0) {
    items.push({
      key: 'critical-failed',
      tone: 'warning',
      text: `${headerMetrics.failedCriticalItems} critical item(s) are failing`,
    })
  }

  if (items.length === 0) {
    items.push({
      key: 'all-good',
      tone: 'info',
      text: 'No active alerts for now',
    })
  }

  return items
})

const currentThemeLabel = computed(() =>
  settingsStore.darkMode
    ? t('settings.darkModeEnabled', settingsStore.language)
    : t('settings.darkModeDisabled', settingsStore.language),
)

const testsPassed = computed(() => Math.max(0, headerMetrics.testsRun - headerMetrics.testsFailed))

const testerCount = computed(() =>
  searchIndex.availableTesters.filter((user) => user.roles.includes('testeur')).length,
)

const lastLoginLabel = computed(() => {
  const raw = auth.user?.last_login_at

  if (!raw) {
    return t('profile.today', settingsStore.language)
  }

  const date = new Date(raw)
  if (Number.isNaN(date.getTime())) {
    return t('profile.today', settingsStore.language)
  }

  return new Intl.DateTimeFormat(settingsStore.language, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
})

const profileActivityItems = computed(() => {
  const items = [{
    key: 'last-login',
    label: t('profile.lastLogin', settingsStore.language),
    value: lastLoginLabel.value,
  }]

  if (auth.isSystemAdmin) {
    items.push({
      key: 'users',
      label: t('profile.totalUsers', settingsStore.language),
      value: entityTotals.users,
    })
    items.push({
      key: 'projects',
      label: t('profile.totalProjects', settingsStore.language),
      value: headerMetrics.totalProjects,
    })
    items.push({
      key: 'tests-total',
      label: t('profile.totalTests', settingsStore.language),
      value: headerMetrics.testsRun,
    })
    return items
  }

  if (auth.isProjectManager) {
    items.push({
      key: 'my-projects',
      label: t('profile.myProjects', settingsStore.language),
      value: headerMetrics.totalProjects,
    })
    items.push({
      key: 'assigned-testers',
      label: t('profile.assignedTesters', settingsStore.language),
      value: testerCount.value,
    })
    items.push({
      key: 'validated-tests',
      label: t('profile.validatedTests', settingsStore.language),
      value: testsPassed.value,
    })
    return items
  }

  items.push({
    key: 'executed-tests',
    label: t('profile.executedTests', settingsStore.language),
    value: headerMetrics.testsRun,
  })
  items.push({
    key: 'passed-tests',
    label: t('profile.passedTests', settingsStore.language),
    value: testsPassed.value,
  })
  items.push({
    key: 'failed-tests',
    label: t('profile.failedTests', settingsStore.language),
    value: headerMetrics.testsFailed,
  })

  return items
})

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
      type: 'Project',
      label: item.name,
      subtitle: item.subtitle,
      route: { name: 'project-detail', params: { id: item.id } },
    }))

  const checklistMatches = searchIndex.checklists
    .filter((item) => item.name.toLowerCase().includes(q))
    .map((item) => ({
      key: `checklist-${item.id}`,
      type: 'Checklist',
      label: item.name,
      subtitle: item.subtitle,
      route: { name: 'checklists' },
    }))

  const userMatches = searchIndex.users
    .filter((item) => item.name.toLowerCase().includes(q) || item.email.toLowerCase().includes(q))
    .map((item) => ({
      key: `user-${item.id}`,
      type: 'User',
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
    headerMetrics.totalProjects = Number(summary.totalProjects || 0)
    headerMetrics.testsFailed = Number(summary.testsFailed || 0)
    headerMetrics.failedCriticalItems = Number(summary.failedCriticalItems || 0)
    headerMetrics.testsRun = Number(summary.testsRun || 0)
  } catch {
    headerMetrics.totalProjects = 0
    headerMetrics.testsFailed = 0
    headerMetrics.failedCriticalItems = 0
    headerMetrics.testsRun = 0
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
    apiRequest('/available-testers', {}, auth.token),
  ]

  const [projectsResponse, checklistsResponse, usersResponse, availableTestersResponse] = await Promise.allSettled(tasks)

  if (projectsResponse.status === 'fulfilled') {
    const list = normalizeListPayload(projectsResponse.value)
    entityTotals.projects = Number(projectsResponse.value?.total || list.length)
    searchIndex.projects = list.map((item) => ({
      id: item.id,
      name: String(item.name || `Project #${item.id}`),
      subtitle: String(item.app_url || item.description || 'Project'),
    }))
  } else {
    entityTotals.projects = 0
    searchIndex.projects = []
  }

  if (checklistsResponse.status === 'fulfilled') {
    const list = normalizeListPayload(checklistsResponse.value)
    searchIndex.checklists = list.map((item) => ({
      id: item.id,
      name: String(item.name || `Checklist #${item.id}`),
      subtitle: String(item.category || 'Checklist template'),
    }))
  } else {
    searchIndex.checklists = []
  }

  if (usersResponse.status === 'fulfilled') {
    const list = normalizeListPayload(usersResponse.value)
    entityTotals.users = Number(usersResponse.value?.total || list.length)
    searchIndex.users = list.map((item) => ({
      id: item.id,
      name: String(item.name || `User #${item.id}`),
      email: String(item.email || ''),
    }))
  } else {
    entityTotals.users = 0
    searchIndex.users = []
  }

  if (availableTestersResponse.status === 'fulfilled') {
    const list = normalizeListPayload(availableTestersResponse.value)
    searchIndex.availableTesters = list.map((item) => ({
      id: item.id,
      name: String(item.name || `User #${item.id}`),
      roles: Array.isArray(item.roles) ? item.roles.map((role) => String(role.name || '')) : [],
    }))
  } else {
    searchIndex.availableTesters = []
  }
}

function resetInlinePanels() {
  showInlineSettings.value = false
  showProfileEditPanel.value = false
  showPasswordPanel.value = false
  profileFeedback.value = ''
  profileError.value = ''
  passwordForm.current_password = ''
  passwordForm.password = ''
  passwordForm.password_confirmation = ''
}

function seedProfileForm() {
  profileForm.name = String(auth.user?.name || '')
  profileForm.email = String(auth.user?.email || '')
}

function toggleSidebar() {
  isSidebarCollapsed.value = !isSidebarCollapsed.value
  localStorage.setItem(SIDEBAR_STORAGE_KEY, isSidebarCollapsed.value ? '1' : '0')
}

function toggleProfileMenu() {
  const isOpening = !showProfileMenu.value
  showProfileMenu.value = isOpening

  if (isOpening) {
    seedProfileForm()
    resetInlinePanels()
  } else {
    resetInlinePanels()
  }

  showNotificationPanel.value = false
}

function closeProfileMenu() {
  showProfileMenu.value = false
  resetInlinePanels()
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

function toggleSettingsPanel() {
  showProfileEditPanel.value = false
  showPasswordPanel.value = false
  profileFeedback.value = ''
  profileError.value = ''
  showInlineSettings.value = !showInlineSettings.value
}

function toggleEditProfilePanel() {
  showInlineSettings.value = false
  showPasswordPanel.value = false
  profileFeedback.value = ''
  profileError.value = ''
  seedProfileForm()
  showProfileEditPanel.value = !showProfileEditPanel.value
}

function togglePasswordPanel() {
  showInlineSettings.value = false
  showProfileEditPanel.value = false
  profileFeedback.value = ''
  profileError.value = ''
  passwordForm.current_password = ''
  passwordForm.password = ''
  passwordForm.password_confirmation = ''
  showPasswordPanel.value = !showPasswordPanel.value
}

function changeLanguage(langCode) {
  settingsStore.setLanguage(langCode)
}

function toggleDarkMode() {
  settingsStore.toggleDarkMode()
}

async function submitProfileUpdate() {
  profileFeedback.value = ''
  profileError.value = ''

  try {
    await auth.updateProfile({
      name: profileForm.name.trim(),
      email: profileForm.email.trim(),
    })

    profileFeedback.value = t('profile.profileUpdated', settingsStore.language)
    showProfileEditPanel.value = false
  } catch (error) {
    profileError.value = error.data?.message || error.message
  }
}

async function submitPasswordUpdate() {
  profileFeedback.value = ''
  profileError.value = ''

  if (passwordForm.password !== passwordForm.password_confirmation) {
    profileError.value = t('msg.error', settingsStore.language)
    return
  }

  try {
    await auth.changePassword({
      current_password: passwordForm.current_password,
      password: passwordForm.password,
      password_confirmation: passwordForm.password_confirmation,
    })

    profileFeedback.value = t('profile.passwordUpdated', settingsStore.language)
    showPasswordPanel.value = false
    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
  } catch (error) {
    profileError.value = error.data?.message || error.message
  }
}

async function handleLogout() {
  closeProfileMenu()
  await auth.logout()
  await router.push({ name: 'login' })
}

function onGlobalSearchFocus() {
  showSearchMenu.value = true
}

function closePanelsOnRouteChange() {
  closeProfileMenu()
  showNotificationPanel.value = false
  showSearchMenu.value = false
}

function handleDocumentClick(event) {
  const target = event.target

  if (profileMenuRef.value && !profileMenuRef.value.contains(target)) {
    closeProfileMenu()
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
    searchIndex.availableTesters = []
    entityTotals.projects = 0
    entityTotals.users = 0
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
              placeholder="Search projects, users, checklists..."
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
              No matching result.
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
              <div class="notifications-title">Alerts</div>
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
              <div class="profile-identity-card">
                <div class="profile-identity-avatar">{{ profileInitial }}</div>
                <div class="profile-identity-meta">
                  <strong>{{ auth.user?.name }}</strong>
                  <span>{{ auth.user?.email }}</span>
                </div>
                <span class="profile-role-chip">{{ primaryRoleLabel }}</span>
              </div>

              <div class="profile-actions-grid">
                <button type="button" class="profile-action-btn" @click="toggleEditProfilePanel">
                  {{ t('profile.editProfile', settingsStore.language) }}
                </button>
                <button type="button" class="profile-action-btn" @click="togglePasswordPanel">
                  {{ t('profile.changePassword', settingsStore.language) }}
                </button>
              </div>

              <div v-if="showProfileEditPanel" class="profile-inline-panel">
                <input v-model="profileForm.name" type="text" class="profile-inline-input" :placeholder="t('common.name', settingsStore.language)" />
                <input v-model="profileForm.email" type="email" class="profile-inline-input" :placeholder="t('common.email', settingsStore.language)" />
                <button type="button" class="profile-inline-submit" @click="submitProfileUpdate">
                  {{ t('profile.saveProfile', settingsStore.language) }}
                </button>
              </div>

              <div v-if="showPasswordPanel" class="profile-inline-panel">
                <input v-model="passwordForm.current_password" type="password" class="profile-inline-input" :placeholder="t('profile.currentPassword', settingsStore.language)" />
                <input v-model="passwordForm.password" type="password" class="profile-inline-input" :placeholder="t('profile.newPassword', settingsStore.language)" />
                <input v-model="passwordForm.password_confirmation" type="password" class="profile-inline-input" :placeholder="t('profile.confirmPassword', settingsStore.language)" />
                <button type="button" class="profile-inline-submit" @click="submitPasswordUpdate">
                  {{ t('profile.changePassword', settingsStore.language) }}
                </button>
              </div>

              <p v-if="profileFeedback" class="profile-feedback success">{{ profileFeedback }}</p>
              <p v-if="profileError" class="profile-feedback error">{{ profileError }}</p>

              <button type="button" class="profile-menu-item profile-menu-parent" @click="toggleSettingsPanel">
                <span>{{ t('settings.title', settingsStore.language) }}</span>
                <ChevronDown :size="14" :stroke-width="2.4" class="profile-menu-chevron" :class="{ 'is-open': showInlineSettings }" />
              </button>

              <div v-if="showInlineSettings" class="profile-settings-panel">
                <div class="profile-setting-row">
                  <span class="profile-setting-label">{{ t('settings.language', settingsStore.language) }}</span>
                  <div class="profile-language-options">
                    <button
                      v-for="lang in settingsStore.languages"
                      :key="lang.code"
                      type="button"
                      class="profile-language-option"
                      :class="{ active: settingsStore.language === lang.code }"
                      @click="changeLanguage(lang.code)"
                    >
                      {{ lang.code.toUpperCase() }}
                    </button>
                  </div>
                </div>

                <div class="profile-setting-row">
                  <span class="profile-setting-label">{{ t('settings.darkMode', settingsStore.language) }}</span>
                  <button
                    type="button"
                    class="profile-toggle"
                    :class="{ active: settingsStore.darkMode }"
                    @click="toggleDarkMode"
                  >
                    <span class="profile-toggle-knob" :class="{ active: settingsStore.darkMode }" />
                  </button>
                </div>

                <p class="profile-setting-hint">{{ currentThemeLabel }}</p>
              </div>

              <div class="profile-activity-panel">
                <p class="profile-activity-title">{{ t('profile.activity', settingsStore.language) }}</p>
                <div v-for="item in profileActivityItems" :key="item.key" class="profile-activity-item">
                  <span>{{ item.label }}</span>
                  <strong>{{ item.value }}</strong>
                </div>
              </div>

              <button type="button" class="profile-menu-item danger" @click="handleLogout">
                {{ t('nav.logout', settingsStore.language) }}
              </button>
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
              :title="isSidebarCollapsed ? 'Expand' : 'Collapse'"
            >
              <Menu :size="16" />
            </button>
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
            <p class="sidebar-group" v-show="!isSidebarCollapsed">QUICK ACTIONS</p>
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

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  Archive,
  ArrowLeft,
  ArrowRight,
  ChartColumn,
  CirclePlus,
  Ellipsis,
  LoaderCircle,
  Pencil,
  RotateCcw,
  Rocket,
  Search,
  Save,
  Settings,
  Sparkles,
  Trash2,
  Users,
  X,
} from 'lucide-vue-next'
import { apiRequest, withQuery } from '@/lib/api'
import {
  analyzeImportedUserStories,
} from '@/lib/projectUserStories'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'
import { localizeError, localizeMessage, tr } from '@/lib/localization'

const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()
const route = useRoute()
const router = useRouter()

const projects = ref([])
const archivedProjects = ref([])
const pagination = reactive({
  current_page: 1,
  last_page: 1,
})
const archivedPagination = reactive({
  current_page: 1,
  last_page: 1,
})

const users = ref([])
const listError = ref('')
const createError = ref('')
const importFileError = ref('')
const successMessage = ref('')
const loadingProjects = ref(false)
const loadingArchivedProjects = ref(false)
const creating = ref(false)
const showProjectForm = ref(false)
const showAdvancedFilters = ref(false)
const openProjectMenuId = ref(null)
const importValidationLoading = ref(false)
const importedStoriesFile = ref(null)
const importedStoriesAnalysis = ref(null)
const userStoriesFileInput = ref(null)
const manualStorySeed = ref(1)
const manualStories = ref([])
const projectNameError = ref('')
const checkingProjectName = ref(false)
const projectNameValidationTimer = ref(null)

const filters = reactive({
  name: '',
  type: '',
  category: '',
  query: '',
  creator: 'all',
})

const form = reactive({
  id: null,
  name: '',
  description: '',
  test_objectives: '',
  app_url: '',
  tester_ids: [],
})

const projectPageCopy = computed(() => {
  switch (auth.primaryRole) {
    case 'admin':
      return {
        kicker: 'Espace administrateur',
        title: 'Projets',
        description:
          'Supervisez la configuration des projets, leur périmètre fonctionnel et l'organisation globale de la plateforme.',
      }
    case 'testeur':
      return {
        kicker: 'Espace d'exécution',
        title: 'Projets assignés',
        description:
          'Consultez les projets qui vous sont assignés, comprenez leur périmètre et accédez rapidement   l'exécution des tests.',
      }
    default:
      return {
        kicker: 'Espace chef de projet',
        title: 'Projets',
        description:
          'Créez les projets, définissez leur contexte, préparez le backlog initial et assignez les testeurs.',
      }
  }
})

const creatorFilterOptions = computed(() => {
  const creators = projects.value
    .map((project) => project.creator)
    .filter((creator) => Boolean(creator?.id))

  const seen = new Set()
  return creators.filter((creator) => {
    if (seen.has(creator.id)) {
      return false
    }

    seen.add(creator.id)
    return true
  })
})

const activeFilterBadges = computed(() => {
  const badges = []

  if (filters.name.trim() !== '') {
    badges.push({ key: 'name', label: `Nom : ${filters.name.trim()}` })
  }

  if (filters.type.trim() !== '') {
    badges.push({ key: 'type', label: `Type : ${filters.type.trim()}` })
  }

  if (filters.category.trim() !== '') {
    badges.push({ key: 'category', label: `Catégorie : ${filters.category.trim()}` })
  }

  if (filters.query.trim() !== '') {
    badges.push({ key: 'query', label: `Recherche : ${filters.query.trim()}` })
  }

  if (filters.creator !== 'all') {
    const creator = creatorFilterOptions.value.find((item) => String(item.id) === String(filters.creator))
    badges.push({ key: 'creator', label: `Créateur : ${creator?.name || filters.creator}` })
  }

  return badges
})

const hasActiveFilters = computed(() => activeFilterBadges.value.length > 0)
const importedValidUserStoriesCount = computed(() => importedStoriesAnalysis.value?.validCount || 0)
const importedInvalidUserStoriesCount = computed(() => importedStoriesAnalysis.value?.invalidCount || 0)
const hasImportedUserStories = computed(() => importedValidUserStoriesCount.value >= 1)
const manualValidUserStoriesCount = computed(() =>
  manualStories.value.filter(
    (story) =>
      story.title.trim() !== '' &&
      story.description.trim() !== '' &&
      story.acceptance_criteria.trim() !== ''
  ).length
)
const isProjectNameTaken = computed(() => projectNameError.value !== '')

const projectMetrics = computed(() => {
  const totalProjects = projects.value.length
  const totalAssignedTesters = projects.value.reduce((count, project) => count + (project.testers?.length || 0), 0)
  const totalLinkedStories = projects.value.reduce((count, project) => count + Number(project.user_stories_count || 0), 0)
  const projectsWithUrl = projects.value.filter((project) => Boolean(project.app_url)).length

  return [
    { label: 'Projets', value: totalProjects, caption: 'Projets disponibles dans l'espace de travail' },
    { label: 'Testeurs assignés', value: totalAssignedTesters, caption: 'Capacité d'exécution mobilisée' },
    { label: 'User Stories liées', value: totalLinkedStories, caption: 'Périmètre fonctionnel déj  préparé' },
    { label: 'Environnements prêts', value: projectsWithUrl, caption: 'Projets disposant d'une URL cible' },
  ]
})

const filteredProjects = computed(() => {
  return projects.value.filter((project) => {
    const projectType = String(project.type || project.project_type || '').trim()
    const projectCategory = String(project.category || '').trim()
    const searchText = `${project.name || ''} ${project.description || ''} ${project.test_objectives || ''} ${project.app_url || ''} ${projectType} ${projectCategory}`.toLowerCase()

    const matchesName =
      filters.name.trim() === '' ||
      String(project.name || '').toLowerCase().includes(filters.name.trim().toLowerCase())
    const matchesType =
      filters.type.trim() === '' ||
      projectType.toLowerCase().includes(filters.type.trim().toLowerCase())
    const matchesCategory =
      filters.category.trim() === '' ||
      projectCategory.toLowerCase().includes(filters.category.trim().toLowerCase())
    const matchesQuery =
      filters.query.trim() === '' || searchText.includes(filters.query.trim().toLowerCase())
    const matchesCreator =
      filters.creator === 'all' ||
      String(project.creator?.id || '') === String(filters.creator)

    return matchesName && matchesType && matchesCategory && matchesQuery && matchesCreator
  })
})

function createEmptyManualStory() {
  const localId = manualStorySeed.value
  manualStorySeed.value += 1

  return {
    localId,
    title: '',
    description: '',
    acceptance_criteria: '',
    priority: 'medium',
    status: 'backlog',
  }
}

function addManualStory() {
  manualStories.value = [...manualStories.value, createEmptyManualStory()]
}

function removeManualStory(localId) {
  manualStories.value = manualStories.value.filter((story) => story.localId !== localId)
}

function buildManualStoriesPayload() {
  return manualStories.value
    .map((story) => ({
      title: story.title.trim(),
      description: story.description.trim(),
      acceptance_criteria: story.acceptance_criteria.trim(),
      priority: story.priority,
      status: story.status,
    }))
    .filter(
      (story) =>
        story.title !== '' ||
        story.description !== '' ||
        story.acceptance_criteria !== ''
    )
}

function buildUserStoriesSuccessMessage(summary) {
  const createdParts = []
  const manualCreated = Number(summary?.manual_created || 0)
  const importedCreated = Number(summary?.imported_created || 0)
  const failedManuals = Number(summary?.failed_manuals || 0)
  const failedImports = Number(summary?.failed_imports || 0)
  const ignoredCount = failedManuals + failedImports

  if (manualCreated > 0) {
    createdParts.push(`${manualCreated} User Stor${manualCreated > 1 ? 'ies manuelles' : 'y manuelle'}`)
  }

  if (importedCreated > 0) {
    createdParts.push(`${importedCreated} User Stor${importedCreated > 1 ? 'ies importées' : 'y importée'}`)
  }

  if (createdParts.length === 0) {
    return tr('project_created_success', {}, settings.language)
  }

  const createdText = createdParts.join(' et ')
  const totalCreated = manualCreated + importedCreated
  const addedVerb = totalCreated > 1 ? 'ajoutées' : 'ajoutée'
  if (ignoredCount > 0) {
    return localizeMessage(`Projet cree avec succes. ${createdText} ${addedVerb} et ${ignoredCount} ligne(s) ont ete ignoree(s).`, settings.language)
  }

  return localizeMessage(`Projet cree avec succes. ${createdText} ${addedVerb}.`, settings.language)
}

async function validateProjectName() {
  const trimmedName = form.name.trim()

  if (!showProjectForm.value || trimmedName === '') {
    projectNameError.value = ''
    checkingProjectName.value = false
    return true
  }

  checkingProjectName.value = true

  try {
    const response = await apiRequest(
      withQuery('/projects/validate-name', {
        name: trimmedName,
        ignore_id: form.id,
      }),
      {},
      auth.token
    )

    projectNameError.value = response?.exists ? tr('project_name_exists', {}, settings.language) : ''
    return !response?.exists
  } catch (error) {
    projectNameError.value = localizeError(error, 'project_name_check_failed', settings.language)
    return false
  } finally {
    checkingProjectName.value = false
  }
}

async function loadProjects(page = pagination.current_page || 1) {
  loadingProjects.value = true
  listError.value = ''

  try {
    const data = await apiRequest(withQuery('/projects', { page, status: 'active' }), {}, auth.token)
    projects.value = data.data || []
    pagination.current_page = data.current_page
    pagination.last_page = data.last_page
  } catch (error) {
    listError.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    loadingProjects.value = false
  }
}

async function loadArchivedProjects(page = archivedPagination.current_page || 1) {
  if (!auth.canManageProjects) {
    archivedProjects.value = []
    return
  }

  loadingArchivedProjects.value = true
  listError.value = ''

  try {
    const data = await apiRequest(withQuery('/projects', { page, status: 'archived' }), {}, auth.token)
    archivedProjects.value = data.data || []
    archivedPagination.current_page = data.current_page
    archivedPagination.last_page = data.last_page
  } catch (error) {
    listError.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    loadingArchivedProjects.value = false
  }
}

async function loadProjectLists(activePage = pagination.current_page || 1, archivedPage = archivedPagination.current_page || 1) {
  await Promise.all([loadProjects(activePage), loadArchivedProjects(archivedPage)])
}

async function loadProjectMetadata() {
  if (!auth.canManageProjects) {
    return
  }

  try {
    const data = await apiRequest('/projects/metadata', {}, auth.token)
    users.value = data.testers || []
  } catch (error) {
    users.value = []
    createError.value = localizeError(error, 'project_metadata_failed', settings.language)
  }
}

async function submitProject() {
  createError.value = ''
  successMessage.value = ''
  creating.value = true

  try {
    const projectNameIsValid = await validateProjectName()
    if (!projectNameIsValid) {
      createError.value = tr('project_choose_another_name', {}, settings.language)
      return
    }

    if (form.id) {
      const payload = {
        name: form.name,
        description: form.description || null,
        test_objectives: form.test_objectives || null,
        app_url: form.app_url,
        tester_ids: form.tester_ids.map((id) => Number(id)),
      }

      await apiRequest(`/projects/${form.id}`, { method: 'PUT', body: payload }, auth.token)
      successMessage.value = tr('project_updated_success', {}, settings.language)
    } else {
      if (importedStoriesFile.value && !importedStoriesAnalysis.value) {
        const importIsValid = await analyzeSelectedUserStoriesFile()
        if (!importIsValid) {
          return
        }
      }

      const manualStoriesPayload = buildManualStoriesPayload()

      if (!hasImportedUserStories.value && manualValidUserStoriesCount.value === 0) {
        createError.value = tr('user_story_required', {}, settings.language)
        return
      }

      const payload = new FormData()
      payload.append('name', form.name)
      payload.append('description', form.description || '')
      payload.append('test_objectives', form.test_objectives || '')
      payload.append('app_url', form.app_url)

      form.tester_ids.forEach((id) => {
        payload.append('tester_ids[]', String(Number(id)))
      })

      if (importedStoriesFile.value) {
        payload.append('user_stories_file', importedStoriesFile.value)
      }

      if (manualStoriesPayload.length > 0) {
        payload.append('manual_user_stories', JSON.stringify(manualStoriesPayload))
      }

      const response = await apiRequest('/projects', { method: 'POST', body: payload }, auth.token)
      const summary = response?.user_stories_summary

      if (summary) {
        successMessage.value = buildUserStoriesSuccessMessage(summary)
      } else {
        successMessage.value = tr('project_created_success', {}, settings.language)
      }

      resetForm(true)
      await loadProjectLists(1, archivedPagination.current_page)
      return
    }

    resetForm(true)
    await loadProjectLists(1, archivedPagination.current_page)
  } catch (error) {
    createError.value = localizeError(error, 'project_create_failed', settings.language)
  } finally {
    creating.value = false
  }
}

async function analyzeSelectedUserStoriesFile() {
  importFileError.value = ''
  importedStoriesAnalysis.value = null

  if (!importedStoriesFile.value) {
    return true
  }

  importValidationLoading.value = true

  try {
    importedStoriesAnalysis.value = await analyzeImportedUserStories(importedStoriesFile.value)
    return true
  } catch (error) {
    importFileError.value = localizeError(error, 'user_story_file_read_failed', settings.language)
    return false
  } finally {
    importValidationLoading.value = false
  }
}

async function onUserStoriesFileChange(event) {
  importFileError.value = ''
  importedStoriesFile.value = event.target.files?.[0] || null
  importedStoriesAnalysis.value = null

  if (importedStoriesFile.value) {
    const importIsValid = await analyzeSelectedUserStoriesFile()

    if (!importIsValid) {
      importedStoriesFile.value = null

      if (userStoriesFileInput.value) {
        userStoriesFileInput.value.value = ''
      }
    }
  }
}

function clearUserStoriesFile() {
  importedStoriesFile.value = null
  importedStoriesAnalysis.value = null
  importFileError.value = ''

  if (userStoriesFileInput.value) {
    userStoriesFileInput.value.value = ''
  }
}

function resetUserStoriesSection() {
  clearUserStoriesFile()
  manualStories.value = []
}

function resetForm(closeForm = false) {
  form.id = null
  form.name = ''
  form.description = ''
  form.test_objectives = ''
  form.app_url = ''
  form.tester_ids = []
  resetUserStoriesSection()
  projectNameError.value = ''
  checkingProjectName.value = false

  if (closeForm) {
    showProjectForm.value = false
  }
}

function clearAllFilters() {
  filters.name = ''
  filters.type = ''
  filters.category = ''
  filters.query = ''
  filters.creator = 'all'
}

function removeFilter(key) {
  if (key === 'name') {
    filters.name = ''
    return
  }

  if (key === 'type') {
    filters.type = ''
    return
  }

  if (key === 'category') {
    filters.category = ''
    return
  }

  if (key === 'query') {
    filters.query = ''
    return
  }

  if (key === 'creator') {
    filters.creator = 'all'
  }
}

function toggleCreatorFilter(id) {
  const value = String(id)
  filters.creator = filters.creator === value ? 'all' : value
}

function getHostname(url) {
  try {
    return new URL(url).hostname
  } catch {
    return url
  }
}

function importedStoryErrorText(error) {
  const message = String(error?.message || '')

  if (
    message.includes('Le titre de la User Story est obligatoire.') ||
    message.includes('La description de la User Story est obligatoire.') ||
    message.includes('Les crit')
  ) {
    return 'user stories mal structurées'
  }

  return message
}

function projectNoTesterText() {
  return 'Aucun testeur assigné'
}

function projectNoUrlText() {
  return 'Aucune URL renseignée'
}

function openCreateForm() {
  createError.value = ''
  successMessage.value = ''
  projectNameError.value = ''
  resetForm(false)
  showProjectForm.value = true
}

function consumeCreateQuery() {
  if (route.query.create !== '1') {
    return
  }

  const nextQuery = { ...route.query }
  delete nextQuery.create
  router.replace({ query: nextQuery })
}

function consumeEditQuery() {
  if (!route.query.edit) {
    return
  }

  const nextQuery = { ...route.query }
  delete nextQuery.edit
  router.replace({ query: nextQuery })
}

function openCreateFormFromQuery() {
  if (!auth.canManageProjects || route.query.create !== '1') {
    return
  }

  openCreateForm()
  consumeCreateQuery()
}

function openEditFormFromQuery() {
  if (!auth.canManageProjects || !route.query.edit) {
    return
  }

  const projectId = String(route.query.edit)
  const project = projects.value.find((entry) => String(entry.id) === projectId)

  if (!project) {
    return
  }

  editProject(project)
  consumeEditQuery()
}

function editProject(project) {
  createError.value = ''
  successMessage.value = ''
  projectNameError.value = ''
  resetUserStoriesSection()
  showProjectForm.value = true
  form.id = project.id
  form.name = project.name
  form.description = project.description || ''
  form.test_objectives = project.test_objectives || ''
  form.app_url = project.app_url || ''
  form.tester_ids = (project.testers || []).map((tester) => tester.id)
}

function toggleProjectMenu(projectId) {
  openProjectMenuId.value = openProjectMenuId.value === projectId ? null : projectId
}

function closeProjectMenu() {
  openProjectMenuId.value = null
}

function canManageProject(project) {
  return auth.isAdmin || project.created_by === auth.user?.id
}

async function deleteProject(projectId) {
  closeProjectMenu()

  if (!confirm(tr('confirm_archive_project', {}, settings.language))) {
    return
  }

  listError.value = ''

  try {
    await apiRequest(`/projects/${projectId}`, { method: 'DELETE' }, auth.token)
    successMessage.value = tr('project_archived_success', {}, settings.language)
    await loadProjectLists(1, 1)
  } catch (error) {
    listError.value = localizeError(error, 'error_generic', settings.language)
  }
}

async function restoreProject(project) {
  closeProjectMenu()
  listError.value = ''

  try {
    await apiRequest(`/projects/${project.id}/restore`, { method: 'POST' }, auth.token)
    successMessage.value = tr('project_restored_success', {}, settings.language)
    await loadProjectLists(1, archivedPagination.current_page)
  } catch (error) {
    listError.value = localizeError(error, 'error_generic', settings.language)
  }
}

async function permanentlyDeleteProject(project) {
  closeProjectMenu()

  if (!confirm(tr('confirm_delete_project_permanent', { name: project.name }, settings.language))) {
    return
  }

  listError.value = ''

  try {
    await apiRequest(`/projects/${project.id}/permanent`, { method: 'DELETE' }, auth.token)
    successMessage.value = tr('project_deleted_success', {}, settings.language)
    await loadProjectLists(pagination.current_page, archivedPagination.current_page)
  } catch (error) {
    listError.value = localizeError(error, 'error_generic', settings.language)
  }
}

onMounted(async () => {
  window.addEventListener('click', closeProjectMenu)
  await Promise.all([loadProjectLists(1, 1), loadProjectMetadata()])
  openCreateFormFromQuery()
  openEditFormFromQuery()
})

onBeforeUnmount(() => {
  window.removeEventListener('click', closeProjectMenu)
  if (projectNameValidationTimer.value) {
    clearTimeout(projectNameValidationTimer.value)
    projectNameValidationTimer.value = null
  }
})

watch(
  () => route.query.create,
  () => {
    openCreateFormFromQuery()
  },
)

watch(
  () => route.query.edit,
  () => {
    openEditFormFromQuery()
  },
)

watch(
  () => form.name,
  (value) => {
    if (projectNameValidationTimer.value) {
      clearTimeout(projectNameValidationTimer.value)
      projectNameValidationTimer.value = null
    }

    if (!showProjectForm.value || String(value || '').trim() === '') {
      projectNameError.value = ''
      checkingProjectName.value = false
      return
    }

    checkingProjectName.value = true
    projectNameValidationTimer.value = setTimeout(() => {
      projectNameValidationTimer.value = null
      validateProjectName()
    }, 350)
  },
)

watch(successMessage, (message) => {
  if (!message) {
    return
  }

  toast.success(message)
  successMessage.value = ''
})
</script>

<template>
  <section class="page stack">
    <div class="dashboard-command projects-dashboard-command">
      <div class="projects-hero-layout">
        <div>
          <p class="dashboard-eyebrow">{{ projectPageCopy.kicker }}</p>
          <h1 class="page-title-icon">
            <Rocket :size="30" :stroke-width="2.3" />
            <span>{{ projectPageCopy.title }}</span>
          </h1>
          <p class="muted page-subtitle">{{ projectPageCopy.description }}</p>
        </div>

        <div v-if="auth.canManageProjects" class="projects-hero-actions">
          <button
            class="btn btn-primary create-project-btn"
            type="button"
            @click="openCreateForm"
            data-testid="projects-btn-open-create"
          >
            <CirclePlus :size="16" :stroke-width="2" />
            <span>Creer un projet</span>
          </button>
        </div>
      </div>
    </div>

    <div class="card stack stack-gap-sm">
      <div class="search-top-row">
        <div class="search-input-wrap">
          <Search :size="18" :stroke-width="2.1" />
          <input v-model="filters.query" placeholder="Rechercher un projet..." class="search-input" />
        </div>
      </div>

      <div class="actions actions-between">
        <button class="btn btn-secondary btn-sm" type="button" @click="showAdvancedFilters = !showAdvancedFilters">
          <span>{{ showAdvancedFilters ? 'Masquer les filtres' : 'Afficher les filtres' }}</span>
        </button>

        <button v-if="hasActiveFilters" class="btn btn-secondary btn-sm" type="button" @click="clearAllFilters">
          Réinitialiser
        </button>
      </div>

      <div v-if="showAdvancedFilters" class="stack advanced-filters-stack">
        <div class="grid filters-grid">
          <div class="field">
            <label class="field-label-strong">Nom</label>
            <input v-model="filters.name" placeholder="Filtrer par nom de projet" />
          </div>

          <div class="field">
            <label class="field-label-strong">Type</label>
            <input v-model="filters.type" placeholder="Filtrer par type" />
          </div>

          <div class="field">
            <label class="field-label-strong">Catégorie</label>
            <input v-model="filters.category" placeholder="Filtrer par catégorie" />
          </div>
        </div>

        <div class="field field-tight">
          <label class="field-label-strong">Créateur</label>
          <div class="chip-row">
            <button type="button" class="filter-chip" :class="{ active: filters.creator === 'all' }" @click="filters.creator = 'all'">
              Tous
            </button>
            <button
              v-for="creator in creatorFilterOptions"
              :key="creator.id"
              type="button"
              class="filter-chip"
              :class="{ active: filters.creator === String(creator.id) }"
              @click="toggleCreatorFilter(creator.id)"
            >
              {{ creator.name }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="hasActiveFilters" class="active-filters-row">
        <span class="muted active-filters-label">Filtres appliqués :</span>
        <div class="chip-row">
          <span v-for="badge in activeFilterBadges" :key="badge.key" class="applied-chip">
            {{ badge.label }}
            <button type="button" @click="removeFilter(badge.key)">
              <X :size="12" />
            </button>
          </span>
        </div>
      </div>
    </div>

    <div v-if="auth.canManageProjects && showProjectForm" class="card stack" >
      <div class="section-divider">
        <h2 class="section-heading-with-icon">
          <Pencil v-if="form.id" :size="20" :stroke-width="2.2" />
          <CirclePlus v-else :size="20" :stroke-width="2.2"  />
          <span>{{ form.id ? 'Modifier le projet' : 'Créer projet' }}</span>
        </h2>
      </div>

      <p v-if="createError" class="error" data-testid="projects-msg-error">{{ createError }}</p>

      <form class="stack" @submit.prevent="submitProject" data-testid="projects-form">
        <div class="form-grid-two">
          <div class="field">
            <label class="field-label-strong">Nom du projet</label>
            <input
              v-model="form.name"
              required
              placeholder="ex. : Phase 1 de test API"
              data-testid="projects-input-name"
              @blur="validateProjectName"
            />
            <p v-if="checkingProjectName" class="muted helper-text">Vérification du nom du projet...</p>
            <p v-else-if="projectNameError" class="error">{{ projectNameError }}</p>
          </div>
          <div class="field">
            <label class="field-label-strong">URL de l'application</label>
            <input v-model="form.app_url" type="url" placeholder="https://example.com" required data-testid="projects-input-app-url" />
          </div>
        </div>

        <div class="field">
          <label class="field-label-strong">Description</label>
          <textarea v-model="form.description" rows="3" placeholder="Décrivez le contexte, le périmètre et les enjeux métier du projet..." />
        </div>

        <div class="field">
          <label class="field-label-strong">Objectifs de test</label>
          <textarea v-model="form.test_objectives" rows="3" placeholder="Précisez les objectifs QA, les risques   couvrir et les résultats attendus..." />
        </div>

        <div class="card project-setup-section">
          <div class="section-divider">
            <h3 class="section-heading-with-icon-sm">
              <Settings :size="18" :stroke-width="2.2" />
              <span>Équipe assignée</span>
            </h3>
          </div>

          <div class="field">
            <label class="label-with-icon">
              <Users :size="18" :stroke-width="2.1" />
              Assigner les testeurs
            </label>
            <p class="muted helper-text">
              Sélectionnez les testeurs responsables de la préparation des checklists et de l'exécution des tests.
            </p>
            <div v-if="users.length === 0" class="muted empty-state-box">
              Aucun testeur disponible pour le moment.
            </div>
            <div v-else class="selection-grid">
              <label v-for="user in users" :key="user.id" class="selection-card selection-card-green">
                <input type="checkbox" :value="user.id" v-model="form.tester_ids" class="selection-check" />
                <div class="selection-body">
                  <div class="selection-title">{{ user.name }}</div>
                  <div class="muted selection-meta">{{ user.email }}</div>
                </div>
              </label>
            </div>

          </div>
        </div>

        <div v-if="!form.id" class="card project-setup-section stack">
          <div class="section-divider">
            <h3 class="section-heading-with-icon-sm">
              <CirclePlus :size="18" :stroke-width="2.2" />
              <span>Backlog initial du projet</span>
            </h3>
          </div>

          <p class="muted helper-text">
            Préparez le backlog initial du projet en ajoutant des User Stories manuellement ou en important un fichier structuré dès la création.
          </p>

          <div class="story-source-summary">
            <span class="mini-chip">{{ manualValidUserStoriesCount }} User Story{{ manualValidUserStoriesCount > 1 ? 'ies manuelles' : ' manuelle' }}</span>
            <span class="mini-chip">{{ importedValidUserStoriesCount }} User Story{{ importedValidUserStoriesCount > 1 ? 'ies' : 'y' }} importée{{ importedValidUserStoriesCount > 1 ? 's' : '' }}</span>
            <span v-if="importedInvalidUserStoriesCount > 0" class="mini-chip mini-chip-warn">
              {{ importedInvalidUserStoriesCount }} ligne(s) ignorée(s)
            </span>
          </div>

          <div class="card stack stack-gap-sm">
            <div class="manual-story-card-head">
              <div class="section-divider">
                <h4 class="section-heading-with-icon-sm">
                  <Pencil :size="16" :stroke-width="2.1" />
                  <span>Saisie manuelle</span>
                </h4>
              </div>

              <button type="button" class="btn btn-secondary btn-sm" @click="addManualStory">
                <CirclePlus :size="14" />
                <span>Ajouter une User Story</span>
              </button>
            </div>

            <p class="muted helper-text">
              Ajoutez manuellement des User Stories si vous ne souhaitez pas passer par un fichier. Le titre, la description et les critères d'acceptation sont requis.
            </p>

            <div v-if="manualStories.length === 0" class="muted empty-state-box">
              Aucune User Story manuelle pour le moment.
            </div>

            <div v-else class="stack stack-gap-sm">
              <article v-for="(story, index) in manualStories" :key="story.localId" class="manual-story-card stack stack-gap-sm">
                <div class="manual-story-card-head">
                  <div>
                    <strong>User Story {{ index + 1 }}</strong>
                    <p class="muted">Préparez un besoin métier prêt   être rattaché au projet dès sa création.</p>
                  </div>

                  <button type="button" class="btn btn-danger btn-sm" @click="removeManualStory(story.localId)">
                    <Trash2 :size="14" />
                    <span>Retirer</span>
                  </button>
                </div>

                <div class="form-grid-two">
                  <div class="field">
                    <label class="field-label-strong">Titre</label>
                    <input v-model="story.title" placeholder="ex. : Se connecter avec email et mot de passe" />
                  </div>

                  <div class="field">
                    <label class="field-label-strong">Priorité</label>
                    <select v-model="story.priority">
                      <option value="critical">Critique</option>
                      <option value="high">Haute</option>
                      <option value="medium">Moyenne</option>
                      <option value="low">Basse</option>
                    </select>
                  </div>
                </div>

                <div class="field">
                  <label class="field-label-strong">Description</label>
                  <textarea v-model="story.description" rows="3" placeholder="Décrivez le besoin utilisateur, le contexte et le comportement attendu..." />
                </div>

                <div class="form-grid-two">
                  <div class="field">
                    <label class="field-label-strong">Critères d'acceptation</label>
                    <textarea v-model="story.acceptance_criteria" rows="3" placeholder="Given / When / Then, règles de validation, cas attendus..." />
                  </div>

                  <div class="field">
                    <label class="field-label-strong">Statut initial</label>
                    <select v-model="story.status">
                      <option value="backlog">Backlog</option>
                      <option value="in_progress">En cours</option>
                      <option value="ready_for_test">Prêt pour test</option>
                      <option value="completed">Terminée</option>
                    </select>
                  </div>
                </div>
              </article>
            </div>
          </div>

          <div class="card stack stack-gap-sm">
            <div class="section-divider">
              <h4 class="section-heading-with-icon-sm">
                <Sparkles :size="16" :stroke-width="2.1" />
                <span>Import depuis un fichier</span>
              </h4>
            </div>

            <p class="muted helper-text">
              Importez un fichier CSV, XLSX ou JSON contenant plusieurs User Stories structurées.
            </p>

            <div class="field">
              <label class="field-label-strong">Fichier de User Stories</label>
              <input ref="userStoriesFileInput" type="file" accept=".csv,.xlsx,.json" @change="onUserStoriesFileChange" />
              <p v-if="importFileError" class="error">{{ importFileError }}</p>
              <p class="muted helper-text">
                Formats acceptés : CSV, XLSX ou JSON. Cette option est recommandée pour importer rapidement un backlog volumineux.
              </p>
            </div>

            <div v-if="importValidationLoading" class="muted">Analyse du fichier en cours...</div>

            <div v-if="importedStoriesFile" class="story-import-summary">
              <p><strong>{{ importedStoriesFile.name }}</strong></p>
              <p class="muted">
                {{ importedValidUserStoriesCount }} User Story{{ importedValidUserStoriesCount > 1 ? 'ies' : 'y' }} importée{{ importedValidUserStoriesCount > 1 ? 's' : '' }}
                <span v-if="importedInvalidUserStoriesCount > 0"> â€¢ {{ importedInvalidUserStoriesCount }} ligne(s) ignorée(s)</span>
              </p>
              <button type="button" class="btn btn-secondary btn-sm" @click="clearUserStoriesFile">
                <X :size="14" />
                <span>Retirer le fichier</span>
              </button>
            </div>

            <div v-if="importedStoriesAnalysis?.errors?.length" class="stack stack-gap-sm">
              <p class="muted">Lignes non importées :</p>
              <div v-for="error in importedStoriesAnalysis.errors.slice(0, 5)" :key="`${error.row}-${error.message}`" class="error-row">
                Ligne {{ error.row }} : {{ importedStoryErrorText(error) }}
              </div>
            </div>
          </div>
        </div>

        <div class="form-actions-row">
          <button class="btn btn-primary btn-min-wide" type="submit" :disabled="creating || checkingProjectName || isProjectNameTaken" data-testid="projects-btn-submit">
            <LoaderCircle v-if="creating" :size="16" class="spin" />
            <Save v-else-if="form.id" :size="16" />
            <Sparkles v-else :size="16" />
            <span>{{ creating ? (form.id ? 'Mise   jour...' : 'Création...') : (form.id ? 'Enregistrer les modifications' : 'Créer le projet') }}</span>
          </button>
          <button type="button" class="btn btn-secondary btn-inline-icon" @click="resetForm(true)">
            <X :size="16" />
            <span>Fermer</span>
          </button>
        </div>
      </form>
    </div>

    <div class="card stack section-space-xl">
      <div class="section-divider">
        <h2 class="section-heading-with-icon">
          <ChartColumn :size="20" :stroke-width="2.2" />
          <span>Liste des projets</span>
        </h2>
      </div>

      <p class="muted">
        Ouvrez un projet pour accéder   son espace de suivi et consulter ses user stories.
      </p>

      <p v-if="listError" class="error" data-testid="projects-msg-error-list">{{ listError }}</p>
      <p v-if="loadingProjects" class="muted">Chargement des projets...</p>

      <div v-if="!loadingProjects && filteredProjects.length > 0" class="table-wrap" data-testid="projects-table">
        <table class="projects-lines-table">
          <thead>
            <tr>
              <th>Projet</th>
              <th>User Stories</th>
              <th>Testeurs</th>
              <th>URL</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="project in filteredProjects" :key="`line-${project.id}`">
              <td>
                <div class="project-line-main">
                  <RouterLink :to="{ name: 'project-detail', params: { id: project.id } }" class="project-title-link">
                    {{ project.name }}
                  </RouterLink>
                  <p class="muted meta-line project-line-meta">
                    <span>#{{ project.id }}</span>
                    <span class="project-card-dot" aria-hidden="true"></span>
                    <span>{{ project.creator?.name || 'Chef de projet' }}</span>
                  </p>
                </div>
              </td>
              <td>{{ project.user_stories_count || 0 }}</td>
              <td>
                <div class="project-line-testers">
                  <span v-for="tester in project.testers || []" :key="tester.id" class="mini-chip tester-chip">{{ tester.name }}</span>
                  <span v-if="!project.testers || project.testers.length === 0" class="muted project-line-fallback">{{ projectNoTesterText() }}</span>
                  <span v-if="!project.testers || project.testers.length === 0" class="muted">Aucun testeur assignƒ©</span>
                </div>
              </td>
              <td>
                <a v-if="project.app_url" :href="project.app_url" target="_blank" rel="noopener noreferrer" class="muted app-url-text">
                  {{ getHostname(project.app_url) }}
                </a>
                <span v-else class="muted app-url-text project-line-fallback">{{ projectNoUrlText() }}</span>
                <span v-if="false" class="muted app-url-text">Aucune URL renseignƒ©e</span>
              </td>
              <td class="projects-actions-cell">
                <div v-if="canManageProject(project)" class="project-card-actions" @click.stop>
                  <button
                    type="button"
                    class="project-menu-trigger"
                    aria-label="Ouvrir les actions du projet"
                    @click.stop="toggleProjectMenu(project.id)"
                  >
                    <Ellipsis :size="18" />
                  </button>

                  <div v-if="openProjectMenuId === project.id" class="project-row-menu">
                    <button type="button" class="project-row-menu-item danger" @click="closeProjectMenu(); deleteProject(project.id)">
                      <Archive :size="16" />
                      <span>Archiver</span>
                    </button>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="false && !loadingProjects && filteredProjects.length > 0" class="projects-grid" data-testid="projects-cards-active">
        <article v-for="project in filteredProjects" :key="project.id" class="project-card">
          <div class="project-card-head">
            <div class="project-card-copy">
              <RouterLink :to="{ name: 'project-detail', params: { id: project.id } }" class="project-title-link">
                {{ project.name }}
              </RouterLink>
              <p class="muted meta-line project-card-meta">
                <span>#{{ project.id }}</span>
                <span class="project-card-dot" aria-hidden="true"></span>
                <span>{{ project.creator?.name || 'Chef de projet' }}</span>
              </p>
            </div>

            <div v-if="canManageProject(project)" class="project-card-actions" @click.stop>
              <button
                type="button"
                class="project-menu-trigger"
                aria-label="Ouvrir les actions du projet"
                @click.stop="toggleProjectMenu(project.id)"
              >
                <Ellipsis :size="18" />
              </button>

              <div v-if="openProjectMenuId === project.id" class="project-row-menu">
                <button type="button" class="project-row-menu-item danger" @click="closeProjectMenu(); deleteProject(project.id)">
                  <Archive :size="16" />
                  <span>Archiver</span>
                </button>
              </div>
            </div>
          </div>

          <p class="muted description-fixed">{{ project.description || 'Aucun contexte projet renseigné.' }}</p>

          <div class="project-meta-row">
            <span class="mini-label">Backlog</span>
            <div class="chip-row">
              <span class="mini-chip">{{ project.user_stories_count || 0 }} User Story{{ (project.user_stories_count || 0) > 1 ? 'ies' : '' }}</span>
            </div>
          </div>

          <div class="project-meta-row">
            <span class="mini-label">Objectifs</span>
            <div class="chip-row">
              <span class="muted">{{ project.test_objectives || 'Aucun objectif de test défini pour le moment.' }}</span>
            </div>
          </div>

          <div class="project-meta-row">
            <span class="mini-label">Testeurs</span>
            <div class="chip-row">
              <span v-for="tester in project.testers || []" :key="tester.id" class="mini-chip tester-chip">{{ tester.name }}</span>
              <span v-if="!project.testers || project.testers.length === 0" class="muted">Aucun testeur assigné</span>
            </div>
          </div>

          <div class="project-card-footer">
            <a v-if="project.app_url" :href="project.app_url" target="_blank" rel="noopener noreferrer" class="muted app-url-text">
              {{ getHostname(project.app_url) }}
            </a>
            <span v-else class="muted app-url-text">Aucune URL renseignée</span>

            <div class="actions">
              <button v-if="canManageProject(project)" class="btn btn-danger btn-sm" @click="deleteProject(project.id)">
                <Trash2 :size="14" />
                <span>Archiver</span>
              </button>
            </div>
          </div>
        </article>
      </div>

      <div v-if="!loadingProjects && filteredProjects.length === 0" class="card empty-dashed-card">
        <p class="muted">Aucun projet ne correspond aux filtres sélectionnés.</p>
      </div>

      <div class="pagination pagination-centered">
        <button class="btn btn-secondary btn-sm btn-nav-icon" :disabled="pagination.current_page <= 1" @click="loadProjects(pagination.current_page - 1)" title="Aller   la page précédente">
          <ArrowLeft :size="14" />
          <span>Précédent</span>
        </button>
        <span class="muted pagination-text">Page {{ pagination.current_page }} sur {{ pagination.last_page }}</span>
        <button class="btn btn-secondary btn-sm btn-nav-icon" :disabled="pagination.current_page >= pagination.last_page" @click="loadProjects(pagination.current_page + 1)" title="Aller   la page suivante">
          <span>Suivant</span>
          <ArrowRight :size="14" />
        </button>
      </div>
    </div>

    <div v-if="auth.canManageProjects" class="card stack section-space-xl">
      <div class="section-divider">
        <h2 class="section-heading-with-icon">
          <Archive :size="20" :stroke-width="2.2" />
          <span>Projets archivés</span>
        </h2>
      </div>

      <p class="muted">
        Les projets archivés sont masqués de la liste principale. Vous pouvez les restaurer ou les supprimer définitivement.
      </p>

      <p v-if="listError" class="error">{{ listError }}</p>
      <p v-if="loadingArchivedProjects" class="muted">Chargement des projets archivés...</p>

      <div v-if="!loadingArchivedProjects && archivedProjects.length > 0" class="table-wrap" data-testid="projects-table-archived">
        <table class="projects-lines-table">
          <thead>
            <tr>
              <th>Projet</th>
              <th>User Stories</th>
              <th>Testeurs</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="project in archivedProjects" :key="`archived-line-${project.id}`">
              <td>
                <div class="project-line-main">
                  <h3 class="project-title-static">{{ project.name }}</h3>
                  <p class="muted meta-line project-line-meta">
                    <span>#{{ project.id }}</span>
                    <span class="project-card-dot" aria-hidden="true"></span>
                    <span>{{ project.creator?.name || 'Chef de projet' }}</span>
                  </p>
                </div>
              </td>
              <td>{{ project.user_stories_count || 0 }}</td>
              <td>
                <div class="project-line-testers">
                  <span v-for="tester in project.testers || []" :key="tester.id" class="mini-chip tester-chip">{{ tester.name }}</span>
                  <span v-if="!project.testers || project.testers.length === 0" class="muted">Aucun testeur assignƒ©</span>
                </div>
              </td>
              <td><span class="muted app-url-text">Archivé</span></td>
              <td class="projects-actions-cell">
                <div v-if="canManageProject(project)" class="project-card-actions" @click.stop>
                  <button
                    type="button"
                    class="project-menu-trigger"
                    aria-label="Ouvrir les actions du projet archivé"
                    @click.stop="toggleProjectMenu(`archived-${project.id}`)"
                  >
                    <Ellipsis :size="18" />
                  </button>

                  <div v-if="openProjectMenuId === `archived-${project.id}`" class="project-row-menu">
                    <button type="button" class="project-row-menu-item" @click="restoreProject(project)">
                      <RotateCcw :size="16" />
                      <span>Restaurer</span>
                    </button>
                    <button type="button" class="project-row-menu-item danger" @click="permanentlyDeleteProject(project)">
                      <Trash2 :size="16" />
                      <span>Supprimer définitivement</span>
                    </button>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="false && !loadingArchivedProjects && archivedProjects.length > 0" class="projects-grid" data-testid="projects-cards-archived">
        <article v-for="project in archivedProjects" :key="`archived-${project.id}`" class="project-card archived-project-card">
          <div class="project-card-head">
            <div class="project-card-copy">
              <h3 class="project-title-static">{{ project.name }}</h3>
              <p class="muted meta-line project-card-meta">
                <span>#{{ project.id }}</span>
                <span class="project-card-dot" aria-hidden="true"></span>
                <span>{{ project.creator?.name || 'Chef de projet' }}</span>
              </p>
            </div>

            <div v-if="canManageProject(project)" class="project-card-actions" @click.stop>
              <button
                type="button"
                class="project-menu-trigger"
                aria-label="Ouvrir les actions du projet archivé"
                @click.stop="toggleProjectMenu(`archived-${project.id}`)"
              >
                <Ellipsis :size="18" />
              </button>

              <div v-if="openProjectMenuId === `archived-${project.id}`" class="project-row-menu">
                <button type="button" class="project-row-menu-item" @click="restoreProject(project)">
                  <RotateCcw :size="16" />
                  <span>Restaurer</span>
                </button>
                <button type="button" class="project-row-menu-item danger" @click="permanentlyDeleteProject(project)">
                  <Trash2 :size="16" />
                  <span>Supprimer définitivement</span>
                </button>
              </div>
            </div>
          </div>

          <p class="muted description-fixed">{{ project.description || 'Aucun contexte projet renseigné.' }}</p>

          <div class="project-meta-row">
            <span class="mini-label">Backlog</span>
            <div class="chip-row">
              <span class="mini-chip">{{ project.user_stories_count || 0 }} User Story{{ (project.user_stories_count || 0) > 1 ? 'ies' : '' }}</span>
            </div>
          </div>

          <div class="project-meta-row">
            <span class="mini-label">Testeurs</span>
            <div class="chip-row">
              <span v-for="tester in project.testers || []" :key="tester.id" class="mini-chip tester-chip">{{ tester.name }}</span>
              <span v-if="!project.testers || project.testers.length === 0" class="muted">Aucun testeur assigné</span>
            </div>
          </div>

          <div class="project-card-footer">
            <span class="muted app-url-text">Archivé</span>

            <div class="actions">
              <button v-if="canManageProject(project)" class="btn btn-secondary btn-sm" @click="restoreProject(project)">
                <RotateCcw :size="14" />
                <span>Restaurer</span>
              </button>
              <button v-if="canManageProject(project)" class="btn btn-danger btn-sm" @click="permanentlyDeleteProject(project)">
                <Trash2 :size="14" />
                <span>Supprimer</span>
              </button>
            </div>
          </div>
        </article>
      </div>

      <div v-if="!loadingArchivedProjects && archivedProjects.length === 0" class="card empty-dashed-card">
        <p class="muted">Aucun projet archivé pour le moment.</p>
      </div>

      <div class="pagination pagination-centered">
        <button class="btn btn-secondary btn-sm btn-nav-icon" :disabled="archivedPagination.current_page <= 1" @click="loadArchivedProjects(archivedPagination.current_page - 1)" title="Aller   la page précédente">
          <ArrowLeft :size="14" />
          <span>Précédent</span>
        </button>
        <span class="muted pagination-text">Page {{ archivedPagination.current_page }} sur {{ archivedPagination.last_page }}</span>
        <button class="btn btn-secondary btn-sm btn-nav-icon" :disabled="archivedPagination.current_page >= archivedPagination.last_page" @click="loadArchivedProjects(archivedPagination.current_page + 1)" title="Aller   la page suivante">
          <span>Suivant</span>
          <ArrowRight :size="14" />
        </button>
      </div>
    </div>
  </section>
</template>

<style>
.story-detail-metrics {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.9rem;
}

.story-detail-metric {
  padding: 1rem 1.05rem;
  border-radius: 1.15rem;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.96));
  border: 1px solid rgba(148, 163, 184, 0.18);
  box-shadow: 0 16px 30px -28px rgba(15, 23, 42, 0.28);
}

.story-detail-metric span {
  display: block;
  margin-bottom: 0.35rem;
  font-size: 0.75rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #64748b;
  font-weight: 800;
}

.story-detail-metric strong {
  font-size: 1.25rem;
  color: #0f172a;
}

.story-detail-metric p {
  margin: 0.45rem 0 0;
}

.story-source-summary,
.manual-story-card-head {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
}

.projects-dashboard-command {
  grid-template-columns: 1fr;
}

.projects-hero-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: flex-start;
  gap: 1.5rem;
  width: 100%;
}

.projects-hero-actions {
  display: flex;
  justify-content: flex-end;
  flex: 0 0 auto;
  margin-left: auto;
}

.project-line-main {
  min-width: 0;
}

.projects-lines-table {
  table-layout: fixed;
}

.projects-lines-table th:first-child,
.projects-lines-table td:first-child {
  width: 44%;
}

.projects-lines-table .project-title-link,
.projects-lines-table .project-title-static {
  max-width: none;
  white-space: normal;
  word-break: break-word;
}

.project-line-meta {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.45rem;
  font-size: 0.76rem;
}

.project-line-testers {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.project-line-fallback + .muted {
  display: none;
}

.projects-actions-cell {
  width: 92px;
}

.manual-story-card,
.story-import-summary {
  padding: 1rem;
  border: 1px solid rgba(148, 163, 184, 0.22);
  border-radius: 1rem;
  background: rgba(248, 250, 252, 0.7);
}

.manual-story-card p,
.story-import-summary p {
  margin: 0.25rem 0 0;
}

.projects-grid {
  grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
  gap: 1.5rem;
}

.project-card {
  position: relative;
  min-height: 236px;
  padding: 1.55rem 1.45rem;
  border-radius: 1.5rem;
  border: 1px solid rgba(219, 228, 239, 0.92);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(252, 253, 255, 0.96));
  box-shadow: 0 22px 44px -34px rgba(15, 23, 42, 0.22);
}

.project-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 28px 48px -36px rgba(15, 23, 42, 0.24);
}

.project-card-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}

.project-card-copy {
  min-width: 0;
}

.project-title-link,
.project-title-static {
  display: inline-block;
  color: #0f172a;
  font-size: 1.02rem;
  font-weight: 800;
  line-height: 1.5;
  letter-spacing: -0.02em;
  max-width: 14ch;
}

.project-title-static {
  margin: 0;
}

.project-card-meta {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.7rem;
  font-size: 0.76rem;
  color: #64748b;
}

.project-card-dot {
  width: 0.32rem;
  height: 0.32rem;
  border-radius: 999px;
  background: #2563eb;
  flex: 0 0 auto;
}

.project-card-actions {
  position: relative;
  margin-left: auto;
}

.project-menu-trigger {
  width: 2.45rem;
  height: 2.45rem;
  border: 0;
  border-radius: 999px;
  background: #f3f6fb;
  color: #1e293b;
  display: grid;
  place-items: center;
  cursor: pointer;
  box-shadow: inset 0 0 0 1px rgba(226, 232, 240, 0.7);
}

.project-menu-trigger:hover {
  background: #eef3fb;
}

.project-row-menu {
  position: absolute;
  top: calc(100% + 0.55rem);
  right: -0.15rem;
  z-index: 20;
  min-width: 12.5rem;
  padding: 0.6rem;
  border-radius: 1.15rem;
  border: 1px solid rgba(224, 232, 241, 0.98);
  background: rgba(255, 255, 255, 0.98);
  box-shadow: 0 24px 44px -28px rgba(15, 23, 42, 0.24);
}

.project-row-menu-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 0.8rem;
  padding: 0.85rem 0.95rem;
  border: 0;
  border-radius: 0.9rem;
  background: transparent;
  color: #334155;
  text-align: left;
  font: inherit;
  font-size: 0.9rem;
  font-weight: 500;
  cursor: pointer;
}

.project-row-menu-item:hover {
  background: #f8fbff;
}

.project-row-menu-item.danger {
  color: #dc2626;
}

.project-row-menu-item svg {
  flex: 0 0 auto;
}

.project-card > .description-fixed,
.project-card > .project-meta-row,
.project-card > .project-card-footer {
  display: none;
}

.dark .project-menu-trigger,
.dark .project-row-menu {
  background: rgba(15, 23, 42, 0.96);
  color: #e2e8f0;
  border-color: rgba(51, 65, 85, 0.9);
}

.dark .project-menu-trigger {
  box-shadow: inset 0 0 0 1px rgba(51, 65, 85, 0.9);
}

.dark .project-menu-trigger:hover,
.dark .project-row-menu-item:hover {
  background: rgba(30, 41, 59, 0.9);
}

.dark .project-row-menu-item,
.dark .project-title-link,
.dark .project-title-static {
  color: #f8fafc;
}

.dark .project-row-menu-item.danger {
  color: #fca5a5;
}

.dark .project-card-meta {
  color: #94a3b8;
}

.mini-chip-warn {
  background: #fff7ed;
  color: #9a3412;
}

.error-row {
  padding: 0.7rem 0.85rem;
  border-radius: 0.85rem;
  background: #fff1f2;
  color: #be123c;
  border: 1px solid #fecdd3;
}

.dark .story-detail-metric {
  background: rgba(15, 23, 42, 0.92);
  border-color: rgba(51, 65, 85, 0.9);
}

.dark .story-detail-metric span {
  color: #94a3b8;
}

.dark .story-detail-metric strong {
  color: #f8fafc;
}

@media (max-width: 720px) {
  .projects-hero-layout {
    grid-template-columns: 1fr;
  }

  .projects-hero-actions .btn {
    width: 100%;
    justify-content: center;
  }

  .story-source-summary,
  .manual-story-card-head {
    flex-direction: column;
  }
}
</style>


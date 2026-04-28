<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  ArrowLeft,
  ArrowRight,
  BookOpen,
  ChartColumn,
  CirclePlus,
  ClipboardList,
  Info,
  LoaderCircle,
  Pencil,
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
import { useAuthStore } from '@/stores/auth'
import { translateCurrentPhrase } from '@/lib/runtimeTranslations'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const projects = ref([])
const pagination = reactive({
  current_page: 1,
  last_page: 1,
})

const checklists = ref([])
const users = ref([]) // for testers
const listError = ref('')
const createError = ref('')
const successMessage = ref('')
const loadingProjects = ref(false)
const creating = ref(false)
const showProjectForm = ref(false)
const showAdvancedFilters = ref(false)

const filters = reactive({
  name: '',
  type: '',
  category: '',
  query: '',
  creator: 'all',
  checklist: 'all',
})

const form = reactive({
  id: null,
  name: '',
  description: '',
  app_url: '',
  checklist_id: '',
  checklist_ids: [], // additional checklists
  tester_ids: [], // assigned testers
})

const projectPageCopy = computed(() => {
  switch (auth.primaryRole) {
    case 'admin':
      return {
        kicker: 'Admin Workspace',
        title: 'Projects',
        description: 'Audit delivery structure, review ownership, and oversee how projects are configured across the platform.',
      }
    case 'testeur':
      return {
        kicker: 'Execution Workspace',
        title: 'Assigned Projects',
        description: 'See the projects linked to you, understand their scope, and move directly toward execution details.',
      }
    default:
      return {
        kicker: 'Chef Workspace',
        title: 'Projects',
        description: 'Manage testing projects, assign checklists and testers, and track execution progress.',
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

const checklistFilterOptions = computed(() => {
  const allChecklists = projects.value.flatMap((project) => project.checklists || [])
  const seen = new Set()

  return allChecklists.filter((checklist) => {
    if (!checklist?.id || seen.has(checklist.id)) {
      return false
    }
    seen.add(checklist.id)
    return true
  })
})

const activeFilterBadges = computed(() => {
  const badges = []

  if (filters.name.trim() !== '') {
    badges.push({ key: 'name', label: `Name: ${filters.name.trim()}` })
  }

  if (filters.type.trim() !== '') {
    badges.push({ key: 'type', label: `Type: ${filters.type.trim()}` })
  }

  if (filters.category.trim() !== '') {
    badges.push({ key: 'category', label: `Category: ${filters.category.trim()}` })
  }

  if (filters.query.trim() !== '') {
    badges.push({ key: 'query', label: `Search: ${filters.query.trim()}` })
  }

  if (filters.creator !== 'all') {
    const creator = creatorFilterOptions.value.find((item) => String(item.id) === String(filters.creator))
    badges.push({ key: 'creator', label: `Creator: ${creator?.name || filters.creator}` })
  }

  if (filters.checklist !== 'all') {
    const checklist = checklistFilterOptions.value.find((item) => String(item.id) === String(filters.checklist))
    badges.push({ key: 'checklist', label: `Checklist: ${checklist?.name || filters.checklist}` })
  }

  return badges
})

const hasActiveFilters = computed(() => activeFilterBadges.value.length > 0)

const projectMetrics = computed(() => {
  const totalProjects = projects.value.length
  const totalAssignedTesters = projects.value.reduce((count, project) => count + (project.testers?.length || 0), 0)
  const totalLinkedChecklists = projects.value.reduce((count, project) => count + (project.checklists?.length || 0), 0)
  const projectsWithUrl = projects.value.filter((project) => Boolean(project.app_url)).length

  return [
    { label: 'Projects', value: totalProjects, caption: 'Active workspace entries' },
    { label: 'Assigned testers', value: totalAssignedTesters, caption: 'Execution capacity linked' },
    { label: 'Linked checklists', value: totalLinkedChecklists, caption: 'Reusable QA coverage attached' },
    { label: 'Ready environments', value: projectsWithUrl, caption: 'Projects with target application URL' },
  ]
})

const filteredProjects = computed(() => {
  return projects.value.filter((project) => {
    const projectType = String(project.type || project.project_type || '').trim()
    const checklistCategories = (project.checklists || []).map((checklist) => String(checklist.category || '').trim())
    const projectCategory = String(project.category || checklistCategories[0] || '').trim()

    const searchText = `${project.name || ''} ${project.description || ''} ${project.app_url || ''} ${projectType} ${projectCategory}`.toLowerCase()
    const matchesName =
      filters.name.trim() === '' ||
      String(project.name || '').toLowerCase().includes(filters.name.trim().toLowerCase())
    const matchesType =
      filters.type.trim() === '' ||
      projectType.toLowerCase().includes(filters.type.trim().toLowerCase())
    const matchesCategory =
      filters.category.trim() === '' ||
      projectCategory.toLowerCase().includes(filters.category.trim().toLowerCase()) ||
      checklistCategories.some((value) => value.toLowerCase().includes(filters.category.trim().toLowerCase()))
    const matchesQuery = filters.query.trim() === '' || searchText.includes(filters.query.trim().toLowerCase())
    const matchesCreator =
      filters.creator === 'all' ||
      String(project.creator?.id || '') === String(filters.creator)
    const matchesChecklist =
      filters.checklist === 'all' ||
      (project.checklists || []).some((checklist) => String(checklist.id) === String(filters.checklist))

    return matchesName && matchesType && matchesCategory && matchesQuery && matchesCreator && matchesChecklist
  })
})

async function loadProjects(page = 1) {
  loadingProjects.value = true
  listError.value = ''

  try {
    const data = await apiRequest(withQuery('/projects', { page }), {}, auth.token)
    projects.value = data.data || []
    pagination.current_page = data.current_page
    pagination.last_page = data.last_page
  } catch (error) {
    listError.value = error.data?.message || error.message
  } finally {
    loadingProjects.value = false
  }
}

async function loadProjectMetadata() {
  if (!auth.canManageProjects) {
    return
  }

  try {
    const data = await apiRequest('/projects/metadata', {}, auth.token)
    checklists.value = data.checklists || []
    users.value = data.testers || []
  } catch (error) {
    checklists.value = []
    users.value = []
    createError.value = error.data?.message || error.message || 'Unable to load project setup data'
  }
}

async function submitProject() {
  createError.value = ''
  successMessage.value = ''
  creating.value = true

  try {
    const payload = {
      name: form.name,
      description: form.description || null,
      app_url: form.app_url,
      ...(form.checklist_id ? { checklist_id: Number(form.checklist_id) } : {}),
    }

    if (form.id) {
      // Update project (name, description, app_url only)
      await apiRequest(`/projects/${form.id}`, { method: 'PUT', body: payload }, auth.token)
      successMessage.value = 'Project updated successfully.'
    } else {
      // Create new project with testers and checklists
      const createPayload = {
        ...payload,
        tester_ids: form.tester_ids.map((id) => Number(id)),
        checklist_ids: form.checklist_ids.map((id) => Number(id)),
      }

      if (form.checklist_id) {
        createPayload.checklist_id = Number(form.checklist_id)
      }

      await apiRequest(
        '/projects',
        {
          method: 'POST',
          body: createPayload,
        },
        auth.token,
      )
      successMessage.value = 'Project created successfully.'
    }

    resetForm(true)
    await loadProjects(1)
  } catch (error) {
    createError.value = error.data?.message || error.message
  } finally {
    creating.value = false
  }
}

function resetForm(closeForm = false) {
  form.id = null
  form.name = ''
  form.description = ''
  form.app_url = ''
  form.checklist_id = ''
  form.checklist_ids = []
  form.tester_ids = []

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
  filters.checklist = 'all'
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
    return
  }

  if (key === 'checklist') {
    filters.checklist = 'all'
  }
}

function toggleCreatorFilter(id) {
  const value = String(id)
  filters.creator = filters.creator === value ? 'all' : value
}

function toggleChecklistFilter(id) {
  const value = String(id)
  filters.checklist = filters.checklist === value ? 'all' : value
}

function getHostname(url) {
  try {
    return new URL(url).hostname
  } catch {
    return url
  }
}

function openCreateForm() {
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

function openCreateFormFromQuery() {
  if (!auth.canManageProjects || route.query.create !== '1') {
    return
  }

  openCreateForm()
  consumeCreateQuery()
}

function editProject(project) {
  showProjectForm.value = true
  form.id = project.id
  form.name = project.name
  form.description = project.description || ''
  form.app_url = project.app_url || ''
  form.checklist_id = ''
}

function canManageProject(project) {
  return auth.isAdmin || project.created_by === auth.user?.id
}

async function deleteProject(projectId) {
  if (!confirm(translateCurrentPhrase('Are you sure you want to delete this project?'))) {
    return
  }

  listError.value = ''

  try {
    await apiRequest(`/projects/${projectId}`, { method: 'DELETE' }, auth.token)
    successMessage.value = 'Project deleted successfully.'
    await loadProjects(1)
  } catch (error) {
    listError.value = error.data?.message || error.message
  }
}

onMounted(async () => {
  await Promise.all([loadProjects(), loadProjectMetadata()])
  openCreateFormFromQuery()
})

watch(
  () => route.query.create,
  () => {
    openCreateFormFromQuery()
  },
)
</script>

<template>
  <section class="page stack">
    <div class="dashboard-command">
      <div>
        <p class="dashboard-eyebrow">{{ projectPageCopy.kicker }}</p>
        <h1 class="page-title-icon">
          <Rocket :size="30" :stroke-width="2.3" />
          <span>{{ projectPageCopy.title }}</span>
        </h1>
        <p class="muted page-subtitle">
          {{ projectPageCopy.description }}
        </p>
      </div>
      <div class="dashboard-command-actions">
        <button
          v-if="auth.canManageProjects"
          class="btn btn-primary create-project-btn"
          type="button"
          @click="openCreateForm"
        >
          <CirclePlus :size="16" />
          <span>Create Project</span>
        </button>
      </div>
    </div>

    <div class="story-detail-metrics">
      <article v-for="metric in projectMetrics" :key="metric.label" class="story-detail-metric">
        <span>{{ metric.label }}</span>
        <strong>{{ metric.value }}</strong>
        <p class="muted">{{ metric.caption }}</p>
      </article>
    </div>

    <div class="card stack stack-gap-sm">
      <div class="search-top-row">
        <div class="search-input-wrap">
          <Search :size="18" :stroke-width="2.1" />
          <input v-model="filters.query" placeholder="Search projects..." class="search-input" />
        </div>

      </div>

      <div class="actions actions-between">
        <button class="btn btn-secondary btn-sm" type="button" @click="showAdvancedFilters = !showAdvancedFilters">
          <span>{{ showAdvancedFilters ? 'Hide Filters' : 'Show Filters' }}</span>
        </button>

        <button v-if="hasActiveFilters" class="btn btn-secondary btn-sm" type="button" @click="clearAllFilters">
          Clear all
        </button>
      </div>

      <div v-if="showAdvancedFilters" class="stack advanced-filters-stack">
        <div class="grid filters-grid">
          <div class="field">
            <label class="field-label-strong">Name</label>
            <input v-model="filters.name" placeholder="Filter by project name" />
          </div>

          <div class="field">
            <label class="field-label-strong">Type</label>
            <input v-model="filters.type" placeholder="Filter by type" />
          </div>

          <div class="field">
            <label class="field-label-strong">Category</label>
            <input v-model="filters.category" placeholder="Filter by category" />
          </div>
        </div>

        <div class="field field-tight">
          <label class="field-label-strong">Creator</label>
          <div class="chip-row">
            <button
              type="button"
              class="filter-chip"
              :class="{ active: filters.creator === 'all' }"
              @click="filters.creator = 'all'"
            >
              All creators
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

        <div class="field field-tight">
          <label class="field-label-strong">Checklist</label>
          <div class="chip-row">
            <button
              type="button"
              class="filter-chip"
              :class="{ active: filters.checklist === 'all' }"
              @click="filters.checklist = 'all'"
            >
              All checklists
            </button>
            <button
              v-for="checklist in checklistFilterOptions"
              :key="checklist.id"
              type="button"
              class="filter-chip"
              :class="{ active: filters.checklist === String(checklist.id) }"
              @click="toggleChecklistFilter(checklist.id)"
            >
              {{ checklist.name }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="hasActiveFilters" class="active-filters-row">
        <span class="muted active-filters-label">Filters applied:</span>
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

    <div v-if="auth.canManageProjects && showProjectForm" class="card stack">
      <div class="section-divider">
        <h2 class="section-heading-with-icon">
          <Pencil v-if="form.id" :size="20" :stroke-width="2.2" />
          <CirclePlus v-else :size="20" :stroke-width="2.2" />
          <span>{{ form.id ? 'Edit Project' : 'Create New Project' }}</span>
        </h2>
      </div>

      <p v-if="createError" class="error" data-testid="projects-msg-error">{{ createError }}</p>
      <p v-if="successMessage" class="success" data-testid="projects-msg-success">{{ successMessage }}</p>

      <form class="stack" @submit.prevent="submitProject" data-testid="projects-form">
        <div class="form-grid-two">
          <div class="field">
            <label class="field-label-strong">Project Name</label>
            <input
              v-model="form.name"
              required
              placeholder="e.g., API Testing Phase 1"
              data-testid="projects-input-name"
            />
          </div>
          <div class="field">
            <label class="field-label-strong">App URL</label>
            <input
              v-model="form.app_url"
              type="url"
              placeholder="https://example.com"
              required
              data-testid="projects-input-app-url"
            />
          </div>
        </div>

        <div class="field">
          <label class="field-label-strong">Description</label>
          <textarea v-model="form.description" rows="3" placeholder="Add details about this project..." />
        </div>

        <!-- Create New Project Only -->
        <div v-if="!form.id" class="card project-setup-section">
          <div class="section-divider">
            <h3 class="section-heading-with-icon-sm">
              <Settings :size="18" :stroke-width="2.2" />
              <span>Project Setup</span>
            </h3>
          </div>

          <!-- Primary Checklist -->
          <div class="field">
            <label class="label-with-icon">
              <ClipboardList :size="18" :stroke-width="2.1" />
              Initial Checklist
            </label>
            <select v-model="form.checklist_id" class="select-top-gap">
              <option value="">Start without a checklist</option>
              <option v-for="checklist in checklists" :key="checklist.id" :value="checklist.id">
                {{ checklist.name }}{{ checklist.description ? ' - ' + checklist.description : '' }}
              </option>
            </select>
            <p class="muted helper-text-info">
              <Info :size="14" class="icon-inline-top" />
              <span>Optional. Leave this empty for a story-first project, then create versions after user stories are ready.</span>
            </p>
          </div>

          <!-- Additional Checklists -->
          <div class="field">
            <label class="label-with-icon">
              <BookOpen :size="18" :stroke-width="2.1" />
              Additional Checklists
            </label>
            <p class="muted helper-text">
              Select all checklists you want to assign to this project
            </p>
            <div v-if="checklists.length === 0" class="muted empty-state-box">
              No checklists available
            </div>
            <div v-else class="selection-grid">
              <label 
                v-for="checklist in checklists" 
                :key="checklist.id" 
                class="selection-card"
              >
                <input 
                  type="checkbox" 
                  :value="checklist.id" 
                  v-model="form.checklist_ids"
                  class="selection-check"
                />
                <div class="selection-body">
                  <div class="selection-title">{{ checklist.name }}</div>
                  <div v-if="checklist.description" class="muted selection-meta">
                    {{ checklist.description }}
                  </div>
                </div>
              </label>
            </div>
          </div>

          <!-- Assign Testers -->
          <div class="field section-space-top">
            <label class="label-with-icon">
              <Users :size="18" :stroke-width="2.1" />
              Assign Testers
            </label>
            <p class="muted helper-text">
              Select testers who will execute tests for this project
            </p>
            <div v-if="users.length === 0" class="muted empty-state-box">
              No testers available
            </div>
            <div v-else class="selection-grid">
              <label 
                v-for="user in users" 
                :key="user.id" 
                class="selection-card selection-card-green"
              >
                <input 
                  type="checkbox" 
                  :value="user.id" 
                  v-model="form.tester_ids"
                  class="selection-check"
                />
                <div class="selection-body">
                  <div class="selection-title">{{ user.name }}</div>
                  <div class="muted selection-meta">{{ user.email }}</div>
                </div>
              </label>
            </div>
          </div>
        </div>

        <div class="form-actions-row">
          <button
            class="btn btn-primary btn-min-wide"
            type="submit"
            :disabled="creating"
            data-testid="projects-btn-submit"
          >
            <LoaderCircle v-if="creating" :size="16" class="spin" />
            <Save v-else-if="form.id" :size="16" />
            <Sparkles v-else :size="16" />
            <span>{{ creating ? (form.id ? 'Updating...' : 'Creating...') : (form.id ? 'Update Project' : 'Create Project') }}</span>
          </button>
          <button type="button" class="btn btn-secondary btn-inline-icon" @click="resetForm(true)">
            <X :size="16" />
            <span>Close</span>
          </button>
        </div>
      </form>
    </div>

    <div class="card stack section-space-xl">
      <div class="section-divider">
        <h2 class="section-heading-with-icon">
          <ChartColumn :size="20" :stroke-width="2.2" />
          <span>Project List</span>
        </h2>
      </div>

      <p class="muted">
        Open a project to reach the execution workspace, select a version, and use <strong>Run Test</strong> on executable items.
      </p>

      <p v-if="listError" class="error" data-testid="projects-msg-error-list">{{ listError }}</p>
      <p v-if="loadingProjects" class="muted">Loading projects...</p>

      <div v-if="!loadingProjects && filteredProjects.length > 0" class="projects-grid" data-testid="projects-table">
        <article v-for="project in filteredProjects" :key="project.id" class="project-card">
          <div class="project-card-head">
            <div>
              <RouterLink :to="{ name: 'project-detail', params: { id: project.id } }" class="project-title-link">
                {{ project.name }}
              </RouterLink>
              <p class="muted meta-line">#{{ project.id }} • {{ project.creator?.name || 'Unknown creator' }}</p>
            </div>
          </div>

          <p class="muted description-fixed">{{ project.description || 'No description provided.' }}</p>

          <div class="project-meta-row">
            <span class="mini-label">Checklists</span>
            <div class="chip-row">
              <span v-for="checklist in project.checklists || []" :key="checklist.id" class="mini-chip">{{ checklist.name }}</span>
              <span v-if="!project.checklists || project.checklists.length === 0" class="muted">No checklist</span>
            </div>
          </div>

          <div class="project-meta-row">
            <span class="mini-label">Testers</span>
            <div class="chip-row">
              <span v-for="tester in project.testers || []" :key="tester.id" class="mini-chip tester-chip">{{ tester.name }}</span>
              <span v-if="!project.testers || project.testers.length === 0" class="muted">No tester assigned</span>
            </div>
          </div>

          <div class="project-card-footer">
            <a v-if="project.app_url" :href="project.app_url" target="_blank" rel="noopener noreferrer" class="muted app-url-text">
              {{ getHostname(project.app_url) }}
            </a>
            <span v-else class="muted app-url-text">No app URL</span>

            <div class="actions">
              <button v-if="canManageProject(project)" class="btn btn-secondary btn-sm" @click="editProject(project)">
                <Pencil :size="14" />
                <span>Edit</span>
              </button>
              <button v-if="canManageProject(project)" class="btn btn-danger btn-sm" @click="deleteProject(project.id)">
                <Trash2 :size="14" />
              </button>
            </div>
          </div>
        </article>
      </div>

      <div v-if="!loadingProjects && filteredProjects.length === 0" class="card empty-dashed-card">
        <p class="muted">No projects found with current filters.</p>
      </div>

      <div class="pagination pagination-centered">
        <button
          class="btn btn-secondary btn-sm btn-nav-icon"
          :disabled="pagination.current_page <= 1"
          @click="loadProjects(pagination.current_page - 1)"
          title="Go to previous page"
        >
          <ArrowLeft :size="14" />
          <span>Previous</span>
        </button>
        <span class="muted pagination-text">
          Page {{ pagination.current_page }} of {{ pagination.last_page }}
        </span>
        <button
          class="btn btn-secondary btn-sm btn-nav-icon"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="loadProjects(pagination.current_page + 1)"
          title="Go to next page"
        >
          <span>Next</span>
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
</style>

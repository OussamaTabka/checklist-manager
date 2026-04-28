<script setup>
import { onMounted, reactive, ref, computed, watch } from 'vue'
import { CirclePlus, Filter, Search, X } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'
import { apiRequest, withQuery } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const checklists = ref([])
const pagination = reactive({ current_page: 1, last_page: 1 })
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const submitting = ref(false)
const availableItems = ref([])
const selectedExistingItemId = ref('')
const itemSearchQueries = ref({}) // Track search input for each item field
const showChecklistForm = ref(false)
const showAdvancedFilters = ref(false)

const filters = reactive({
  name: '',
  type: '',
  category: '',
  search: '',
})

const defaultItem = () => ({
  id: null,
  title: '',
  description: '',
  priority: 'Medium',
  criticality: 'Major',
})

const form = reactive({
  id: null,
  name: '',
  description: '',
  category: '',
  is_active: true,
  items: [defaultItem()],
})

const checklistPageCopy = computed(() => ({
  kicker: auth.primaryRole === 'admin' ? 'Admin Workspace' : 'Chef Workspace',
  title: 'Checklist Templates',
  description:
    auth.primaryRole === 'admin'
      ? 'Govern the reusable quality library, review template status, and keep checklist standards consistent.'
      : 'Build and refine reusable checklist templates that teams can attach to user stories and projects.',
}))

const addableExistingItems = computed(() => {
  const usedSignatures = new Set(
    form.items.map((item) => `${item.title}|${item.description || ''}|${item.priority}|${item.criticality}`)
  )

  return availableItems.value.filter((item) => {
    const signature = `${item.title}|${item.description || ''}|${item.priority}|${item.criticality}`
    return !usedSignatures.has(signature)
  })
})

const filteredChecklists = computed(() => {
  return checklists.value.filter((checklist) => {
    const checklistType = String(checklist.type || '').trim()
    const checklistCategory = String(checklist.category || '').trim()

    const matchesName =
      filters.name.trim() === '' ||
      String(checklist.name || '').toLowerCase().includes(filters.name.trim().toLowerCase())
    const matchesType =
      filters.type.trim() === '' ||
      checklistType.toLowerCase().includes(filters.type.trim().toLowerCase())
    const matchesCategory =
      filters.category.trim() === '' ||
      checklistCategory.toLowerCase().includes(filters.category.trim().toLowerCase())
    const searchText = `${checklist.name || ''} ${checklist.description || ''} ${checklistCategory} ${checklistType}`.toLowerCase()
    const matchesSearch =
      filters.search.trim() === '' ||
      searchText.includes(filters.search.trim().toLowerCase())

    return matchesName && matchesType && matchesCategory && matchesSearch
  })
})

const activeFilterBadges = computed(() => {
  const badges = []

  if (filters.search.trim() !== '') {
    badges.push({ key: 'search', label: `Search: ${filters.search.trim()}` })
  }

  if (filters.name.trim() !== '') {
    badges.push({ key: 'name', label: `Name: ${filters.name.trim()}` })
  }

  if (filters.type.trim() !== '') {
    badges.push({ key: 'type', label: `Type: ${filters.type.trim()}` })
  }

  if (filters.category.trim() !== '') {
    badges.push({ key: 'category', label: `Category: ${filters.category.trim()}` })
  }

  return badges
})

const hasActiveFilters = computed(() => activeFilterBadges.value.length > 0)

const checklistMetrics = computed(() => {
  const totalChecklists = checklists.value.length
  const activeChecklists = checklists.value.filter((checklist) => checklist.is_active).length
  const totalItems = checklists.value.reduce((count, checklist) => count + (checklist.items?.length || 0), 0)
  const categorizedChecklists = checklists.value.filter((checklist) => Boolean(checklist.category)).length

  return [
    { label: 'Templates', value: totalChecklists, caption: 'Reusable checklist library' },
    { label: 'Active', value: activeChecklists, caption: 'Available for suggestions and projects' },
    { label: 'Items', value: totalItems, caption: 'Reusable QA test cases in the library' },
    { label: 'Categorized', value: categorizedChecklists, caption: 'Templates with explicit QA domain' },
  ]
})

// Compute filtered items for autocomplete based on search query
const getFilteredItems = (itemIndex) => {
  const query = itemSearchQueries.value[itemIndex] || ''
  if (!query) return availableItems.value
  
  return availableItems.value.filter(item =>
    item.title.toLowerCase().startsWith(query.toLowerCase())
  )
}

function resetForm() {
  form.id = null
  form.name = ''
  form.description = ''
  form.category = ''
  form.is_active = true
  form.items = [defaultItem()]
  selectedExistingItemId.value = ''
  itemSearchQueries.value = {}
}

function openCreateChecklistForm() {
  resetForm()
  showChecklistForm.value = true
}

function consumeCreateQuery() {
  if (route.query.create !== '1') {
    return
  }

  const nextQuery = { ...route.query }
  delete nextQuery.create
  router.replace({ query: nextQuery })
}

function openCreateChecklistFormFromQuery() {
  if (!auth.canManageChecklists || route.query.create !== '1') {
    return
  }

  openCreateChecklistForm()
  consumeCreateQuery()
}

function closeChecklistForm() {
  resetForm()
  showChecklistForm.value = false
}

function clearAllFilters() {
  filters.name = ''
  filters.type = ''
  filters.category = ''
  filters.search = ''
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

  if (key === 'search') {
    filters.search = ''
  }
}

function editChecklist(checklist) {
  showChecklistForm.value = true
  form.id = checklist.id
  form.name = checklist.name
  form.description = checklist.description || ''
  form.category = checklist.category || ''
  form.is_active = Boolean(checklist.is_active)
  form.items = checklist.items.map((item) => ({
    id: item.id,
    title: item.title,
    description: item.description || '',
    priority: item.priority,
    criticality: item.criticality,
  }))
  itemSearchQueries.value = {}
}

function addItem() {
  form.items.push(defaultItem())
}

function addExistingItemToForm() {
  if (!selectedExistingItemId.value) {
    return
  }

  const existingItem = availableItems.value.find(
    (item) => String(item.id) === String(selectedExistingItemId.value)
  )

  if (!existingItem) {
    return
  }

  form.items.push({
    id: null,
    title: existingItem.title,
    description: existingItem.description || '',
    priority: existingItem.priority,
    criticality: existingItem.criticality,
  })

  selectedExistingItemId.value = ''
}

function removeItem(index) {
  if (form.items.length === 1) {
    return
  }
  form.items.splice(index, 1)
  delete itemSearchQueries.value[index]
}

// Select an item from autocomplete suggestions
function selectExistingItem(itemIndex, existingItem) {
  form.items[itemIndex] = {
    id: null,
    title: existingItem.title,
    description: existingItem.description || '',
    priority: existingItem.priority,
    criticality: existingItem.criticality,
  }
  itemSearchQueries.value[itemIndex] = ''
}

async function loadChecklists(page = 1) {
  loading.value = true
  errorMessage.value = ''

  try {
    const data = await apiRequest(withQuery('/checklists', { page }), {}, auth.token)
    checklists.value = data.data || []
    pagination.current_page = data.current_page
    pagination.last_page = data.last_page
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  } finally {
    loading.value = false
  }
}

async function loadAvailableItems() {
  try {
    const data = await apiRequest('/checklists/items/available', {}, auth.token)
    availableItems.value = data || []
  } catch (error) {
    console.log('Could not load available items:', error.message)
  }
}

async function submitChecklist() {
  submitting.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const payload = {
      name: form.name,
      description: form.description || null,
      category: form.category || null,
      is_active: form.is_active,
      items: form.items.map((item) => ({
        ...(item.id ? { id: item.id } : {}),
        title: item.title,
        description: item.description || null,
        priority: item.priority,
        criticality: item.criticality,
      })),
    }

    if (form.id) {
      await apiRequest(`/checklists/${form.id}`, { method: 'PUT', body: payload }, auth.token)
      successMessage.value = 'Checklist updated successfully.'
    } else {
      await apiRequest('/checklists', { method: 'POST', body: payload }, auth.token)
      successMessage.value = 'Checklist created successfully.'
    }

    resetForm()
    showChecklistForm.value = false
    await loadChecklists()
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  } finally {
    submitting.value = false
  }
}

async function toggleChecklist(checklist) {
  errorMessage.value = ''
  try {
    await apiRequest(`/checklists/${checklist.id}/toggle`, { method: 'PATCH' }, auth.token)
    await loadChecklists(pagination.current_page)
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  }
}

async function deleteChecklist(checklistId) {
  errorMessage.value = ''
  try {
    await apiRequest(`/checklists/${checklistId}`, { method: 'DELETE' }, auth.token)
    await loadChecklists(pagination.current_page)
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  }
}

onMounted(async () => {
  await loadChecklists()
  await loadAvailableItems()
  openCreateChecklistFormFromQuery()
})

watch(
  () => route.query.create,
  () => {
    openCreateChecklistFormFromQuery()
  },
)
</script>

<template>
  <section class="page stack">
    <div class="dashboard-command">
      <div>
        <p class="dashboard-eyebrow">{{ checklistPageCopy.kicker }}</p>
        <h1>{{ checklistPageCopy.title }}</h1>
        <p class="muted page-subtitle">{{ checklistPageCopy.description }}</p>
      </div>

      <div class="dashboard-command-actions">
        <button
          v-if="auth.canManageChecklists"
          class="btn btn-primary create-project-btn"
          type="button"
          @click="openCreateChecklistForm"
        >
          <CirclePlus :size="16" />
          <span>Create Checklist</span>
        </button>
      </div>
    </div>

    <div class="story-detail-metrics">
      <article v-for="metric in checklistMetrics" :key="metric.label" class="story-detail-metric">
        <span>{{ metric.label }}</span>
        <strong>{{ metric.value }}</strong>
        <p class="muted">{{ metric.caption }}</p>
      </article>
    </div>

    <div v-if="!auth.canManageChecklists" class="card error">
      <p>Only project managers and admins can create or edit checklists.</p>
    </div>

    <div class="card stack stack-gap-sm">
      <div class="search-top-row">
        <div class="search-input-wrap">
          <Search :size="18" :stroke-width="2.1" />
          <input v-model="filters.search" placeholder="Search checklists..." class="search-input" />
        </div>

      </div>

      <div class="actions actions-between">
        <button class="btn btn-secondary btn-sm" type="button" @click="showAdvancedFilters = !showAdvancedFilters">
          <Filter :size="14" />
          <span>{{ showAdvancedFilters ? 'Hide Filters' : 'Show Filters' }}</span>
        </button>

        <button v-if="hasActiveFilters" class="btn btn-secondary btn-sm" type="button" @click="clearAllFilters">
          Clear all
        </button>
      </div>

      <div v-if="showAdvancedFilters" class="grid filters-grid">
        <div class="field">
          <label>Name</label>
          <input v-model="filters.name" placeholder="Filter by checklist name" />
        </div>
        <div class="field">
          <label>Type</label>
          <input v-model="filters.type" placeholder="Filter by checklist type" />
        </div>
        <div class="field">
          <label>Category</label>
          <input v-model="filters.category" placeholder="Filter by checklist category" />
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

    <div v-if="auth.canManageChecklists && showChecklistForm" class="card stack">
      <h2>{{ form.id ? `Edit checklist #${form.id}` : 'Create checklist' }}</h2>

      <p v-if="errorMessage" class="error" data-testid="checklists-msg-error">{{ errorMessage }}</p>
      <p v-if="successMessage" class="success" data-testid="checklists-msg-success">{{ successMessage }}</p>

      <form class="stack" @submit.prevent="submitChecklist" data-testid="checklists-form">
        <div class="grid">
          <div class="field">
            <label>Name</label>
            <input v-model="form.name" required />
          </div>
          <div class="field">
            <label>Active</label>
            <select v-model="form.is_active">
              <option :value="true">Active</option>
              <option :value="false">Inactive</option>
            </select>
          </div>
        </div>

        <div class="field">
          <label>Description</label>
          <textarea v-model="form.description" rows="3" />
        </div>

        <div class="field">
          <label>Category</label>
          <input v-model="form.category" placeholder="e.g., Automation, Security, Performance" />
        </div>

        <div class="stack">
          <div class="section-header">
            <h3>Items</h3>
            <button
              type="button"
              class="btn btn-secondary btn-sm"
              @click="addItem"
              data-testid="checklists-btn-add-item"
            >
              Add item
            </button>
          </div>

          <div class="card stack">
            <h4>Previous use cases</h4>
            <p class="muted">Pick an already existing item and add it to this checklist.</p>
            <div class="grid">
              <div class="field">
                <label>Existing item</label>
                <select v-model="selectedExistingItemId">
                  <option value="">Select a previous use case</option>
                  <option v-for="existingItem in addableExistingItems" :key="existingItem.id" :value="existingItem.id">
                    {{ existingItem.title }} ({{ existingItem.priority }} / {{ existingItem.criticality }})
                  </option>
                </select>
              </div>
              <div class="field field-end">
                <button
                  type="button"
                  class="btn btn-secondary"
                  :disabled="!selectedExistingItemId"
                  @click="addExistingItemToForm"
                >
                  Add selected use case
                </button>
              </div>
            </div>
          </div>

          <div v-for="(item, index) in form.items" :key="index" class="card stack">
            <div class="grid">
              <div class="field field-relative">
                <label>Title</label>
                <input 
                  v-model="item.title" 
                  required
                  @input="itemSearchQueries[index] = item.title"
                  @focus="loadAvailableItems"
                  placeholder="Start typing to search existing items..."
                />
                <!-- Autocomplete suggestions dropdown -->
                <div
                  v-if="getFilteredItems(index).length > 0 && itemSearchQueries[index]"
                  class="autocomplete-dropdown"
                >
                  <div v-for="suggestion in getFilteredItems(index)" 
                       :key="suggestion.id"
                       @click="selectExistingItem(index, suggestion)"
                       class="autocomplete-option">
                    <strong>{{ suggestion.title }}</strong>
                    <div class="autocomplete-desc">{{ suggestion.description || 'No description' }}</div>
                    <div class="autocomplete-meta">
                      Priority: {{ suggestion.priority }} | Criticality: {{ suggestion.criticality }}
                    </div>
                  </div>
                </div>
              </div>
              <div class="field">
                <label>Priority</label>
                <select v-model="item.priority" required>
                  <option>Low</option>
                  <option>Medium</option>
                  <option>High</option>
                </select>
              </div>
              <div class="field">
                <label>Criticality</label>
                <select v-model="item.criticality" required>
                  <option>Minor</option>
                  <option>Major</option>
                  <option>Critical</option>
                </select>
              </div>
            </div>

            <div class="field">
              <label>Description</label>
              <textarea v-model="item.description" rows="2" />
            </div>

            <div class="actions">
              <button type="button" class="btn btn-danger btn-sm" @click="removeItem(index)">Remove item</button>
            </div>
          </div>
        </div>

        <div class="actions">
          <button class="btn btn-primary" type="submit" :disabled="submitting" data-testid="checklists-btn-submit">
            {{ submitting ? 'Saving...' : form.id ? 'Update checklist' : 'Create checklist' }}
          </button>
          <button type="button" class="btn btn-secondary" @click="closeChecklistForm">Close</button>
        </div>
      </form>
    </div>

    <div class="card stack">
      <h2>Checklist list</h2>
      <p class="muted">
        Templates stay reusable. Automated execution happens after a checklist is turned into a project version inside the execution workspace.
      </p>

      <p v-if="loading" class="muted">Loading checklists...</p>

      <div class="table-wrap" v-if="!loading">
        <table data-testid="checklists-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Category</th>
              <th>Status</th>
              <th>Items</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="checklist in filteredChecklists" :key="checklist.id">
              <td>{{ checklist.id }}</td>
              <td>
                <RouterLink :to="{ name: 'checklist-detail', params: { id: checklist.id } }" class="project-title-link">
                  {{ checklist.name }}
                </RouterLink>
                <div class="muted">{{ checklist.description || '-' }}</div>
              </td>
              <td>{{ checklist.category || '-' }}</td>
              <td>
                <span class="tag" :class="checklist.is_active ? 'active' : 'inactive'">
                  {{ checklist.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td>{{ checklist.items?.length || 0 }}</td>
              <td>
                <div class="actions">
                  <RouterLink class="btn btn-secondary btn-sm" :to="{ name: 'checklist-detail', params: { id: checklist.id } }">Open</RouterLink>
                  <button v-if="auth.canManageChecklists" class="btn btn-secondary btn-sm" @click="editChecklist(checklist)">Edit</button>
                  <button v-if="auth.canManageChecklists" class="btn btn-secondary btn-sm" @click="toggleChecklist(checklist)">Toggle</button>
                  <button v-if="auth.canManageChecklists" class="btn btn-danger btn-sm" @click="deleteChecklist(checklist.id)">Delete</button>
                </div>
              </td>
            </tr>
            <tr v-if="filteredChecklists.length === 0">
              <td colspan="6" class="muted">No checklists found.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page <= 1"
          @click="loadChecklists(pagination.current_page - 1)"
        >
          Previous
        </button>
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="loadChecklists(pagination.current_page + 1)"
        >
          Next
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

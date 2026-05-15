<script setup>
import { onMounted, reactive, ref, computed, watch } from 'vue'
import { CirclePlus, Filter, Search, X } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'
import { apiRequest, withQuery } from '@/lib/api'
import { localizeError, localizeMessage } from '@/lib/localization'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()
const route = useRoute()
const router = useRouter()
const projectId = computed(() => String(route.query.projectId || '').trim())

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
  project_id: '',
  name: '',
  description: '',
  category: '',
  is_active: true,
  items: [defaultItem()],
})

const checklistPageCopy = computed(() => ({
  kicker: 'Espace checklists',
  title: 'Bibliothèque de checklists',
  description:
    'Créez, adaptez et réutilisez des checklists de test pour préparer l’exécution QA sur vos projets.',
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
    badges.push({ key: 'search', label: `Recherche : ${filters.search.trim()}` })
  }

  if (filters.name.trim() !== '') {
    badges.push({ key: 'name', label: `Nom : ${filters.name.trim()}` })
  }

  if (filters.type.trim() !== '') {
    badges.push({ key: 'type', label: `Type : ${filters.type.trim()}` })
  }

  if (filters.category.trim() !== '') {
    badges.push({ key: 'category', label: `Catégorie : ${filters.category.trim()}` })
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
    { label: 'Checklists', value: totalChecklists, caption: 'Bibliothèque réutilisable de checklists QA' },
    { label: 'Actives', value: activeChecklists, caption: 'Disponibles pour les suggestions et les projets' },
    { label: 'Items', value: totalItems, caption: 'Cas de test réutilisables dans la bibliothèque' },
    { label: 'Catégorisées', value: categorizedChecklists, caption: 'Checklists classées par domaine QA' },
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
  form.project_id = projectId.value
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
    const data = await apiRequest(withQuery('/checklists', { page, ...(projectId.value ? { project_id: projectId.value } : {}) }), {}, auth.token)
    checklists.value = data.data || []
    pagination.current_page = data.current_page
    pagination.last_page = data.last_page
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
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

  try {
    const payload = {
      project_id: projectId.value ? Number(form.project_id || projectId.value) : null,
      template_scope: projectId.value ? 'project' : 'global',
      lifecycle_status: projectId.value ? 'draft' : 'approved',
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
      successMessage.value = localizeMessage('Checklist mise a jour avec succes.', settings.language)
    } else {
      await apiRequest('/checklists', { method: 'POST', body: payload }, auth.token)
      successMessage.value = localizeMessage('Checklist creee avec succes.', settings.language)
    }

    resetForm()
    showChecklistForm.value = false
    await loadChecklists()
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    submitting.value = false
  }
}

async function deleteChecklist(checklistId) {
  errorMessage.value = ''
  try {
    await apiRequest(`/checklists/${checklistId}`, { method: 'DELETE' }, auth.token)
    toast.success(localizeMessage('Checklist supprimee avec succes.', settings.language))
    await loadChecklists(pagination.current_page)
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  }
}

onMounted(async () => {
  resetForm()
  await loadChecklists()
  await loadAvailableItems()
  openCreateChecklistFormFromQuery()
})

watch(successMessage, (message) => {
  if (!message) {
    return
  }

  toast.success(message)
  successMessage.value = ''
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
          <span>Créer une checklist</span>
        </button>
      </div>
    </div>

    <div v-if="auth.canManageChecklists && !projectId" class="card">
      <p class="muted">Vous pouvez creer ici une checklist systeme reutilisable, sans association directe a une User Story. Pour creer une checklist d execution projet, ouvrez cette page depuis un projet assigne.</p>
    </div>

    <div class="story-detail-metrics">
      <article v-for="metric in checklistMetrics" :key="metric.label" class="story-detail-metric">
        <span>{{ metric.label }}</span>
        <strong>{{ metric.value }}</strong>
        <p class="muted">{{ metric.caption }}</p>
      </article>
    </div>

    <div v-if="!auth.canManageChecklists" class="card error">
      <p>Seuls les chefs de projet et les administrateurs peuvent créer ou modifier des checklists.</p>
    </div>

    <div class="card stack stack-gap-sm">
      <div class="search-top-row">
        <div class="search-input-wrap">
          <Search :size="18" :stroke-width="2.1" />
          <input v-model="filters.search" placeholder="Rechercher une checklist..." class="search-input" />
        </div>

      </div>

      <div class="actions actions-between">
        <button class="btn btn-secondary btn-sm" type="button" @click="showAdvancedFilters = !showAdvancedFilters">
          <Filter :size="14" />
          <span>{{ showAdvancedFilters ? 'Masquer les filtres' : 'Afficher les filtres' }}</span>
        </button>

        <button v-if="hasActiveFilters" class="btn btn-secondary btn-sm" type="button" @click="clearAllFilters">
          Réinitialiser
        </button>
      </div>

      <div v-if="showAdvancedFilters" class="grid filters-grid">
        <div class="field">
          <label>Nom</label>
          <input v-model="filters.name" placeholder="Filtrer par nom de checklist" />
        </div>
        <div class="field">
          <label>Type</label>
          <input v-model="filters.type" placeholder="Filtrer par type de checklist" />
        </div>
        <div class="field">
          <label>Catégorie</label>
          <input v-model="filters.category" placeholder="Filtrer par catégorie de checklist" />
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

    <div v-if="auth.canManageChecklists && showChecklistForm" class="card stack">
      <h2>{{ form.id ? `Modifier la checklist #${form.id}` : 'Créer une checklist' }}</h2>
      <p class="muted">
        {{ projectId || form.project_id ? `Contexte projet : ${projectId || form.project_id}` : 'Contexte : checklist systeme reutilisable, non attachee a une User Story.' }}
      </p>

      <p v-if="errorMessage" class="error" data-testid="checklists-msg-error">{{ errorMessage }}</p>
      <form class="stack" @submit.prevent="submitChecklist" data-testid="checklists-form">
        <div class="grid">
          <div class="field">
            <label>Titre</label>
            <input v-model="form.name" required />
          </div>
          <div class="field">
            <label>Statut</label>
            <select v-model="form.is_active">
              <option :value="true">Active</option>
              <option :value="false">Archivée</option>
            </select>
          </div>
        </div>

        <div class="field">
          <label>Description</label>
          <textarea v-model="form.description" rows="3" />
        </div>

        <div class="field">
          <label>Catégorie</label>
          <input v-model="form.category" placeholder="Ex. : Automatisation, sécurité, performance" />
        </div>

        <div class="stack">
          <div class="section-header">
            <h3>Cas de test</h3>
            <button
              type="button"
              class="btn btn-secondary btn-sm"
              @click="addItem"
              data-testid="checklists-btn-add-item"
            >
              Ajouter un item
            </button>
          </div>

          <div class="card stack">
            <h4>Cas de test réutilisables</h4>
            <p class="muted">Sélectionnez un item existant pour l’ajouter à cette checklist.</p>
            <div class="grid">
              <div class="field">
                <label>Item existant</label>
                <select v-model="selectedExistingItemId">
                  <option value="">Sélectionner un item existant</option>
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
                  Ajouter l’item sélectionné
                </button>
              </div>
            </div>
          </div>

          <div v-for="(item, index) in form.items" :key="index" class="card stack">
            <div class="grid">
              <div class="field field-relative">
                <label>Titre</label>
                <input 
                  v-model="item.title" 
                  required
                  @input="itemSearchQueries[index] = item.title"
                  @focus="loadAvailableItems"
                  placeholder="Commencez à saisir pour rechercher un item existant..."
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
                    <div class="autocomplete-desc">{{ suggestion.description || 'Aucune description' }}</div>
                    <div class="autocomplete-meta">
                      Priorité : {{ suggestion.priority }} | Criticité : {{ suggestion.criticality }}
                    </div>
                  </div>
                </div>
              </div>
              <div class="field">
                <label>Priorité</label>
                <select v-model="item.priority" required>
                  <option value="Low">Basse</option>
                  <option value="Medium">Moyenne</option>
                  <option value="High">Haute</option>
                </select>
              </div>
              <div class="field">
                <label>Criticité</label>
                <select v-model="item.criticality" required>
                  <option value="Minor">Mineure</option>
                  <option value="Major">Majeure</option>
                  <option value="Critical">Critique</option>
                </select>
              </div>
            </div>

            <div class="field">
              <label>Description</label>
              <textarea v-model="item.description" rows="2" />
            </div>

            <div class="actions">
              <button type="button" class="btn btn-danger btn-sm" @click="removeItem(index)">Retirer l’item</button>
            </div>
          </div>
        </div>

        <div class="actions">
          <button class="btn btn-primary" type="submit" :disabled="submitting" data-testid="checklists-btn-submit">
            {{ submitting ? 'Enregistrement...' : form.id ? 'Enregistrer les modifications' : 'Créer la checklist' }}
          </button>
          <button type="button" class="btn btn-secondary" @click="closeChecklistForm">Fermer</button>
        </div>
      </form>
    </div>

    <div class="card stack">
      <h2>Liste des checklists</h2>
      <p class="muted">
        Les checklists restent réutilisables. L’exécution automatique intervient une fois la checklist transformée en version projet dans l’espace d’exécution.
      </p>

      <p v-if="loading" class="muted">Chargement des checklists...</p>

      <div class="table-wrap" v-if="!loading">
        <table data-testid="checklists-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Titre</th>
              <th>Catégorie</th>
              <th>Statut</th>
              <th>Nombre d’items</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="checklist in filteredChecklists" :key="checklist.id">
              <td>{{ checklist.id }}</td>
              <td>
                <RouterLink :to="{ name: 'checklist-detail', params: { id: checklist.id } }" class="checklist-row-link">
                  <strong class="checklist-row-title">{{ checklist.name }}</strong>
                  <div class="muted">{{ checklist.description || '-' }}</div>
                </RouterLink>
              </td>
              <td>{{ checklist.category || '-' }}</td>
              <td>
                <span class="tag" :class="checklist.is_active ? 'active' : 'inactive'">
                  {{ checklist.is_active ? 'Active' : 'Archivée' }}
                </span>
              </td>
              <td>{{ checklist.items?.length || 0 }}</td>
              <td>
                <div class="checklist-actions">
                  <button v-if="auth.canManageChecklists" class="btn btn-secondary btn-sm" @click="editChecklist(checklist)">Modifier</button>
                  <button v-if="auth.canManageChecklists" class="btn btn-danger btn-sm" @click="deleteChecklist(checklist.id)">Archiver</button>
                </div>
              </td>
            </tr>
            <tr v-if="filteredChecklists.length === 0">
              <td colspan="6" class="muted">Aucune checklist ne correspond aux filtres appliqués.</td>
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
          Précédent
        </button>
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="loadChecklists(pagination.current_page + 1)"
        >
          Suivant
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

.checklist-row-link {
  display: grid;
  gap: 0.35rem;
  color: inherit;
  text-decoration: none;
}

.checklist-row-title {
  color: #1f2937;
  transition: color 0.18s ease;
}

.checklist-row-link:hover .checklist-row-title {
  color: #1d4ed8;
}

.checklist-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.6rem;
  flex-wrap: wrap;
}

.checklist-actions .btn {
  min-width: 7.25rem;
  justify-content: center;
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

.dark .checklist-row-title {
  color: #f8fafc;
}

.dark .checklist-row-link:hover .checklist-row-title {
  color: #93c5fd;
}
</style>


<script setup>
import { onMounted, reactive, ref, computed } from 'vue'
import { apiRequest, withQuery } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const checklists = ref([])
const pagination = reactive({ current_page: 1, last_page: 1 })
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const submitting = ref(false)
const availableItems = ref([])
const selectedExistingItemId = ref('')
const itemSearchQueries = ref({}) // Track search input for each item field

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

const addableExistingItems = computed(() => {
  const usedSignatures = new Set(
    form.items.map((item) => `${item.title}|${item.description || ''}|${item.priority}|${item.criticality}`)
  )

  return availableItems.value.filter((item) => {
    const signature = `${item.title}|${item.description || ''}|${item.priority}|${item.criticality}`
    return !usedSignatures.has(signature)
  })
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

function editChecklist(checklist) {
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
})
</script>

<template>
  <section class="page stack">
    <div class="section-header">
      <h1>Checklists</h1>
    </div>

    <div v-if="!auth.canManageChecklists" class="card error">
      <p>Only project managers and content admins can create or edit checklists.</p>
    </div>

    <div v-if="auth.canManageChecklists" class="card stack">
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
              <div class="field" style="align-self: end;">
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
              <div class="field" style="position: relative;">
                <label>Title</label>
                <input 
                  v-model="item.title" 
                  required
                  @input="itemSearchQueries[index] = item.title"
                  @focus="loadAvailableItems"
                  placeholder="Start typing to search existing items..."
                />
                <!-- Autocomplete suggestions dropdown -->
                <div v-if="getFilteredItems(index).length > 0 && itemSearchQueries[index]" 
                     class="autocomplete-dropdown"
                     style="
                       position: absolute;
                       top: 100%;
                       left: 0;
                       right: 0;
                       background: white;
                       border: 1px solid #ddd;
                       border-top: none;
                       max-height: 200px;
                       overflow-y: auto;
                       z-index: 10;
                     ">
                  <div v-for="suggestion in getFilteredItems(index)" 
                       :key="suggestion.id"
                       @click="selectExistingItem(index, suggestion)"
                       style="
                         padding: 8px 12px;
                         cursor: pointer;
                         border-bottom: 1px solid #eee;
                       "
                       @mouseenter="$event.target.style.backgroundColor = '#f0f0f0'"
                       @mouseleave="$event.target.style.backgroundColor = 'white'">
                    <strong>{{ suggestion.title }}</strong>
                    <div style="font-size: 0.85em; color: #666;">{{ suggestion.description || 'No description' }}</div>
                    <div style="font-size: 0.8em; color: #999;">
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
          <button type="button" class="btn btn-secondary" @click="resetForm">Reset</button>
        </div>
      </form>
    </div>

    <div class="card stack">
      <h2>Checklist list</h2>
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
            <tr v-for="checklist in checklists" :key="checklist.id">
              <td>{{ checklist.id }}</td>
              <td>
                <strong>{{ checklist.name }}</strong>
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
                  <button class="btn btn-secondary btn-sm" @click="editChecklist(checklist)">Edit</button>
                  <button class="btn btn-secondary btn-sm" @click="toggleChecklist(checklist)">Toggle</button>

                  <button class="btn btn-danger btn-sm" @click="deleteChecklist(checklist.id)">Delete</button>
                </div>
              </td>
            </tr>
            <tr v-if="checklists.length === 0">
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
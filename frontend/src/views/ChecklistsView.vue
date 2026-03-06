<script setup>
import { onMounted, reactive, ref } from 'vue'
import { apiRequest, withQuery } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const checklists = ref([])
const pagination = reactive({ current_page: 1, last_page: 1 })
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const submitting = ref(false)

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

function resetForm() {
  form.id = null
  form.name = ''
  form.description = ''
  form.category = ''
  form.is_active = true
  form.items = [defaultItem()]
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
}

function addItem() {
  form.items.push(defaultItem())
}

function removeItem(index) {
  if (form.items.length === 1) {
    return
  }
  form.items.splice(index, 1)
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
})
</script>

<template>
  <section class="page stack">
    <div class="section-header">
      <h1>Checklists</h1>
    </div>

    <div v-if="!auth.isAdmin" class="card error">
      <p>Only administrators can create or edit checklists.</p>
    </div>

    <div v-if="auth.isAdmin" class="card stack">
      <h2>{{ form.id ? `Edit checklist #${form.id}` : 'Create checklist' }}</h2>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
      <p v-if="successMessage" class="success">{{ successMessage }}</p>

      <form class="stack" @submit.prevent="submitChecklist">
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
            <button type="button" class="btn btn-secondary btn-sm" @click="addItem">Add item</button>
          </div>

          <div v-for="(item, index) in form.items" :key="index" class="card stack">
            <div class="grid">
              <div class="field">
                <label>Title</label>
                <input v-model="item.title" required />
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
          <button class="btn btn-primary" type="submit" :disabled="submitting">
            {{ submitting ? 'Saving...' : form.id ? 'Update checklist' : 'Create checklist' }}
          </button>
          <button type="button" class="btn btn-secondary" @click="resetForm">Reset</button>
        </div>
      </form>
    </div>>

    <div class="card stack">
      <h2>Checklist list</h2>
      <p v-if="loading" class="muted">Loading checklists...</p>

      <div class="table-wrap" v-if="!loading">
        <table>
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
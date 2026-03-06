<script setup>
import { onMounted, reactive, ref } from 'vue'
import { apiRequest, withQuery } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const users = ref([])
const pagination = reactive({ current_page: 1, last_page: 1 })
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const generatedPassword = ref('')
const showPassword = ref(false)

const form = reactive({
  id: null,
  name: '',
  email: '',
  password: '',
  role: 'testeur',
})

function resetForm() {
  form.id = null
  form.name = ''
  form.email = ''
  form.password = ''
  form.role = 'testeur'
}

async function loadUsers(page = 1) {
  loading.value = true
  errorMessage.value = ''

  try {
    const data = await apiRequest(withQuery('/users', { page }), {}, auth.token)
    users.value = data.data || []
    pagination.current_page = data.current_page
    pagination.last_page = data.last_page
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  } finally {
    loading.value = false
  }
}

function editUser(user) {
  form.id = user.id
  form.name = user.name
  form.email = user.email
  form.password = ''
  form.role = user.roles?.[0]?.name || 'testeur'
}

async function submitUser() {
  errorMessage.value = ''
  successMessage.value = ''
  generatedPassword.value = ''

  try {
    const payload = {
      name: form.name,
      email: form.email,
      role: form.role,
      ...(form.password ? { password: form.password } : {}),
    }

    if (form.id) {
      await apiRequest(`/users/${form.id}`, { method: 'PUT', body: payload }, auth.token)
      successMessage.value = 'User updated successfully.'
    } else {
      const data = await apiRequest('/users', { method: 'POST', body: payload }, auth.token)
      successMessage.value = 'User created successfully.'
      generatedPassword.value = data.generated_password || ''
    }

    resetForm()
    await loadUsers(pagination.current_page)
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  }
}

async function deleteUser(userId) {
  errorMessage.value = ''
  successMessage.value = ''

  try {
    await apiRequest(`/users/${userId}`, { method: 'DELETE' }, auth.token)
    successMessage.value = 'User deleted successfully.'
    await loadUsers(pagination.current_page)
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  }
}

onMounted(async () => {
  await loadUsers()
})
</script>

<template>
  <section class="page stack">
    <div class="section-header">
      <h1>Users</h1>
    </div>

    <div class="card stack">
      <h2>{{ form.id ? `Edit user ${form.id}` : 'Create user' }}</h2>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
      <p v-if="successMessage" class="success">{{ successMessage }}</p>
      <p v-if="generatedPassword" class="success">Generated password: {{ generatedPassword }}</p>

      <form class="stack" @submit.prevent="submitUser">
        <div class="grid">
          <div class="field">
            <label>Name</label>
            <input v-model="form.name" required />
          </div>

          <div class="field">
            <label>Email</label>
            <input v-model="form.email" type="email" required />
          </div>

          <div class="field">
            <label>Role</label>
            <select v-model="form.role" required>
              <option value="admin">admin</option>
              <option value="chef">chef</option>
              <option value="testeur">testeur</option>
            </select>
          </div>

          <div class="field">
            <label>Password</label>
            <div class="relative flex items-center">
              <input 
                v-model="form.password" 
                :type="showPassword ? 'text' : 'password'"
                :placeholder="form.id ? 'Leave empty to keep current password' : 'Leave empty to auto-generate'"
                class="w-full border-2 border-gray-200 rounded-lg px-4 py-3 font-inherit text-sm bg-white transition-all duration-200 pr-12 focus:outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-100"
              />
              <button
                type="button"
                class="absolute right-3 p-2 text-gray-600 hover:text-gray-900 transition-colors"
                @click="showPassword = !showPassword"
                :title="showPassword ? 'Hide password' : 'Show password'"
              >
                <svg v-if="!showPassword" class="w-5 h-5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M12 5C7 5 2.73 8.11 1 12.46c1.73 4.35 6 7.54 11 7.54s9.27-3.19 11-7.54C21.27 8.11 17 5 12 5zm0 12.5c-2.49 0-4.5-2.01-4.5-4.5s2.01-4.5 4.5-4.5 4.5 2.01 4.5 4.5-2.01 4.5-4.5 4.5zm0-7c-1.38 0-2.5 1.12-2.5 2.5s1.12 2.5 2.5 2.5 2.5-1.12 2.5-2.5-1.12-2.5-2.5-2.5z" fill="currentColor"/>
                </svg>
                <svg v-else class="w-5 h-5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M11.83 9L15.23 12.39c.75-1.48.75-4.21.75-4.21s2.01-.02 4.24 1.12c-1.73-4.39-6-7.3-11-7.3s-9.27 2.91-11 7.3c2.23-1.14 4.24-1.12 4.24-1.12s0 2.73.75 4.21L12.17 9h-.34zM12 15.5c1.93 0 3.5-1.57 3.5-3.5s-1.57-3.5-3.5-3.5-3.5 1.57-3.5 3.5 1.57 3.5 3.5 3.5z" fill="currentColor"/>
                  <line x1="3" y1="3" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
              </button>
            </div>
          </div>
        </div>

        <div class="actions">
          <button class="btn btn-primary" type="submit">{{ form.id ? 'Update user' : 'Create user' }}</button>
          <button class="btn btn-secondary" type="button" @click="resetForm">Reset</button>
        </div>
      </form>
    </div>

    <div class="card stack">
      <h2>User list</h2>
      <p v-if="loading" class="muted">Loading users...</p>

      <div class="table-wrap" v-if="!loading">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Name</th>
              <th>Email</th>
              <th>Role</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users" :key="user.id">
              <td>{{ user.id }}</td>
              <td>{{ user.name }}</td>
              <td>{{ user.email }}</td>
              <td>{{ user.roles?.[0]?.name || '-' }}</td>
              <td>
                <div class="actions">
                  <button class="btn btn-secondary btn-sm" @click="editUser(user)">Edit</button>
                  <button class="btn btn-danger btn-sm" @click="deleteUser(user.id)">Delete</button>
                </div>
              </td>
            </tr>
            <tr v-if="users.length === 0">
              <td colspan="5" class="muted">No users found.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page <= 1"
          @click="loadUsers(pagination.current_page - 1)"
        >
          Previous
        </button>
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="loadUsers(pagination.current_page + 1)"
        >
          Next
        </button>
      </div>
    </div>
  </section>
</template>
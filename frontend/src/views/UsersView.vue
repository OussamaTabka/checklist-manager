<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { apiRequest, withQuery } from '@/lib/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const users = ref([])
const pagination = reactive({ current_page: 1, last_page: 1 })
const loading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const form = reactive({
  id: null,
  name: '',
  email: '',
  role: 'testeur',
})

const roleOptions = computed(() => {
  if (form.id) {
    return [
      { value: 'admin', label: 'Administrateur' },
      { value: 'chef', label: 'Chef de projet' },
      { value: 'testeur', label: 'Testeur' },
    ]
  }

  return [
    { value: 'admin', label: 'Administrateur' },
    { value: 'chef', label: 'Chef de projet' },
    { value: 'testeur', label: 'Testeur' },
  ]
})

const usersPageCopy = computed(() => ({
  kicker: 'Espace administrateur',
  title: 'Utilisateurs et rôles',
  description: 'Invitez les collaborateurs, attribuez les rôles et gardez un contrôle clair des accès à la plateforme.',
}))

function resetForm() {
  form.id = null
  form.name = ''
  form.email = ''
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
  form.role = user.roles?.[0]?.name || 'testeur'
}

async function submitUser() {
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const payload = {
      name: form.name,
      email: form.email,
      role: form.role,
    }

    if (form.id) {
      await apiRequest(`/users/${form.id}`, { method: 'PUT', body: payload }, auth.token)
      successMessage.value = 'L’utilisateur a été mis à jour avec succès.'
    } else {
      await apiRequest('/users', { method: 'POST', body: payload }, auth.token)
      successMessage.value = 'L’utilisateur a été créé avec succès. Une invitation lui a été envoyée.'
    }

    resetForm()
    await loadUsers(pagination.current_page)
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  }
}

async function resendInvitation(userId) {
  errorMessage.value = ''
  successMessage.value = ''

  try {
    await apiRequest(`/users/${userId}/invitations/resend`, { method: 'POST' }, auth.token)
    successMessage.value = 'L’invitation a été renvoyée avec succès.'
    await loadUsers(pagination.current_page)
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  }
}

async function revokeInvitation(userId) {
  errorMessage.value = ''
  successMessage.value = ''

  try {
    await apiRequest(`/users/${userId}/invitations/revoke`, { method: 'POST' }, auth.token)
    successMessage.value = 'L’invitation a été révoquée avec succès.'
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
    successMessage.value = 'L’utilisateur a été supprimé avec succès.'
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
    <div class="dashboard-command">
      <div>
        <p class="dashboard-eyebrow">{{ usersPageCopy.kicker }}</p>
        <h1>{{ usersPageCopy.title }}</h1>
        <p class="muted page-subtitle">{{ usersPageCopy.description }}</p>
      </div>
    </div>

    <div class="card stack">
      <h2>{{ form.id ? `Modifier l’utilisateur #${form.id}` : 'Créer un utilisateur' }}</h2>

      <p v-if="errorMessage" class="error" data-testid="users-msg-error">{{ errorMessage }}</p>
      <p v-if="successMessage" class="success" data-testid="users-msg-success">{{ successMessage }}</p>

      <form class="stack" @submit.prevent="submitUser" data-testid="users-form">
        <div class="grid">
          <div class="field">
            <label>Nom complet</label>
            <input v-model="form.name" required data-testid="users-input-name" />
          </div>

          <div class="field">
            <label>Email</label>
            <input v-model="form.email" type="email" required data-testid="users-input-email" />
          </div>

          <div class="field">
            <label>Rôle</label>
            <select v-model="form.role" required data-testid="users-select-role">
              <option v-for="option in roleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
        </div>

        <p v-if="!form.id" class="muted">L’utilisateur recevra un e-mail d’invitation pour définir son mot de passe.</p>

        <div class="actions">
          <button class="btn btn-primary" type="submit" data-testid="users-btn-submit">{{ form.id ? 'Enregistrer les modifications' : 'Créer l’utilisateur' }}</button>
          <button class="btn btn-secondary" type="button" @click="resetForm">Réinitialiser</button>
        </div>
      </form>
    </div>

    <div class="card stack">
      <h2>Liste des utilisateurs</h2>
      <p v-if="loading" class="muted">Chargement des utilisateurs...</p>

      <div class="table-wrap" v-if="!loading">
        <table data-testid="users-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nom</th>
              <th>Email</th>
              <th>Rôle</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users" :key="user.id">
              <td>{{ user.id }}</td>
              <td>{{ user.name }}</td>
              <td>{{ user.email }}</td>
              <td>{{ roleOptions.find((option) => option.value === (user.roles?.[0]?.name || ''))?.label || '-' }}</td>
              <td>{{ (user.account_status || 'active') === 'pending' ? 'Invitation en attente' : 'Actif' }}</td>
              <td>
                <div class="actions">
                  <button class="btn btn-secondary btn-sm" @click="editUser(user)">Modifier</button>
                  <button
                    v-if="(user.account_status || 'active') === 'pending'"
                    class="btn btn-secondary btn-sm"
                    @click="resendInvitation(user.id)"
                  >
                    Renvoyer l’invitation
                  </button>
                  <button
                    v-if="(user.account_status || 'active') === 'pending'"
                    class="btn btn-secondary btn-sm"
                    @click="revokeInvitation(user.id)"
                  >
                    Révoquer l’invitation
                  </button>
                  <button class="btn btn-danger btn-sm" @click="deleteUser(user.id)">Supprimer</button>
                </div>
              </td>
            </tr>
            <tr v-if="users.length === 0">
              <td colspan="6" class="muted">Aucun utilisateur trouvé pour le moment.</td>
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
          Précédent
        </button>
        <button
          class="btn btn-secondary btn-sm"
          :disabled="pagination.current_page >= pagination.last_page"
          @click="loadUsers(pagination.current_page + 1)"
        >
          Suivant
        </button>
      </div>
    </div>
  </section>
</template>

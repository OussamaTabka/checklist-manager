<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { Archive, Ellipsis, Mail, Pencil, RotateCcw, ShieldX, Trash2 } from 'lucide-vue-next'
import { apiRequest, withQuery } from '@/lib/api'
import { localizeError, tr } from '@/lib/localization'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()

const activeUsers = ref([])
const archivedUsers = ref([])
const activePagination = reactive({ current_page: 1, last_page: 1 })
const archivedPagination = reactive({ current_page: 1, last_page: 1 })
const loadingActive = ref(false)
const loadingArchived = ref(false)
const errorMessage = ref('')
const deliveryNotice = ref('')
const selectedRole = ref('')
const openUserMenuId = ref(null)
const showUserForm = ref(false)
const availableProjectManagers = ref([])
const loadingProjectManagers = ref(false)
const deleteModalError = ref('')
const deleteModal = reactive({
  open: false,
  loading: false,
  user: null,
  replacementOwnerId: '',
})

const form = reactive({
  id: null,
  name: '',
  email: '',
  role: 'testeur',
})

const isEditingUser = computed(() => form.id !== null)

const roleOptions = computed(() => [
  { value: 'admin', label: 'Administrateur' },
  { value: 'chef', label: 'Chef de projet' },
  { value: 'testeur', label: 'Testeur' },
])

const usersPageCopy = computed(() => ({
  kicker: 'Espace administrateur',
  title: 'Utilisateurs et roles',
  description: 'Invitez les collaborateurs, attribuez les roles et gardez un controle clair des acces a la plateforme.',
}))

function accountStatusLabel(status) {
  if (status === 'pending') {
    return 'Invitation en attente'
  }

  if (status === 'disabled') {
    return 'Archive'
  }

  return 'Actif'
}

function hasPendingInvitation(user) {
  return (user.account_status || 'active') === 'pending'
}

function toggleUserMenu(menuKey) {
  openUserMenuId.value = openUserMenuId.value === menuKey ? null : menuKey
}

function closeUserMenu() {
  openUserMenuId.value = null
}

function resetForm() {
  form.id = null
  form.name = ''
  form.email = ''
  form.role = 'testeur'
  showUserForm.value = false
}

function closeDeleteModal() {
  deleteModal.open = false
  deleteModal.loading = false
  deleteModal.user = null
  deleteModal.replacementOwnerId = ''
  deleteModalError.value = ''
}

function userOwnedProjectsCount(user) {
  return Number(user?.owned_projects_count || 0)
}

async function loadAvailableProjectManagers() {
  loadingProjectManagers.value = true

  try {
    const data = await apiRequest('/available-project-managers', {}, auth.token)
    availableProjectManagers.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    availableProjectManagers.value = []
    deleteModalError.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    loadingProjectManagers.value = false
  }
}

function buildUsersQuery(page, status) {
  return withQuery('/users', {
    page,
    role: selectedRole.value,
    status,
  })
}

async function loadActiveUsers(page = activePagination.current_page || 1) {
  loadingActive.value = true

  try {
    const data = await apiRequest(buildUsersQuery(page, 'active'), {}, auth.token)
    activeUsers.value = data.data || []
    activePagination.current_page = data.current_page
    activePagination.last_page = data.last_page
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    loadingActive.value = false
  }
}

async function loadArchivedUsers(page = archivedPagination.current_page || 1) {
  loadingArchived.value = true

  try {
    const data = await apiRequest(buildUsersQuery(page, 'archived'), {}, auth.token)
    archivedUsers.value = data.data || []
    archivedPagination.current_page = data.current_page
    archivedPagination.last_page = data.last_page
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    loadingArchived.value = false
  }
}

async function loadUserLists(activePage = activePagination.current_page || 1, archivedPage = archivedPagination.current_page || 1) {
  errorMessage.value = ''
  await Promise.all([loadActiveUsers(activePage), loadArchivedUsers(archivedPage)])
}

function editUser(user) {
  closeUserMenu()
  showUserForm.value = true
  form.id = user.id
  form.name = user.name
  form.email = user.email
  form.role = user.roles?.[0]?.name || 'testeur'
}

async function submitUser() {
  errorMessage.value = ''
  deliveryNotice.value = ''

  try {
    if (form.id) {
      await apiRequest(
        `/users/${form.id}`,
        {
          method: 'PUT',
          body: { role: form.role },
        },
        auth.token
      )

      toast.success(tr('user_role_updated_success', {}, settings.language))
    } else {
      const data = await apiRequest(
        '/users',
        {
          method: 'POST',
          body: {
            name: form.name,
            email: form.email,
            role: form.role,
          },
        },
        auth.token
      )

      toast.success(data.message || tr('user_created_success', {}, settings.language))
      deliveryNotice.value = data.delivery_notice || ''
    }

    resetForm()
    await loadUserLists(activePagination.current_page, archivedPagination.current_page)
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  }
}

async function resendInvitation(user) {
  closeUserMenu()
  errorMessage.value = ''
  deliveryNotice.value = ''

  try {
    const data = await apiRequest(`/users/${user.id}/invitations/resend`, { method: 'POST' }, auth.token)
    toast.success(data.message || tr('user_invitation_resent_success', {}, settings.language))
    deliveryNotice.value = data.delivery_notice || ''
    await loadUserLists(activePagination.current_page, archivedPagination.current_page)
  } catch (error) {
    if (error.status === 404) {
      errorMessage.value = tr('user_not_found_refreshed', {}, settings.language)
      await loadUserLists(activePagination.current_page, archivedPagination.current_page)
      return
    }

    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  }
}

async function revokeInvitation(user) {
  closeUserMenu()
  errorMessage.value = ''
  deliveryNotice.value = ''

  try {
    await apiRequest(`/users/${user.id}/invitations/revoke`, { method: 'POST' }, auth.token)
    toast.success(tr('user_invitation_revoked_success', {}, settings.language))
    await loadUserLists(activePagination.current_page, archivedPagination.current_page)
  } catch (error) {
    if (error.status === 404) {
      errorMessage.value = tr('user_not_found_refreshed', {}, settings.language)
      await loadUserLists(activePagination.current_page, archivedPagination.current_page)
      return
    }

    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  }
}

async function archiveUser(userId) {
  closeUserMenu()
  errorMessage.value = ''
  deliveryNotice.value = ''

  try {
    await apiRequest(`/users/${userId}`, { method: 'DELETE' }, auth.token)
    toast.success(tr('user_archived_success', {}, settings.language))
    await loadUserLists(activePagination.current_page, archivedPagination.current_page)
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  }
}

async function restoreUser(user) {
  closeUserMenu()
  errorMessage.value = ''
  deliveryNotice.value = ''

  try {
    await apiRequest(`/users/${user.id}/restore`, { method: 'POST' }, auth.token)
    toast.success(tr('user_restored_success', {}, settings.language))
    await loadUserLists(activePagination.current_page, archivedPagination.current_page)
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  }
}

function formatBlockingResources(data) {
  const counts = data?.ownership_counts || {}
  const labels = {
    projects: 'projets',
    checklists: 'checklists',
    user_stories: 'user stories',
  }

  return Object.entries(counts)
    .filter(([, count]) => Number(count) > 0)
    .map(([key, count]) => `${count} ${labels[key] || key}`)
    .join(', ')
}

async function openPermanentDeleteModal(user) {
  closeUserMenu()
  errorMessage.value = ''
  deliveryNotice.value = ''

  if (!confirm(tr('confirm_delete_user_permanent', { name: user.name }, settings.language))) {
    return
  }

  deleteModal.user = user
  deleteModal.open = true
  deleteModal.replacementOwnerId = ''
  deleteModalError.value = ''

  if (userOwnedProjectsCount(user) > 0 && availableProjectManagers.value.length === 0) {
    await loadAvailableProjectManagers()
  }
}

async function permanentlyDeleteUser() {
  const user = deleteModal.user

  if (!user) {
    return
  }

  if (userOwnedProjectsCount(user) > 0 && !deleteModal.replacementOwnerId) {
    deleteModalError.value = tr('user_delete_reassign_projects_required', {}, settings.language)
    return
  }

  deleteModal.loading = true
  deleteModalError.value = ''

  try {
    await apiRequest(
      `/users/${user.id}/permanent`,
      {
        method: 'DELETE',
        body: {
          replacement_owner_id: deleteModal.replacementOwnerId ? Number(deleteModal.replacementOwnerId) : null,
        },
      },
      auth.token
    )

    toast.success(tr('user_deleted_success', {}, settings.language))
    closeDeleteModal()
    await loadUserLists(activePagination.current_page, archivedPagination.current_page)
  } catch (error) {
    if (error.status === 422 && error.data?.requires_replacement_owner) {
      deleteModalError.value = tr('user_delete_reassign_projects_required', {}, settings.language)
      return
    }

    if (error.status === 422 && error.data?.ownership_counts) {
      const details = formatBlockingResources(error.data)
      deleteModalError.value = details
        ? tr('user_delete_blocked', { details }, settings.language)
        : localizeError(error, 'error_generic', settings.language)
      return
    }

    deleteModalError.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    deleteModal.loading = false
  }
}

onMounted(async () => {
  window.addEventListener('click', closeUserMenu)
  await loadUserLists(1, 1)
})

onBeforeUnmount(() => {
  window.removeEventListener('click', closeUserMenu)
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
      <h2 v-if="showUserForm || form.id">{{ form.id ? `Modifier le role de l'utilisateur #${form.id}` : 'Creer un utilisateur' }}</h2>

      <div class="users-form-header">
        <button
          v-if="!showUserForm && !form.id"
          class="btn btn-primary"
          type="button"
          @click="showUserForm = true"
          data-testid="users-btn-open-create"
        >
          CrÃ©er utilisateur
        </button>
      </div>

      <p v-if="errorMessage" class="error" data-testid="users-msg-error">{{ errorMessage }}</p>
      <p v-if="deliveryNotice" class="muted">{{ deliveryNotice }}</p>

      <form v-if="showUserForm || form.id" class="stack" @submit.prevent="submitUser" data-testid="users-form">
        <div class="grid">
          <div class="field">
            <label>Nom complet</label>
            <input v-model="form.name" :disabled="isEditingUser" required data-testid="users-input-name" />
          </div>

          <div class="field">
            <label>Email</label>
            <input v-model="form.email" :disabled="isEditingUser" type="email" required data-testid="users-input-email" />
          </div>

          <div class="field">
            <label>Role</label>
            <select v-model="form.role" required data-testid="users-select-role">
              <option v-for="option in roleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </div>
        </div>

        <p v-if="isEditingUser" class="muted">Le nom et l'email ne sont pas modifiables ici. Seul le role peut etre change.</p>
        <p v-else class="muted">L'utilisateur recevra automatiquement un email pour initialiser son mot de passe.</p>

        <div class="actions">
          <button class="btn btn-primary" type="submit" data-testid="users-btn-submit">
            {{ form.id ? 'Enregistrer le role' : "Creer l'utilisateur" }}
          </button>
          <button class="btn btn-secondary" type="button" @click="resetForm">
            {{ form.id ? 'Annuler' : 'Reinitialiser' }}
          </button>
        </div>
      </form>

      
    </div>

    <div class="card stack">
      <h2>Utilisateurs actifs et en attente</h2>
      <p v-if="loadingActive" class="muted">Chargement des utilisateurs...</p>

      <div v-if="!loadingActive" class="grid">
        <div class="field">
          <label>Filtrer par role</label>
          <select v-model="selectedRole" data-testid="users-filter-role" @change="loadUserLists(1, 1)">
            <option value="">Tous les roles</option>
            <option v-for="option in roleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
          </select>
        </div>
      </div>

      <div v-if="!loadingActive" class="table-wrap">
        <table data-testid="users-table-active">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nom</th>
              <th>Email</th>
              <th>Role</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in activeUsers" :key="`active-${user.id}`">
              <td>{{ user.id }}</td>
              <td>{{ user.name }}</td>
              <td>{{ user.email }}</td>
              <td>{{ roleOptions.find((option) => option.value === (user.roles?.[0]?.name || ''))?.label || '-' }}</td>
              <td>{{ accountStatusLabel(user.account_status || 'active') }}</td>
              <td class="users-actions-cell">
                <div class="user-row-actions" @click.stop>
                  <button
                    type="button"
                    class="user-menu-trigger"
                    aria-label="Ouvrir les actions de l'utilisateur"
                    @click.stop="toggleUserMenu(`active-${user.id}`)"
                  >
                    <Ellipsis :size="18" />
                  </button>

                  <div v-if="openUserMenuId === `active-${user.id}`" class="user-row-menu">
                    <button type="button" class="user-row-menu-item" @click="editUser(user)">
                      <Pencil :size="16" />
                      <span>Modifier</span>
                    </button>
                    <button
                      v-if="hasPendingInvitation(user)"
                      type="button"
                      class="user-row-menu-item"
                      @click="resendInvitation(user)"
                    >
                      <Mail :size="16" />
                      <span>Renvoyer l'invitation</span>
                    </button>
                    <button
                      v-if="hasPendingInvitation(user)"
                      type="button"
                      class="user-row-menu-item"
                      @click="revokeInvitation(user)"
                    >
                      <ShieldX :size="16" />
                      <span>Revoquer l'invitation</span>
                    </button>
                    <button type="button" class="user-row-menu-item danger" @click="archiveUser(user.id)">
                      <Archive :size="16" />
                      <span>Archiver</span>
                    </button>
                  </div>
                </div>
              </td>
            </tr>
            <tr v-if="activeUsers.length === 0">
              <td colspan="6" class="muted">
                {{ selectedRole ? "Aucun utilisateur actif ou en attente ne correspond a ce role." : "Aucun utilisateur actif ou en attente pour le moment." }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <button
          class="btn btn-secondary btn-sm"
          :disabled="activePagination.current_page <= 1"
          @click="loadActiveUsers(activePagination.current_page - 1)"
        >
          Precedent
        </button>
        <button
          class="btn btn-secondary btn-sm"
          :disabled="activePagination.current_page >= activePagination.last_page"
          @click="loadActiveUsers(activePagination.current_page + 1)"
        >
          Suivant
        </button>
      </div>
    </div>

    <div class="card stack">
      <h2>Utilisateurs archives</h2>
      <p class="muted">Les utilisateurs archives peuvent etre restaures ou supprimes definitivement. Les projets encore possedes doivent etre reassignes avant suppression.</p>
      <p v-if="loadingArchived" class="muted">Chargement des utilisateurs archives...</p>

      <div v-if="!loadingArchived" class="table-wrap">
        <table data-testid="users-table-archived">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nom</th>
              <th>Email</th>
              <th>Role</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in archivedUsers" :key="`archived-${user.id}`">
              <td>{{ user.id }}</td>
              <td>{{ user.name }}</td>
              <td>{{ user.email }}</td>
              <td>{{ roleOptions.find((option) => option.value === (user.roles?.[0]?.name || ''))?.label || '-' }}</td>
              <td>{{ accountStatusLabel(user.account_status || 'disabled') }}</td>
              <td class="users-actions-cell">
                <div class="user-row-actions" @click.stop>
                  <button
                    type="button"
                    class="user-menu-trigger"
                    aria-label="Ouvrir les actions archivees de l'utilisateur"
                    @click.stop="toggleUserMenu(`archived-${user.id}`)"
                  >
                    <Ellipsis :size="18" />
                  </button>

                  <div v-if="openUserMenuId === `archived-${user.id}`" class="user-row-menu">
                    <button type="button" class="user-row-menu-item" @click="restoreUser(user)">
                      <RotateCcw :size="16" />
                      <span>Restaurer</span>
                    </button>
                    <button type="button" class="user-row-menu-item danger" @click="openPermanentDeleteModal(user)">
                      <Trash2 :size="16" />
                      <span>Supprimer definitivement</span>
                    </button>
                  </div>
                </div>
              </td>
            </tr>
            <tr v-if="archivedUsers.length === 0">
              <td colspan="6" class="muted">
                {{ selectedRole ? "Aucun utilisateur archive ne correspond a ce role." : "Aucun utilisateur archive pour le moment." }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <button
          class="btn btn-secondary btn-sm"
          :disabled="archivedPagination.current_page <= 1"
          @click="loadArchivedUsers(archivedPagination.current_page - 1)"
        >
          Precedent
        </button>
        <button
          class="btn btn-secondary btn-sm"
          :disabled="archivedPagination.current_page >= archivedPagination.last_page"
          @click="loadArchivedUsers(archivedPagination.current_page + 1)"
        >
          Suivant
        </button>
      </div>
    </div>

    <div v-if="deleteModal.open" class="user-delete-modal-overlay" @click.self="closeDeleteModal">
      <div class="card stack user-delete-modal-card" aria-modal="true" role="dialog">
        <div class="stack stack-xs">
          <h3>{{ tr('user_delete_reassign_projects_title', {}, settings.language) }}</h3>
          <p class="muted">
            {{ tr('confirm_delete_user_permanent', { name: deleteModal.user?.name || '' }, settings.language) }}
          </p>
          <p v-if="userOwnedProjectsCount(deleteModal.user) > 0" class="muted">
            {{ tr('user_delete_reassign_projects_intro', { count: userOwnedProjectsCount(deleteModal.user) }, settings.language) }}
          </p>
        </div>

        <div v-if="userOwnedProjectsCount(deleteModal.user) > 0" class="stack stack-sm">
          <div class="field">
            <label>{{ tr('user_delete_reassign_projects_select', {}, settings.language) }}</label>
            <select
              v-model="deleteModal.replacementOwnerId"
              :disabled="loadingProjectManagers || deleteModal.loading"
              data-testid="users-select-replacement-owner"
            >
              <option value="">{{ tr('user_delete_reassign_projects_placeholder', {}, settings.language) }}</option>
              <option v-for="manager in availableProjectManagers" :key="manager.id" :value="String(manager.id)">
                {{ manager.name }} - {{ manager.email }}
              </option>
            </select>
          </div>

          <p v-if="loadingProjectManagers" class="muted">Chargement des chefs de projet...</p>
          <p v-else-if="availableProjectManagers.length === 0" class="error">
            {{ tr('user_delete_reassign_projects_none', {}, settings.language) }}
          </p>
          <p class="muted">{{ tr('user_delete_reassign_projects_hint', {}, settings.language) }}</p>
        </div>

        <p v-if="deleteModalError" class="error">{{ deleteModalError }}</p>

        <div class="actions">
          <button
            class="btn btn-danger"
            type="button"
            :disabled="deleteModal.loading || loadingProjectManagers || (userOwnedProjectsCount(deleteModal.user) > 0 && availableProjectManagers.length === 0)"
            @click="permanentlyDeleteUser"
          >
            {{ deleteModal.loading ? 'Suppression...' : 'Confirmer la suppression' }}
          </button>
          <button class="btn btn-secondary" type="button" :disabled="deleteModal.loading" @click="closeDeleteModal">
            Annuler
          </button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.users-form-header {
  display: flex;
  justify-content: flex-end;
  align-items: center;
}

.users-form-empty {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 92px;
  border: 1px dashed rgba(148, 163, 184, 0.35);
  border-radius: 1rem;
  background: rgba(255, 255, 255, 0.55);
  text-align: center;
  padding: 1.25rem;
}

.user-row-actions {
  position: relative;
  display: flex;
  justify-content: center;
  align-items: center;
}

.user-menu-trigger {
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

.user-menu-trigger:hover {
  background: #eef3fb;
}

.user-row-menu {
  position: absolute;
  top: calc(100% + 0.55rem);
  right: 0;
  z-index: 20;
  min-width: 14rem;
  padding: 0.6rem;
  border-radius: 1.15rem;
  border: 1px solid rgba(224, 232, 241, 0.98);
  background: rgba(255, 255, 255, 0.98);
  box-shadow: 0 24px 44px -28px rgba(15, 23, 42, 0.24);
}

.users-actions-cell {
  width: 110px;
}

.user-delete-modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 50;
  background: rgba(15, 23, 42, 0.42);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
}

.user-delete-modal-card {
  width: min(100%, 34rem);
}

.user-row-menu-item {
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

.user-row-menu-item:hover {
  background: #f8fbff;
}

.user-row-menu-item.danger {
  color: #dc2626;
}

.dark .user-menu-trigger,
.dark .user-row-menu {
  background: rgba(15, 23, 42, 0.96);
  color: #e2e8f0;
  border-color: rgba(51, 65, 85, 0.9);
}

.dark .user-menu-trigger {
  box-shadow: inset 0 0 0 1px rgba(51, 65, 85, 0.9);
}

.dark .user-menu-trigger:hover,
.dark .user-row-menu-item:hover {
  background: rgba(30, 41, 59, 0.9);
}

.dark .user-row-menu-item {
  color: #f8fafc;
}

.dark .user-row-menu-item.danger {
  color: #fca5a5;
}

.dark .user-delete-modal-card {
  background: rgba(15, 23, 42, 0.96);
}

@media (max-width: 640px) {
  .users-form-header {
    justify-content: stretch;
  }

  .users-form-header .btn {
    width: 100%;
    justify-content: center;
  }

  .user-delete-modal-card {
    width: 100%;
  }
}
</style>

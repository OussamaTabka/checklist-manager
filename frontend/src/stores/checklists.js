import { defineStore } from 'pinia'
import { ref } from 'vue'
import axios from 'axios'
import { API_BASE_URL, ensureCsrfCookie } from '@/lib/api'

const api = axios.create({
  baseURL: API_BASE_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

export const useChecklistsStore = defineStore('checklists', () => {
  const checklists = ref([])
  const currentChecklist = ref(null)
  const loading = ref(false)
  const errors = ref({})
  const selectedChecklistId = ref(null)

  function authHeaders(extra = {}) {
    const token = localStorage.getItem('auth_token')

    return {
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...extra,
    }
  }

  // Get all checklists
  async function fetchChecklists() {
    loading.value = true
    try {
      const response = await api.get('/checklists', {
        headers: authHeaders(),
      })
      checklists.value = response.data.data || response.data
      return checklists.value
    } catch (error) {
      errors.value.fetch = error.message
      throw error
    } finally {
      loading.value = false
    }
  }

  // Get single checklist
  async function fetchChecklist(checklistId) {
    loading.value = true
    try {
      const response = await api.get(`/checklists/${checklistId}`, {
        headers: authHeaders(),
      })
      currentChecklist.value = response.data
      return response.data
    } catch (error) {
      errors.value.fetch = error.message
      throw error
    } finally {
      loading.value = false
    }
  }

  // Create checklist with user story fields
  async function createChecklist(payload) {
    loading.value = true
    try {
      await ensureCsrfCookie()
      const response = await api.post('/checklists', payload, {
        headers: authHeaders({
          'Content-Type': 'application/json',
        }),
      })
      checklists.value.unshift(response.data)
      currentChecklist.value = response.data
      return response.data
    } catch (error) {
      if (error.response?.data?.errors) {
        errors.value = error.response.data.errors
      } else {
        errors.value.submit = error.message
      }
      throw error
    } finally {
      loading.value = false
    }
  }

  // Update checklist and scenarios
  async function updateChecklist(checklistId, payload) {
    loading.value = true
    try {
      await ensureCsrfCookie()
      const response = await api.put(`/checklists/${checklistId}`, payload, {
        headers: authHeaders({
          'Content-Type': 'application/json',
        }),
      })
      const index = checklists.value.findIndex((c) => c.id === checklistId)
      if (index !== -1) {
        checklists.value[index] = response.data
      }
      currentChecklist.value = response.data
      return response.data
    } catch (error) {
      if (error.response?.data?.errors) {
        errors.value = error.response.data.errors
      } else {
        errors.value.submit = error.message
      }
      throw error
    } finally {
      loading.value = false
    }
  }

  // Delete checklist
  async function deleteChecklist(checklistId) {
    try {
      await ensureCsrfCookie()
      await api.delete(`/checklists/${checklistId}`, {
        headers: authHeaders(),
      })
      checklists.value = checklists.value.filter((c) => c.id !== checklistId)
      if (currentChecklist.value?.id === checklistId) {
        currentChecklist.value = null
      }
    } catch (error) {
      errors.value.delete = error.message
      throw error
    }
  }

  // Update item status with history logging
  async function updateItemStatus(checklistId, itemId, status, notes = null) {
    try {
      await ensureCsrfCookie()
      const response = await api.patch(
        `/checklists/${checklistId}/items/${itemId}/status`,
        { status, notes },
        {
          headers: authHeaders({
            'Content-Type': 'application/json',
          }),
        }
      )
      // Update the item in current checklist
      if (currentChecklist.value) {
        const item = currentChecklist.value.items.find((i) => i.id === itemId)
        if (item) {
          item.status = status
          item.qa_comment = response.data.qa_comment ?? item.qa_comment
          item.tested_by = response.data.tested_by
          item.tested_at = response.data.tested_at
          if (response.data.history) {
            item.history = response.data.history
          }
        }
      }
      return response.data
    } catch (error) {
      errors.value.statusUpdate = error.message
      throw error
    }
  }

  // Get item history
  async function getItemHistory(checklistId, itemId) {
    try {
      const response = await api.get(
        `/checklists/${checklistId}/items/${itemId}/history`,
        {
          headers: authHeaders(),
        }
      )
      return response.data
    } catch (error) {
      errors.value.history = error.message
      throw error
    }
  }

  async function getItemExecution(checklistId, itemId) {
    try {
      const response = await api.get(
        `/checklists/${checklistId}/items/${itemId}/execution`,
        {
          headers: authHeaders(),
        }
      )
      return response.data
    } catch (error) {
      errors.value.execution = error.message
      throw error
    }
  }

  async function runChecklistItem(checklistId, itemId, payload) {
    try {
      await ensureCsrfCookie()
      const response = await api.post(
        `/checklists/${checklistId}/items/${itemId}/runs`,
        payload,
        {
          headers: authHeaders({
            'Content-Type': 'application/json',
          }),
        }
      )
      return response.data
    } catch (error) {
      errors.value.run = error.message
      throw error
    }
  }

  async function getItemComment(checklistId, itemId) {
    try {
      const response = await api.get(
        `/checklists/${checklistId}/items/${itemId}/comment`,
        {
          headers: authHeaders(),
        }
      )
      return response.data
    } catch (error) {
      errors.value.comment = error.message
      throw error
    }
  }

  async function updateItemComment(checklistId, itemId, comment) {
    try {
      await ensureCsrfCookie()
      const response = await api.patch(
        `/checklists/${checklistId}/items/${itemId}/comment`,
        { comment },
        {
          headers: authHeaders({
            'Content-Type': 'application/json',
          }),
        }
      )

      if (currentChecklist.value) {
        const item = currentChecklist.value.items.find((entry) => entry.id === itemId)
        if (item) {
          item.qa_comment = response.data.comment
          if (response.data.history) {
            item.history = response.data.history
          }
        }
      }

      return response.data
    } catch (error) {
      errors.value.comment = error.message
      throw error
    }
  }

  // Clear errors
  function clearErrors() {
    errors.value = {}
  }

  return {
    checklists,
    currentChecklist,
    loading,
    errors,
    selectedChecklistId,
    fetchChecklists,
    fetchChecklist,
    createChecklist,
    updateChecklist,
    deleteChecklist,
    updateItemStatus,
    getItemHistory,
    getItemExecution,
    runChecklistItem,
    getItemComment,
    updateItemComment,
    clearErrors,
  }
})

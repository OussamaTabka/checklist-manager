import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
})

export const useChecklistsStore = defineStore('checklists', () => {
  const checklists = ref([])
  const currentChecklist = ref(null)
  const loading = ref(false)
  const errors = ref({})
  const selectedChecklistId = ref(null)

  // Get all checklists
  async function fetchChecklists() {
    loading.value = true
    try {
      const response = await api.get('/checklists', {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        },
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
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        },
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
      const response = await api.post('/checklists', payload, {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          'Content-Type': 'application/json',
        },
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
      const response = await api.put(`/checklists/${checklistId}`, payload, {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          'Content-Type': 'application/json',
        },
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
      await api.delete(`/checklists/${checklistId}`, {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        },
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
      const response = await api.patch(
        `/checklists/${checklistId}/items/${itemId}/status`,
        { status, notes },
        {
          headers: {
            Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
            'Content-Type': 'application/json',
          },
        }
      )
      // Update the item in current checklist
      if (currentChecklist.value) {
        const item = currentChecklist.value.items.find((i) => i.id === itemId)
        if (item) {
          item.status = status
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
          headers: {
            Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          },
        }
      )
      return response.data
    } catch (error) {
      errors.value.history = error.message
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
    clearErrors,
  }
})

<script setup>
import { ref } from 'vue'
import { localizeError, tr } from '@/lib/localization'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const settings = useSettingsStore()
const toast = useToastStore()

const email = ref('')
const isSubmitting = ref(false)
const errorMessage = ref('')

async function onSubmit() {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    const response = await auth.requestPasswordReset(email.value)
    toast.success(response?.message || tr('password_reset_link_sent', {}, settings.language))
  } catch (error) {
    errorMessage.value = localizeError(error, 'error_generic', settings.language)
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="page page-auth-md">
    <div class="card stack">
      <div>
        <h1>Forgot password</h1>
        <p class="muted">Enter your email address and we will send you a password reset link.</p>
      </div>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <form class="stack" @submit.prevent="onSubmit">
        <div class="field">
          <label>Email</label>
          <input v-model="email" type="email" required autocomplete="email" />
        </div>

        <button class="btn btn-primary" type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? 'Sending...' : 'Send reset link' }}
        </button>
      </form>

      <div>
        <RouterLink :to="{ name: 'login' }" class="muted">Back to sign in</RouterLink>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const email = ref('')
const isSubmitting = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

async function onSubmit() {
  errorMessage.value = ''
  successMessage.value = ''
  isSubmitting.value = true

  try {
    const response = await auth.requestPasswordReset(email.value)
    successMessage.value = response?.message || 'If your email exists, a reset link has been sent.'
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
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

      <p v-if="successMessage" class="success">{{ successMessage }}</p>
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

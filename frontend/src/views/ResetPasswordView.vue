<script setup>
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const form = reactive({
  token: typeof route.query.token === 'string' ? route.query.token : '',
  email: typeof route.query.email === 'string' ? route.query.email : '',
  password: '',
  password_confirmation: '',
})

const isSubmitting = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

async function onSubmit() {
  errorMessage.value = ''
  successMessage.value = ''
  isSubmitting.value = true

  try {
    const response = await auth.resetPassword({ ...form })
    successMessage.value = response?.message || 'Your password has been reset successfully.'

    setTimeout(() => {
      router.push({ name: 'login' })
    }, 1000)
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
        <h1>Reset password</h1>
        <p class="muted">Enter your account email and choose a new password.</p>
      </div>

      <p v-if="successMessage" class="success">{{ successMessage }}</p>
      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <form class="stack" @submit.prevent="onSubmit">
        <div class="field">
          <label>Email</label>
          <input v-model="form.email" type="email" required autocomplete="email" />
        </div>

        <div class="field">
          <label>Reset token</label>
          <input v-model="form.token" type="text" required autocomplete="off" />
        </div>

        <div class="field">
          <label>New password</label>
          <input v-model="form.password" type="password" required minlength="8" autocomplete="new-password" />
        </div>

        <div class="field">
          <label>Confirm new password</label>
          <input
            v-model="form.password_confirmation"
            type="password"
            required
            minlength="8"
            autocomplete="new-password"
          />
        </div>

        <button class="btn btn-primary" type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? 'Resetting...' : 'Reset password' }}
        </button>
      </form>

      <div>
        <RouterLink :to="{ name: 'login' }" class="muted">Back to sign in</RouterLink>
      </div>
    </div>
  </section>
</template>

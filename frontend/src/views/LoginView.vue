<script setup>
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const form = reactive({
  email: '',
  password: '',
})

const errorMessage = ref('')
const isSubmitting = ref(false)

async function onSubmit() {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    await auth.login({ ...form })
    await router.push({ name: 'dashboard' })
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="page" style="max-width: 420px; margin-top: 4rem">
    <div class="card stack">
      <div>
        <h1>Sign in</h1>
        <p class="muted">Use your account credentials to access Checklist Manager.</p>
      </div>

      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <form class="stack" @submit.prevent="onSubmit">
        <div class="field">
          <label>Email</label>
          <input v-model="form.email" type="email" required autocomplete="email" />
        </div>

        <div class="field">
          <label>Password</label>
          <input v-model="form.password" type="password" required autocomplete="current-password" />
        </div>

        <button class="btn btn-primary" type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? 'Signing in...' : 'Sign in' }}
        </button>
      </form>
    </div>
  </section>
</template>
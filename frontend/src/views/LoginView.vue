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
  <section class="page page-auth-sm">
    <div class="card stack">
      <div>
        <h1>Connexion</h1>
        <p class="muted">Utilisez vos identifiants pour accéder à IntelliTest.</p>
      </div>

      <p v-if="errorMessage" class="error" data-testid="login-msg-error">{{ errorMessage }}</p>

      <form class="stack" @submit.prevent="onSubmit">
        <div class="field">
          <label>Email</label>
          <input
            v-model="form.email"
            type="email"
            required
            autocomplete="email"
            data-testid="login-input-email"
          />
        </div>

        <div class="field">
          <label>Mot de passe</label>
          <input
            v-model="form.password"
            type="password"
            required
            autocomplete="current-password"
            data-testid="login-input-password"
          />
        </div>

        <div class="auth-link-row">
          <RouterLink :to="{ name: 'forgot-password' }" class="muted">Mot de passe oublié ?</RouterLink>
        </div>

        <button
          class="btn btn-primary"
          type="submit"
          :disabled="isSubmitting"
          data-testid="login-btn-submit"
        >
          {{ isSubmitting ? 'Connexion en cours...' : 'Se connecter' }}
        </button>
      </form>
    </div>
  </section>
</template>

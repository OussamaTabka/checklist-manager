<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiRequest, ensureCsrfCookie, withQuery } from '@/lib/api'

const route = useRoute()
const router = useRouter()

const selector = ref(typeof route.query.selector === 'string' ? route.query.selector : '')
const token = ref(typeof route.query.token === 'string' ? route.query.token : '')

const validating = ref(true)
const isSubmitting = ref(false)
const successMessage = ref('')
const errorMessage = ref('')
const invitationInfo = ref(null)

const form = reactive({
  password: '',
  password_confirmation: '',
})

async function validateInvitationLink() {
  errorMessage.value = ''
  validating.value = true

  if (!selector.value || !token.value) {
    validating.value = false
    errorMessage.value = 'Invalid invitation link.'
    return
  }

  try {
    const data = await apiRequest(withQuery('/invitations/validate', {
      selector: selector.value,
      token: token.value,
    }))

    invitationInfo.value = data?.user || null
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  } finally {
    validating.value = false
  }
}

async function onSubmit() {
  errorMessage.value = ''
  successMessage.value = ''
  isSubmitting.value = true

  try {
    await ensureCsrfCookie()

    const data = await apiRequest('/invitations/accept', {
      method: 'POST',
      body: {
        selector: selector.value,
        token: token.value,
        password: form.password,
        password_confirmation: form.password_confirmation,
      },
    })

    successMessage.value = data?.message || 'Account activated successfully.'

    setTimeout(() => {
      router.push({ name: 'login' })
    }, 1200)
  } catch (error) {
    errorMessage.value = error.data?.message || error.message
  } finally {
    isSubmitting.value = false
  }
}

onMounted(async () => {
  await validateInvitationLink()
})
</script>

<template>
  <section class="page" style="max-width: 520px; margin-top: 4rem">
    <div class="card stack">
      <div>
        <h1>Set up your account</h1>
        <p class="muted">Choose your password to activate your account.</p>
      </div>

      <p v-if="successMessage" class="success">{{ successMessage }}</p>
      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>

      <p v-if="validating" class="muted">Validating invitation link...</p>

      <div v-else-if="invitationInfo" class="stack">
        <p class="muted">
          Invitation for <strong>{{ invitationInfo.name }}</strong> ({{ invitationInfo.email }})
        </p>
        <p class="muted">Role: {{ invitationInfo.role || 'testeur' }}</p>

        <form class="stack" @submit.prevent="onSubmit">
          <div class="field">
            <label>New password</label>
            <input v-model="form.password" type="password" minlength="8" required autocomplete="new-password" />
          </div>

          <div class="field">
            <label>Confirm password</label>
            <input
              v-model="form.password_confirmation"
              type="password"
              minlength="8"
              required
              autocomplete="new-password"
            />
          </div>

          <button class="btn btn-primary" type="submit" :disabled="isSubmitting">
            {{ isSubmitting ? 'Activating...' : 'Activate account' }}
          </button>
        </form>
      </div>

      <div>
        <RouterLink :to="{ name: 'login' }" class="muted">Back to sign in</RouterLink>
      </div>
    </div>
  </section>
</template>

<template>

<div style="max-width:300px;margin:auto;margin-top:100px">

<h2>Login</h2>

<input v-model="email" placeholder="Email" />
<br><br>

<input v-model="password" type="password" placeholder="Password" />
<br><br>

<button @click="loginUser">Login</button>

<p v-if="error" style="color:red">{{ error }}</p>

</div>

</template>

<script setup>

import { ref } from "vue"
import { useRouter } from "vue-router"
import api from "../services/api"

const router = useRouter()

const email = ref("")
const password = ref("")
const error = ref("")

const loginUser = async () => {

  error.value = ""

  try {

    const res = await api.post("/login", {
      email: email.value,
      password: password.value
    })

    localStorage.setItem("token", res.data.token)

    router.push("/projects")

  } catch (e) {

    error.value = "Login failed"

  }

}

</script>
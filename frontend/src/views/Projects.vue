<template>
  <div style="max-width:900px;margin:20px auto;padding:12px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h2>Projects</h2>
      <div style="display:flex;gap:10px;">
        <router-link to="/checklists">Checklists</router-link>
        <button @click="doLogout">Logout</button>
      </div>
    </div>

    <h3>Create Project</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <input v-model="newName" placeholder="Project name" />
      <input v-model="newDesc" placeholder="Description" />
      <input v-model.number="newChecklistId" placeholder="Checklist ID" type="number" style="width:120px;" />
      <button @click="createProject" :disabled="creating">{{ creating ? "..." : "Create" }}</button>
    </div>
    <p style="opacity:.75;font-size:13px;margin-top:6px;">
      (Tu peux créer une checklist d’abord dans /checklists, puis copier son ID ici)
    </p>

    <hr style="margin:16px 0;" />

    <button @click="load" :disabled="loading">{{ loading ? "..." : "Refresh" }}</button>
    <p v-if="error" style="color:red">{{ error }}</p>

    <ul style="margin-top:12px;">
      <li v-for="p in projects" :key="p.id">
        <router-link :to="`/projects/${p.id}`">{{ p.name }}</router-link>
        <small style="opacity:.7"> (#{{ p.id }})</small>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import api from "../services/api";

const router = useRouter();
const projects = ref([]);
const loading = ref(false);
const error = ref("");

const newName = ref("");
const newDesc = ref("");
const newChecklistId = ref(null);
const creating = ref(false);

const load = async () => {
  loading.value = true;
  error.value = "";
  try {
    const res = await api.get("/projects");
    projects.value = res.data.data;
  } catch (e) {
    error.value = "Failed to load projects";
    console.log(e.response?.status, e.response?.data);
  } finally {
    loading.value = false;
  }
};

const createProject = async () => {
  creating.value = true;
  error.value = "";
  try {
    const payload = {
      name: newName.value,
      description: newDesc.value,
      checklist_id: newChecklistId.value,
    };
    const res = await api.post("/projects", payload);
    // reset
    newName.value = "";
    newDesc.value = "";
    newChecklistId.value = null;

    // refresh + go to details
    await load();
    router.push(`/projects/${res.data.id}`);
  } catch (e) {
    console.log(e.response?.status, e.response?.data);
    error.value = e.response?.data?.message || "Create project failed";
  } finally {
    creating.value = false;
  }
};

const doLogout = async () => {
  try { await api.post("/logout"); } catch {}
  localStorage.removeItem("token");
  router.push("/login");
};

onMounted(load);
</script>
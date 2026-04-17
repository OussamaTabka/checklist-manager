<template>
  <div style="max-width:900px;margin:20px auto;padding:12px;">
    <h2>Project Details</h2>
    <div style="margin:12px 0;">
  <button @click="createVersion" :disabled="creatingVersion">
    {{ creatingVersion ? "..." : "Create new version from current checklist" }}
  </button>
</div>

    <p v-if="loading">Loading...</p>
    <p v-if="error" style="color:red">{{ error }}</p>

    <div v-if="project">
      <h3>{{ project.name }}</h3>
      <p style="opacity:.8">{{ project.description }}</p>

      <div v-for="version in project.versions" :key="version.id" style="margin-top:16px;">
        <h4>Version {{ version.version_number }} <small style="opacity:.6">(#{{ version.id }})</small></h4>

        <div
          v-for="item in version.items"
          :key="item.id"
          style="border:1px solid #ccc;padding:10px;margin:10px 0;border-radius:8px;"
        >
          <b>{{ item.title }}</b>
          <div style="font-size:13px;opacity:.8;">
            Priority: {{ item.priority }} • Criticality: {{ item.criticality }}
          </div>

          <p>Status: <b>{{ item.status }}</b></p>

          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button @click="setStatus(item.id,'Passed')">Pass</button>
            <button @click="setStatus(item.id,'Failed')">Fail</button>
            <button @click="setStatus(item.id,'Blocked')">Block</button>
            <button @click="setStatus(item.id,'Not Tested')">Reset</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import { useRoute } from "vue-router";
import api from "../services/api";
const creatingVersion = ref(false);

const createVersion = async () => {
  creatingVersion.value = true;
  try {
    await api.post(`/projects/${route.params.id}/versions`, {});
    await loadProject();
  } catch (e) {
    console.log("createVersion error:", e.response?.status, e.response?.data);
    alert(e.response?.data?.message || "Failed to create version");
  } finally {
    creatingVersion.value = false;
  }
};
const route = useRoute();

const project = ref(null);
const loading = ref(false);
const error = ref("");

const loadProject = async () => {
  loading.value = true;
  error.value = "";
  try {
    const res = await api.get(`/projects/${route.params.id}`);
    project.value = res.data;
  } catch (e) {
    console.log("ProjectDetails error:", e.response?.status, e.response?.data);
    error.value =
      e.response?.data?.message
        ? `${e.response.status} - ${e.response.data.message}`
        : "Failed to load project (check console)";
  } finally {
    loading.value = false;
  }
};

const setStatus = async (id, status) => {
  try {
    await api.patch(`/version-items/${id}/status`, { status });
    await loadProject();
  } catch (e) {
    console.log("setStatus error:", e.response?.status, e.response?.data);
    alert("Failed to update status");
  }
};

onMounted(loadProject);
</script>
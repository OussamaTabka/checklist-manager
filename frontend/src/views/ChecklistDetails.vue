<template>
  <div style="max-width:900px;margin:20px auto;padding:12px;">
    <router-link to="/checklists">← Back</router-link>

    <p v-if="loading">Loading...</p>
    <p v-if="error" style="color:red">{{ error }}</p>

    <div v-if="checklist">
      <h2>{{ checklist.name }}</h2>
      <p style="opacity:.8">{{ checklist.description }}</p>

      <h3>Add item</h3>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input v-model="newTitle" placeholder="Title" style="min-width:280px;" />
        <select v-model="newPriority">
          <option>Low</option><option>Medium</option><option>High</option>
        </select>
        <select v-model="newCriticality">
          <option>Minor</option><option>Major</option><option>Critical</option>
        </select>
        <button @click="addItem" :disabled="saving">{{ saving ? "..." : "Add" }}</button>
      </div>

      <h3 style="margin-top:16px;">Items</h3>
      <ol>
        <li v-for="it in checklist.items" :key="it.id || it.order">
          {{ it.title }} <small style="opacity:.7">({{ it.priority }} / {{ it.criticality }})</small>
        </li>
      </ol>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import { useRoute } from "vue-router";
import api from "../services/api";

const route = useRoute();
const checklist = ref(null);
const loading = ref(false);
const saving = ref(false);
const error = ref("");

const newTitle = ref("");
const newPriority = ref("Medium");
const newCriticality = ref("Major");

const loadChecklist = async () => {
  loading.value = true;
  error.value = "";
  try {
    const res = await api.get(`/checklists/${route.params.id}`);
    checklist.value = res.data;
  } catch (e) {
    console.log(e.response?.status, e.response?.data);
    error.value = "Failed to load checklist";
  } finally {
    loading.value = false;
  }
};

const addItem = async () => {
  if (!newTitle.value.trim()) return;

  saving.value = true;
  error.value = "";
  try {
    // rebuild full items array for PUT
    const items = (checklist.value.items || []).map((it, idx) => ({
      title: it.title,
      priority: it.priority,
      criticality: it.criticality,
      order: idx,
    }));

    items.push({
      title: newTitle.value.trim(),
      priority: newPriority.value,
      criticality: newCriticality.value,
      order: items.length,
    });

    const payload = {
      name: checklist.value.name,
      description: checklist.value.description,
      items,
    };

    await api.put(`/checklists/${route.params.id}`, payload);

    newTitle.value = "";
    newPriority.value = "Medium";
    newCriticality.value = "Major";

    await loadChecklist();
  } catch (e) {
    console.log(e.response?.status, e.response?.data);
    error.value = e.response?.data?.message || "Add item failed";
  } finally {
    saving.value = false;
  }
};

onMounted(loadChecklist);
</script>
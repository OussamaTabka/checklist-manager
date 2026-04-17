<template>
  <div style="max-width:900px;margin:20px auto;padding:12px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <h2>Checklists</h2>
      <router-link to="/projects">Projects</router-link>
    </div>

    <h3>Create Checklist</h3>
    <div style="display:flex;flex-direction:column;gap:8px;max-width:420px;">
      <input v-model="name" placeholder="Checklist name" />
      <input v-model="description" placeholder="Description" />
      <textarea v-model="itemsText" rows="6" placeholder="Items (1 title per line)"></textarea>
      <button @click="createChecklist" :disabled="creating">{{ creating ? "..." : "Create" }}</button>
    </div>

    <hr style="margin:16px 0;" />

    <button @click="load" :disabled="loading">{{ loading ? "..." : "Refresh" }}</button>
    <p v-if="error" style="color:red">{{ error }}</p>

    <ul style="margin-top:12px;">
      <li v-for="c in checklists" :key="c.id">
        <router-link :to="`/checklists/${c.id}`">{{ c.name }}</router-link>
        <small style="opacity:.7"> (#{{ c.id }})</small>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import api from "../services/api";

const router = useRouter();
const checklists = ref([]);
const loading = ref(false);
const error = ref("");

const name = ref("");
const description = ref("");
const itemsText = ref("");
const creating = ref(false);

const load = async () => {
  loading.value = true;
  error.value = "";
  try {
    const res = await api.get("/checklists");
    checklists.value = res.data.data ?? res.data; // selon ton backend (paginated ou non)
  } catch (e) {
    console.log(e.response?.status, e.response?.data);
    error.value = "Failed to load checklists";
  } finally {
    loading.value = false;
  }
};

const createChecklist = async () => {
  creating.value = true;
  error.value = "";
  try {
    const lines = itemsText.value
      .split("\n")
      .map((x) => x.trim())
      .filter(Boolean);

    const payload = {
      name: name.value,
      description: description.value,
      items: lines.map((t, idx) => ({
        title: t,
        priority: "Medium",
        criticality: "Major",
        order: idx,
      })),
    };

    const res = await api.post("/checklists", payload);

    name.value = "";
    description.value = "";
    itemsText.value = "";

    await load();
    router.push(`/checklists/${res.data.id}`);
  } catch (e) {
    console.log(e.response?.status, e.response?.data);
    error.value = e.response?.data?.message || "Create checklist failed";
  } finally {
    creating.value = false;
  }
};

onMounted(load);
</script>
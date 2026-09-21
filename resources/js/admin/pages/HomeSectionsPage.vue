<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminIcon from '../components/AdminIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';

const sections = ref([]);
const active = ref([]);
const functions = ref([]);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const selectedKey = ref('');
const newTitle = ref('');
const newFunction = ref('latest_update');
const draggedIndex = ref(null);
const dragOverIndex = ref(null);

const available = computed(() => sections.value.filter(
    (section) => !active.value.some((item) => item.key === section.key),
));

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const response = await api('/admin/home-sections');
        sections.value = response?.sections || [];
        functions.value = response?.functions || [];
        active.value = sections.value.filter((section) => section.is_enabled);
    } catch (reason) {
        error.value = reason.message;
    } finally {
        loading.value = false;
    }
}

function move(index, direction) {
    const target = index + direction;
    if (target < 0 || target >= active.value.length) return;
    const next = [...active.value];
    [next[index], next[target]] = [next[target], next[index]];
    active.value = next;
}

function remove(key) {
    active.value = active.value.filter((section) => section.key !== key);
    selectedKey.value = key;
}

function add(section) {
    active.value = [...active.value, { ...section, is_enabled: true }];
    selectedKey.value = '';
}

function createSection() {
    const title = newTitle.value.trim();
    if (!title || !newFunction.value) return;
    const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 45) || 'section';
    const section = {
        key: `custom_${slug}_${Date.now().toString(36)}`,
        source_key: newFunction.value,
        title,
        position: active.value.length,
        is_enabled: true,
        is_custom: true,
    };
    sections.value = [...sections.value, section];
    active.value = [...active.value, section];
    newTitle.value = '';
}

function addSelected() {
    const section = available.value.find((item) => item.key === selectedKey.value) || available.value[0];
    if (section) add(section);
}

function startDrag(index, event) {
    draggedIndex.value = index;
    dragOverIndex.value = index;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', active.value[index].key);
}

function dropAt(index) {
    if (draggedIndex.value === null || draggedIndex.value === index) {
        finishDrag();
        return;
    }
    const next = [...active.value];
    const [moved] = next.splice(draggedIndex.value, 1);
    next.splice(index, 0, moved);
    active.value = next;
    finishDrag();
}

function finishDrag() {
    draggedIndex.value = null;
    dragOverIndex.value = null;
}

async function save() {
    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        const response = await api('/admin/home-sections', {
            method: 'PUT',
            body: {
                sections: active.value.map(({ key, source_key, title }) => ({ key, source_key, title: title.trim() })),
            },
        });
        sections.value = response?.sections || [];
        active.value = sections.value.filter((section) => section.is_enabled);
        notice.value = 'Home section layout saved.';
    } catch (reason) {
        error.value = reason.message;
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="admin-page">
        <PageHeader
            eyebrow="Homepage CMS"
            title="Arrange home sections"
            description="Move, rename, add or remove shelves without changing recommendation ranking."
        />
        <StatusPanel tone="success" :message="notice" />
        <StatusPanel tone="error" :message="error" />

        <div v-if="loading" class="admin-loading">Loading home sections…</div>
        <div v-else class="home-layout-grid">
            <section class="admin-panel active-sections">
                <header>
                    <div><p>FRONTEND RESPONSE ORDER</p><h2>Active sections</h2></div>
                    <span>{{ active.length }} active</span>
                </header>

                <div v-if="active.length" class="section-list">
                    <article
                        v-for="(section, index) in active"
                        :key="section.key"
                        :class="{ dragging: draggedIndex === index, 'drag-over': dragOverIndex === index && draggedIndex !== index }"
                        @dragenter.prevent="dragOverIndex = index"
                        @dragover.prevent
                        @drop.prevent="dropAt(index)"
                    >
                        <b>{{ index + 1 }}</b>
                        <label>
                            <small>{{ section.key }}</small>
                            <input v-model="section.title" required maxlength="120">
                        </label>
                        <label class="function-field">
                            <small>FUNCTION</small>
                            <select v-model="section.source_key">
                                <option v-for="item in functions" :key="item.key" :value="item.key">{{ item.title }}</option>
                            </select>
                        </label>
                        <div>
                            <button type="button" class="icon-action drag-handle" draggable="true" title="Drag to reorder" @dragstart="startDrag(index, $event)" @dragend="finishDrag"><AdminIcon name="grip" /></button>
                            <button type="button" class="icon-action" :disabled="index === 0" title="Move up" @click="move(index, -1)">↑</button>
                            <button type="button" class="icon-action" :disabled="index === active.length - 1" title="Move down" @click="move(index, 1)">↓</button>
                            <button type="button" class="icon-action danger" title="Remove" @click="remove(section.key)"><AdminIcon name="trash" /></button>
                        </div>
                    </article>
                </div>
                <p v-else class="admin-empty">No section is active. Add one from the available list.</p>
            </section>

            <aside>
                <section class="admin-panel available-sections">
                    <header><div><p>NEW SECTION</p><h2>Create</h2></div></header>
                    <form class="create-section-control" @submit.prevent="createSection">
                        <label>Section title<input v-model="newTitle" maxlength="120" placeholder="e.g. Weekend Picks" required></label>
                        <label>Function<select v-model="newFunction" required><option v-for="item in functions" :key="item.key" :value="item.key">{{ item.title }}</option></select></label>
                        <p>{{ functions.find((item) => item.key === newFunction)?.description }}</p>
                        <button type="submit" class="admin-primary" :disabled="!newTitle.trim() || !newFunction"><AdminIcon name="plus" /> Create & add</button>
                    </form>
                </section>

                <section class="admin-panel available-sections restore-sections">
                    <header><div><p>REMOVED SECTIONS</p><h2>Add again</h2></div></header>
                    <div class="add-section-control">
                        <select v-model="selectedKey" :disabled="!available.length">
                            <option value="">{{ available.length ? 'Select a section' : 'No sections available' }}</option>
                            <option v-for="section in available" :key="section.key" :value="section.key">{{ section.title }}</option>
                        </select>
                        <button type="button" class="admin-primary" :disabled="!available.length" @click="addSelected">
                            <AdminIcon name="plus" /> Add section
                        </button>
                    </div>
                    <p v-if="!available.length" class="admin-empty">Every section is active.</p>
                </section>
                <button
                    class="admin-primary save-layout"
                    :disabled="saving || active.some((section) => !section.title.trim())"
                    @click="save"
                >{{ saving ? 'Saving…' : 'Save home layout' }}</button>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.home-layout-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px}.active-sections>header,.available-sections>header{display:flex;align-items:center;justify-content:space-between;padding:18px;border-bottom:1px solid var(--a-line)}header p{color:var(--a-cyan);font-size:9px;font-weight:900;letter-spacing:1.6px}header h2{margin-top:4px;font:800 20px 'Manrope',sans-serif}.active-sections>header>span{padding:6px 10px;border-radius:999px;background:rgba(30,208,219,.12);color:var(--a-cyan);font-size:10px;font-weight:800}.section-list{display:grid;gap:10px;padding:15px}.section-list article{display:grid;grid-template-columns:40px minmax(160px,1fr) minmax(150px,.65fr) auto;align-items:center;gap:12px;padding:12px;border:1px solid var(--a-line);border-radius:12px;background:rgba(0,0,0,.1);transition:border-color .15s,opacity .15s,transform .15s}.section-list article.dragging{opacity:.42}.section-list article.drag-over{border-color:var(--a-cyan);transform:translateY(2px)}.section-list article>b{display:grid;width:38px;height:38px;place-items:center;border-radius:9px;background:var(--a-cyan);color:#031013}.section-list label{display:grid;gap:5px}.section-list small,.available-sections small{color:var(--a-muted);font-size:9px}.section-list input,.section-list select{width:100%;min-height:39px;padding:0 10px;border:1px solid var(--a-line);border-radius:8px;background:rgba(4,12,15,.55);color:var(--a-text);font-weight:700}.section-list article>div{display:flex;gap:4px}.icon-action{display:grid;width:34px;height:34px;place-items:center;border:1px solid var(--a-line);border-radius:8px;background:transparent;color:var(--a-text);cursor:pointer}.icon-action svg{width:15px}.icon-action:disabled{cursor:not-allowed;opacity:.25}.icon-action.danger{color:#ff8790}.drag-handle{cursor:grab;touch-action:none}.drag-handle:active{cursor:grabbing}.available-sections{overflow:hidden}.restore-sections{margin-top:12px}.create-section-control,.add-section-control{display:grid;gap:11px;padding:14px}.create-section-control label{display:grid;gap:6px;color:var(--a-muted);font-size:10px;font-weight:700}.create-section-control input,.create-section-control select,.add-section-control select{width:100%;min-height:43px;padding:0 10px;border:1px solid var(--a-line);border-radius:9px;background:rgba(4,12,15,.6);color:var(--a-text)}.create-section-control p{min-height:30px;margin:0;color:var(--a-muted);font-size:10px;line-height:1.5}.create-section-control button,.add-section-control button{display:flex;align-items:center;justify-content:center;gap:8px;width:100%}.create-section-control button svg,.add-section-control button svg{width:16px}.save-layout{width:100%;margin-top:12px}.admin-empty{padding:28px;text-align:center;color:var(--a-muted);font-size:12px}@media(max-width:1100px){.section-list article{grid-template-columns:38px minmax(0,1fr) auto}.section-list .function-field{grid-column:2}.section-list article>div{grid-column:3;grid-row:1/3}}@media(max-width:850px){.home-layout-grid{grid-template-columns:1fr}.section-list article{grid-template-columns:38px minmax(0,1fr)}.section-list .function-field,.section-list article>div{grid-column:2;grid-row:auto}}
</style>

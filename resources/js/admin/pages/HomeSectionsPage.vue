<script setup>
import { computed, onMounted, ref } from 'vue';
import AdminIcon from '../components/AdminIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';

const sections = ref([]);
const active = ref([]);
const loading = ref(true);
const saving = ref(false);
const error = ref('');
const notice = ref('');

const available = computed(() => sections.value.filter(
    (section) => !active.value.some((item) => item.key === section.key),
));

async function load() {
    loading.value = true;
    error.value = '';
    try {
        const response = await api('/admin/home-sections');
        sections.value = response?.sections || [];
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
}

function add(section) {
    active.value = [...active.value, { ...section, is_enabled: true }];
}

async function save() {
    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        const response = await api('/admin/home-sections', {
            method: 'PUT',
            body: {
                sections: active.value.map(({ key, title }) => ({ key, title: title.trim() })),
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
                    <article v-for="(section, index) in active" :key="section.key">
                        <b>{{ index + 1 }}</b>
                        <label>
                            <small>{{ section.key }}</small>
                            <input v-model="section.title" required maxlength="120">
                        </label>
                        <div>
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
                    <header><div><p>ADD SECTIONS</p><h2>Available</h2></div></header>
                    <button v-for="section in available" :key="section.key" type="button" @click="add(section)">
                        <AdminIcon name="plus" />
                        <span><b>{{ section.title }}</b><small>{{ section.key }}</small></span>
                    </button>
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
.home-layout-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:16px}.active-sections>header,.available-sections>header{display:flex;align-items:center;justify-content:space-between;padding:18px;border-bottom:1px solid var(--a-line)}header p{color:var(--a-cyan);font-size:9px;font-weight:900;letter-spacing:1.6px}header h2{margin-top:4px;font:800 20px 'Manrope',sans-serif}.active-sections>header>span{padding:6px 10px;border-radius:999px;background:rgba(30,208,219,.12);color:var(--a-cyan);font-size:10px;font-weight:800}.section-list{display:grid;gap:10px;padding:15px}.section-list article{display:grid;grid-template-columns:40px minmax(0,1fr) auto;align-items:center;gap:12px;padding:12px;border:1px solid var(--a-line);border-radius:12px;background:rgba(0,0,0,.1)}.section-list article>b{display:grid;width:38px;height:38px;place-items:center;border-radius:9px;background:var(--a-cyan);color:#031013}.section-list label{display:grid;gap:5px}.section-list small,.available-sections small{color:var(--a-muted);font-size:9px}.section-list input{width:100%;min-height:39px;padding:0 10px;border:1px solid var(--a-line);border-radius:8px;background:rgba(4,12,15,.55);color:var(--a-text);font-weight:700}.section-list article>div{display:flex;gap:4px}.icon-action{display:grid;width:34px;height:34px;place-items:center;border:1px solid var(--a-line);border-radius:8px;background:transparent;color:var(--a-text);cursor:pointer}.icon-action svg{width:15px}.icon-action:disabled{cursor:not-allowed;opacity:.25}.icon-action.danger{color:#ff8790}.available-sections{overflow:hidden}.available-sections>button{display:flex;width:calc(100% - 24px);align-items:center;gap:10px;margin:10px 12px;padding:11px;border:1px solid var(--a-line);border-radius:10px;background:rgba(0,0,0,.1);color:var(--a-text);cursor:pointer;text-align:left}.available-sections>button:hover{border-color:var(--a-cyan)}.available-sections>button svg{width:17px;color:var(--a-cyan)}.available-sections>button span{display:grid;gap:3px;min-width:0}.save-layout{width:100%;margin-top:12px}.admin-empty{padding:28px;text-align:center;color:var(--a-muted);font-size:12px}@media(max-width:850px){.home-layout-grid{grid-template-columns:1fr}.section-list article{grid-template-columns:38px minmax(0,1fr)}.section-list article>div{grid-column:2}}
</style>

<script setup>
import { onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import AdminIcon from './AdminIcon.vue';
import StatusPanel from './StatusPanel.vue';
import { api, queryString } from '../lib/api';

const props = defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue', 'select']);
const id = useId(); const query = ref(props.modelValue); const results = ref([]);
const busy = ref(false); const error = ref(''); const searched = ref(false); const open = ref(false);
const page = ref(1); const lastPage = ref(1); const total = ref(0); const active = ref(-1);
let timer; let version = 0;
function reset() { clearTimeout(timer); version++; results.value = []; busy.value = false; searched.value = false; error.value = ''; active.value = -1; page.value = 1; lastPage.value = 1; }
watch(() => props.modelValue, value => { if (value !== query.value) { query.value = value; reset(); } });
async function load(nextPage = 1) {
    const term = query.value.trim();
    if (term.length < 1) return;
    const current = ++version; busy.value = true; error.value = ''; active.value = -1; open.value = true;
    try {
        const response = await api(`/admin/catalog/items/search${queryString({ q: term, per_page: 12, page: nextPage })}`);
        if (current !== version) return;
        const value = response?.data ?? response;
        results.value = Array.isArray(value) ? value : value?.data || [];
        const pagination = response?.pagination || (Array.isArray(value) ? {} : value);
        page.value = pagination?.current_page || nextPage; lastPage.value = pagination?.last_page || 1;
        total.value = pagination?.total ?? results.value.length; searched.value = true;
    } catch (reason) { if (current === version) { error.value = reason.message; results.value = []; } }
    finally { if (current === version) busy.value = false; }
}
function search() {
    reset(); emit('update:modelValue', query.value); open.value = true;
    if (query.value.trim().length >= 1) { busy.value = true; timer = setTimeout(() => load(), 300); }
}
function select(movie) { reset(); open.value = false; query.value = ''; emit('update:modelValue', ''); emit('select', movie); }
function move(direction) { if (busy.value) return; open.value = true; if (results.value.length) active.value = active.value < 0 ? (direction > 0 ? 0 : results.value.length - 1) : (active.value + direction + results.value.length) % results.value.length; }
function enter() { if (!busy.value && open.value && results.value[active.value]) select(results.value[active.value]); }
function blur(event) { if (!event.currentTarget.contains(event.relatedTarget)) open.value = false; }
onMounted(() => { if (query.value.trim().length >= 1) load(); });
onBeforeUnmount(reset);
</script>

<template>
    <div class="movie-lookup" @focusout="blur">
        <div class="movie-search-input"><AdminIcon name="search" /><input v-model="query" type="search" role="combobox" aria-label="Find movie" placeholder="Title, movie ID, director or genre…" maxlength="180" autocomplete="off" :aria-expanded="open && Boolean(results.length)" :aria-controls="id" :aria-activedescendant="open && active >= 0 ? `${id}-${active}` : undefined" @input="search" @focus="open = true" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="enter" @keydown.esc.stop.prevent="open = false"></div>
        <template v-if="open">
            <StatusPanel tone="error" :message="error" /><button v-if="error" type="button" class="admin-secondary compact" @click="load()">Retry search</button>
            <p v-if="busy" class="movie-search-note" role="status">Searching movies…</p>
            <p v-else-if="searched && !results.length && !error" class="movie-search-note" role="status">No movies found. Try another title, ID, director or genre.</p>
            <div v-if="results.length" class="movie-search-results">
                <div :id="id" :aria-busy="busy" role="listbox" aria-label="Matching movies"><button v-for="(movie, index) in results" :id="`${id}-${index}`" :key="movie.id" type="button" :disabled="busy" role="option" :aria-selected="active === index" :class="{ active: active === index }" @mousedown.prevent @click="select(movie)"><AdminIcon :name="movie.isSeason === true || movie.isSeason === 1 ? 'layers' : 'film'" /><span><strong>{{ movie.title || movie.id }}</strong><small>{{ [movie.status, movie.director, movie.genre, movie.id].filter(Boolean).join(' · ') }}</small></span><span class="movie-select-label">Select →</span></button></div>
                <footer v-if="lastPage > 1"><button type="button" :disabled="page <= 1 || busy" @click="load(page - 1)">Previous</button><span>{{ total }} matches · Page {{ page }} / {{ lastPage }}</span><button type="button" :disabled="page >= lastPage || busy" @click="load(page + 1)">Next</button></footer>
            </div>
        </template>
    </div>
</template>

<style scoped>
.movie-lookup{min-width:0}.movie-search-input{position:relative}.movie-search-input>svg{position:absolute;top:13px;left:13px;width:20px;height:20px;color:var(--a-muted)}.movie-search-input input{width:100%;min-height:46px;padding:10px 14px 10px 44px;border:1px solid var(--a-line);border-radius:9px;background:var(--surface-field);color:var(--a-text);font:inherit;font-size:14px}.movie-search-note{margin:12px 0 0;font-size:13px;color:var(--a-muted)}.movie-search-results{margin-top:10px;padding:5px;border:1px solid var(--a-line);border-radius:10px;background:var(--surface-overlay)}.movie-search-results>[role=listbox]{max-height:350px;overflow-y:auto}.movie-search-results [role=option]{display:flex;align-items:center;gap:12px;width:100%;padding:13px 10px;border:0;border-radius:7px;background:transparent;color:var(--a-text);text-align:left;cursor:pointer}.movie-search-results [role=option]:hover,.movie-search-results [role=option].active{background:var(--surface-hover)}.movie-search-results [role=option]>svg{flex:none;width:22px;height:22px;color:var(--a-muted)}.movie-search-results [role=option]>span:nth-child(2){display:grid;gap:5px;flex:1;min-width:0}.movie-search-results strong{font-size:14px;overflow-wrap:anywhere}.movie-search-results small{font-size:11px;line-height:1.5;color:var(--a-muted);overflow-wrap:anywhere}.movie-select-label{font-size:12px;color:var(--a-cyan);flex:none}.movie-search-results footer{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:5px;padding:10px;border-top:1px solid var(--a-line);font-size:11px;color:var(--a-muted)}.movie-search-results footer button{min-height:34px;padding:5px 9px;border:1px solid var(--a-line);border-radius:6px;background:var(--a-soft);color:var(--a-text);font:inherit;cursor:pointer}.movie-search-results button:disabled{opacity:.45;cursor:not-allowed}.movie-lookup :is(input,button):focus-visible{outline:2px solid var(--a-cyan);outline-offset:3px}@media(max-width:600px){.movie-select-label{display:none}}
</style>

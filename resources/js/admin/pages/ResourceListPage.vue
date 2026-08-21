<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AdminIcon from '../components/AdminIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, queryString } from '../lib/api';
import { formatAdminDate } from '../lib/adminDate';
import { resources } from '../lib/resources';

const route = useRoute();
const key = computed(() => route.params.resource);
const resource = computed(() => resources[key.value]);
const items = ref([]); const loading = ref(false); const error = ref(''); const notice = ref('');
const search = ref(''); const parent = ref(''); const page = ref(1); const lastPage = ref(1);
const episodeMovie = ref(null); const episodeSeason = ref(null);
let debounce;

function collection(response) {
    const value = response?.status !== undefined && response?.data !== undefined ? response.data : response;
    if (Array.isArray(value)) return { items: value, lastPage: response?.pagination?.last_page || response?.last_page || 1 };
    if (Array.isArray(value?.data)) return { items: value.data, lastPage: value.last_page || 1 };
    if (Array.isArray(value?.items)) return { items: value.items, lastPage: value.last_page || 1 };
    return { items: [], lastPage: 1 };
}

function resourceCollection(response) {
    const result = collection(response);
    if (key.value === 'subscriptions') {
        return {
            ...result,
            items: result.items.map(item => ({
                ...item,
                plan_id: item.plan_id || item.plan?.id,
                plan_name: item.plan?.name,
                device_type: item.device_type || item.plan?.device_type || item.devices?.[0]?.device_type || ''
            }))
        };
    }
    if (!['seasons', 'episodes'].includes(key.value)) return result;
    const raw = response?.data ?? response;
    if (!Array.isArray(raw)) return result;
    if (key.value === 'seasons') {
        return { items: raw.flatMap(movie => (movie.seasons || []).map(season => ({ ...season, movie_id: season.movie_id || movie.num || movie.id, movie_title: movie.title || `Movie ${season.movie_id || movie.num || movie.id}` }))), lastPage: 1 };
    }
    return {
        items: raw.map(movie => {
            const seasons = movie.seasons || [];
            const episodeCount = seasons.reduce((total, season) => total + (season.episodes || []).length, 0);
            return { ...movie, id: movie.id || movie.num || movie.title, movie_id: movie.num || movie.id, movie_title: movie.title || `Movie ${movie.num || movie.id}`, season_count: seasons.length, episode_count: episodeCount, __kind: 'episode_movie' };
        }),
        lastPage: 1
    };
}

const columns = computed(() => {
    if (key.value === 'episodes' && !parent.value && search.value.trim().length >= 2) {
        if (episodeSeason.value) return ['id', 'season_title', 'episode_number', 'title', 'status'];
        if (episodeMovie.value) return ['movie_title', 'title', 'season_number', 'episode_count', 'status'];
        return ['movie_title', 'season_count', 'episode_count', 'status'];
    }
    return resource.value?.columns || [];
});

const rowActionLabel = (item) => {
    if (item.__kind === 'episode_movie') return 'View seasons';
    if (item.__kind === 'episode_season') return 'View episodes';
    return '';
};

function setEpisodeMovie(movie) {
    episodeMovie.value = movie;
    episodeSeason.value = null;
    items.value = (movie.seasons || []).map(season => ({
        ...season,
        movie_id: movie.movie_id || movie.num || movie.id,
        movie_title: movie.movie_title || movie.title,
        season_title: season.title,
        episode_count: (season.episodes || []).length,
        __kind: 'episode_season'
    }));
}

function setEpisodeSeason(season) {
    episodeSeason.value = season;
    items.value = (season.episodes || []).map(episode => ({
        ...episode,
        movie_id: season.movie_id,
        movie_title: season.movie_title,
        season_id: episode.season_id || season.id,
        season_title: season.title
    }));
}

function backEpisodeDrilldown() {
    if (episodeSeason.value) {
        setEpisodeMovie(episodeMovie.value);
        return;
    }
    episodeMovie.value = null;
    load(true);
}

function handleRowAction(item) {
    if (item.__kind === 'episode_movie') return setEpisodeMovie(item);
    if (item.__kind === 'episode_season') return setEpisodeSeason(item);
    return null;
}

async function load(reset = false) {
    if (!resource.value) return;
    if (reset) page.value = 1;
    const query = search.value.trim();
    if ((resource.value.parentKey && !parent.value && query.length < 2) || (resource.value.searchOnly && query.length < 2)) {
        items.value = [];
        return;
    }
    loading.value = true; error.value = '';
    try {
        let endpoint = (resource.value.listEndpoint || resource.value.endpoint).replace('{parent}', encodeURIComponent(parent.value));
        const searching = query.length >= 2 && resource.value.searchEndpoint;
        if (searching) endpoint = resource.value.searchEndpoint;
        const searchParams = { per_page: 20, page: page.value };
        if (searching) searchParams[resource.value.searchParam || 'q'] = query;
        const result = resourceCollection(await api(`${endpoint}${queryString(searchParams)}`));
        if (query && !resource.value.searchEndpoint) result.items = result.items.filter(item => Object.values(item).some(value => typeof value === 'string' && value.toLowerCase().includes(query.toLowerCase())));
        items.value = result.items; lastPage.value = result.lastPage;
    } catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}

async function remove(item) {
    const id = item.id ?? item.uid;
    if (!confirm(`Delete this ${resource.value.singular.toLowerCase()}?`)) return;
    try {
        await api(`${resource.value.endpoint}/${encodeURIComponent(id)}`, { method: 'DELETE' });
        notice.value = `${resource.value.singular} deleted.`;
        await load();
    } catch (reason) { error.value = reason.message; }
}
async function suspend(item) {
    const id = item.uid ?? item.id;
    if (!confirm(`Suspend ${item.name || id}?`)) return;
    try {
        await api(`/admin/users/${encodeURIComponent(id)}`, { method: 'PUT', body: { isACActive: false } });
        notice.value = 'User suspended.'; await load();
    } catch (reason) { error.value = reason.message; }
}
function isDateColumn(column) {
    const field = resource.value?.fields?.find(item => item.name === column);
    return ['date', 'datetime-local'].includes(field?.type) || /(^|_)(date|at|on|time|activity)$/.test(column) || /Date|Time|Login$/.test(column);
}
function isBooleanColumn(column) {
    return resource.value?.fields?.some(item => item.name === column && item.type === 'checkbox') || /^is[A-Z_]/.test(column);
}
function display(value, column = '') {
    if (value == null || value === '') return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (isBooleanColumn(column) && (value === 1 || value === '1')) return 'Yes';
    if (isBooleanColumn(column) && (value === 0 || value === '0')) return 'No';
    if (isDateColumn(column)) return formatAdminDate(value);
    if (typeof value === 'object') return Array.isArray(value) ? `${value.length} items` : 'View details';
    return String(value);
}
function heading(column) {
    const labels = { movie_title: 'movie', season_title: 'season', season_count: 'seasons', episode_count: 'episodes' };
    return labels[column] || column.replaceAll('_', ' ');
}
watch(key, () => { search.value = ''; parent.value = ''; episodeMovie.value = null; episodeSeason.value = null; load(true); });
watch(search, () => { episodeMovie.value = null; episodeSeason.value = null; clearTimeout(debounce); debounce = setTimeout(() => load(true), 350); });
watch(parent, () => { episodeMovie.value = null; episodeSeason.value = null; });
onMounted(load);
</script>

<template>
    <div v-if="resource" class="admin-page">
        <PageHeader eyebrow="Workspace" :title="resource.label" :description="`Search, review and manage ${resource.label.toLowerCase()} from one place.`">
            <RouterLink v-if="!resource.readOnly && !resource.noCreate" :to="`/manage/${key}/new`" class="admin-primary compact"><AdminIcon name="plus" /> Add {{ resource.singular }}</RouterLink>
        </PageHeader>
        <StatusPanel tone="success" :message="notice" /><StatusPanel tone="error" :message="error" />
        <section class="admin-list-toolbar">
            <label class="admin-search"><AdminIcon name="search" /><input v-model="search" :placeholder="resource.searchOnly ? 'Type at least 2 characters…' : `Search ${resource.label.toLowerCase()}…`"></label>
            <label v-if="resource.parentKey" class="admin-parent-filter"><span>{{ resource.parentKey.replace('_', ' ') }}</span><input v-model="parent" placeholder="Enter ID, or search by movie title" @keyup.enter="load(true)"><button @click="load(true)">Load</button></label>
            <button class="admin-icon-button" aria-label="Refresh" @click="load">↻</button>
        </section>
        <section v-if="key === 'episodes' && (episodeMovie || episodeSeason)" class="admin-drilldown-bar">
            <button class="admin-secondary compact" @click="backEpisodeDrilldown">← {{ episodeSeason ? 'Back to seasons' : 'Back to movies' }}</button>
            <span>{{ episodeSeason ? `Episodes in ${episodeSeason.title || episodeSeason.id}` : `Seasons in ${episodeMovie.movie_title || episodeMovie.title}` }}</span>
        </section>
        <section class="admin-table-card">
            <div v-if="loading" class="admin-loading">Loading {{ resource.label.toLowerCase() }}…</div>
            <div v-else-if="!items.length" class="admin-empty-state"><AdminIcon :name="resource.icon" /><h2>No {{ resource.label.toLowerCase() }} found</h2><p>{{ resource.parentKey && !parent && search.length < 2 ? `Enter a ${resource.parentKey.replace('_', ' ')} or search by movie title above.` : resource.searchOnly && search.length < 2 ? 'Start with a search above.' : 'Try another search or create the first record.' }}</p></div>
            <div v-else class="admin-table-scroll"><table><thead><tr><th v-for="column in columns" :key="column">{{ heading(column) }}</th><th>Actions</th></tr></thead><tbody><tr v-for="item in items" :key="item.id || item.uid"><td v-for="column in columns" :key="column"><span v-if="column.includes('status') || column.startsWith('is')" class="admin-pill">{{ display(item[column], column) }}</span><span v-else class="admin-cell-text" :title="display(item[column], column)">{{ display(item[column], column) }}</span></td><td><div class="admin-row-actions"><button v-if="rowActionLabel(item)" class="admin-row-text-action" @click="handleRowAction(item)">{{ rowActionLabel(item) }}</button><RouterLink v-if="key === 'polls'" :to="`/polls/${item.id}/insights`" title="Results"><AdminIcon name="chart" /></RouterLink><button v-if="key === 'users' && item.isACActive !== false && item.isACActive !== 0" title="Suspend user" @click="suspend(item)">⊘</button><RouterLink v-if="!resource.readOnly && !item.__kind" :to="`/manage/${key}/${item.id || item.uid}`"><AdminIcon name="edit" /></RouterLink><button v-if="!resource.readOnly && !item.__kind" @click="remove(item)"><AdminIcon name="trash" /></button></div></td></tr></tbody></table></div>
            <footer v-if="lastPage > 1" class="admin-pagination"><button :disabled="page <= 1" @click="page--; load()">Previous</button><span>Page {{ page }} / {{ lastPage }}</span><button :disabled="page >= lastPage" @click="page++; load()">Next</button></footer>
        </section>
    </div>
</template>

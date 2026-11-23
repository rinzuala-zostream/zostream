<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AdminIcon from '../components/AdminIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import MovieLookup from '../components/MovieLookup.vue';
import MovieEditDialog from '../components/MovieEditDialog.vue';
import { api } from '../lib/api';
import { confirmAction, notify } from '../lib/feedback';
import { formatAdminDate } from '../lib/adminDate';
import { resources } from '../lib/resources';

const route = useRoute(); const router = useRouter();
const search = ref(route.query.movie ? '' : String(route.query.q || '')); const movie = ref(null); const editing = ref(null);
const loading = ref(false); const deleting = ref(false); const error = ref(''); const linksError = ref('');
const linksLoading = ref(false); const linksReady = ref(false);
const links = ref({}); const failedImages = ref({});
let version = 0; let mounted = true;
function flag(value) { return value == null ? '—' : value === true || value === 1 || value === '1' ? 'Yes' : 'No'; }
const isSeries = computed(() => flag(movie.value?.isSeason) === 'Yes');
const access = computed(() => flag(movie.value?.isPayPerView) === 'Yes' ? 'Pay per view' : flag(movie.value?.isPremium) === 'Yes' ? 'Premium' : flag(movie.value?.isPremium) === 'No' ? 'Free' : '—');
const flags = computed(() => resources.movies.fields.filter(field => field.type === 'checkbox' && !['notification', 'refresh_latest'].includes(field.name)));
const artwork = computed(() => movie.value ? [
    { label: 'Cover', field: 'cover', url: movie.value.opt_cover_url || movie.value.cover_img },
    { label: 'Hero artwork', field: 'hero', url: movie.value.hero_img },
    { label: 'Title logo', field: 'title', url: movie.value.title_img },
] : []);
const playback = computed(() => [
    ['Primary video', links.value.url], ['HLS', links.value.hls_url], ['DASH', links.value.dash_url], ['Subtitle', links.value.subtitle],
    ...(links.value.trailers || (links.value.trailer ? [links.value.trailer] : [])).map((url, index) => [`Trailer ${index + 1}`, url]),
]);
function safeLink(value) {
    if (!value || typeof value !== 'string') return null;
    try { const url = new URL(value, window.location?.origin || 'https://zostream.invalid'); return ['http:', 'https:'].includes(url.protocol) ? value : null; }
    catch { return null; }
}
function price(value) { return value == null || value === '' ? '—' : `INR ${Number(value).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`; }
async function loadLinks(id, current) {
    linksLoading.value = true;
    try {
        const response = await api(`/admin/catalog/items/${encodeURIComponent(id)}/links?type=movie`);
        if (current === version) { links.value = (response?.data ?? response)?.links || {}; linksReady.value = true; }
    } catch (reason) {
        if (current === version) {
            if (reason.status === 404) linksReady.value = true;
            else linksError.value = `Playback links could not be loaded. ${reason.message}`;
        }
    } finally { if (current === version) linksLoading.value = false; }
}
async function load() {
    const id = String(route.query.movie || ''); const current = ++version;
    movie.value = null; editing.value = null; links.value = {}; failedImages.value = {};
    error.value = ''; linksError.value = ''; linksReady.value = false; linksLoading.value = false; loading.value = Boolean(id);
    if (!id) return;
    // Link loading must not hold the artwork/details screen behind a slow media request.
    void loadLinks(id, current);
    try {
        const response = await api(`/admin/catalog/items/${encodeURIComponent(id)}`);
        if (current !== version) return;
        movie.value = response?.data ?? response;
        if (route.query.edit === '1') editing.value = { ...movie.value };
    } catch (reason) { if (current === version) error.value = reason.message; }
    finally { if (current === version) loading.value = false; }
}
function selectMovie(value) { search.value = ''; if (String(route.query.movie) === String(value.id)) load(); else router.replace({ query: { movie: value.id } }); }
function clearMovie() { search.value = ''; router.replace({ query: {} }); }
function openEditor() { if (movie.value) router.replace({ query: { ...route.query, edit: '1' } }); }
async function closeEditor() { editing.value = null; const query = { ...route.query }; delete query.edit; await router.replace({ query }); }
async function saved() { await closeEditor(); load(); }
async function remove() {
    if (!movie.value || deleting.value) return;
    const id = movie.value.id;
    if (!(await confirmAction(`Delete “${movie.value.title || id}”? This cannot be undone.`))) return;
    deleting.value = true; error.value = '';
    try {
        await api(`/admin/catalog/items/${encodeURIComponent(id)}`, { method: 'DELETE' });
        notify('Movie deleted successfully.', 'success');
        if (mounted && String(route.query.movie) === String(id)) clearMovie();
    } catch (reason) { if (mounted && String(route.query.movie) === String(id)) error.value = reason.message; }
    finally { deleting.value = false; }
}
watch(() => route.query.movie, load, { immediate: true });
watch(() => route.query.edit, value => { editing.value = value === '1' && movie.value && !loading.value ? { ...movie.value } : null; });
onBeforeUnmount(() => { mounted = false; version++; });
</script>

<template>
    <div class="admin-page movies-page">
        <PageHeader eyebrow="Content" title="Movies" description="Find a movie or series to review its artwork, details and availability."><RouterLink to="/manage/movies/new" class="admin-primary compact"><AdminIcon name="plus" /> Add movie</RouterLink></PageHeader>
        <section class="admin-panel movie-search-panel" aria-label="Movie search"><label><span>Find a movie or series</span><MovieLookup v-model="search" @select="selectMovie" /></label><p>Search by title, movie ID, director or genre, then select a title to see its details.</p></section>
        <StatusPanel tone="error" :message="error" />
        <div v-if="loading" class="admin-panel admin-loading" role="status">Loading movie details…</div>
        <template v-else-if="movie">
            <section class="admin-panel movie-identity" aria-label="Selected movie details">
                <div class="movie-identity-body"><div class="movie-poster"><img v-if="safeLink(movie.opt_poster_url || movie.poster) && !failedImages.poster" :src="movie.opt_poster_url || movie.poster" :alt="`${movie.title} poster`" @error="failedImages.poster = true"><span v-else><AdminIcon name="film" /><small>{{ failedImages.poster ? 'Preview unavailable' : 'No poster' }}</small></span></div><div class="movie-heading"><p>{{ isSeries ? 'Series' : 'Movie' }}</p><h2>{{ movie.title || movie.id }}</h2><div class="movie-badges"><span class="admin-pill">{{ movie.status || 'Status not set' }}</span><span class="admin-pill">{{ access }}</span><span v-if="movie.age_rating" class="admin-pill">{{ movie.age_rating }}</span></div><p v-if="movie.director || movie.duration" class="movie-credit">{{ [movie.director, movie.duration].filter(Boolean).join(' · ') }}</p><p v-if="movie.genre" class="movie-credit">{{ Array.isArray(movie.genre) ? movie.genre.join(', ') : movie.genre }}</p><small class="movie-id">Movie ID: {{ movie.id }}</small></div><button type="button" class="admin-secondary compact" :disabled="deleting" @click="clearMovie">Clear selection</button></div>
                <footer class="movie-actions"><button type="button" class="admin-primary compact" :disabled="deleting" @click="openEditor"><AdminIcon name="edit" /> Edit movie</button><button type="button" class="admin-secondary compact" :disabled="deleting" @click="load">Refresh</button><RouterLink v-if="isSeries" :to="{ path: '/manage/seasons', query: { q: movie.title } }" class="admin-secondary compact">Manage seasons</RouterLink></footer>
            </section>
            <div class="movie-detail-grid">
                <section class="admin-panel movie-detail"><header><h2>Movie information</h2></header><div class="movie-description"><h3>Description</h3><p>{{ movie.description || 'No description added.' }}</p></div><dl><div><dt>Director</dt><dd>{{ movie.director || '—' }}</dd></div><div><dt>Genre</dt><dd>{{ Array.isArray(movie.genre) ? movie.genre.join(', ') : movie.genre || '—' }}</dd></div><div><dt>Duration</dt><dd>{{ movie.duration || '—' }}</dd></div><div><dt>Age rating</dt><dd>{{ movie.age_rating || '—' }}</dd></div><div><dt>Release date</dt><dd>{{ formatAdminDate(movie.release_on, false) }}</dd></div><div><dt>Added</dt><dd>{{ formatAdminDate(movie.create_date, false) }}</dd></div><div><dt>Last updated</dt><dd>{{ formatAdminDate(movie.updated_at) }}</dd></div><div><dt>Views</dt><dd>{{ movie.views == null ? '—' : Number(movie.views).toLocaleString('en-IN') }}</dd></div><div><dt>Internal ID</dt><dd>{{ movie.num ?? '—' }}</dd></div></dl></section>
                <section class="admin-panel movie-detail"><header><h2>Publishing & access</h2></header><dl><div><dt>Status</dt><dd>{{ movie.status || '—' }}</dd></div><div><dt>Access</dt><dd>{{ access }}</dd></div><div v-if="access === 'Pay per view'"><dt>PPV amount</dt><dd>{{ price(movie.ppv_amount) }}</dd></div><div><dt>Playback token</dt><dd>{{ movie.token ? 'Configured' : 'Not set' }}</dd></div><div v-for="field in flags" :key="field.name"><dt>{{ field.label }}</dt><dd>{{ flag(movie[field.name]) }}</dd></div></dl></section>
            </div>
            <section class="admin-panel movie-artwork"><header><h2>Artwork</h2></header><div class="movie-artwork-grid"><figure v-for="image in artwork" :key="image.field"><figcaption>{{ image.label }}</figcaption><div class="movie-artwork-preview" :class="{ 'is-logo': image.field === 'title' }"><img v-if="safeLink(image.url) && !failedImages[image.field]" :src="image.url" :alt="`${movie.title} ${image.label}`" loading="lazy" @error="failedImages[image.field] = true"><span v-else>{{ failedImages[image.field] ? 'Preview unavailable' : 'Not set' }}</span></div><a v-if="safeLink(image.url)" :href="image.url" target="_blank" rel="noopener noreferrer">Open artwork ↗</a></figure></div></section>
            <section class="admin-panel movie-playback"><details><summary>Playback & trailer links</summary><div><p v-if="linksLoading" role="status">Loading playback links…</p><StatusPanel tone="warning" :message="linksError" /><button v-if="linksError" type="button" class="admin-secondary compact" @click="load">Retry playback links</button><dl><div v-for="[label, url] in playback" :key="label"><dt>{{ label }}</dt><dd><a v-if="safeLink(url)" :href="url" target="_blank" rel="noopener noreferrer">{{ url }}</a><span v-else>{{ url || 'Not set' }}</span></dd></div></dl></div></details></section>
            <section class="admin-panel movie-management"><div><h2>Movie actions</h2><p>Manage this title and its catalog record.</p></div><button type="button" class="admin-danger compact" :disabled="deleting" @click="remove">{{ deleting ? 'Deleting…' : 'Delete movie' }}</button></section>
        </template>
        <MovieEditDialog v-if="editing" :movie="editing" :links="linksReady ? links : null" @saved="saved" @cancel="closeEditor" />
        <section v-if="!loading && !movie && error" class="movie-start"><button type="button" class="admin-secondary compact" @click="load">Retry</button></section>
        <section v-else-if="!loading && !movie" class="movie-start"><AdminIcon name="film" /><h2>Find a movie to get started</h2><p>Its artwork, movie information and access settings will appear here.</p></section>
    </div>
</template>

<style scoped>
.movie-search-panel{padding:20px 24px;margin-bottom:20px}.movie-search-panel>label{display:grid;gap:9px;max-width:760px;font-size:13px;font-weight:600}.movie-search-panel>p{margin:10px 0 0;color:var(--a-muted);font-size:12px;line-height:1.6}.movie-identity{overflow:hidden;margin-bottom:20px}.movie-identity-body{display:flex;align-items:start;gap:24px;padding:24px}.movie-poster{flex:none;width:140px;aspect-ratio:2/3;overflow:hidden;border:1px solid var(--a-line);border-radius:9px;background:var(--a-soft)}.movie-poster img{width:100%;height:100%;object-fit:cover}.movie-poster>span{display:flex;height:100%;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:var(--a-muted)}.movie-poster svg{width:32px;height:32px}.movie-poster small{font-size:11px}.movie-heading{flex:1;min-width:0}.movie-heading>p:first-child{margin:0 0 8px;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:var(--a-muted)}.movie-heading h2{margin:0;font-size:clamp(23px,3vw,32px);line-height:1.3;overflow-wrap:anywhere}.movie-badges{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0}.movie-credit{color:var(--a-muted);font-size:13px;line-height:1.6;margin:8px 0}.movie-id{display:block;margin-top:15px;color:var(--a-muted);font-size:12px;overflow-wrap:anywhere}.movie-actions{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:18px 24px;border-top:1px solid var(--a-line)}.movie-actions :is(a,button){display:inline-flex;align-items:center;gap:8px}
.movie-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;margin-bottom:20px}.movie-detail{min-width:0}.movie-detail header h2,.movie-artwork header h2{font-size:18px}.movie-detail dl,.movie-playback dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px;margin:0;padding:22px 24px}.movie-detail dt,.movie-playback dt{color:var(--a-muted);font-size:12px}.movie-detail dd,.movie-playback dd{margin:6px 0 0;font-size:14px;line-height:1.6;overflow-wrap:anywhere}.movie-description{padding:22px 24px 0}.movie-description h3{font-size:12px;margin:0;color:var(--a-muted);font-weight:500}.movie-description p{margin:8px 0 0;font-size:14px;line-height:1.8;white-space:pre-line;overflow-wrap:anywhere}
.movie-artwork{margin-bottom:20px}.movie-artwork-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;padding:22px 24px}.movie-artwork figure{margin:0;min-width:0}.movie-artwork figcaption{font-size:13px;font-weight:600;margin-bottom:10px}.movie-artwork-preview{display:grid;place-items:center;aspect-ratio:16/9;border:1px solid var(--a-line);border-radius:8px;background:var(--a-soft);overflow:hidden}.movie-artwork-preview img{width:100%;height:100%;object-fit:cover}.movie-artwork-preview.is-logo img{object-fit:contain;padding:16px}.movie-artwork-preview span{font-size:12px;color:var(--a-muted)}.movie-artwork a{display:inline-block;font-size:12px;margin-top:9px;color:var(--a-cyan)}.movie-playback{margin-bottom:20px}.movie-playback summary{padding:20px 24px;font-size:16px;font-weight:700;cursor:pointer}.movie-playback details>div{padding:0 24px 20px}.movie-playback dl{padding:0;grid-template-columns:1fr}.movie-playback a{color:var(--a-cyan)}.movie-management{display:flex;align-items:center;flex-wrap:wrap;gap:12px;padding:20px 24px}.movie-management>div{flex:1;min-width:180px}.movie-management h2{margin:0;font-size:18px}.movie-management p,.movie-start p{font-size:13px;color:var(--a-muted);line-height:1.7;margin:7px 0 0}.movie-start{text-align:center;padding:40px 20px}.movie-start>svg{display:block;width:34px;height:34px;margin:0 auto 14px;color:var(--a-muted)}.movie-start h2{margin:0;font-size:18px}
@media(max-width:800px){.movie-detail-grid{grid-template-columns:1fr}.movie-artwork-grid{grid-template-columns:1fr 1fr}.movie-identity-body{flex-wrap:wrap}.movie-identity-body>button{margin-left:auto}}@media(max-width:600px){.movie-search-panel,.movie-identity-body{padding:18px}.movie-identity-body{gap:16px}.movie-poster{width:90px}.movie-actions{padding:16px 18px}.movie-artwork-grid{grid-template-columns:1fr;padding:18px}.movie-detail dl{grid-template-columns:1fr;padding:18px}.movie-description{padding:18px 18px 0}}
</style>

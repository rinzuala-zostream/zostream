<script setup>
import { ref, watch } from 'vue';
import AdminIcon from '../components/AdminIcon.vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, queryString } from '../lib/api';

const query = ref('');
const results = ref([]);
const selectedMovie = ref(null);
const trailer = ref('');
const searching = ref(false);
const loadingTrailer = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
let searchTimer;
let skipNextSearch = false;

function responseData(response) {
    const value = response?.data ?? response;
    return value?.data ?? value ?? {};
}

async function searchMovies() {
    const term = query.value.trim();
    if (term.length < 2) {
        results.value = [];
        return;
    }

    searching.value = true;
    error.value = '';
    try {
        const response = responseData(await api(`/admin/catalog/items/search${queryString({ q: term, limit: 20 })}`));
        results.value = Array.isArray(response) ? response : [];
    } catch (reason) {
        error.value = reason.message;
    } finally {
        searching.value = false;
    }
}

async function selectMovie(movie) {
    selectedMovie.value = movie;
    results.value = [];
    const selectedLabel = movie.title || movie.id;
    if (query.value !== selectedLabel) {
        skipNextSearch = true;
        query.value = selectedLabel;
    }
    trailer.value = '';
    notice.value = '';
    error.value = '';
    loadingTrailer.value = true;

    try {
        const response = responseData(await api(`/admin/catalog/items/${encodeURIComponent(movie.id)}/links?type=movie`));
        trailer.value = response.links?.trailer || '';
    } catch (reason) {
        if (reason.status !== 404) error.value = reason.message;
    } finally {
        loadingTrailer.value = false;
    }
}

async function saveTrailer() {
    if (!selectedMovie.value) {
        error.value = 'Select a main movie first.';
        return;
    }

    saving.value = true;
    error.value = '';
    notice.value = '';
    try {
        await api(`/admin/catalog/items/${encodeURIComponent(selectedMovie.value.id)}/trailer`, {
            method: 'PUT',
            body: { trailer: trailer.value.trim() },
        });
        notice.value = `Trailer saved for ${selectedMovie.value.title}.`;
    } catch (reason) {
        error.value = reason.message;
    } finally {
        saving.value = false;
    }
}

watch(query, () => {
    clearTimeout(searchTimer);
    if (skipNextSearch) {
        skipNextSearch = false;
        return;
    }
    if (query.value !== selectedMovie.value?.title) {
        selectedMovie.value = null;
        trailer.value = '';
    }
    searchTimer = setTimeout(searchMovies, 300);
});
</script>

<template>
    <div class="admin-page trailer-page">
        <PageHeader eyebrow="Content" title="Trailers" description="Link one trailer directly to a main movie." />
        <StatusPanel tone="success" :message="notice" />
        <StatusPanel tone="error" :message="error" />

        <section class="admin-panel trailer-editor">
            <header>
                <span>01</span>
                <div><strong>Choose main movie</strong><small>Search by movie title, ID, director or genre.</small></div>
            </header>
            <label class="movie-search">
                <span>Movie</span>
                <div><AdminIcon name="search" /><input v-model="query" type="search" placeholder="Type at least 2 characters…" autocomplete="off"></div>
            </label>
            <div v-if="searching" class="trailer-hint">Searching movies…</div>
            <div v-else-if="results.length" class="movie-results">
                <button v-for="movie in results" :key="movie.id" type="button" @click="selectMovie(movie)">
                    <img v-if="movie.poster || movie.cover_img" :src="movie.poster || movie.cover_img" alt="">
                    <span><strong>{{ movie.title }}</strong><small>{{ movie.director || movie.genre || movie.id }} · {{ movie.status || 'No status' }}</small></span>
                </button>
            </div>
            <article v-if="selectedMovie" class="selected-movie">
                <img v-if="selectedMovie.poster || selectedMovie.cover_img" :src="selectedMovie.poster || selectedMovie.cover_img" alt="">
                <div><small>SELECTED MAIN MOVIE</small><strong>{{ selectedMovie.title }}</strong><span>{{ selectedMovie.director || selectedMovie.genre || selectedMovie.id }}</span></div>
            </article>
        </section>

        <form class="admin-panel trailer-editor" @submit.prevent="saveTrailer">
            <header>
                <span>02</span>
                <div><strong>Add trailer</strong><small>The movie detail API continues to return this value as <code>trailer</code>.</small></div>
            </header>
            <label>
                <span>Trailer URL</span>
                <input v-model="trailer" type="url" required :disabled="!selectedMovie || loadingTrailer" placeholder="https://cdn.example.com/trailer.m3u8">
            </label>
            <div v-if="loadingTrailer" class="trailer-hint">Loading current trailer…</div>
            <a v-else-if="trailer" class="trailer-preview" :href="trailer" target="_blank" rel="noopener">Open trailer URL ↗</a>
            <button class="admin-primary" type="submit" :disabled="!selectedMovie || !trailer.trim() || saving || loadingTrailer">
                <AdminIcon name="play" /> {{ saving ? 'Saving…' : 'Save trailer' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
.trailer-page{max-width:980px}.trailer-editor{margin-bottom:14px;padding:18px}.trailer-editor>header{display:flex;align-items:center;gap:12px;margin-bottom:16px}.trailer-editor>header>span{display:grid;width:38px;height:38px;flex:none;place-items:center;border-radius:10px;background:var(--a-cyan);color:#031013;font-weight:900}.trailer-editor>header strong,.trailer-editor>header small{display:block}.trailer-editor>header small{margin-top:3px;color:var(--a-muted);font-size:10px}.trailer-editor label{display:grid;gap:6px;color:var(--a-muted);font-size:10px;font-weight:750}.trailer-editor label>input,.movie-search>div{width:100%;min-height:44px;border:1px solid var(--a-line);border-radius:10px;background:rgba(4,12,15,.55);color:var(--a-text)}.trailer-editor label>input{padding:0 12px}.movie-search>div{display:flex;align-items:center;padding:0 12px}.movie-search svg{width:17px;flex:none}.movie-search input{width:100%;height:42px;padding:0 10px;border:0;background:transparent;color:var(--a-text);outline:0}.movie-results{display:grid;max-height:310px;margin-top:8px;overflow:auto;border:1px solid var(--a-line);border-radius:10px}.movie-results button{display:flex;align-items:center;gap:11px;padding:10px;border:0;border-bottom:1px solid var(--a-line);background:transparent;color:var(--a-text);text-align:left;cursor:pointer}.movie-results button:last-child{border-bottom:0}.movie-results button:hover{background:rgba(53,213,208,.08)}.movie-results img,.selected-movie img{width:46px;height:58px;border-radius:7px;object-fit:cover}.movie-results strong,.movie-results small{display:block}.movie-results small{margin-top:4px;color:var(--a-muted);font-size:9px}.selected-movie{display:flex;align-items:center;gap:12px;margin-top:12px;padding:12px;border:1px solid rgba(53,213,208,.3);border-radius:11px;background:rgba(53,213,208,.06)}.selected-movie div>*{display:block}.selected-movie small{color:var(--a-cyan);font-size:8px;font-weight:900;letter-spacing:.12em}.selected-movie strong{margin-top:4px}.selected-movie span{margin-top:3px;color:var(--a-muted);font-size:10px}.trailer-hint{margin-top:9px;color:var(--a-muted);font-size:10px}.trailer-preview{display:inline-flex;margin:10px 0;color:var(--a-cyan);font-size:10px;font-weight:800}.trailer-editor .admin-primary{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:14px}.trailer-editor .admin-primary svg{width:16px}.trailer-editor code{color:var(--a-cyan)}
</style>

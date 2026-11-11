<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import ThemeCardDecoration from '../components/ThemeCardDecoration.vue';
import { api } from '../lib/api';

const library = ref(null), detail = ref(null), form = ref(null), saved = ref('');
let refreshTimer, libraryRequest = 0;
const loading = ref(true), busy = ref(false), error = ref(''), notice = ref('');
const newKey = ref(''), newName = ref(''), template = ref('christmas'), platform = ref('web');
const preview = ref(null), audit = ref([]), auditPage = ref(1), auditLastPage = ref(1);
const dirty = computed(() => form.value && JSON.stringify(form.value) !== saved.value);
const selected = computed(() => detail.value?.theme);
const selectedDocument = computed(() => form.value?.document);
const activeTheme = computed(() => library.value?.themes.find(t => t.key === library.value.active.theme_key));
const activePreset = computed(() => activeTheme.value?.preset_key || (library.value?.active.theme_key === 'default' ? 'default' : null));
const baseTheme = computed(() => library.value?.themes.find(t => t.id === Number(library.value.selected_theme_id)));
const previewStyle = computed(() => {
    const tokens = preview.value?.preview?.tokens;
    return tokens ? { background: tokens.background, '--preview-primary': tokens.primary, '--preview-secondary': tokens.secondary, '--preview-accent': tokens.accent } : {};
});
const dateFormat = new Intl.DateTimeFormat('en-IN', { timeZone: 'Asia/Kolkata', dateStyle: 'medium', timeStyle: 'short' });
const displayDate = value => value ? `${dateFormat.format(new Date(value))} IST` : '—';
function toIst(value) {
    if (!value) return '';
    return new Date(new Date(value).getTime() + 330 * 60000).toISOString().slice(0, 16);
}
const fromIst = value => value ? `${value}:00+05:30` : '';
function hydrate(data) {
    detail.value = data;
    const document = structuredClone(data.theme.draft);
    document.assets = { ...document.assets };
    document.cards = { enabled: false, preset: 'none', opacity: 0.8, movie_cards: true, episode_cards: true, ...document.cards };
    document.schedules = document.schedules.map(s => ({ start_at: toIst(s.start_at), end_at: toIst(s.end_at) }));
    form.value = { name: data.theme.name, document };
    saved.value = JSON.stringify(form.value);
    preview.value = null;
}
async function refreshLibrary() {
    const request = ++libraryRequest;
    const response = await api(`/admin/themes?platform=${encodeURIComponent(platform.value)}`);
    if (request === libraryRequest) library.value = response;
}
async function loadAudit(page = auditPage.value) {
    const response = await api(`/admin/themes/audit?page=${page}`);
    audit.value = response.data; auditPage.value = response.current_page; auditLastPage.value = response.last_page;
}
async function run(action, message = '') {
    busy.value = true; error.value = ''; notice.value = '';
    try { await action(); notice.value = message; }
    catch (e) { error.value = e.message; }
    finally { busy.value = false; }
}
async function reloadSelected(id) { hydrate(await api(`/admin/themes/${id}`)); }
async function selectTheme(id) {
    if (dirty.value && !window.confirm('Discard unsaved changes?')) return;
    await run(() => reloadSelected(id));
}
async function ensurePreset(key) {
    const matching = library.value.themes.filter(t => t.preset_key === key);
    const existing = matching.find(t => t.id === Number(library.value.selected_theme_id))
        || matching.find(t => t.key === library.value.active.theme_key)
        || matching.sort((a, b) => String(b.updated_at || '').localeCompare(String(a.updated_at || '')) || b.id - a.id)[0];
    return existing || await api('/admin/themes', { method: 'POST', body: { preset_key: key, reuse_preset: true } });
}
async function editPreset(key, schedule = false) {
    if (dirty.value && !window.confirm('Discard unsaved changes?')) return;
    await run(async () => {
        const theme = await ensurePreset(key);
        await reloadSelected(theme.id); await refreshLibrary();
        if (schedule) {
            const suggested = library.value.theme_presets[key]?.document.schedules?.[0];
            selectedDocument.value.schedules.push(!selectedDocument.value.schedules.length && suggested
                ? { start_at: toIst(suggested.start_at), end_at: toIst(suggested.end_at) } : { start_at: '', end_at: '' });
        }
        await nextTick();
        window.document?.getElementById(schedule ? 'theme-schedules' : 'theme-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        if (schedule) window.document?.querySelector('#theme-schedules ~ .theme-schedule input')?.focus();
    }, schedule ? 'Set the dates and save changes to enable this schedule.' : 'Edit the theme, then save changes.');
}
async function saveRaw() {
    const id = selected.value.id;
    const document = JSON.parse(JSON.stringify(form.value.document));
    document.schedules = document.schedules.map(s => ({ start_at: fromIst(s.start_at), end_at: fromIst(s.end_at) }));
    await api(`/admin/themes/${id}/settings`, { method: 'PUT', body: { revision: selected.value.revision, name: form.value.name, document } });
    await reloadSelected(id);
}
async function saveSettings() {
    await run(async () => { await saveRaw(); await refreshLibrary(); await loadAudit(1); }, 'Changes saved. Active appearance and schedules are updated.');
}
async function usePreset(key) {
    const editingSame = selected.value?.preset_key === key;
    if (dirty.value && !editingSame && !window.confirm('Discard unsaved changes?')) return;
    await run(async () => {
        if (dirty.value && editingSame) await saveRaw();
        const theme = editingSame ? selected.value : await ensurePreset(key);
        await api(`/admin/themes/${theme.id}/use`, { method: 'POST', body: { revision: theme.revision } });
        await reloadSelected(theme.id); await refreshLibrary(); await loadAudit(1);
    }, 'Theme selected. Scheduled themes take precedence during their time windows.');
}
async function useSelected() {
    await run(async () => {
        if (dirty.value) await saveRaw();
        const id = selected.value.id;
        await api(`/admin/themes/${id}/use`, { method: 'POST', body: { revision: selected.value.revision } });
        await reloadSelected(id); await refreshLibrary(); await loadAudit(1);
    }, 'Theme selected. Scheduled themes take precedence during their time windows.');
}
async function createTheme() {
    await run(async () => {
        const created = await api('/admin/themes', { method: 'POST', body: { key: newKey.value, name: newName.value, document: library.value.templates[template.value] } });
        await reloadSelected(created.id); await refreshLibrary(); await loadAudit(1);
        newKey.value = ''; newName.value = '';
    }, 'Theme ready to edit, use or schedule.');
}
async function previewTheme() {
    await run(async () => {
        preview.value = await api(`/admin/themes/${selected.value.id}/preview`, { method: 'POST', body: { revision: selected.value.revision, platform: platform.value } });
    });
}
async function resumeSchedules() {
    await run(async () => {
        await api('/admin/theme-overrides', { method: 'DELETE', body: { reason: 'Resume schedules from Theme Manager' } });
        await refreshLibrary(); await loadAudit(1);
    }, 'Schedules resumed.');
}
async function uploadAsset(event, slot) {
    const image = event.target.files?.[0]; if (!image) return;
    await run(async () => {
        const body = new FormData();
        body.append('theme_id', selected.value.id); body.append('asset_key', slot); body.append('image', image);
        const asset = await api('/admin/themes/assets', { method: 'POST', body });
        detail.value.assets.unshift(asset); form.value.document.assets[slot] = asset.id;
    }, 'Image uploaded. Save changes to attach it.');
    event.target.value = '';
}
function selectAsset(slot, value) {
    if (value) form.value.document.assets[slot] = Number(value); else delete form.value.document.assets[slot];
}
watch(form, () => { preview.value = null; }, { deep: true });
watch(platform, async () => { preview.value = null; await run(refreshLibrary); });
onMounted(async () => {
    await run(async () => { await refreshLibrary(); await loadAudit(1); }); loading.value = false;
    refreshTimer = window.setInterval?.(() => {
        if (!busy.value && !window.document?.hidden) refreshLibrary().catch(() => {});
    }, 60000);
});
onUnmounted(() => { if (refreshTimer) window.clearInterval?.(refreshTimer); });
</script>

<template>
    <div class="admin-page theme-manager">
        <PageHeader eyebrow="App appearance" title="Seasonal themes" description="Use a theme, add schedules and edit its appearance across Zo Stream apps." />
        <StatusPanel tone="error" :message="error" />
        <StatusPanel tone="success" :message="notice" />
        <div v-if="loading" class="admin-loading">Loading themes…</div>
        <template v-else-if="library">
            <section class="admin-panel theme-summary">
                <div><small>RESOLVED THEME</small><h2>{{ activeTheme?.name || 'Default Zo Stream' }}</h2><p>Version {{ library.active.version }} · {{ platform }} · next transition {{ displayDate(library.active.next_refresh_at) }}</p></div>
                <label>Preview platform<select v-model="platform" :disabled="busy"><option v-for="p in library.platforms" :key="p">{{ p }}</option></select></label>
                <p v-if="library.override">Timed override until {{ displayDate(library.override.expires_at) }}. <button :disabled="busy" @click="resumeSchedules">Resume schedules</button></p>
                <p>Selected theme: <strong>{{ baseTheme?.name || 'Default Zo Stream' }}</strong>. Schedules take precedence during their time windows and return to this selection afterwards.</p>
                <p>Clients need the theme renderer before these settings appear in their apps. <button type="button" :disabled="busy" @click="run(refreshLibrary)">Refresh active theme</button></p>
            </section>
            <section class="admin-panel theme-stack">
                <h2>Ready-made theme presets</h2>
                <p>Use a preset immediately, add a schedule, or edit its colors and decorations. The preset currently in use is highlighted for the selected platform.</p>
                <div class="theme-preset-grid">
                    <article v-for="preset in library.theme_presets" :key="preset.key" class="theme-preset" :class="{ 'is-active': activePreset === preset.key }" :aria-label="`${preset.name}${activePreset === preset.key ? ', in use' : ''}`">
                        <span class="preset-art" :style="{ background: preset.document.tokens.background }"><span class="preset-card"><ThemeCardDecoration :config="preset.document.cards" :tokens="preset.document.tokens" /></span><span class="preset-swatches"><i v-for="color in preset.document.tokens" :key="color" :style="{ background: color }" /></span></span>
                        <strong>{{ preset.name }} <em v-if="activePreset === preset.key" class="theme-in-use">✓ In use</em></strong>
                        <small>{{ preset.description }}</small><small v-if="baseTheme?.preset_key === preset.key && activePreset !== preset.key">Selected between schedules</small>
                        <div class="theme-actions"><button :disabled="busy" class="theme-button" @click="usePreset(preset.key)">Use</button><button :disabled="busy" @click="editPreset(preset.key, true)">Add schedule</button><button :disabled="busy" @click="editPreset(preset.key)">Edit</button></div>
                    </article>
                </div>
            </section>
            <div class="theme-columns">
                <aside class="admin-panel theme-library">
                    <h2>Theme library</h2>
                    <button v-for="theme in library.themes" :key="theme.id" type="button" class="theme-choice" :class="{ selected: selected?.id === theme.id }" :disabled="busy" @click="selectTheme(theme.id)"><strong>{{ theme.name }}</strong><small>{{ theme.key }} · {{ library.active.theme_key === theme.key ? 'In use' : Number(library.selected_theme_id) === theme.id ? 'Selected' : theme.published_version_id ? 'Saved' : 'Available' }}</small></button>
                    <p v-if="!library.themes.length">No seasonal themes yet. Default Zo Stream is always available.</p>
                    <form class="theme-stack" @submit.prevent="createTheme">
                        <h3>Custom theme (optional)</h3>
                        <label>Name<input v-model="newName" required maxlength="120" placeholder="Christmas 2026"></label>
                        <label>Unique key<input v-model="newKey" required maxlength="80" pattern="[a-z][a-z0-9_]*" placeholder="christmas_2026"></label>
                        <label>Starting palette<select v-model="template"><option value="christmas">Christmas</option><option value="default">Default Zo Stream</option></select></label>
                        <button class="theme-button" :disabled="busy">Create theme</button>
                    </form>
                </aside>
                <section v-if="form" id="theme-editor" class="admin-panel theme-editor">
                    <header><h2>{{ selected.name }}</h2><small>Revision {{ selected.revision }} · {{ dirty ? 'Unsaved changes' : 'Saved settings' }}</small><button type="button" :disabled="busy" @click="selectTheme(selected.id)">Reload</button></header>
                    <form class="theme-stack" @submit.prevent="saveSettings">
                        <fieldset :disabled="busy">
                            <div class="theme-fields"><label>Name<input v-model="form.name" required maxlength="120"></label><label>Priority<input v-model.number="selectedDocument.priority" type="number" required min="0" max="1000"></label></div>
                            <h3>Colors</h3>
                            <div class="theme-fields"><label v-for="(_, key) in selectedDocument.tokens" :key="key">{{ key }}<span class="theme-color"><input v-model="selectedDocument.tokens[key]" type="color" :aria-label="`${key} color`"><input v-model="selectedDocument.tokens[key]" required pattern="#[0-9a-fA-F]{6}" :aria-label="`${key} hex`"></span></label></div>
                            <h3>Platforms</h3>
                            <div class="theme-checks"><label v-for="p in library.platforms" :key="p"><input v-model="selectedDocument.platforms" type="checkbox" :value="p">{{ p }}</label></div>
                            <h3>Movie &amp; episode card decorations</h3>
                            <div class="theme-checks"><label><input v-model="selectedDocument.cards.enabled" type="checkbox">Decorate cards</label><label><input v-model="selectedDocument.cards.movie_cards" type="checkbox">Movie cards</label><label><input v-model="selectedDocument.cards.episode_cards" type="checkbox">Episode cards</label></div>
                            <div class="theme-fields"><label>Card motif<select v-model="selectedDocument.cards.preset"><option v-for="motif in library.card_presets" :key="motif">{{ motif }}</option></select></label><label>Decoration opacity<input v-model.number="selectedDocument.cards.opacity" type="range" min="0" max="1" step="0.05"><span>{{ Math.round(selectedDocument.cards.opacity * 100) }}%</span></label></div>
                            <p>Static corner motifs and edge frames decorate cards without replacing their artwork. Movie and episode decorations can be enabled separately.</p>
                            <h3>Home decorations &amp; motion</h3>
                            <div class="theme-checks"><label><input v-model="selectedDocument.effects.snowfall.enabled" type="checkbox">Snowfall</label><label><input v-model="selectedDocument.effects.animation.enabled" type="checkbox">Animation</label></div>
                            <div class="theme-fields"><label>Particle limit<input v-model.number="selectedDocument.effects.snowfall.max_particles" type="number" required min="0" max="35"></label><label>Animation preset<select v-model="selectedDocument.effects.animation.preset"><option v-for="preset in library.presets" :key="preset">{{ preset }}</option></select></label></div>
                            <p>TV receives static decoration. Clients must respect Reduce Motion and power-saving preferences.</p>
                            <div v-for="slot in ['header', 'decoration']" :key="slot" class="theme-asset">
                                <label>{{ slot }} asset<select :value="selectedDocument.assets[slot] || ''" @change="selectAsset(slot, $event.target.value)"><option value="">None</option><option v-for="asset in detail.assets.filter(a => a.asset_key === slot)" :key="asset.id" :value="asset.id">#{{ asset.id }} · {{ asset.width }} × {{ asset.height }}</option></select></label>
                                <label>Upload PNG, JPEG or WebP (2 MB max)<input type="file" accept="image/png,image/jpeg,image/webp" @change="uploadAsset($event, slot)"></label>
                            </div>
                            <h3 id="theme-schedules">Schedule · Asia/Kolkata (IST)</h3>
                            <p>Start is inclusive, end is exclusive. Enter each year's dates explicitly. Save changes to enable schedules. With no schedule, Use keeps this theme selected until you choose another.</p>
                            <div v-for="(schedule, i) in selectedDocument.schedules" :key="i" class="theme-schedule"><label>Start<input v-model="schedule.start_at" type="datetime-local" required></label><label>End (exclusive)<input v-model="schedule.end_at" type="datetime-local" required></label><button type="button" :aria-label="`Remove schedule ${i + 1}`" @click="selectedDocument.schedules.splice(i, 1)">Remove</button></div>
                            <button type="button" :disabled="selectedDocument.schedules.length >= 30" @click="selectedDocument.schedules.push({ start_at: '', end_at: '' })">Add schedule</button>
                        </fieldset>
                        <div class="theme-actions"><button class="theme-button" :disabled="busy || !dirty">Save changes</button><button type="button" :disabled="busy" @click="useSelected">Use this theme</button><button type="button" :disabled="busy || dirty" @click="previewTheme">Preview</button></div>
                    </form>
                    <section v-if="preview" class="theme-stack">
                        <h3>{{ platform }} preview</h3>
                        <p v-if="!preview.platform_enabled">This platform is excluded. Live clients on this platform receive another eligible theme or Default.</p>
                        <div class="theme-preview" :class="{ 'preview-tv': ['android-tv', 'webos', 'tizen'].includes(platform), 'preview-mobile': ['android', 'ios'].includes(platform) }" :style="previewStyle">
                            <img v-if="preview.preview.assets.header" :src="preview.preview.assets.header.url" alt="Header decoration" class="preview-header-asset">
                            <header><b>ZO STREAM</b><span>Home</span></header><h3>Your next story</h3><div class="preview-posters"><span v-for="i in 3" :key="i"><ThemeCardDecoration :config="preview.preview.cards" :tokens="preview.preview.tokens" /></span></div><div class="preview-episodes"><span v-for="i in 2" :key="i"><ThemeCardDecoration :config="preview.preview.cards" :tokens="preview.preview.tokens" kind="episode" /><small>Episode {{ i }}</small></span></div><footer>Home · Movies · Series</footer>
                            <img v-if="preview.preview.assets.decoration" :src="preview.preview.assets.decoration.url" alt="" class="preview-decoration">
                        </div>
                        <p>Palette, assets and card decoration illustration. Check contrast, focus and artwork visibility. Motion and exact layouts require device QA.</p>
                        <p>Snowfall: {{ preview.preview.effects.snowfall.enabled ? `on (${preview.preview.effects.snowfall.max_particles} particles)` : 'off' }} · Animation: {{ preview.preview.effects.animation.enabled ? preview.preview.effects.animation.preset : 'off' }}</p>
                    </section>
                    <details v-if="detail.versions.length"><summary>Saved version history</summary><ul><li v-for="version in detail.versions" :key="version.id">v{{ version.number }} · {{ displayDate(version.published_at) }}</li></ul></details>
                </section>
                <section v-else class="admin-panel"><h2>Choose Use, Add schedule or Edit</h2><p>Default Zo Stream is always available.</p></section>
            </div>
            <section class="admin-panel theme-stack"><h2>Audit log</h2><div class="theme-audit"><table><thead><tr><th>Time · IST</th><th>Actor</th><th>Action</th><th>Theme</th><th>Reason</th></tr></thead><tbody><tr v-for="entry in audit" :key="entry.id"><td>{{ displayDate(entry.created_at) }}</td><td>{{ entry.actor_id }}</td><td>{{ entry.action }}</td><td>{{ entry.theme_id || 'Global' }}</td><td>{{ entry.reason || '—' }}</td></tr></tbody></table></div><div class="theme-actions"><button :disabled="busy || auditPage <= 1" @click="run(() => loadAudit(auditPage - 1))">Previous</button><span>{{ auditPage }} / {{ auditLastPage }}</span><button :disabled="busy || auditPage >= auditLastPage" @click="run(() => loadAudit(auditPage + 1))">Next</button></div></section>
        </template>
        <button v-else :disabled="busy" @click="run(async () => { await refreshLibrary(); await loadAudit(1); })">Retry loading themes</button>
    </div>
</template>

<style scoped>
.theme-manager{display:grid;gap:20px}.theme-manager .admin-panel{padding:24px;min-width:0}.theme-manager h2{margin:0 0 12px;font-size:20px}.theme-manager h3{margin:20px 0 10px;font-size:15px}.theme-manager p{font-size:13px;line-height:1.65;opacity:.8}.theme-manager small{font-size:12px;opacity:.8}.theme-manager label{display:grid;gap:7px;font-size:13px}.theme-manager input:not([type=checkbox]):not([type=color]),.theme-manager select{width:100%;min-width:0;padding:10px;border:1px solid var(--a-line);border-radius:8px;background:var(--a-bg,#101820);color:inherit;font:inherit;color-scheme:dark}.theme-manager button{padding:10px 14px;border:1px solid var(--a-line);border-radius:8px;color:inherit;background:transparent;cursor:pointer;font:inherit;font-size:13px}.theme-manager button:disabled{opacity:.45;cursor:not-allowed}.theme-manager .theme-button{background:#206b60;color:white;border-color:#338a7a}.theme-manager input:focus-visible,.theme-manager select:focus-visible,.theme-manager button:focus-visible{outline:2px solid #71ddc6;outline-offset:3px}.theme-columns{display:grid;grid-template-columns:280px minmax(0,1fr);gap:20px;align-items:start}.theme-stack{display:grid;gap:16px}.theme-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:end}.theme-library .theme-choice{display:grid;gap:6px;width:100%;margin-bottom:10px;text-align:left}.theme-choice.selected{border-color:#71ddc6;background:#206b6028}.theme-editor header{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.theme-editor header h2{margin:0}.theme-editor fieldset{border:0;padding:0;margin:0;min-width:0}.theme-checks,.theme-actions{display:flex;gap:14px;align-items:center;flex-wrap:wrap}.theme-checks label,.theme-manager .theme-inline{display:flex;align-items:center;gap:8px}.theme-color{display:flex;gap:8px;align-items:center}.theme-color input[type=color]{height:40px;width:45px;flex-shrink:0;padding:0;border:0;background:transparent}.theme-asset{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}.theme-schedule{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;margin-bottom:16px}.theme-actions{margin-top:16px}.theme-preview{position:relative;padding:24px;border:1px solid var(--preview-secondary);border-radius:14px;color:#fff;overflow:hidden;max-width:780px;isolation:isolate}.theme-preview.preview-mobile{max-width:340px}.theme-preview.preview-tv{aspect-ratio:16/9}.theme-preview header{display:flex;justify-content:space-between;color:var(--preview-accent);position:relative;z-index:1}.theme-preview header span{color:var(--preview-primary)}.theme-preview h3,.theme-preview footer,.preview-posters{position:relative;z-index:1}.preview-posters{display:flex;gap:12px;margin:24px 0}.preview-posters>span{position:relative;display:block;width:30%;aspect-ratio:2/3;background:#30343b;border-radius:8px}.theme-preview footer{color:var(--preview-accent);border-top:2px solid var(--preview-primary);padding-top:12px}.preview-header-asset{position:absolute;inset:0 0 auto;width:100%;max-height:90px;object-fit:contain;pointer-events:none}.preview-decoration{position:absolute;bottom:0;right:0;max-width:25%;max-height:40%;pointer-events:none}.theme-audit{overflow:auto}.theme-audit table{width:100%;font-size:12px;border-collapse:collapse}.theme-audit td,.theme-audit th{padding:12px;text-align:left;border-bottom:1px solid var(--a-line);vertical-align:top}.theme-manager pre{overflow:auto;max-height:400px;font-size:12px}.theme-summary{display:grid;gap:12px;grid-template-columns:1fr 220px}.theme-summary>p{grid-column:1/-1;margin:0}:global(.is-light) .theme-manager input:not([type=checkbox]):not([type=color]),:global(.is-light) .theme-manager select{background:#fff;color:#10282d;color-scheme:light}@media(max-width:1050px){.theme-columns{grid-template-columns:1fr}.theme-library{display:grid;gap:12px}}@media(max-width:650px){.theme-fields,.theme-asset,.theme-schedule,.theme-summary{grid-template-columns:1fr}.theme-summary>p{grid-column:auto}.theme-manager .admin-panel{padding:16px}}
.theme-preset-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}.theme-manager .theme-preset{border:1px solid var(--a-line);border-radius:12px;display:grid;gap:10px;text-align:left;padding:12px}.theme-preset small{line-height:1.5}.theme-preset b{font-size:12px;color:#71ddc6}.preset-art{display:flex;align-items:center;gap:16px;padding:14px;border-radius:8px}.preset-card{display:block;position:relative;background:#30343b;width:64px;height:84px;border-radius:8px}.preset-swatches{display:grid;grid-template-columns:repeat(2,18px);gap:6px}.preset-swatches i{width:18px;height:18px;border-radius:50%}.preview-episodes{display:flex;gap:12px;margin:0 0 18px}.preview-episodes>span{position:relative;display:block;width:46%;aspect-ratio:16/9;background:#30343b;border-radius:8px}.preview-episodes small{position:absolute;bottom:6px;left:8px;font-size:10px}
.theme-manager .theme-preset.is-active{border:2px solid #71ddc6;background:#206b6028;box-shadow:0 0 0 1px #71ddc633}.theme-in-use{display:inline-block;font-size:11px;font-style:normal;padding:3px 6px;border-radius:6px;background:#206b60;color:#fff}.theme-preset .theme-actions{gap:6px;margin:0}.theme-preset .theme-actions button{padding:7px 9px;font-size:12px}#theme-editor,#theme-schedules{scroll-margin-top:24px}
</style>

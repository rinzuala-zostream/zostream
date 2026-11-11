<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';

const library = ref(null);
const detail = ref(null);
const form = ref(null);
const saved = ref('');
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const newKey = ref('');
const newName = ref('');
const template = ref('christmas');
const platform = ref('web');
const preview = ref(null);
const approved = ref(false);
const reason = ref('');
const overrideTheme = ref('');
const overrideExpiry = ref('');
const audit = ref([]);
const auditPage = ref(1);
const auditLastPage = ref(1);
const rollbackVersion = ref('');
const dirty = computed(() => form.value && JSON.stringify(form.value) !== saved.value);
const selected = computed(() => detail.value?.theme);
const selectedDocument = computed(() => form.value?.document);
const previewStyle = computed(() => {
    const tokens = preview.value?.preview?.tokens;
    return tokens ? { background: tokens.background, '--preview-primary': tokens.primary, '--preview-secondary': tokens.secondary, '--preview-accent': tokens.accent } : {};
});
const dateFormat = new Intl.DateTimeFormat('en-IN', { timeZone: 'Asia/Kolkata', dateStyle: 'medium', timeStyle: 'short' });
const displayDate = value => value ? `${dateFormat.format(new Date(value))} IST` : '—';
// datetime-local fields deliberately use IST, independent of the administrator's browser timezone.
function toIst(value) {
    if (!value) return '';
    return new Date(new Date(value).getTime() + 330 * 60000).toISOString().slice(0, 16);
}
const fromIst = value => value ? `${value}:00+05:30` : '';
function hydrate(data) {
    detail.value = data;
    const document = structuredClone(data.theme.draft);
    document.assets = { ...document.assets };
    document.schedules = document.schedules.map(s => ({ start_at: toIst(s.start_at), end_at: toIst(s.end_at) }));
    form.value = { name: data.theme.name, document };
    saved.value = JSON.stringify(form.value);
    preview.value = null;
    approved.value = false;
    rollbackVersion.value = '';
}
async function refreshLibrary() {
    library.value = await api(`/admin/themes?platform=${encodeURIComponent(platform.value)}`);
}
async function loadAudit(page = auditPage.value) {
    const response = await api(`/admin/themes/audit?page=${page}`);
    audit.value = response.data;
    auditPage.value = response.current_page;
    auditLastPage.value = response.last_page;
}
async function run(action, message = '') {
    busy.value = true;
    error.value = '';
    notice.value = '';
    try { await action(); notice.value = message; }
    catch (e) { error.value = e.message; }
    finally { busy.value = false; }
}
async function selectTheme(id) {
    if (dirty.value && !window.confirm('Discard unsaved theme changes?')) return;
    await run(async () => hydrate(await api(`/admin/themes/${id}`)));
}
async function createTheme() {
    await run(async () => {
        const data = await api('/admin/themes', { method: 'POST', body: { key: newKey.value, name: newName.value, document: library.value.templates[template.value] } });
        await refreshLibrary();
        hydrate(await api(`/admin/themes/${data.id}`));
        newKey.value = ''; newName.value = '';
        await loadAudit(1);
    }, 'Theme draft created. Add a schedule before publishing for automatic activation.');
}
async function saveDraft() {
    await run(async () => {
        const document = JSON.parse(JSON.stringify(form.value.document));
        document.schedules = document.schedules.map(s => ({ start_at: fromIst(s.start_at), end_at: fromIst(s.end_at) }));
        await api(`/admin/themes/${selected.value.id}`, { method: 'PATCH', body: { revision: selected.value.revision, name: form.value.name, document } });
        hydrate(await api(`/admin/themes/${selected.value.id}`));
        await refreshLibrary();
        await loadAudit(1);
    }, 'Draft saved. Preview and approve it before publishing.');
}
async function previewDraft() {
    await run(async () => {
        preview.value = await api(`/admin/themes/${selected.value.id}/preview`, { method: 'POST', body: { revision: selected.value.revision, platform: platform.value } });
        approved.value = false;
    });
}
async function mutateTheme(action) {
    if (!reason.value.trim()) { error.value = 'Enter a reason for this change.'; return; }
    const isRollback = action === 'rollback';
    if (!window.confirm(isRollback ? 'Publish this historical version, including its original schedule? Unsaved changes will be replaced.' : `${action === 'publish' ? 'Publish this approved draft' : 'Unpublish this theme'}?`)) return;
    await run(async () => {
        await api(`/admin/themes/${selected.value.id}/${action}`, { method: 'POST', body: {
            revision: selected.value.revision, reason: reason.value,
            ...(action === 'publish' ? { preview_token: preview.value.preview_token, approved: approved.value } : {}),
            ...(isRollback ? { version_id: Number(rollbackVersion.value), approved: true } : {}),
        } });
        hydrate(await api(`/admin/themes/${selected.value.id}`));
        await refreshLibrary(); await loadAudit(1);
    }, action === 'unpublish' ? 'Theme unpublished.' : 'Version published. Schedules and any active override determine what clients receive.');
}
async function setOverride(clear = false) {
    if (!reason.value.trim()) { error.value = 'Enter a reason for this change.'; return; }
    if (!clear && !overrideExpiry.value) { error.value = 'Choose an override expiry in IST.'; return; }
    if (!window.confirm(clear ? 'Resume automatic theme schedules?' : 'Apply this override until the selected expiry?')) return;
    await run(async () => {
        await api('/admin/theme-overrides', { method: clear ? 'DELETE' : 'POST', body: clear ? { reason: reason.value } : {
            theme_id: overrideTheme.value ? Number(overrideTheme.value) : null, expires_at: fromIst(overrideExpiry.value), reason: reason.value,
        } });
        await refreshLibrary(); await loadAudit(1);
    }, clear ? 'Automatic schedules resumed.' : 'Override applied.');
}
async function uploadAsset(event, slot) {
    const image = event.target.files?.[0];
    if (!image) return;
    await run(async () => {
        const body = new FormData();
        body.append('theme_id', selected.value.id); body.append('asset_key', slot); body.append('image', image);
        const asset = await api('/admin/themes/assets', { method: 'POST', body });
        detail.value.assets.unshift(asset);
        form.value.document.assets[slot] = asset.id;
    }, 'Asset uploaded. Save the draft to attach it.');
    event.target.value = '';
}
function selectAsset(slot, value) {
    if (value) form.value.document.assets[slot] = Number(value);
    else delete form.value.document.assets[slot];
}
watch(form, () => { preview.value = null; approved.value = false; }, { deep: true });
watch(platform, async () => {
    preview.value = null; approved.value = false;
    await run(refreshLibrary);
});
onMounted(async () => {
    overrideExpiry.value = toIst(new Date(Date.now() + 3600000).toISOString());
    await run(async () => { await refreshLibrary(); await loadAudit(1); });
    loading.value = false;
});
</script>

<template>
    <div class="admin-page theme-manager">
        <PageHeader eyebrow="App appearance" title="Seasonal themes" description="Create, preview and schedule Home appearance across Zo Stream apps." />
        <StatusPanel tone="error" :message="error" />
        <StatusPanel tone="success" :message="notice" />
        <div v-if="loading" class="admin-loading">Loading themes…</div>
        <template v-else-if="library">
            <section class="admin-panel theme-summary">
                <div><small>RESOLVED THEME</small><h2>{{ library.active.theme_key }}</h2><p>Version {{ library.active.version }} · {{ platform }} · next transition {{ displayDate(library.active.next_refresh_at) }}</p></div>
                <label>Preview platform<select v-model="platform" :disabled="busy"><option v-for="p in library.platforms" :key="p">{{ p }}</option></select></label>
                <p v-if="library.override">Override active until {{ displayDate(library.override.expires_at) }}. {{ library.override.version_id ? 'Pinned published version.' : 'Emergency Default.' }}</p>
                <p>Clients need the theme renderer before these settings appear in their apps. <button type="button" :disabled="busy" @click="run(refreshLibrary)">Refresh active theme</button></p>
            </section>
            <div class="theme-columns">
                <aside class="admin-panel theme-library">
                    <h2>Theme library</h2>
                    <button v-for="theme in library.themes" :key="theme.id" type="button" class="theme-choice" :class="{ selected: selected?.id === theme.id }" :disabled="busy" @click="selectTheme(theme.id)"><strong>{{ theme.name }}</strong><small>{{ theme.key }} · {{ theme.published_version_id ? 'Published' : 'Draft' }}</small></button>
                    <p v-if="!library.themes.length">No seasonal themes yet. Default Zo Stream is always available.</p>
                    <form class="theme-stack" @submit.prevent="createTheme">
                        <h3>Create a draft</h3>
                        <label>Name<input v-model="newName" required maxlength="120" placeholder="Christmas 2026"></label>
                        <label>Unique key<input v-model="newKey" required maxlength="80" pattern="[a-z][a-z0-9_]*" placeholder="christmas_2026"></label>
                        <label>Starting palette<select v-model="template"><option value="christmas">Christmas</option><option value="default">Default Zo Stream</option></select></label>
                        <button class="theme-button" :disabled="busy">Create draft</button>
                    </form>
                </aside>
                <section v-if="form" class="admin-panel theme-editor">
                    <header><h2>{{ selected.name }}</h2><small>Revision {{ selected.revision }} · {{ dirty ? 'Unsaved changes' : 'Saved draft' }}</small><button type="button" :disabled="busy" @click="selectTheme(selected.id)">Reload</button></header>
                    <form class="theme-stack" @submit.prevent="saveDraft">
                        <fieldset :disabled="busy">
                            <div class="theme-fields"><label>Name<input v-model="form.name" required maxlength="120"></label><label>Priority<input v-model.number="selectedDocument.priority" type="number" required min="0" max="1000"></label></div>
                            <h3>Colors</h3>
                            <div class="theme-fields"><label v-for="(_, key) in selectedDocument.tokens" :key="key">{{ key }}<span class="theme-color"><input v-model="selectedDocument.tokens[key]" type="color" :aria-label="`${key} color`"><input v-model="selectedDocument.tokens[key]" required pattern="#[0-9a-fA-F]{6}" :aria-label="`${key} hex`"></span></label></div>
                            <h3>Platforms</h3>
                            <div class="theme-checks"><label v-for="p in library.platforms" :key="p"><input v-model="selectedDocument.platforms" type="checkbox" :value="p">{{ p }}</label></div>
                            <h3>Home decorations &amp; motion</h3>
                            <div class="theme-checks"><label><input v-model="selectedDocument.effects.snowfall.enabled" type="checkbox">Snowfall</label><label><input v-model="selectedDocument.effects.animation.enabled" type="checkbox">Animation</label></div>
                            <div class="theme-fields"><label>Particle limit<input v-model.number="selectedDocument.effects.snowfall.max_particles" type="number" required min="0" max="35"></label><label>Animation preset<select v-model="selectedDocument.effects.animation.preset"><option v-for="preset in library.presets" :key="preset">{{ preset }}</option></select></label></div>
                            <p>TV receives static decoration. Clients must respect Reduce Motion and power-saving preferences.</p>
                            <div v-for="slot in ['header', 'decoration']" :key="slot" class="theme-asset">
                                <label>{{ slot }} asset<select :value="selectedDocument.assets[slot] || ''" @change="selectAsset(slot, $event.target.value)"><option value="">None</option><option v-for="asset in detail.assets.filter(a => a.asset_key === slot)" :key="asset.id" :value="asset.id">#{{ asset.id }} · {{ asset.width }} × {{ asset.height }}</option></select></label>
                                <label>Upload PNG, JPEG or WebP (2 MB max)<input type="file" accept="image/png,image/jpeg,image/webp" @change="uploadAsset($event, slot)"></label>
                            </div>
                            <h3>Schedule · Asia/Kolkata (IST)</h3>
                            <p>Start is inclusive, end is exclusive. Enter each year's dates explicitly. With no schedule, this theme can only run through a manual override.</p>
                            <div v-for="(schedule, i) in selectedDocument.schedules" :key="i" class="theme-schedule"><label>Start<input v-model="schedule.start_at" type="datetime-local" required></label><label>End (exclusive)<input v-model="schedule.end_at" type="datetime-local" required></label><button type="button" :aria-label="`Remove schedule ${i + 1}`" @click="selectedDocument.schedules.splice(i, 1)">Remove</button></div>
                            <button type="button" :disabled="selectedDocument.schedules.length >= 30" @click="selectedDocument.schedules.push({ start_at: '', end_at: '' })">Add schedule</button>
                        </fieldset>
                        <div class="theme-actions"><button class="theme-button" :disabled="busy || !dirty">Save draft</button><button type="button" :disabled="busy || dirty" @click="previewDraft">Preview saved draft</button></div>
                    </form>
                    <section v-if="preview" class="theme-stack">
                        <h3>{{ platform }} preview</h3>
                        <p v-if="!preview.platform_enabled">This platform is excluded. Live clients on this platform receive another eligible theme or Default.</p>
                        <div class="theme-preview" :class="{ 'preview-tv': ['android-tv', 'webos', 'tizen'].includes(platform), 'preview-mobile': ['android', 'ios'].includes(platform) }" :style="previewStyle">
                            <img v-if="preview.preview.assets.header" :src="preview.preview.assets.header.url" alt="Header decoration" class="preview-header-asset">
                            <header><b>ZO STREAM</b><span>Home</span></header><h3>Your next story</h3><div class="preview-posters"><span /><span /><span /></div><footer>Home · Movies · Series</footer>
                            <img v-if="preview.preview.assets.decoration" :src="preview.preview.assets.decoration.url" alt="" class="preview-decoration">
                        </div>
                        <p>Palette and asset placement illustration. Check contrast, focus and artwork visibility. Motion and exact layouts require device QA.</p>
                        <p>Snowfall: {{ preview.preview.effects.snowfall.enabled ? `on (${preview.preview.effects.snowfall.max_particles} particles)` : 'off' }} · Animation: {{ preview.preview.effects.animation.enabled ? preview.preview.effects.animation.preset : 'off' }}</p>
                        <label class="theme-inline"><input v-model="approved" type="checkbox">I reviewed this saved draft for publication.</label>
                    </section>
                    <div class="theme-actions"><button type="button" class="theme-button" :disabled="busy || dirty || !preview || !approved || !reason.trim()" @click="mutateTheme('publish')">Publish approved draft</button><button type="button" :disabled="busy || !selected.published_version_id || !reason.trim()" @click="mutateTheme('unpublish')">Unpublish</button></div>
                    <h3>Version history &amp; rollback</h3>
                    <p>Rollback creates a new published version with the selected version's colors, assets, platforms and original schedules.</p>
                    <div class="theme-fields"><label>Historical version<select v-model="rollbackVersion"><option value="">Select a version</option><option v-for="version in detail.versions" :key="version.id" :value="version.id">v{{ version.number }} · {{ displayDate(version.published_at) }}</option></select></label><button type="button" :disabled="busy || !rollbackVersion || !reason.trim()" @click="mutateTheme('rollback')">Publish historical version</button></div>
                    <details v-if="rollbackVersion"><summary>Review historical configuration</summary><pre>{{ JSON.stringify(detail.versions.find(v => v.id === Number(rollbackVersion))?.document, null, 2) }}</pre></details>
                </section>
                <section v-else class="admin-panel"><h2>Select or create a theme</h2><p>Default Zo Stream is bundled as the safe fallback.</p></section>
            </div>
            <section class="admin-panel theme-stack">
                <h2>Publication reason &amp; emergency controls</h2>
                <label>Reason for publish, rollback or override<input v-model="reason" maxlength="500" placeholder="Explain this operational change"></label>
                <div class="theme-fields"><label>Manual theme<select v-model="overrideTheme"><option value="">Restore Default Zo Stream</option><option v-for="theme in library.themes.filter(t => t.published_version_id)" :key="theme.id" :value="theme.id">{{ theme.name }}</option></select></label><label>Override expires · IST<input v-model="overrideExpiry" type="datetime-local" required></label></div>
                <p>Overrides take effect immediately, pin the current published version and expire within 31 days. Automatic schedules resume at expiry.</p>
                <div class="theme-actions"><button class="theme-button" type="button" :disabled="busy || !reason.trim() || !overrideExpiry" @click="setOverride()">{{ overrideTheme ? 'Apply manual theme' : 'Restore Default' }}</button><button type="button" :disabled="busy || !library.override || !reason.trim()" @click="setOverride(true)">Resume automatic schedules</button></div>
            </section>
            <section class="admin-panel theme-stack"><h2>Audit log</h2><div class="theme-audit"><table><thead><tr><th>Time · IST</th><th>Actor</th><th>Action</th><th>Theme</th><th>Reason</th></tr></thead><tbody><tr v-for="entry in audit" :key="entry.id"><td>{{ displayDate(entry.created_at) }}</td><td>{{ entry.actor_id }}</td><td>{{ entry.action }}</td><td>{{ entry.theme_id || 'Global' }}</td><td>{{ entry.reason || '—' }}</td></tr></tbody></table></div><div class="theme-actions"><button :disabled="busy || auditPage <= 1" @click="run(() => loadAudit(auditPage - 1))">Previous</button><span>{{ auditPage }} / {{ auditLastPage }}</span><button :disabled="busy || auditPage >= auditLastPage" @click="run(() => loadAudit(auditPage + 1))">Next</button></div></section>
        </template>
        <button v-else :disabled="busy" @click="run(async () => { await refreshLibrary(); await loadAudit(1); })">Retry loading themes</button>
    </div>
</template>

<style scoped>
.theme-manager{display:grid;gap:20px}.theme-manager .admin-panel{padding:24px;min-width:0}.theme-manager h2{margin:0 0 12px;font-size:20px}.theme-manager h3{margin:20px 0 10px;font-size:15px}.theme-manager p{font-size:13px;line-height:1.65;opacity:.8}.theme-manager small{font-size:12px;opacity:.8}.theme-manager label{display:grid;gap:7px;font-size:13px}.theme-manager input:not([type=checkbox]):not([type=color]),.theme-manager select{width:100%;min-width:0;padding:10px;border:1px solid var(--a-line);border-radius:8px;background:var(--a-bg,#101820);color:inherit;font:inherit;color-scheme:dark}.theme-manager button{padding:10px 14px;border:1px solid var(--a-line);border-radius:8px;color:inherit;background:transparent;cursor:pointer;font:inherit;font-size:13px}.theme-manager button:disabled{opacity:.45;cursor:not-allowed}.theme-manager .theme-button{background:#206b60;color:white;border-color:#338a7a}.theme-manager input:focus-visible,.theme-manager select:focus-visible,.theme-manager button:focus-visible{outline:2px solid #71ddc6;outline-offset:3px}.theme-columns{display:grid;grid-template-columns:280px minmax(0,1fr);gap:20px;align-items:start}.theme-stack{display:grid;gap:16px}.theme-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:end}.theme-library .theme-choice{display:grid;gap:6px;width:100%;margin-bottom:10px;text-align:left}.theme-choice.selected{border-color:#71ddc6;background:#206b6028}.theme-editor header{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.theme-editor header h2{margin:0}.theme-editor fieldset{border:0;padding:0;margin:0;min-width:0}.theme-checks,.theme-actions{display:flex;gap:14px;align-items:center;flex-wrap:wrap}.theme-checks label,.theme-manager .theme-inline{display:flex;align-items:center;gap:8px}.theme-color{display:flex;gap:8px;align-items:center}.theme-color input[type=color]{height:40px;width:45px;flex-shrink:0;padding:0;border:0;background:transparent}.theme-asset{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:16px}.theme-schedule{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;margin-bottom:16px}.theme-actions{margin-top:16px}.theme-preview{position:relative;padding:24px;border:1px solid var(--preview-secondary);border-radius:14px;color:#fff;overflow:hidden;max-width:780px;isolation:isolate}.theme-preview.preview-mobile{max-width:340px}.theme-preview.preview-tv{aspect-ratio:16/9}.theme-preview header{display:flex;justify-content:space-between;color:var(--preview-accent);position:relative;z-index:1}.theme-preview header span{color:var(--preview-primary)}.theme-preview h3,.theme-preview footer,.preview-posters{position:relative;z-index:1}.preview-posters{display:flex;gap:12px;margin:24px 0}.preview-posters span{display:block;width:30%;aspect-ratio:2/3;background:#30343b;border-radius:8px}.theme-preview footer{color:var(--preview-accent);border-top:2px solid var(--preview-primary);padding-top:12px}.preview-header-asset{position:absolute;inset:0 0 auto;width:100%;max-height:90px;object-fit:contain;pointer-events:none}.preview-decoration{position:absolute;bottom:0;right:0;max-width:25%;max-height:40%;pointer-events:none}.theme-audit{overflow:auto}.theme-audit table{width:100%;font-size:12px;border-collapse:collapse}.theme-audit td,.theme-audit th{padding:12px;text-align:left;border-bottom:1px solid var(--a-line);vertical-align:top}.theme-manager pre{overflow:auto;max-height:400px;font-size:12px}.theme-summary{display:grid;gap:12px;grid-template-columns:1fr 220px}.theme-summary>p{grid-column:1/-1;margin:0}:global(.is-light) .theme-manager input:not([type=checkbox]):not([type=color]),:global(.is-light) .theme-manager select{background:#fff;color:#10282d;color-scheme:light}@media(max-width:1050px){.theme-columns{grid-template-columns:1fr}.theme-library{display:grid;gap:12px}}@media(max-width:650px){.theme-fields,.theme-asset,.theme-schedule,.theme-summary{grid-template-columns:1fr}.theme-summary>p{grid-column:auto}.theme-manager .admin-panel{padding:16px}}
</style>

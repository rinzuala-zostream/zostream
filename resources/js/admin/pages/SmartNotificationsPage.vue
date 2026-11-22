<script setup>
import { computed, onMounted, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api } from '../lib/api';

const rules = ref([]); const stats = ref([]); const runtime = ref({});
const loading = ref(true); const busy = ref(''); const error = ref(''); const notice = ref('');
const sampleTitle = ref('Sample movie'); const testToken = ref(''); const movieId = ref('');
const hasChanges = ref({});
const totals = computed(() => stats.value.reduce((sum, row) => {
    sum[row.status] = (sum[row.status] || 0) + Number(row.total);
    sum.opened += Number(row.opened); sum.played += Number(row.played);
    return sum;
}, { opened: 0, played: 0 }));
const preview = (text) => String(text || '').replaceAll('{title}', sampleTitle.value || 'Sample movie');
const count = (rule, key) => stats.value.filter(row => row.rule === rule).reduce((sum, row) => sum + Number(key === 'opened' || key === 'played' ? row[key] : row.status === key ? row.total : 0), 0);
async function load() {
    loading.value = true; error.value = '';
    try { const data = await api('/admin/notifications/smart'); rules.value = data.rules; stats.value = data.stats; runtime.value = data.runtime; hasChanges.value = {}; }
    catch (reason) { error.value = reason.message; }
    finally { loading.value = false; }
}
async function save(rule) {
    if (busy.value) return;
    busy.value = rule.rule; error.value = ''; notice.value = '';
    try {
        const saved = await api(`/admin/notifications/smart/${rule.rule}`, { method: 'PUT', body: { enabled: rule.enabled, title_template: rule.title_template, body_template: rule.body_template } });
        Object.assign(rule, saved); hasChanges.value[rule.rule] = false;
        notice.value = `${rule.label} saved. Disabling a rule cancels its pending notifications.`;
    } catch (reason) { error.value = reason.message; }
    finally { busy.value = ''; }
}
async function sendTest(rule) {
    if (busy.value) return;
    busy.value = rule.rule; error.value = ''; notice.value = '';
    try {
        await api('/admin/notifications/smart/test', { method: 'POST', body: { rule: rule.rule, token: testToken.value.trim(), movie_id: movieId.value.trim() } });
        notice.value = 'Test notification accepted by FCM. Tap it on your test device to check the movie destination.';
    } catch (reason) { error.value = reason.message; }
    finally { busy.value = ''; }
}
onMounted(load);
</script>

<template>
    <div class="admin-page">
        <PageHeader eyebrow="Engagement" title="Smart notifications" description="Edit automated reminders and preview how they appear to viewers." />
        <StatusPanel tone="error" :message="error" /><StatusPanel tone="success" :message="notice" />
        <p v-if="loading" role="status">Loading smart notifications…</p>
        <template v-else-if="rules.length">
            <section class="admin-panel smart-summary">
                <p>Tracking: <b>{{ runtime.tracking_enabled ? 'ON' : 'OFF' }}</b> · Automated sending: <b>{{ runtime.enabled ? 'ON' : 'OFF' }}</b></p>
                <p>Quiet hours {{ runtime.quiet_hours }} · Minimum gap {{ runtime.cooldown_hours }} hours. Server rollout switches remain managed on the server.</p>
                <p>Last 30 days · FCM accepted: <b>{{ totals.sent || 0 }}</b> · Opened: <b>{{ totals.opened }}</b> · Played: <b>{{ totals.played }}</b> · Failed: <b>{{ totals.failed || 0 }}</b></p>
                <small>FCM acceptance does not confirm device delivery. Opens and plays depend on app analytics.</small>
            </section>
            <section class="admin-panel admin-tool smart-preview-controls">
                <label>Preview movie / episode title<input v-model="sampleTitle" maxlength="200"></label>
                <p>Use <code>{title}</code> in a template to insert the recommended movie or episode title. Tapping a movie notification opens that movie’s details.</p>
                <label>Test device FCM token<input v-model="testToken" autocomplete="off" placeholder="A single test device token"></label>
                <label>Test movie public ID<input v-model="movieId" placeholder="Published movie ID"></label>
                <small>Test pushes use the saved template and the selected movie’s actual title. They are sent only to the device token above.</small>
            </section>
            <div class="admin-tool-grid">
                <form v-for="rule in rules" :key="rule.rule" class="admin-panel admin-tool" @submit.prevent="save(rule)" @input="hasChanges[rule.rule] = true" @change="hasChanges[rule.rule] = true">
                    <header><div><p>AUTOMATED REMINDER</p><h2>{{ rule.label }}</h2></div></header>
                    <label class="smart-toggle"><input v-model="rule.enabled" type="checkbox" :disabled="!!busy"> Rule enabled</label>
                    <label>Notification title<input v-model="rule.title_template" required maxlength="200" :disabled="!!busy"></label>
                    <label>Message<textarea v-model="rule.body_template" required maxlength="2000" :disabled="!!busy" /></label>
                    <div class="smart-push-preview" aria-label="Notification preview"><small>ZO STREAM · PREVIEW</small><strong>{{ preview(rule.title_template) }}</strong><p>{{ preview(rule.body_template) }}</p></div>
                    <small>Last 30 days · Accepted {{ count(rule.rule, 'sent') }} · Opened {{ count(rule.rule, 'opened') }} · Played {{ count(rule.rule, 'played') }} · Pending {{ count(rule.rule, 'pending') + count(rule.rule, 'queued') }} · Failed {{ count(rule.rule, 'failed') }}</small>
                    <div class="admin-tool-actions">
                        <button type="button" class="admin-secondary" :disabled="!!busy || !testToken.trim() || !movieId.trim() || hasChanges[rule.rule]" @click="sendTest(rule)">Test saved template</button>
                        <button class="admin-primary" :disabled="!!busy">{{ busy === rule.rule ? 'Working…' : 'Save rule' }}</button>
                    </div>
                    <small v-if="hasChanges[rule.rule]">Save your changes before sending a test.</small>
                </form>
            </div>
        </template>
    </div>
</template>

<style scoped>
.smart-summary{padding:20px;margin-bottom:18px;line-height:1.7}.smart-summary p{margin:0 0 8px}.smart-preview-controls{margin-bottom:18px}.smart-preview-controls p{line-height:1.6;margin:0}.smart-push-preview{padding:18px;border:1px solid var(--a-line);border-radius:14px;background:rgba(127,127,127,.08);overflow-wrap:anywhere}.smart-push-preview small,.smart-push-preview strong{display:block}.smart-push-preview strong{margin-top:10px}.smart-push-preview p{margin:8px 0 0;white-space:pre-wrap;line-height:1.6}.smart-toggle{display:flex!important;align-items:center;gap:10px!important}.smart-toggle input{width:18px!important;min-height:18px!important;height:18px;accent-color:var(--a-cyan)}small{line-height:1.6}
</style>

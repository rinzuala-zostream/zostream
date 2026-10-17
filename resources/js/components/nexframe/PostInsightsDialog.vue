<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';

const props = defineProps({ post: { type: Object, required: true }, account: { type: Object, default: () => ({}) }, syncedAt: String });
const emit = defineEmits(['close']);
const modal = ref(null);
const imageFailed = ref(false);
const titleId = useId();
const platformName = computed(() => props.post.platform === 'instagram' ? 'Instagram' : 'Facebook');
const accountName = computed(() => props.account.username ? `@${props.account.username}` : props.account.name || platformName.value);
const format = (value) => value == null ? '—' : new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 }).format(value);
const date = (value) => value ? new Intl.DateTimeFormat('en-IN', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }).format(new Date(value)) : 'Unavailable';
const originalUrl = computed(() => {
    try {
        const url = new URL(props.post.permalink);
        return url.protocol === 'https:' && /(^|\.)(instagram\.com|facebook\.com|fb\.watch)$/.test(url.hostname) ? url.href : null;
    } catch { return null; }
});
const metricCards = computed(() => [
    { key: 'reach', label: 'Accounts reached', note: 'Accounts that saw this content' },
    { key: 'views', label: 'Content views', note: 'Plays or displays' },
    { key: 'impressions', label: 'Impressions', note: 'Display count, where available' },
    { key: 'engagement', label: 'Available interactions', note: 'Reported total or available action counts' },
    { key: 'likes', label: 'Likes', note: 'Like count returned by the platform' },
    { key: 'comments', label: 'Comments', note: 'Comments on this post' },
    { key: 'shares', label: 'Shares', note: 'Times this content was shared' },
    { key: 'saves', label: 'Saves', note: 'Times this content was saved' },
].map((metric) => ({ ...metric, value: props.post[metric.key] })));
const breakdown = computed(() => [
    { key: 'likes', label: 'Likes', color: '#f887bc' },
    { key: 'comments', label: 'Comments', color: '#54c9ff' },
    { key: 'shares', label: 'Shares', color: '#aa91ff' },
    { key: 'saves', label: 'Saves', color: '#48d4b3' },
].filter((item) => props.post[item.key] != null).map((item) => ({ ...item, value: Number(props.post[item.key]) })));
const knownTotal = computed(() => breakdown.value.reduce((total, item) => total + item.value, 0));
const interactionRatio = computed(() => Number(props.post.reach) > 0 && props.post.engagement != null
    ? `${new Intl.NumberFormat('en-IN', { maximumFractionDigits: 2 }).format(props.post.engagement / props.post.reach * 100)}%` : '—');

let previousOverflow;
let previousFocus;
onMounted(() => {
    previousFocus = document.activeElement;
    previousOverflow = document.body.style.overflow;
    modal.value.showModal();
    document.body.style.overflow = 'hidden';
});
onBeforeUnmount(() => {
    if (modal.value?.open) modal.value.close();
    document.body.style.overflow = previousOverflow ?? '';
    if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
});
function close() {
    emit('close');
}
function backdrop(event) {
    if (event.target !== event.currentTarget) return;
    const rect = modal.value.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close();
}
</script>

<template>
    <dialog ref="modal" class="post-dialog" :class="post.platform" :aria-labelledby="titleId" @cancel.prevent="close" @close="close" @click="backdrop">
        <header class="dialog-header"><div class="heading"><span class="platform-icon">{{ post.platform === 'instagram' ? '◎' : 'f' }}</span><div><p>{{ accountName }} · {{ post.type || 'post' }}</p><h2 :id="titleId">{{ platformName }} Post Insights</h2></div></div><button type="button" class="close" aria-label="Close post insights" autofocus @click="close">×</button></header>
        <div class="dialog-content">
            <section class="post-content" aria-label="Post content"><div class="media"><img v-if="post.image && !imageFailed" :src="post.image" alt="Post preview" @error="imageFailed = true"><div v-else class="media-placeholder"><span>{{ post.platform === 'instagram' ? '◎' : 'f' }}</span><p>Preview unavailable</p></div><span class="media-badge">{{ platformName }} · {{ post.type || 'post' }}</span></div><div class="caption"><span class="section-label">PUBLISHED {{ date(post.published_at) }}</span><p>{{ post.caption || 'No caption provided.' }}</p></div><a v-if="originalUrl" class="original-link" :href="originalUrl" target="_blank" rel="noopener noreferrer">Open original {{ platformName }} post ↗</a><p v-else class="link-note">An original post link is unavailable.</p></section>
            <section class="insight-content" aria-label="Detailed post metrics"><div class="metrics-heading"><h3>Performance overview</h3><span>Lifetime totals at sync</span></div><div class="metrics"><article v-for="metric in metricCards" :key="metric.key" :class="{ unavailable: metric.value == null }"><span>{{ metric.label }}</span><b>{{ format(metric.value) }}</b><small>{{ metric.value == null ? 'Not returned by the platform' : metric.note }}</small></article></div>
                <section class="breakdown"><h3>Interaction breakdown</h3><p>Available action counts; unavailable actions are excluded.</p><div v-if="knownTotal > 0" class="breakdown-bar" aria-hidden="true"><span v-for="item in breakdown" :key="item.key" :style="{ width: `${item.value / knownTotal * 100}%`, background: item.color }"/></div><dl v-if="breakdown.length"><div v-for="item in breakdown" :key="item.key"><dt><i :style="{ background: item.color }"/>{{ item.label }}</dt><dd>{{ format(item.value) }}</dd></div></dl><p v-else class="no-breakdown">Interaction details are unavailable for this post.</p></section>
                <section class="ratio"><div><h3>Interaction-to-reach ratio</h3><p>Available interactions ÷ reach. Multiple actions per account can make this exceed 100%.</p></div><b>{{ interactionRatio }}</b></section>
                <footer class="data-note"><b>About these insights</b><p>Selected dates determine which posts are listed. These post metrics reflect lifetime values returned at sync, rather than activity only within the selected dates.</p><p>— indicates unavailable data; 0 indicates a reported zero. Availability varies by platform, content type and permission.</p><span>Report sync: {{ date(syncedAt) }}</span></footer>
            </section>
        </div>
    </dialog>
</template>

<style scoped>
.post-dialog{box-sizing:border-box;width:min(1080px,calc(100vw - 40px));max-width:none;max-height:90dvh;margin:auto;padding:0;overflow:auto;border:1px solid #35516d;border-radius:16px;background:#051426;color:#e9f3ff;font-family:'DM Sans',sans-serif;box-shadow:0 35px 100px #0009;color-scheme:dark}.post-dialog::backdrop{background:#000915c9;backdrop-filter:blur(5px)}.post-dialog.instagram{border-top:3px solid #d876bc}.post-dialog.facebook{border-top:3px solid #448ff1}.dialog-header{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:15px;padding:18px 22px;border-bottom:1px solid #24415e;background:#07182df5;backdrop-filter:blur(15px)}.heading{display:flex;align-items:center;gap:12px;min-width:0}.platform-icon{display:grid;place-items:center;flex-shrink:0;width:43px;height:43px;border-radius:12px;background:linear-gradient(135deg,#d07c3b,#b73d85,#6548ad);font-size:25px;font-weight:800}.facebook .platform-icon{background:#1877f2}.heading p{margin:0 0 5px;color:#a0bad1;font-size:12px;text-transform:capitalize;overflow-wrap:anywhere}h2{margin:0;font-size:20px;line-height:1.3}.close{display:grid;place-items:center;flex-shrink:0;width:36px;height:36px;padding:0;border:1px solid #36536d;border-radius:9px;background:#112943;color:#e9f3ff;font-size:26px;cursor:pointer}.dialog-content{display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.35fr)}.post-content{padding:22px;border-right:1px solid #203d57;background:#06182b}.media{position:relative;display:grid;place-items:center;min-height:180px;overflow:hidden;border:1px solid #28415c;border-radius:10px;background:#020c18}.media img{display:block;width:100%;max-height:400px;object-fit:contain}.media-placeholder{display:grid;place-items:center;min-height:250px;color:#88a1bb;font-size:13px}.media-placeholder>span{font-size:50px}.media-badge{position:absolute;top:12px;left:12px;padding:6px 9px;border-radius:6px;background:#041325da;font-size:11px;text-transform:capitalize}.caption{padding-top:20px}.section-label{font-size:11px;color:#90aac3;line-height:1.6}.caption>p{margin:12px 0 0;color:#d4e3f2;font-size:14px;line-height:1.8;white-space:pre-wrap;overflow-wrap:anywhere}.original-link{display:block;margin-top:20px;padding:12px;border:1px solid #385d81;border-radius:9px;background:#113253;color:#cde9ff;text-align:center;text-decoration:none;font-size:13px;font-weight:700}.instagram .original-link{border-color:#65436a;background:#30203c;color:#f7d3ee}.link-note{color:#8fa9c3;font-size:12px}.insight-content{padding:22px;min-width:0}.metrics-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:14px}h3{margin:0;font-size:15px;line-height:1.5}.metrics-heading>span{font-size:11px;color:#9ab3cb}.metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.metrics article{min-width:0;padding:13px;border:1px solid #24435f;border-radius:9px;background:#0a2037}.metrics article>span{display:block;color:#a6bdd2;font-size:12px;line-height:1.5}.metrics article>b{display:block;margin:10px 0 7px;color:#ecf5ff;font-size:25px;overflow-wrap:anywhere;line-height:1.2}.metrics article>small{display:block;color:#7f9ab4;font-size:10px;line-height:1.5}.metrics article.unavailable{background:#081b2f;border-color:#1d354c}.metrics article.unavailable>b{color:#819ab2}.breakdown{margin-top:23px;padding-top:20px;border-top:1px solid #203e57}.breakdown>p,.ratio p{margin:6px 0 0;color:#91abc4;font-size:12px;line-height:1.6}.breakdown-bar{display:flex;height:10px;margin:17px 0 15px;overflow:hidden;border-radius:6px;background:#17314c}.breakdown-bar span{display:block;height:100%}dl{display:grid;grid-template-columns:1fr 1fr;gap:11px 20px;margin:16px 0 0}dl>div{display:flex;justify-content:space-between;gap:10px;font-size:12px}dt{display:flex;align-items:center;gap:7px;color:#b9cee0}dt>i{width:8px;height:8px;flex-shrink:0;border-radius:50%}dd{margin:0;font-weight:750;color:#e7f2fe}.ratio{display:flex;align-items:center;gap:18px;justify-content:space-between;margin-top:22px;padding:14px;border:1px solid #26465e;border-radius:9px;background:#0a2135}.ratio>b{font-size:24px;white-space:nowrap;color:#8fe0d4}.ratio h3{font-size:13px}.ratio p{font-size:11px}.data-note{margin-top:20px;padding-top:17px;border-top:1px solid #203e57;font-size:11px;line-height:1.7;color:#849eb8}.data-note b{font-size:12px;color:#adc5d9}.data-note p{margin:7px 0}.data-note>span{display:block;margin-top:10px;color:#adc5d9}.close:focus-visible,.original-link:focus-visible{outline:2px solid #75d8ff;outline-offset:3px}@media(max-width:800px){.dialog-content{grid-template-columns:1fr}.post-content{border-right:0;border-bottom:1px solid #203d57}.media img{max-height:320px}.caption>p{max-height:200px;overflow:auto}.metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:500px){.post-dialog{width:calc(100vw - 18px);max-height:94dvh;border-radius:12px}.dialog-header{padding:13px}.heading{gap:9px}.platform-icon{width:34px;height:34px;font-size:21px}h2{font-size:17px}.heading p{font-size:11px}.post-content,.insight-content{padding:15px}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.metrics article>b{font-size:24px}.metrics-heading{align-items:flex-start;flex-direction:column;gap:4px}.ratio{gap:12px}.ratio>b{font-size:21px}dl{gap:12px}}
</style>

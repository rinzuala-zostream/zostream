<script setup>
import { formatDate, formatDateTime } from './dates';
import { computed, ref, watch } from 'vue';
import PostInsightsDialog from './PostInsightsDialog.vue';

const props = defineProps({ social: { type: Object, default: () => ({}) }, posts: { type: Array, default: () => [] }, expanded: Boolean, compact: Boolean });
defineEmits(['open']);
let savedPlatform = 'instagram';
try {
    const saved = window.localStorage.getItem('nexframe.social.platform');
    if (['instagram', 'facebook', 'compare'].includes(saved)) savedPlatform = saved;
} catch {}
const platform = ref(savedPlatform);
watch(platform, (value) => {
    try { window.localStorage.setItem('nexframe.social.platform', value); } catch {}
});
const sort = ref('engagement');
const previewPlatform = ref('all');
const visibleCount = ref(24);
const selectedPost = ref(null);
const selectedSyncedAt = ref('');
function openPost(post, event) {
    const element = event?.currentTarget;
    const trigger = element?.matches('button') ? element : element?.querySelector('button');
    trigger?.focus({ preventScroll: true });
    selectedPost.value = post;
    selectedSyncedAt.value = props.social.synced_at || '';
}
watch(() => props.posts, (posts) => {
    if (!selectedPost.value) return;
    const refreshed = posts.find((post) => post.id === selectedPost.value.id && post.platform === selectedPost.value.platform);
    if (refreshed) {
        selectedPost.value = refreshed;
        selectedSyncedAt.value = props.social.synced_at || '';
    }
});
watch(() => [platform.value, props.posts, sort.value], () => { visibleCount.value = 24; });
const status = (data) => !data?.configured ? 'Not configured' : data.healthy ? 'Connected' : 'Needs attention';
const format = (value) => value == null ? '—' : new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
const when = formatDateTime;
const percent = (value) => value == null ? '—' : `${Number(value).toFixed(2)}%`;
const activeAccount = computed(() => props.social[platform.value] || {});
const activeLabel = computed(() => platform.value === 'instagram' ? 'Instagram' : platform.value === 'facebook' ? 'Facebook' : 'Platform comparison');
const displayedPosts = computed(() => [...props.posts].filter((post) => props.expanded ? platform.value === 'compare' || post.platform === platform.value : previewPlatform.value === 'all' || post.platform === previewPlatform.value).sort((a, b) => sort.value === 'newest' ? new Date(b.published_at || 0) - new Date(a.published_at || 0) : Number(b[sort.value] || 0) - Number(a[sort.value] || 0)));
const visiblePosts = computed(() => displayedPosts.value.slice(0, props.compact ? 4 : visibleCount.value));
const insightCards = computed(() => [
    ['Current followers', activeAccount.value.followers, `${activeLabel.value} account`],
    ['Accounts reached', activeAccount.value.insights?.reach, 'Account insight · selected period'],
    ['Content views', activeAccount.value.insights?.views ?? activeAccount.value.insights?.impressions, 'Plays or displays'],
    ['Interactions in period', activeAccount.value.insights?.interactions, 'Account insight · selected period'],
    ['Engagement rate', activeAccount.value.insights?.engagement_rate, 'Interactions ÷ reach', true],
    ['Published content', activeAccount.value.healthy ? activeAccount.value.posts?.length ?? displayedPosts.value.length : null, 'Posts in selected period'],
    ['Saves', activeAccount.value.insights?.saves, `${activeLabel.value} content`],
    ['Shares', activeAccount.value.insights?.shares, `${activeLabel.value} content`],
    ['Link actions', activeAccount.value.insights?.link_clicks, `${activeLabel.value} account`],
]);
const typeStats = computed(() => Object.values(displayedPosts.value.reduce((types, post) => {
    const key = `${post.platform}:${post.type || 'post'}`;
    types[key] ||= { type: `${post.platform} · ${post.type || 'post'}`, posts: 0, engagement: 0, reach: 0, views: 0, hasReach: false, hasViews: false };
    types[key].posts += 1;
    types[key].engagement += Number(post.engagement || 0);
    if (post.reach != null) { types[key].reach += Number(post.reach); types[key].hasReach = true; }
    if ((post.views ?? post.impressions) != null) { types[key].views += Number(post.views ?? post.impressions); types[key].hasViews = true; }
    return types;
}, {})).map((row) => ({ ...row, reach: row.hasReach ? row.reach : null, views: row.hasViews ? row.views : null })));
const comparisonRows = computed(() => [
    ['Current followers', props.social.instagram?.followers, props.social.facebook?.followers],
    ['Accounts reached', props.social.instagram?.insights?.reach, props.social.facebook?.insights?.reach],
    ['Content views', props.social.instagram?.insights?.views ?? props.social.instagram?.insights?.impressions, props.social.facebook?.insights?.views ?? props.social.facebook?.insights?.impressions],
    ['Interactions in period', props.social.instagram?.insights?.interactions, props.social.facebook?.insights?.interactions],
    ['Engagement rate', percent(props.social.instagram?.insights?.engagement_rate), percent(props.social.facebook?.insights?.engagement_rate), true],
    ['Saves', props.social.instagram?.insights?.saves, props.social.facebook?.insights?.saves],
    ['Shares', props.social.instagram?.insights?.shares, props.social.facebook?.insights?.shares],
    ['Link actions', props.social.instagram?.insights?.link_clicks, props.social.facebook?.insights?.link_clicks],
    ['Published content', props.social.instagram?.healthy ? props.social.instagram.posts?.length : null, props.social.facebook?.healthy ? props.social.facebook.posts?.length : null],
]);
</script>

<template>
    <article class="panel" :class="{ expanded, compact }">
        <header class="panel-head"><div><span>◉</span><p><b>{{ compact ? 'Social Media Feed' : 'Social media insights' }}</b><small>Facebook & Instagram performance</small></p></div><button v-if="!expanded" @click="$emit('open')">View insights →</button><em v-else>Last attempt {{ when(social.synced_at) }}</em></header>
        <div v-if="compact" class="preview-toolbar"><div><button v-for="item in ['all', 'instagram', 'facebook']" :key="item" :class="[item, { active: previewPlatform === item }]" :aria-pressed="previewPlatform === item" @click="previewPlatform = item">{{ item === 'instagram' ? '◎ Instagram' : item === 'facebook' ? 'f Facebook' : 'All' }}</button></div><small>{{ social.connected_platforms || 0 }}/2 connected</small></div>
        <div v-else class="connections">
            <div class="connection instagram" :class="{ selected: expanded && platform === 'instagram' }" @click="expanded && (platform = 'instagram')"><i>◎</i><p><b>{{ social.instagram?.username ? `@${social.instagram.username}` : 'Instagram Business' }}</b><small>{{ social.instagram?.message || `${format(social.instagram?.followers)} current followers` }}</small></p><span :class="{ ok: social.instagram?.healthy }">{{ status(social.instagram) }}</span></div>
            <div class="connection facebook" :class="{ selected: expanded && platform === 'facebook' }" @click="expanded && (platform = 'facebook')"><i>f</i><p><b>{{ social.facebook?.name || 'Facebook Page' }}</b><small>{{ social.facebook?.message || `${format(social.facebook?.followers)} current followers` }}</small></p><span :class="{ ok: social.facebook?.healthy }">{{ status(social.facebook) }}</span></div>
        </div>
        <template v-if="expanded">
            <p v-if="social.posts_complete === false" class="data-note">Some post pages could not be fetched. Post totals and content matches are partial.</p>
            <nav class="platform-tabs"><button :class="{ active: platform === 'instagram' }" @click="platform = 'instagram'"><i>◎</i><span>Instagram</span><small>Insights</small></button><button :class="{ active: platform === 'facebook' }" @click="platform = 'facebook'"><i>f</i><span>Facebook</span><small>Insights</small></button><button :class="{ active: platform === 'compare' }" @click="platform = 'compare'"><i>⇄</i><span>Compare</span><small>Side by side</small></button></nav>
            <div v-if="platform !== 'compare'" class="platform-heading"><div :class="platform"><i>{{ platform === 'instagram' ? '◎' : 'f' }}</i><p><b>{{ activeLabel }} performance</b><small>Only {{ activeLabel }} data is shown below</small></p></div><span :class="{ ok: activeAccount.healthy }">{{ status(activeAccount) }}</span></div>
            <div v-if="platform !== 'compare'" class="insight-grid"><div v-for="card in insightCards" :key="card[0]"><span>{{ card[0] }}</span><b>{{ card[3] ? percent(card[1]) : format(card[1]) }}</b><small>{{ card[2] }}</small></div></div>
            <div v-else class="comparison-table">
                <header><div><b>Instagram vs Facebook</b><small>Metrics remain separate—no mixed totals</small></div></header>
                <table><thead><tr><th>Metric</th><th class="ig-column">◎ Instagram</th><th class="fb-column">f Facebook</th></tr></thead><tbody><tr v-for="row in comparisonRows" :key="row[0]"><td>{{ row[0] }}</td><td>{{ row[3] ? row[1] : format(row[1]) }}</td><td>{{ row[3] ? row[2] : format(row[2]) }}</td></tr></tbody></table>
            </div>
            <section v-if="platform !== 'compare'" class="data-note"><p>Last successful account sync: {{ when(activeAccount.last_successful_sync) }}. Unavailable metrics display as —.</p><details v-if="Object.keys(activeAccount.insight_issues || {}).length"><summary>Insight availability</summary><p v-for="(reason, metric) in activeAccount.insight_issues" :key="metric">{{ metric }}: {{ reason }}</p></details></section>
            <section v-if="platform !== 'compare'" class="follower-history"><header><b>{{ activeLabel }} follower history</b><small>{{ activeAccount.follower_history?.message || 'History begins with successful syncs.' }}</small></header><p>Observed change: {{ activeAccount.follower_history?.change == null ? 'More observations needed' : `${activeAccount.follower_history.change > 0 ? '+' : ''}${format(activeAccount.follower_history.change)} followers` }}</p><details v-if="activeAccount.follower_history?.points?.length"><summary>{{ activeAccount.follower_history.points.length }} observed days in selected dates</summary><div v-for="point in activeAccount.follower_history.points" :key="point.date" class="history-row"><span>{{ formatDate(point.date) }}</span><b>{{ format(point.followers) }}</b></div></details></section>
            <section v-if="typeStats.length" class="content-types"><header><b>{{ activeLabel }} content-type performance</b><small>{{ platform === 'compare' ? 'Both platforms are shown only in comparison mode' : `Only ${activeLabel} posts` }}</small></header><div><article v-for="type in typeStats" :key="type.type"><span>{{ type.type }}</span><b>{{ format(type.engagement) }}</b><small>{{ type.posts }} posts · {{ format(type.reach) }} reach · {{ format(type.views) }} views</small></article></div></section>
            <div class="post-toolbar"><div><b>{{ activeLabel }} posts</b><small>{{ displayedPosts.length }} published in selected dates · post metrics are lifetime values at sync</small></div><select v-model="sort"><option value="engagement">Top engagement</option><option value="reach">Top reach</option><option value="views">Most viewed</option><option value="newest">Newest</option></select></div>
        </template>
        <div v-if="displayedPosts.length" class="feed">
            <article v-for="post in visiblePosts" class="post-card" :key="`${post.platform}:${post.id}`" :class="`post-${post.platform}`" @click="openPost(post, $event)"><div class="image"><img v-if="post.image" :src="post.image" alt=""><span v-else>{{ post.platform === 'instagram' ? '◎' : 'f' }}</span><em :class="post.platform">{{ post.platform }} · {{ post.type || 'post' }}</em></div><p>{{ post.caption || 'Social post' }}</p><small>{{ when(post.published_at) }}</small><footer><span>♥ {{ format(post.likes) }}</span><span>● {{ format(post.comments) }}</span><span>↗ {{ format(post.shares) }}</span><span v-if="post.saves != null">◆ {{ format(post.saves) }}</span></footer><dl v-if="expanded"><div><dt>Reach</dt><dd>{{ format(post.reach) }}</dd></div><div><dt>Views</dt><dd>{{ format(post.views ?? post.impressions) }}</dd></div><div><dt>Interactions</dt><dd>{{ format(post.engagement) }}</dd></div></dl><button type="button" class="post-open" :aria-label="`View ${post.platform} post insights: ${(post.caption || 'Social post').slice(0, 100)}`" @click.stop="openPost(post, $event)">View insights <span>↗</span></button></article>
        </div>
        <div v-else class="empty"><span>↻</span><p><b>No social posts available</b><small>No matching posts were returned for this platform and selected dates. Check connection details above if data is unavailable.</small></p></div>
        <button v-if="!compact && displayedPosts.length > visibleCount" class="more-posts" @click="visibleCount += 24">Show more posts ({{ visibleCount }} of {{ displayedPosts.length }})</button>
        <PostInsightsDialog v-if="selectedPost" :key="`${selectedPost.platform}:${selectedPost.id}`" :post="selectedPost" :account="social[selectedPost.platform] || {}" :synced-at="selectedSyncedAt" @close="selectedPost = null" />
    </article>
</template>

<style scoped>
.more-posts{display:block;margin:20px auto 0;padding:10px 16px;border:1px solid #326561;border-radius:8px;background:#102d30;color:#b3eee5;font-size:12px;cursor:pointer}
.data-note,.follower-history{margin:15px 0;padding:14px;border:1px solid #27455b;border-radius:8px;background:#0a1c2f;color:#9db1c8;font-size:12px;line-height:1.6}.data-note p{margin:5px 0}.data-note summary,.follower-history summary{cursor:pointer}.follower-history header{display:grid;gap:5px}.follower-history header b{font-size:14px;color:#d6e6f4}.follower-history header small{font-size:11px}.history-row{display:flex;justify-content:space-between;gap:15px;padding:8px;border-bottom:1px solid #213c53}
.platform-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:22px 0 18px}.platform-tabs button{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:3px 10px;padding:14px;border:1px solid #214363;border-radius:10px;background:#081a30;color:#9bb1ca;text-align:left;cursor:pointer}.platform-tabs i{grid-row:span 2;font-size:24px;font-style:normal}.platform-tabs span{font-size:14px;font-weight:800}.platform-tabs small{font-size:11px;color:#92aac4}.platform-tabs button.active{border-color:#73a4c7;background:#12304b;color:#fff}.platform-tabs button:first-child.active{border-color:#da64b5;background:linear-gradient(120deg,#3b1c42,#1c2040)}.platform-tabs button:nth-child(2).active{border-color:#4095ff;background:#0d315b}.expanded .connection{cursor:pointer}.connection.instagram.selected{border-color:#cf60b0}.connection.facebook.selected{border-color:#388df6}.platform-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.platform-heading>div{display:flex;align-items:center;gap:10px}.platform-heading i{font-size:25px;font-style:normal;color:#ef83ca}.platform-heading .facebook i{color:#459dff}.platform-heading p{display:grid;gap:4px;margin:0}.platform-heading b{font-size:15px}.platform-heading small{font-size:12px;color:#91a7c0}.platform-heading>span{font-size:12px;color:#e2b5cd}.platform-heading>span.ok{color:#32e8a4}.comparison-table{margin-top:16px;padding:16px;border:1px solid #214363;border-radius:10px;overflow:auto}.comparison-table header b{font-size:15px}.comparison-table header small{display:block;margin-top:5px;color:#91a7c0;font-size:12px}.comparison-table table{width:100%;margin-top:15px;border-collapse:collapse;min-width:430px}.comparison-table th,.comparison-table td{padding:13px 12px;border-bottom:1px solid #173650;text-align:right;font-size:13px}.comparison-table th:first-child,.comparison-table td:first-child{text-align:left}.comparison-table .ig-column{color:#f18dcc}.comparison-table .fb-column{color:#64aeff}.post-toolbar>div{display:grid;gap:5px}.post-toolbar b{font-size:14px}.post-toolbar small{font-size:11px;color:#91a7c0}.feed>.post-card.post-instagram{border-top:3px solid #d95eb1}.feed>.post-card.post-facebook{border-top:3px solid #368cfa}.image em.instagram{background:linear-gradient(110deg,#963872,#59318c);font-size:11px;font-weight:700}.image em.facebook{background:#1257ad;font-size:11px;font-weight:700}.feed dl dt{font-size:11px;color:#91a7c0}.feed dl dd{margin:5px 0 0;font-size:13px;font-weight:700}@media(max-width:540px){.platform-tabs{gap:6px}.platform-tabs button{display:flex;flex-direction:column;align-items:center;padding:10px 5px;text-align:center}.platform-tabs span{font-size:12px}.platform-tabs small{font-size:10px}.platform-heading{align-items:flex-start}}
.panel{min-width:0;padding:20px;border:1px solid #12355a;border-radius:10px;background:linear-gradient(145deg,#071931,#041123);overflow:hidden}.panel.expanded{min-height:430px}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.panel-head>div{display:flex;align-items:center;gap:10px}.panel-head>div>span{color:#9854ff;font-size:22px}.panel-head p{display:grid;margin:0}.panel-head b{font-size:15px}.panel-head small{margin-top:4px;color:#7f96b3;font-size:12px}.panel-head button{border:0;background:transparent;color:#19c8ff;font-size:12px;cursor:pointer}.panel-head>em{color:#8296b2;font-size:11px;font-style:normal}.connections{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px}.connection{display:flex;align-items:center;gap:11px;padding:14px;border:1px solid #173a60;border-radius:9px;background:#071b34}.connection>i{display:grid;place-items:center;width:38px;height:38px;border-radius:9px;color:white;font-size:21px;font-style:normal;font-weight:900}.instagram>i{background:linear-gradient(135deg,#f8af3f,#e22b91,#6548dc)}.facebook>i{background:#1877f2}.connection p{display:grid;min-width:0;margin:0}.connection b{overflow:hidden;font-size:12px;text-overflow:ellipsis}.connection small{margin-top:4px;color:#8ba1ba;font-size:11px}.connection>span{margin-left:auto;padding:5px 8px;border-radius:8px;background:#362247;color:#d5b6ff;font-size:10px}.connection>span.ok{background:#0a4135;color:#32e8a4}.insight-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:18px}.insight-grid>div{padding:14px;border:1px solid #173a60;border-radius:9px;background:#06162b}.insight-grid span,.insight-grid small{display:block;color:#8399b3;font-size:10px}.insight-grid b{display:block;margin:8px 0 5px;font:800 21px 'Manrope',sans-serif}.platform-insights{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.platform-insights>section,.content-types{padding:16px;border:1px solid #173a60;border-radius:9px;background:#06162b}.platform-insights header{display:flex;justify-content:space-between}.platform-insights header b{font-size:13px}.platform-insights header span{color:#31dda3;font-size:10px}.platform-insights dl{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0 0}.platform-insights dl div{padding:10px;border-radius:7px;background:#081d37}.platform-insights dt,.feed dl dt{color:#7f96b3;font-size:9px}.platform-insights dd,.feed dl dd{margin:5px 0 0;font-size:12px;font-weight:800}.content-types{margin-top:12px}.content-types header b{font-size:13px}.content-types header small{display:block;margin-top:4px;color:#7f96b3;font-size:10px}.content-types>div{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:12px}.content-types article{padding:12px;border-radius:8px;background:#081d37}.content-types article span{text-transform:capitalize;color:#8faccc;font-size:10px}.content-types article b{display:block;margin:7px 0;font-size:18px}.content-types article small{color:#7d93ad;font-size:9px}.post-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px}.post-toolbar>div{display:flex;gap:6px}.post-toolbar button,.post-toolbar select{padding:8px 11px;border:1px solid #194166;border-radius:7px;background:#071a32;color:#a9bdd3;font-size:10px;text-transform:capitalize}.post-toolbar button.active{border-color:#1384e8;background:#0b5ebb;color:white}.feed{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-top:14px}.feed>.post-card{min-width:0;padding:11px;border:1px solid #163654;border-radius:9px;background:#06162b}.image{position:relative;display:grid;place-items:center;height:145px;overflow:hidden;border-radius:7px;background:#102c4a}.image img{width:100%;height:100%;object-fit:cover}.image>span{font-size:32px}.image em{position:absolute;top:8px;left:8px;padding:4px 7px;border-radius:5px;background:#06101cdd;font-size:9px;font-style:normal;text-transform:capitalize}.feed>.post-card>p{display:-webkit-box;min-height:39px;margin:11px 0 6px;overflow:hidden;font-size:12px;line-height:1.55;-webkit-box-orient:vertical;-webkit-line-clamp:2}.feed>.post-card>small{color:#8299b4;font-size:10px}.feed footer{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;color:#a9bdd3;font-size:10px}.feed dl{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin:11px 0 0;padding-top:10px;border-top:1px solid #15304d}.empty{display:flex;align-items:center;gap:12px;min-height:110px;margin-top:14px;padding:18px;border:1px dashed #21466c;border-radius:8px}.empty>span{font-size:28px;color:#496d94}.empty p{display:grid;margin:0}.empty b{font-size:13px}.empty small{max-width:650px;margin-top:5px;color:#849bb6;font-size:11px}@media(max-width:1100px){.insight-grid{grid-template-columns:repeat(3,1fr)}}@media(max-width:700px){.connections,.platform-insights{grid-template-columns:1fr}.platform-insights dl{grid-template-columns:repeat(2,1fr)}.insight-grid{grid-template-columns:repeat(2,1fr)}.post-toolbar{align-items:stretch;flex-direction:column}}
</style>
<style scoped>
.compact{padding:17px;border-color:#19416b;border-radius:11px;background:linear-gradient(130deg,#06182e,#031124)}.compact .panel-head small{display:none}.compact .panel-head>div>span{color:#ae7aff}.compact .panel-head b{font-size:15px}.preview-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:16px 0 10px}.preview-toolbar>div{display:flex;flex-wrap:wrap;gap:6px}.preview-toolbar button{padding:6px 9px;border:1px solid #1d4164;border-radius:7px;background:#071b33;color:#9bb4cf;font-size:11px;cursor:pointer}.preview-toolbar button.active{background:#0c4b86;border-color:#237ad0;color:white}.preview-toolbar button.instagram.active{background:#52254b;border-color:#a54c8c;color:#ffc0e4}.preview-toolbar button.facebook.active{background:#144d93;border-color:#347dce;color:#cae5ff}.preview-toolbar>small{font-size:10px;white-space:nowrap;color:#829db8}.compact .feed{grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;margin-top:12px}.compact .feed>.post-card{padding:8px;border-radius:8px}.compact .image{height:128px}.compact .image em{top:5px;left:5px;right:5px;padding:4px;font-size:9px;text-align:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}.compact .feed>.post-card>p{font-size:12px;line-height:1.5;min-height:36px}.compact .feed>.post-card>small{font-size:10px}.compact .feed footer{font-size:10px;gap:7px}.compact .empty{min-height:230px;align-items:center}.compact .empty small{font-size:12px}.compact .empty b{font-size:14px}@media(max-width:650px){.compact .feed{grid-template-columns:repeat(2,minmax(0,1fr))}.compact .image{height:145px}.preview-toolbar>small{display:none}}
</style>

<style scoped>
.feed>.post-card{cursor:pointer;transition:border-color .15s,box-shadow .15s}.feed>.post-card:hover{border-color:#3a6387;box-shadow:0 5px 18px #0003}.post-open{display:flex;align-items:center;justify-content:space-between;width:100%;margin-top:13px;padding:8px 10px;border:1px solid #315371;border-radius:6px;background:#0d2b47;color:#bde8ff;font:700 11px 'DM Sans',sans-serif;cursor:pointer;text-align:left}.post-instagram .post-open{border-color:#5b3d67;background:#281d38;color:#efc6e7}.post-open:focus-visible{outline:2px solid #66d7ff;outline-offset:3px}.compact .post-open{padding:7px;font-size:10px}
</style>

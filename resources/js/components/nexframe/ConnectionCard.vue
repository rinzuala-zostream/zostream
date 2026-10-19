<script setup>
const props = defineProps({ platform: String, icon: String, data: { type: Object, default: () => ({}) }, variables: String });
const insightHealth = () => !props.data?.healthy
    ? 'Unavailable'
    : props.data.insights_status === 'partial'
        ? 'Partial'
        : props.data.insights_status === 'unavailable'
            ? 'Unavailable'
            : 'Healthy';
</script>
<style scoped>
.card.instagram{border-color:#633554}.card.facebook{border-color:#245a8f}.card.instagram header>i{background:linear-gradient(135deg,#ce7136,#af357d,#6948b0)}.card.facebook header>i{background:#1877f2}
</style>
<template><article class="card" :class="platform?.toLowerCase().includes('instagram') ? 'instagram' : 'facebook'"><header><i>{{icon}}</i><div><span>{{platform}}</span><b :class="{ok:data.healthy}">{{data.healthy?'Connected':data.configured?'Needs attention':'Not configured'}}</b></div></header><dl><div><dt>Account</dt><dd>{{data.username?`@${data.username}`:data.name||data.account_id||'—'}}</dd></div><div><dt>Followers</dt><dd>{{data.followers??'—'}}</dd></div><div><dt>Insight health</dt><dd>{{ insightHealth() }}</dd></div></dl><p v-if="data.message">{{data.message}}</p><p v-else-if="data.healthy && data.insights_status !== 'healthy'">Account connected; some analytics are unavailable. See Social Media → Insight availability.</p><footer><span>Server variables</span><code>{{variables}}</code></footer></article></template>
<style scoped>.card{padding:20px;border:1px solid #12355a;border-radius:10px;background:linear-gradient(145deg,#071931,#041123)}header{display:flex;align-items:center;gap:12px}header>i{display:grid;place-items:center;width:42px;height:42px;border-radius:10px;background:#174b88;font-size:22px;font-style:normal;font-weight:800}header div{display:grid;gap:5px}header span{font-size:14px;font-weight:800}header b{color:#c59aff;font-size:11px}header b.ok{color:#2ee5a1}dl{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:20px 0}dl div{padding:11px;border-radius:7px;background:#071b34}dt{color:#7088a6;font-size:10px}dd{margin:5px 0 0;font-size:13px;font-weight:700;overflow-wrap:anywhere}.card>p{padding:10px;border:1px solid #5d2841;border-radius:7px;background:#291426;color:#ffaac3;font-size:11px;line-height:1.5}footer{display:grid;gap:6px;padding-top:14px;border-top:1px solid #14304e}footer span{color:#738ca9;font-size:10px}code{color:#00c8ff;font-size:11px;line-height:1.5;overflow-wrap:anywhere}@media(max-width:560px){dl{grid-template-columns:1fr}}</style>

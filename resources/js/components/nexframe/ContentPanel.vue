<script setup>
import { formatDate } from './dates';
defineProps({ rows: { type: Array, default: () => [] }, expanded: Boolean });
defineEmits(['open']);
const format = (value) => new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 }).format(Number(value || 0));
</script>
<template>
    <article class="panel" :class="{ expanded }">
        <header><div><span>▣</span><p><b>{{ expanded ? 'Zo Stream Content Performance' : 'Latest Releases Catalog' }}</b><small>Catalog views alongside promotion on each platform</small></p></div><button v-if="!expanded" @click="$emit('open')">View performance →</button><em v-else>{{ rows.length }} titles</em></header>
        <div class="scroll"><table><thead><tr><th>Movie / Series</th><th v-if="expanded">Published</th><th v-if="expanded">Category</th><th>Zo Stream views</th><th class="instagram">◎ Instagram</th><th class="facebook">f Facebook</th></tr></thead><tbody>
            <tr v-for="row in rows" :key="row.id"><td><img v-if="row.image" :src="row.image" alt=""><span v-else>▶</span><b>{{ row.title }}</b></td><td v-if="expanded">{{ formatDate(row.published_at) }}</td><td v-if="expanded">{{ row.category }}</td><td>{{ format(row.views) }}<small v-if="expanded">Lifetime catalog views</small></td>
                <td v-for="platform in ['instagram', 'facebook']" :key="platform"><div class="promotion" :class="platform"><template v-if="row.platform_promotions?.[platform]?.available"><b>{{ format(row.platform_promotions[platform].posts) }} {{ expanded ? 'matching posts' : 'posts' }}</b><small>{{ format(row.platform_promotions[platform].engagement) }} interactions</small></template><template v-else><b>—</b><small>{{ expanded ? 'Awaiting platform data' : 'Unavailable' }}</small></template></div></td>
            </tr>
        </tbody></table><p v-if="!rows.length" class="empty">No matching catalog titles.</p></div>
        <p v-if="expanded" class="matching-note">Promotions are matched by title in post captions. Social counts cover fetched posts in the selected dates.</p>
    </article>
</template>
<style scoped>
th.instagram,.promotion.instagram{color:#f193ce}th.facebook,.promotion.facebook{color:#6bb5ff}.promotion{display:grid;gap:5px;padding:9px 11px;border-left:2px solid currentColor;border-radius:5px;background:#0a1e33}.promotion b{font-size:12px}.promotion small,td>small{display:block;font-size:11px;margin-top:4px;color:#91a7c0}.matching-note{margin:15px 0 0;font-size:12px;color:#91a7c0;line-height:1.6}
</style>
<style scoped>.panel{min-width:0;padding:18px;border:1px solid #12355a;border-radius:9px;background:linear-gradient(145deg,#071931,#041123);overflow:hidden}.panel.expanded{min-height:420px}header{display:flex;align-items:center;justify-content:space-between;gap:10px}header>div{display:flex;align-items:center;gap:10px}header>div>span{color:#9854ff;font-size:20px}header p{display:grid;margin:0}header b{font-size:14px}header small{margin-top:4px;color:#7f96b3;font-size:11px}header button{border:0;background:transparent;color:#19c8ff;font-size:11px;cursor:pointer}header>em{color:#8296b2;font-size:10px;font-style:normal}.scroll{margin-top:16px;overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap;font-size:11px}th{padding:11px;color:#718aa7;text-align:left}td{padding:10px 11px;border-top:1px solid #112d4b;color:#9fb2c9}td:first-child{display:flex;align-items:center;gap:8px;color:#eef6ff}td img,td>span{width:36px;height:43px;border-radius:4px;object-fit:cover}td>span{display:grid;place-items:center;background:#12355c}td em{padding:5px 7px;border-radius:7px;background:#36234b;color:#cba2ff;font-size:10px;font-style:normal}.empty{padding:35px;color:#7890aa;text-align:center;font-size:11px}</style>

<style scoped>
.panel:not(.expanded){padding:17px;border-color:#19416b;border-radius:11px;background:linear-gradient(130deg,#06182e,#031124)}.panel:not(.expanded) header small{display:none}.panel:not(.expanded) header b{font-size:15px}.panel:not(.expanded) .scroll{margin-top:14px}.panel:not(.expanded) th,.panel:not(.expanded) td{padding:10px 7px}.panel:not(.expanded) td:first-child b{max-width:115px;white-space:normal;font-size:12px;line-height:1.4}.panel:not(.expanded) .promotion{padding:5px 7px;background:transparent}.panel:not(.expanded) .promotion b{font-size:11px}.panel:not(.expanded) .promotion small{font-size:10px}.panel:not(.expanded) table{font-size:12px}.panel:not(.expanded) td img{width:31px;height:42px}.panel:not(.expanded) .empty{min-height:230px;display:grid;place-items:center;font-size:13px}
</style>

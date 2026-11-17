<script setup>
import { formatAdminDate } from '../../lib/adminDate';
import { money, formatBytes } from '../../lib/channels';
import AdminIcon from '../AdminIcon.vue';
defineProps({ rows: Array, columns: Array, loading: Boolean, page: { type: Number, default: 1 }, lastPage: { type: Number, default: 1 }, total: Number, disabled: Boolean, emptyTitle: { type: String, default: 'No records found.' }, emptyDescription: { type: String, default: 'Records will appear here once they are added. Try adjusting your filters.' } });
defineEmits(['page']);
function value(row, column) {
    const result = column.key.split('.').reduce((data, key) => data?.[key], row);
    if (column.bytes) return formatBytes(result);
    if (column.money) return money(result);
    if (column.date) return formatAdminDate(result);
    if (typeof result === 'boolean') return result ? 'Yes' : 'No';
    if (typeof result === 'string' && ['status', 'content_type', 'access_type', 'media_type'].includes(column.key)) return result.replaceAll('_', ' ');
    return result ?? '—';
}
</script>
<template>
    <section class="admin-table-card" :aria-busy="loading">
        <div v-if="loading" class="admin-loading">Loading…</div>
        <template v-else>
            <div class="admin-table-scroll">
                <table><thead><tr><th v-for="column in columns" :key="column.key" scope="col">{{ column.label }}</th><th v-if="$slots.actions" scope="col">Actions</th></tr></thead>
                    <tbody><tr v-for="row in rows" :key="row.id"><td v-for="column in columns" :key="column.key"><slot :name="`cell-${column.key}`" :row="row" :value="value(row, column)"><span v-if="['status', 'is_active', 'is_verified'].includes(column.key)" class="channel-badge" :data-state="String(row[column.key])">{{ value(row, column) }}</span><span v-else>{{ value(row, column) }}</span></slot></td><td v-if="$slots.actions"><div class="channel-table-actions"><slot name="actions" :row="row" /></div></td></tr>
                    <tr v-if="!rows?.length"><td :colspan="columns.length + ($slots.actions ? 1 : 0)"><div class="channel-empty"><AdminIcon name="layers" /><strong>{{ emptyTitle }}</strong><p>{{ emptyDescription }}</p><slot name="empty" /></div></td></tr></tbody>
                </table>
            </div>
            <footer class="admin-pagination"><span v-if="total != null">{{ total }} records</span><button type="button" :disabled="disabled || loading || page <= 1" @click="$emit('page', page - 1)">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button type="button" :disabled="disabled || loading || page >= lastPage" @click="$emit('page', page + 1)">Next</button></footer>
        </template>
    </section>
</template>
<style scoped>
.admin-table-scroll td{max-width:320px;overflow-wrap:anywhere}.channel-badge{display:inline-flex;padding:5px 9px;border:1px solid var(--a-line);border-radius:99px;font-size:10px;text-transform:capitalize;white-space:nowrap;color:var(--a-muted)}.channel-badge[data-state=active],.channel-badge[data-state=published],.channel-badge[data-state=true],.channel-badge[data-state=success]{color:var(--a-cyan);background:rgba(53,213,208,.08)}.channel-badge[data-state=pending],.channel-badge[data-state=draft]{color:#d7aa52;background:rgba(215,170,82,.08)}.channel-badge[data-state=suspended],.channel-badge[data-state=failed]{color:#e98787}.channel-empty{display:grid;justify-items:center;text-align:center;padding:40px 20px;gap:12px}.channel-empty svg{width:30px;height:30px;color:var(--a-muted)}.channel-empty strong{font-size:14px}.channel-empty p{margin:0;max-width:360px;color:var(--a-muted);font-size:12px;line-height:1.7}

.channel-table-actions{display:flex;gap:8px;flex-wrap:wrap}.channel-table-actions :deep(button),.channel-table-actions :deep(a){padding:6px 10px;border:1px solid var(--a-line);border-radius:7px;color:var(--a-cyan);background:transparent;font-size:11px;white-space:nowrap;text-decoration:none}.admin-pagination button:disabled{opacity:.4;cursor:default}
</style>

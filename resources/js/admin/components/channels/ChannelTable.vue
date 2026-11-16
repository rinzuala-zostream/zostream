<script setup>
import { formatAdminDate } from '../../lib/adminDate';
import { money } from '../../lib/channels';
defineProps({ rows: Array, columns: Array, loading: Boolean, page: { type: Number, default: 1 }, lastPage: { type: Number, default: 1 }, total: Number });
defineEmits(['page']);
function value(row, column) {
    const result = column.key.split('.').reduce((data, key) => data?.[key], row);
    if (column.money) return money(result);
    if (column.date) return formatAdminDate(result);
    if (typeof result === 'boolean') return result ? 'Yes' : 'No';
    return result ?? '—';
}
</script>
<template>
    <section class="admin-table-card" :aria-busy="loading">
        <div v-if="loading" class="admin-loading">Loading…</div>
        <template v-else>
            <div class="admin-table-scroll">
                <table><thead><tr><th v-for="column in columns" :key="column.key" scope="col">{{ column.label }}</th><th v-if="$slots.actions" scope="col">Actions</th></tr></thead>
                    <tbody><tr v-for="row in rows" :key="row.id"><td v-for="column in columns" :key="column.key">{{ value(row, column) }}</td><td v-if="$slots.actions"><div class="channel-table-actions"><slot name="actions" :row="row" /></div></td></tr>
                    <tr v-if="!rows.length"><td :colspan="columns.length + ($slots.actions ? 1 : 0)">No records found.</td></tr></tbody>
                </table>
            </div>
            <footer class="admin-pagination"><span v-if="total != null">{{ total }} records</span><button type="button" :disabled="page <= 1" @click="$emit('page', page - 1)">Previous</button><span>Page {{ page }} of {{ lastPage }}</span><button type="button" :disabled="page >= lastPage" @click="$emit('page', page + 1)">Next</button></footer>
        </template>
    </section>
</template>
<style scoped>
.channel-table-actions{display:flex;gap:8px;flex-wrap:wrap}.channel-table-actions :deep(button),.channel-table-actions :deep(a){padding:6px 10px;border:1px solid var(--a-line);border-radius:7px;color:var(--a-cyan);background:transparent;font-size:11px;white-space:nowrap;text-decoration:none}.admin-pagination button:disabled{opacity:.4;cursor:default}
</style>

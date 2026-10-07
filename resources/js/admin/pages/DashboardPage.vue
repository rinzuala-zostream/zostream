<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import PageHeader from '../components/PageHeader.vue';
import StatusPanel from '../components/StatusPanel.vue';
import { api, queryString } from '../lib/api';

const loading = ref(true);
const error = ref('');
const data = ref({});
const defaultComparisonMonth = new Date();
defaultComparisonMonth.setDate(1);
defaultComparisonMonth.setMonth(defaultComparisonMonth.getMonth() - 1);
const filters = reactive({
  period: 'monthly',
  device_type: '',
  date_field: 'created_at',
  chart_month: new Date().toISOString().slice(0, 7),
  comparison_month: defaultComparisonMonth.toISOString().slice(0, 7),
  chart_device_type: '',
});

const overview = computed(() => data.value.overview || {});
const content = computed(() => data.value.content || {});
const trend = computed(() => data.value.subscription_trend || {});
const cards = computed(() => [
  ['Active users', overview.value.total_active_users, 'Live customer accounts'],
  ['Subscribers', overview.value.total_users_with_active_subscription, 'Users with active access'],
  ['Subscriptions', overview.value.total_active_subscriptions, 'Across every device type'],
  ['Movies', overview.value.total_movies ?? content.value.total_movies, 'Published catalog titles'],
  ['Seasons', overview.value.total_seasons ?? content.value.total_seasons, 'Series collections'],
  ['Episodes', overview.value.total_episodes ?? content.value.total_episodes, 'Available episodes'],
]);

const chartColors = ['#14b8a6', '#f97316', '#8b5cf6', '#0ea5e9', '#e11d48', '#84cc16', '#d946ef', '#eab308'];
const chartDays = computed(() => Math.max(trend.value.days || 31, trend.value.previous_days || 31));
const chartWidth = computed(() => 100 + chartDays.value * 44);
const chartHeight = 330;
const chartMax = computed(() => Math.max(1, ...(trend.value.plans || []).flatMap((plan) => [...(plan.current || []), ...(plan.previous || [])])));
const number = (value) => new Intl.NumberFormat('en-IN').format(Number(value || 0));
const money = (value) => new Intl.NumberFormat('en-IN', {
  style: 'currency',
  currency: 'INR',
  maximumFractionDigits: 0,
}).format(Number(value || 0));

function xForDay(index) {
  return 90 + index * 44;
}

function yForValue(value) {
  return 270 - (Number(value || 0) / chartMax.value) * 230;
}

function linePoints(values) {
  return (values || [])
    .map((value, index) => `${xForDay(index)},${yForValue(value)}`)
    .join(' ');
}

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const response = await api(`/admin/dashboard${queryString(filters)}`);
    const legacy = response?.data || response;
    data.value = legacy?.data || legacy || {};
  } catch (reason) {
    error.value = reason.message;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="admin-page">
    <PageHeader eyebrow="Control room" title="Dashboard" description="A live view of Zo Stream's audience, revenue and content library.">
      <button class="admin-secondary" @click="load">Refresh</button>
    </PageHeader>

    <section class="admin-filter-bar">
      <label>Period<select v-model="filters.period" @change="load"><option>daily</option><option>monthly</option><option>yearly</option></select></label>
      <label>Device<select v-model="filters.device_type" @change="load"><option value="">All devices</option><option>mobile</option><option>browser</option><option>tv</option></select></label>
      <label>Date field<select v-model="filters.date_field" @change="load"><option value="created_at">Created</option><option value="start_at">Start</option><option value="end_at">End</option></select></label>
    </section>

    <StatusPanel tone="error" :message="error" />
    <div v-if="loading" class="admin-loading">Loading dashboard…</div>

    <template v-else>
      <section class="admin-stat-grid">
        <article v-for="(card, index) in cards" :key="card[0]" :style="`--card-index:${index}`">
          <p>{{ card[0] }}</p><strong>{{ number(card[1]) }}</strong><span>{{ card[2] }}</span>
        </article>
      </section>

      <section class="admin-panel admin-subscription-trend">
        <header>
          <div>
            <p>SUBSCRIPTION ACTIVITY</p>
            <h2>Daily subscriptions by plan</h2>
            <small>Solid lines: selected month · Dashed lines: previous month</small>
          </div>
          <label>Month<input v-model="filters.chart_month" type="month" @change="load"></label>
          <label>Compare with<input v-model="filters.comparison_month" type="month" @change="load"></label>
          <label>Device type<select v-model="filters.chart_device_type" @change="load"><option value="">All devices</option><option value="mobile">Mobile</option><option value="browser">Browser</option><option value="tv">TV</option></select></label>
        </header>

        <div v-if="trend.plans?.length" class="trend-chart-wrap">
          <svg
            :viewBox="`0 0 ${chartWidth} ${chartHeight}`"
            :style="{ width: `${chartWidth}px`, height: `${chartHeight}px` }"
            role="img"
            :aria-label="`Daily subscriptions by plan for ${trend.month} compared with ${trend.previous_month}`"
          >
            <g v-for="(plan, index) in trend.plans" :key="plan.plan_id">
              <polyline
                v-if="plan.previous?.length > 1"
                :points="linePoints(plan.previous)"
                :stroke="chartColors[index % chartColors.length]"
                class="trend-previous"
              />
              <polyline
                v-if="plan.current?.length > 1"
                :points="linePoints(plan.current)"
                :stroke="chartColors[index % chartColors.length]"
                class="trend-current"
              />
              <circle
                v-for="(value, dayIndex) in plan.previous"
                :key="`previous-${dayIndex}`"
                :cx="xForDay(dayIndex)"
                :cy="yForValue(value)"
                :r="chartDays > 20 ? 2.5 : 4"
                :fill="chartColors[index % chartColors.length]"
                class="trend-point trend-point-previous"
              ><title>{{ trend.previous_month }} · day {{ dayIndex + 1 }} · {{ plan.plan_name || 'Plan' }} · {{ plan.device_type || 'unknown device' }} · {{ number(value) }} subscriptions</title></circle>
              <circle
                v-for="(value, dayIndex) in plan.current"
                :key="`current-${dayIndex}`"
                :cx="xForDay(dayIndex)"
                :cy="yForValue(value)"
                :r="chartDays > 20 ? 2.5 : 4"
                :fill="chartColors[index % chartColors.length]"
                class="trend-point"
              ><title>{{ trend.month }} · day {{ dayIndex + 1 }} · {{ plan.plan_name || 'Plan' }} · {{ plan.device_type || 'unknown device' }} · {{ number(value) }} subscriptions</title></circle>
            </g>
            <line x1="90" :x2="chartWidth - 10" y1="270" y2="270" class="trend-axis" />
            <text
              v-for="day in chartDays"
              :key="day"
              :x="xForDay(day - 1)"
              y="291"
              text-anchor="middle"
            >{{ day }}</text>
            <text :x="(chartWidth + 90) / 2" y="315" text-anchor="middle" class="trend-axis-title">DAY</text>
          </svg>
        </div>
        <div v-else class="trend-empty">No subscription records found for the selected month or previous month.</div>

        <div v-if="trend.plans?.length" class="trend-plan-legend">
          <span v-for="(plan, index) in trend.plans" :key="plan.plan_id"><i :style="{ backgroundColor: chartColors[index % chartColors.length] }"></i>{{ plan.plan_name || 'Plan' }} · {{ plan.device_type || 'unknown' }}</span>
        </div>
        <p v-if="trend.plans?.length" class="trend-month-legend"><span>{{ trend.month }} — solid</span><span>{{ trend.previous_month }} — dashed</span></p>
      </section>

      <section class="admin-dashboard-grid">
        <article class="admin-panel">
          <header><div><p>SUBSCRIPTION MIX</p><h2>Performance by plan</h2></div><strong>{{ money(data.plan_amount_summary?.total_amount) }}</strong></header>
          <div class="admin-plan-list">
            <div v-for="plan in data.active_subscriptions_by_plan || []" :key="plan.plan_id">
              <span><b>{{ plan.plan_name || 'Unnamed plan' }}</b><small>{{ plan.device_type }} · {{ plan.duration_days }} days</small></span>
              <i><b>{{ number(plan.total_active_subscriptions) }}</b><small>{{ money(plan.total_amount) }}</small></i>
            </div>
          </div>
        </article>
        <article class="admin-panel">
          <header><div><p>DEVICE REACH</p><h2>Active subscriptions</h2></div></header>
          <div class="admin-device-list">
            <div v-for="device in data.active_subscriptions_by_device || []" :key="device.device_type">
              <span>{{ device.device_type }}</span><strong>{{ number(device.total_active_subscriptions) }}</strong><small>{{ money(device.total_amount) }}</small>
            </div>
          </div>
        </article>
      </section>
    </template>
  </div>
</template>

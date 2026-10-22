<script setup>
import { onMounted, ref } from 'vue';
import { api, config, formatDate, letter } from '../api.js';

const props = defineProps({ id: { type: Number, required: true } });
defineEmits(['navigate']);

const quiz = ref(null);
const data = ref({ items: [], total: 0, total_pages: 0 });
const stats = ref(null);
const page = ref(1);
const loading = ref(true);
const error = ref('');
const expanded = ref(0);

const exportUrl = () => `${config.exportUrl}&quiz=${props.id}`;
const pct = (part, whole) => (whole ? Math.round((part / whole) * 100) : 0);

async function load(to = 1) {
  loading.value = true;
  error.value = '';
  try {
    const result = await api(`/quizzes/${props.id}/submissions?page=${to}`);
    if (result.stats) stats.value = result.stats;
    data.value = result;
    page.value = to;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function remove(item) {
  if (!window.confirm('¿Eliminar esta respuesta?')) return;
  try {
    await api(`/submissions/${item.id}`, { method: 'DELETE' });
    await load(data.value.items.length === 1 && page.value > 1 ? page.value - 1 : page.value);
    if (page.value !== 1) {
      const first = await api(`/quizzes/${props.id}/submissions?page=1`);
      stats.value = first.stats;
    }
  } catch (e) {
    error.value = e.message;
  }
}

onMounted(async () => {
  try {
    quiz.value = await api(`/quizzes/${props.id}`);
  } catch (e) {
    error.value = e.message;
  }
  await load(1);
});
</script>

<template>
  <div>
    <div class="msc-header">
      <h1 class="wp-heading-inline">Respuestas{{ quiz ? `: ${quiz.title}` : '' }}</h1>
      <a href="#/" class="page-title-action">← Volver al listado</a>
      <span class="msc-spacer"></span>
      <a v-if="quiz" :href="`#/edit/${quiz.id}`" class="button">Editar quiz</a>
      <a v-if="data.total" :href="exportUrl()" class="button button-primary"><span class="dashicons dashicons-download"></span> Exportar CSV</a>
    </div>

    <div v-if="error" class="notice notice-error"><p>{{ error }}</p></div>

    <template v-if="stats && stats.total">
      <div class="msc-stats">
        <div class="msc-card msc-stat"><div class="msc-stat-value">{{ stats.total }}</div><div class="msc-muted">Respuestas recibidas</div></div>
        <div class="msc-card msc-stat"><div class="msc-stat-value">{{ stats.average }}%</div><div class="msc-muted">Puntuación promedio</div></div>
      </div>

      <div class="msc-card">
        <h2>Resultados por pregunta</h2>
        <div v-for="(q, qi) in stats.questions" :key="q.id" class="msc-stat-question">
          <p>
            <strong>{{ qi + 1 }}. {{ q.question }}</strong>
            <span class="msc-muted"> — {{ pct(q.correct, stats.total) }}% de aciertos</span>
          </p>
          <div v-for="(a, ai) in q.answers" :key="a.id" class="msc-bar-row">
            <span class="msc-bar-label" :class="{ 'is-correct': a.is_correct }">
              {{ letter(ai) }}. {{ a.answer }}<span v-if="a.is_correct" class="dashicons dashicons-yes"></span>
            </span>
            <span class="msc-bar"><span :style="{ width: pct(a.picks, stats.total) + '%' }" :class="{ 'is-correct': a.is_correct }"></span></span>
            <span class="msc-bar-count">{{ a.picks }} ({{ pct(a.picks, stats.total) }}%)</span>
          </div>
        </div>
      </div>
    </template>

    <div v-if="loading && !data.items.length" class="msc-loading"><span class="spinner"></span></div>

    <div v-else-if="!data.total" class="msc-card msc-empty">
      <span class="dashicons dashicons-chart-bar"></span>
      <h2>Todavía no hay respuestas</h2>
      <p class="msc-muted">Cuando los usuarios completen el quiz aparecerán aquí.</p>
    </div>

    <template v-else>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Usuario</th>
            <th>IP</th>
            <th style="width: 140px">Puntuación</th>
            <th style="width: 170px"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="item in data.items" :key="item.id">
            <tr>
              <td>{{ formatDate(item.created_at) }}</td>
              <td>{{ item.user }}<div v-if="item.email" class="msc-muted">{{ item.email }}</div></td>
              <td>{{ item.ip || '—' }}</td>
              <td><strong>{{ item.score }}/{{ item.total }}</strong> <span class="msc-muted">({{ Math.round(item.percentage) }}%)</span></td>
              <td>
                <button type="button" class="button button-small" @click="expanded = expanded === item.id ? 0 : item.id">
                  {{ expanded === item.id ? 'Ocultar' : 'Ver detalle' }}
                </button>
                <button type="button" class="button-link msc-danger" title="Eliminar" @click="remove(item)"><span class="dashicons dashicons-trash"></span></button>
              </td>
            </tr>
            <tr v-if="expanded === item.id" class="msc-detail-row">
              <td colspan="5">
                <ol class="msc-detail">
                  <li v-for="r in item.detail" :key="r.question_id">
                    <span class="dashicons" :class="r.correct ? 'dashicons-yes-alt is-good' : 'dashicons-dismiss is-bad'"></span>
                    <strong>{{ r.question }}</strong>
                    <div class="msc-muted">Respondió: {{ r.selected.map((s) => s.answer).join(' · ') || '—' }}</div>
                  </li>
                </ol>
              </td>
            </tr>
          </template>
        </tbody>
      </table>

      <div v-if="data.total_pages > 1" class="tablenav bottom">
        <div class="tablenav-pages">
          <span class="displaying-num">{{ data.total }} elementos</span>
          <span class="pagination-links">
            <button type="button" class="button" :disabled="page <= 1 || loading" @click="load(page - 1)">‹</button>
            <span class="paging-input"> {{ page }} de {{ data.total_pages }} </span>
            <button type="button" class="button" :disabled="page >= data.total_pages || loading" @click="load(page + 1)">›</button>
          </span>
        </div>
      </div>
    </template>
  </div>
</template>

<style>
.msc-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }
.msc-stat-value { font-size: 2em; font-weight: 700; color: #2457a7; line-height: 1.2; }
.msc-stat-question { padding: 6px 0 12px; border-bottom: 1px solid #f0f0f1; }
.msc-stat-question:last-child { border-bottom: 0; }
.msc-bar-row { display: grid; grid-template-columns: minmax(0, 2fr) minmax(80px, 1fr) 90px; gap: 12px; align-items: center; margin: 4px 0; }
.msc-bar-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msc-bar-label.is-correct { font-weight: 600; color: #1a7a36; }
.msc-bar-label .dashicons { color: #1a7a36; }
.msc-bar { height: 10px; background: #f0f0f1; border-radius: 99px; overflow: hidden; }
.msc-bar > span { display: block; height: 100%; background: #a7aaad; border-radius: 99px; }
.msc-bar > span.is-correct { background: #2457a7; }
.msc-bar-count { text-align: right; color: #646970; }
.msc-detail { margin: 4px 0 4px 20px; }
.msc-detail li { margin-bottom: 8px; }
.msc-detail .is-good { color: #1a7a36; }
.msc-detail .is-bad { color: #b32d2e; }
</style>

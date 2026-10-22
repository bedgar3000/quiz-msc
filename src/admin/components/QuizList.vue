<script setup>
import { computed, onMounted, ref } from 'vue';
import { api, AVAILABILITY, copy, formatDate } from '../api.js';
import SettingsCard from './SettingsCard.vue';

defineEmits(['navigate']);

const quizzes = ref([]);
const loading = ref(true);
const error = ref('');
const notice = ref('');
const search = ref('');
const copied = ref(0);
const showSettings = ref(false);

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase();
  if (!term) return quizzes.value;
  return quizzes.value.filter((q) => q.title.toLowerCase().includes(term) || q.slug.includes(term));
});

async function load() {
  loading.value = true;
  error.value = '';
  try {
    quizzes.value = await api('/quizzes');
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function duplicate(quiz) {
  try {
    const created = await api(`/quizzes/${quiz.id}/duplicate`, { method: 'POST' });
    notice.value = `Se creó «${created.title}» como borrador.`;
    await load();
  } catch (e) {
    error.value = e.message;
  }
}

async function remove(quiz) {
  const extra = quiz.submission_count ? ` y sus ${quiz.submission_count} respuestas registradas` : '';
  if (!window.confirm(`¿Eliminar «${quiz.title}»${extra}? Esta acción no se puede deshacer.`)) return;
  try {
    await api(`/quizzes/${quiz.id}`, { method: 'DELETE' });
    quizzes.value = quizzes.value.filter((q) => q.id !== quiz.id);
    notice.value = 'Quiz eliminado.';
  } catch (e) {
    error.value = e.message;
  }
}

async function copyShortcode(quiz) {
  if (await copy(quiz.shortcode)) {
    copied.value = quiz.id;
    setTimeout(() => (copied.value = 0), 1500);
  }
}

onMounted(load);
</script>

<template>
  <div>
    <div class="msc-header">
      <h1 class="wp-heading-inline">Quiz MSC</h1>
      <a href="#/new" class="page-title-action">Añadir nuevo</a>
      <span class="msc-spacer"></span>
      <input v-if="quizzes.length" v-model="search" type="search" class="regular-text" placeholder="Buscar quiz…" />
      <button type="button" class="button" :class="{ active: showSettings }" @click="showSettings = !showSettings">
        <span class="dashicons dashicons-admin-generic"></span> Ajustes
      </button>
    </div>

    <SettingsCard v-if="showSettings" />

    <div v-if="notice" class="notice notice-success is-dismissible"><p>{{ notice }}</p></div>
    <div v-if="error" class="notice notice-error"><p>{{ error }}</p></div>

    <div v-if="loading" class="msc-loading"><span class="spinner"></span></div>

    <div v-else-if="!quizzes.length" class="msc-card msc-empty">
      <span class="dashicons dashicons-forms"></span>
      <h2>Aún no hay quizzes</h2>
      <p class="msc-muted">Crea el primero y publícalo con su URL propia o con un shortcode.</p>
      <a href="#/new" class="button button-primary button-hero">Crear quiz</a>
    </div>

    <table v-else class="wp-list-table widefat fixed striped">
      <thead>
        <tr>
          <th class="column-primary" style="width: 30%">Título</th>
          <th>Shortcode</th>
          <th style="width: 110px">Estado</th>
          <th>Publicación</th>
          <th>Expiración</th>
          <th style="width: 80px">Preguntas</th>
          <th style="width: 90px">Respuestas</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="quiz in filtered" :key="quiz.id">
          <td class="column-primary">
            <strong><a class="row-title" :href="`#/edit/${quiz.id}`">{{ quiz.title }}</a></strong>
            <div class="msc-muted">/{{ quiz.slug }}</div>
            <div class="row-actions visible">
              <span class="edit"><a :href="`#/edit/${quiz.id}`">Editar</a> | </span>
              <span><a :href="`#/submissions/${quiz.id}`">Respuestas</a> | </span>
              <span><a :href="quiz.url" target="_blank" rel="noopener">{{ quiz.availability === 'open' ? 'Ver' : 'Vista previa' }}</a> | </span>
              <span><a href="#" @click.prevent="duplicate(quiz)">Duplicar</a> | </span>
              <span class="trash"><a href="#" @click.prevent="remove(quiz)">Eliminar</a></span>
            </div>
          </td>
          <td>
            <code class="msc-code">{{ quiz.shortcode }}</code>
            <button type="button" class="button-link" :title="'Copiar'" @click="copyShortcode(quiz)">
              <span class="dashicons" :class="copied === quiz.id ? 'dashicons-yes' : 'dashicons-admin-page'"></span>
            </button>
          </td>
          <td><span class="msc-badge" :class="AVAILABILITY[quiz.availability].cls">{{ AVAILABILITY[quiz.availability].label }}</span></td>
          <td>{{ formatDate(quiz.published_at) }}</td>
          <td>{{ formatDate(quiz.expires_at) }}</td>
          <td>{{ quiz.question_count }}</td>
          <td><a :href="`#/submissions/${quiz.id}`">{{ quiz.submission_count }}</a></td>
        </tr>
        <tr v-if="!filtered.length">
          <td colspan="7" class="msc-muted">Ningún quiz coincide con «{{ search }}».</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

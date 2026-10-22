<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import QuizList from './components/QuizList.vue';
import QuizEditor from './components/QuizEditor.vue';
import Submissions from './components/Submissions.vue';

// Navegación por hash: #/  ·  #/new  ·  #/edit/5  ·  #/submissions/5
const hash = ref(window.location.hash);
const route = computed(() => {
  const [, view = '', id = ''] = hash.value.replace(/^#/, '').split('/');
  return { view: view || 'list', id: Number(id) || 0 };
});

const editor = ref(null);
// Al crear un quiz se pasa de #/new a #/edit/ID sin volver a montar el editor.
const adoptedId = ref(0);
const editorKey = computed(() => {
  const { view, id } = route.value;
  return view === 'new' || (view === 'edit' && id === adoptedId.value) ? 'new' : `edit${id}`;
});

function onHashChange() {
  if (editor.value && editor.value.isDirty() && !window.confirm('Hay cambios sin guardar. ¿Salir de todos modos?')) {
    window.history.replaceState(null, '', hash.value || '#/');
    return;
  }
  hash.value = window.location.hash;
  window.scrollTo(0, 0);
}

function navigate(to) {
  window.location.hash = to;
}

onMounted(() => window.addEventListener('hashchange', onHashChange));
onBeforeUnmount(() => window.removeEventListener('hashchange', onHashChange));
</script>

<template>
  <div class="msc-admin">
    <QuizEditor
      v-if="route.view === 'new' || route.view === 'edit'"
      ref="editor"
      :key="editorKey"
      :id="route.id"
      @created="adoptedId = $event"
      @navigate="navigate"
    />
    <Submissions v-else-if="route.view === 'submissions'" :key="'s' + route.id" :id="route.id" @navigate="navigate" />
    <QuizList v-else @navigate="navigate" />
  </div>
</template>

<style>
.msc-admin { max-width: 1200px; }
.msc-admin .msc-header { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 6px 0 16px; }
.msc-admin .msc-header h1 { margin: 0; padding: 0; }
.msc-admin .msc-header .msc-spacer { flex: 1; }
.msc-admin .notice { margin: 0 0 16px; }
.msc-admin code.msc-code { user-select: all; white-space: nowrap; }
.msc-admin .msc-badge { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 12px; font-weight: 600; line-height: 1.6; }
.msc-admin .msc-badge.is-draft { background: #f0f0f1; color: #50575e; }
.msc-admin .msc-badge.is-scheduled { background: #fcf9e8; color: #8a6d00; }
.msc-admin .msc-badge.is-expired { background: #fcf0f1; color: #b32d2e; }
.msc-admin .msc-badge.is-open { background: #edfaef; color: #1a7a36; }
.msc-admin .msc-muted { color: #646970; }
.msc-admin .msc-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 16px 20px; margin-bottom: 16px; }
.msc-admin .msc-card > h2 { margin: 0 0 12px; font-size: 15px; }
.msc-admin .msc-empty { text-align: center; padding: 48px 20px; }
.msc-admin .msc-empty .dashicons { font-size: 48px; width: 48px; height: 48px; color: #a7aaad; }
.msc-admin .button .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-top; }
.msc-admin .msc-loading { padding: 40px; text-align: center; }
.msc-admin .msc-loading .spinner { float: none; visibility: visible; }
</style>

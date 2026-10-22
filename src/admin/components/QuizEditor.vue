<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { api, AVAILABILITY, config, copy, letter, quizUrl, slugify } from '../api.js';

const props = defineProps({ id: { type: Number, default: 0 } });
const emit = defineEmits(['navigate', 'created']);

let keySeq = 0;
const key = () => `k${++keySeq}`;

const newAnswer = () => ({ _key: key(), answer: '', note: '', selection_note: '', is_correct: false });
const newQuestion = () => ({
  _key: key(),
  question: '',
  instruction: '',
  note: '',
  type: 'single',
  answers: [newAnswer(), newAnswer()],
  _open: true,
});

const quiz = reactive({
  id: 0,
  title: '',
  slug: '',
  eyebrow: '',
  description: '',
  footer_text: '',
  status: 'draft',
  published_at: '',
  expires_at: '',
  availability: 'draft',
  url: '',
  shortcode: '',
  questions: [],
});

const loading = ref(!!props.id);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const slugTouched = ref(!!props.id);
const saved = ref('');
const copied = ref(false);
const showIssues = ref(false);

function serialize() {
  return {
    title: quiz.title,
    slug: quiz.slug,
    eyebrow: quiz.eyebrow,
    description: quiz.description,
    footer_text: quiz.footer_text,
    status: quiz.status,
    published_at: quiz.published_at,
    expires_at: quiz.expires_at,
    questions: quiz.questions.map((q) => ({
      id: q.id,
      question: q.question,
      instruction: q.instruction,
      note: q.note,
      type: q.type,
      answers: q.answers.map((a) => ({
        id: a.id,
        answer: a.answer,
        note: a.note,
        selection_note: a.selection_note,
        is_correct: a.is_correct,
      })),
    })),
  };
}

function isDirty() {
  return !loading.value && JSON.stringify(serialize()) !== saved.value;
}
defineExpose({ isDirty });

function hydrate(data) {
  Object.assign(quiz, data, {
    questions: data.questions.map((q) => ({
      ...q,
      _key: key(),
      _open: false,
      answers: q.answers.map((a) => ({ ...a, _key: key() })),
    })),
  });
  saved.value = JSON.stringify(serialize());
}

const previewUrl = computed(() => quizUrl(quiz.slug || slugify(quiz.title) || 'mi-quiz'));
const urlPrefix = computed(() => quizUrl('__s__').split('__s__')[0]);
const urlSuffix = computed(() => quizUrl('__s__').split('__s__')[1] || '');

function onTitleInput() {
  if (!slugTouched.value) quiz.slug = slugify(quiz.title);
}

function onSlugBlur() {
  slugTouched.value = true;
  quiz.slug = slugify(quiz.slug);
  if (!quiz.slug) {
    slugTouched.value = false;
    quiz.slug = slugify(quiz.title);
  }
}

/* Preguntas y respuestas */
function move(list, index, delta) {
  const to = index + delta;
  if (to < 0 || to >= list.length) return;
  const [item] = list.splice(index, 1);
  list.splice(to, 0, item);
}

function addQuestion() {
  quiz.questions.forEach((q) => (q._open = false));
  quiz.questions.push(newQuestion());
}

function duplicateQuestion(index) {
  const source = quiz.questions[index];
  const copyQ = JSON.parse(JSON.stringify(source));
  delete copyQ.id;
  copyQ._key = key();
  copyQ._open = true;
  copyQ.answers.forEach((a) => {
    delete a.id;
    a._key = key();
  });
  quiz.questions.splice(index + 1, 0, copyQ);
}

function removeQuestion(index) {
  const q = quiz.questions[index];
  if ((q.question || q.answers.some((a) => a.answer)) && !window.confirm(`¿Eliminar la pregunta ${index + 1}?`)) return;
  quiz.questions.splice(index, 1);
}

function removeAnswer(q, index) {
  q.answers.splice(index, 1);
}

function setCorrect(q, answer, checked) {
  if (q.type === 'single' && checked) q.answers.forEach((a) => (a.is_correct = a === answer));
  else answer.is_correct = checked;
}

function onTypeChange(q) {
  if (q.type === 'single') {
    let found = false;
    q.answers.forEach((a) => {
      if (a.is_correct && !found) found = true;
      else a.is_correct = false;
    });
  }
}

function questionIssues(q) {
  const issues = [];
  if (!q.question.trim()) issues.push('Falta el texto de la pregunta.');
  if (q.answers.length < 2) issues.push('Necesita al menos dos respuestas.');
  if (q.answers.some((a) => !a.answer.trim())) issues.push('Hay respuestas vacías.');
  const correct = q.answers.filter((a) => a.is_correct).length;
  if (q.type === 'single' && correct !== 1) issues.push('Marca exactamente una respuesta correcta.');
  if (q.type === 'multiple' && correct < 1) issues.push('Marca al menos una respuesta correcta.');
  return issues;
}

const defaultInstruction = (q) =>
  q.type === 'multiple' ? 'Selecciona todas las respuestas correctas.' : 'Selecciona una respuesta.';

/* Guardar */
function validate() {
  if (!quiz.title.trim()) return 'El título del quiz es obligatorio.';
  if (quiz.published_at && quiz.expires_at && quiz.expires_at <= quiz.published_at) {
    return 'La fecha de expiración debe ser posterior a la fecha de publicación.';
  }
  if (quiz.status === 'published' && !quiz.questions.length) return 'Agrega al menos una pregunta antes de publicar el quiz.';
  const bad = quiz.questions.findIndex((q) => questionIssues(q).length);
  if (bad !== -1) {
    quiz.questions[bad]._open = true;
    return `Pregunta ${bad + 1}: ${questionIssues(quiz.questions[bad])[0]}`;
  }
  return '';
}

async function save() {
  showIssues.value = true;
  error.value = validate();
  notice.value = '';
  if (error.value) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    return;
  }
  saving.value = true;
  try {
    const wasNew = !quiz.id;
    const data = await api(wasNew ? '/quizzes' : `/quizzes/${quiz.id}`, {
      method: wasNew ? 'POST' : 'PUT',
      body: serialize(),
    });
    const open = new Set(quiz.questions.map((q, i) => (q._open ? i : -1)));
    hydrate(data);
    quiz.questions.forEach((q, i) => (q._open = open.has(i)));
    slugTouched.value = true;
    notice.value = 'Quiz guardado.';
    if (wasNew) {
      emit('created', data.id);
      emit('navigate', `#/edit/${data.id}`);
    }
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

async function copyShortcode() {
  if (await copy(quiz.shortcode)) {
    copied.value = true;
    setTimeout(() => (copied.value = false), 1500);
  }
}

function onBeforeUnload(event) {
  if (isDirty()) {
    event.preventDefault();
    event.returnValue = '';
  }
}

function onKeydown(event) {
  if ((event.ctrlKey || event.metaKey) && event.key === 's') {
    event.preventDefault();
    save();
  }
}

onMounted(async () => {
  window.addEventListener('beforeunload', onBeforeUnload);
  window.addEventListener('keydown', onKeydown);
  if (!props.id) {
    quiz.questions.push(newQuestion());
    saved.value = JSON.stringify(serialize());
    return;
  }
  try {
    hydrate(await api(`/quizzes/${props.id}`));
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
});

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', onBeforeUnload);
  window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
  <div class="msc-editor">
    <div class="msc-header">
      <h1 class="wp-heading-inline">{{ quiz.id ? 'Editar quiz' : 'Nuevo quiz' }}</h1>
      <a href="#/" class="page-title-action">← Volver al listado</a>
      <span class="msc-spacer"></span>
      <template v-if="quiz.id">
        <span class="msc-badge" :class="AVAILABILITY[quiz.availability].cls">{{ AVAILABILITY[quiz.availability].label }}</span>
        <a :href="`#/submissions/${quiz.id}`" class="button">Respuestas</a>
        <a :href="quiz.url" target="_blank" rel="noopener" class="button">
          {{ quiz.availability === 'open' ? 'Ver quiz' : 'Vista previa' }} <span class="dashicons dashicons-external"></span>
        </a>
      </template>
      <button type="button" class="button button-primary" :disabled="saving || loading" @click="save">
        {{ saving ? 'Guardando…' : 'Guardar quiz' }}
      </button>
    </div>

    <div v-if="notice" class="notice notice-success"><p>{{ notice }}</p></div>
    <div v-if="error" class="notice notice-error"><p>{{ error }}</p></div>

    <div v-if="loading" class="msc-loading"><span class="spinner"></span></div>

    <div v-else class="msc-editor-grid">
      <div class="msc-editor-main">
        <div class="msc-card">
          <input
            v-model="quiz.title"
            type="text"
            class="msc-title-input"
            placeholder="Título del quiz"
            @input="onTitleInput"
          />
          <p class="msc-permalink">
            <strong>URL:</strong>
            <span class="msc-muted">{{ urlPrefix }}</span><input
              v-model="quiz.slug"
              type="text"
              class="msc-slug-input"
              placeholder="slug"
              @input="slugTouched = true"
              @blur="onSlugBlur"
            /><span class="msc-muted">{{ urlSuffix }}</span>
          </p>
          <table class="form-table" role="presentation">
            <tbody>
              <tr>
                <th scope="row"><label for="msc-eyebrow">Antetítulo</label></th>
                <td><input id="msc-eyebrow" v-model="quiz.eyebrow" type="text" class="regular-text" placeholder="Preguntas prácticas" /></td>
              </tr>
              <tr>
                <th scope="row"><label for="msc-description">Descripción</label></th>
                <td>
                  <textarea id="msc-description" v-model="quiz.description" rows="2" class="large-text" placeholder="Responde estas {n} preguntas y revisa los resultados al final"></textarea>
                  <p class="description">Usa <code>{n}</code> para el número de preguntas. Si se deja vacío se usa el texto de ejemplo.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="msc-footer">Pie de página</label></th>
                <td>
                  <input id="msc-footer" v-model="quiz.footer_text" type="text" class="regular-text" placeholder="MS Consultores © año | Website By Ktech Solutions" />
                  <p class="description">Si se deja vacío: «MS Consultores © {{ new Date().getFullYear() }} | Website By Ktech Solutions», con enlace a ktech.com.do.</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <h2 class="msc-section-title">Preguntas <span class="msc-muted">({{ quiz.questions.length }})</span></h2>

        <div
          v-for="(q, qi) in quiz.questions"
          :key="q._key"
          class="msc-card msc-question"
          :class="{ 'has-issues': showIssues && questionIssues(q).length }"
        >
          <div class="msc-question-head">
            <button type="button" class="msc-question-toggle" @click="q._open = !q._open">
              <span class="msc-number">{{ String(qi + 1).padStart(2, '0') }}</span>
              <span class="msc-question-summary">{{ q.question || 'Pregunta sin texto' }}</span>
              <span class="msc-muted msc-type-label">{{ q.type === 'multiple' ? 'Selección múltiple' : 'Selección única' }} · {{ q.answers.length }} resp.</span>
              <span class="dashicons" :class="q._open ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2'"></span>
            </button>
            <div class="msc-tools">
              <button type="button" class="button button-small" title="Subir" :disabled="qi === 0" @click="move(quiz.questions, qi, -1)"><span class="dashicons dashicons-arrow-up-alt"></span></button>
              <button type="button" class="button button-small" title="Bajar" :disabled="qi === quiz.questions.length - 1" @click="move(quiz.questions, qi, 1)"><span class="dashicons dashicons-arrow-down-alt"></span></button>
              <button type="button" class="button button-small" title="Duplicar" @click="duplicateQuestion(qi)"><span class="dashicons dashicons-admin-page"></span></button>
              <button type="button" class="button button-small msc-danger" title="Eliminar" @click="removeQuestion(qi)"><span class="dashicons dashicons-trash"></span></button>
            </div>
          </div>

          <div v-if="q._open" class="msc-question-body">
            <div class="msc-field">
              <label>Pregunta</label>
              <textarea v-model="q.question" rows="2" class="large-text" placeholder="Escribe la pregunta"></textarea>
            </div>
            <div class="msc-row">
              <div class="msc-field">
                <label>Tipo de respuesta</label>
                <select v-model="q.type" @change="onTypeChange(q)">
                  <option value="single">Selección única</option>
                  <option value="multiple">Selección múltiple</option>
                </select>
              </div>
              <div class="msc-field msc-grow">
                <label>Instrucción <span class="msc-muted">(opcional)</span></label>
                <input v-model="q.instruction" type="text" class="large-text" :placeholder="defaultInstruction(q)" />
              </div>
            </div>
            <div class="msc-field">
              <label>Nota / comentario <span class="msc-muted">(opcional · se muestra en la retroalimentación cuando la respuesta es incorrecta)</span></label>
              <textarea v-model="q.note" rows="2" class="large-text"></textarea>
            </div>

            <table class="widefat msc-answers">
              <thead>
                <tr>
                  <th style="width: 30px"></th>
                  <th>Respuesta</th>
                  <th>Notas</th>
                  <th style="width: 80px; text-align: center">Correcta</th>
                  <th style="width: 96px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(a, ai) in q.answers" :key="a._key" :class="{ 'is-correct': a.is_correct }">
                  <td><strong>{{ letter(ai) }}.</strong></td>
                  <td><textarea v-model="a.answer" rows="2" placeholder="Texto de la respuesta"></textarea></td>
                  <td>
                    <label class="msc-note-label">Nota al seleccionar <span class="msc-muted">(opcional · se muestra en cuanto el usuario la elige)</span></label>
                    <textarea v-model="a.selection_note" rows="2" class="msc-selection-note" placeholder="Aparece en un recuadro amarillo bajo la respuesta"></textarea>
                    <label class="msc-note-label">Retroalimentación al corregir <span class="msc-muted">(opcional)</span></label>
                    <textarea v-model="a.note" rows="2" placeholder="Explicación de por qué es o no correcta"></textarea>
                  </td>
                  <td style="text-align: center">
                    <input
                      :type="q.type === 'single' ? 'radio' : 'checkbox'"
                      :name="'correct-' + q._key"
                      :checked="a.is_correct"
                      @change="setCorrect(q, a, $event.target.checked)"
                    />
                  </td>
                  <td class="msc-tools">
                    <button type="button" class="button-link" title="Subir" :disabled="ai === 0" @click="move(q.answers, ai, -1)"><span class="dashicons dashicons-arrow-up-alt"></span></button>
                    <button type="button" class="button-link" title="Bajar" :disabled="ai === q.answers.length - 1" @click="move(q.answers, ai, 1)"><span class="dashicons dashicons-arrow-down-alt"></span></button>
                    <button type="button" class="button-link msc-danger" title="Eliminar" @click="removeAnswer(q, ai)"><span class="dashicons dashicons-no-alt"></span></button>
                  </td>
                </tr>
              </tbody>
            </table>
            <p><button type="button" class="button" @click="q.answers.push(newAnswer())">+ Añadir respuesta</button></p>
          </div>

          <ul v-if="showIssues && questionIssues(q).length" class="msc-issues">
            <li v-for="issue in questionIssues(q)" :key="issue">{{ issue }}</li>
          </ul>
        </div>

        <p><button type="button" class="button button-secondary button-large" @click="addQuestion">+ Añadir pregunta</button></p>
      </div>

      <aside class="msc-editor-side">
        <div class="msc-card">
          <h2>Publicación</h2>
          <div class="msc-field">
            <label for="msc-status">Estado</label>
            <select id="msc-status" v-model="quiz.status">
              <option value="draft">Borrador</option>
              <option value="published">Publicado</option>
            </select>
          </div>
          <div class="msc-field">
            <label for="msc-published">Fecha de publicación</label>
            <input id="msc-published" v-model="quiz.published_at" type="datetime-local" />
            <p class="description">Vacío = disponible en cuanto se publique.</p>
          </div>
          <div class="msc-field">
            <label for="msc-expires">Fecha de expiración</label>
            <input id="msc-expires" v-model="quiz.expires_at" type="datetime-local" :min="quiz.published_at || undefined" />
            <p class="description">Vacío = no expira. Hora del sitio ({{ config.now ? config.now.replace('T', ' ') : '' }}).</p>
          </div>
          <button type="button" class="button button-primary button-large msc-full" :disabled="saving" @click="save">
            {{ saving ? 'Guardando…' : 'Guardar quiz' }}
          </button>
        </div>

        <div class="msc-card">
          <h2>Mostrar el quiz</h2>
          <p class="msc-muted">URL propia (plantilla en blanco, sin header ni footer del tema):</p>
          <p><a :href="quiz.id ? quiz.url : undefined" target="_blank" rel="noopener" class="msc-break">{{ quiz.id ? quiz.url : previewUrl }}</a></p>
          <template v-if="quiz.id">
            <p class="msc-muted">Shortcode para insertarlo en cualquier página:</p>
            <p>
              <code class="msc-code">{{ quiz.shortcode }}</code>
              <button type="button" class="button-link" title="Copiar" @click="copyShortcode">
                <span class="dashicons" :class="copied ? 'dashicons-yes' : 'dashicons-admin-page'"></span>
              </button>
            </p>
            <p class="msc-muted">También: <code>[msc_quiz slug="{{ quiz.slug }}"]</code></p>
          </template>
          <p v-else class="msc-muted">El shortcode estará disponible al guardar.</p>
        </div>
      </aside>
    </div>
  </div>
</template>

<style>
.msc-editor-grid { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; align-items: start; }
@media (max-width: 1100px) { .msc-editor-grid { grid-template-columns: 1fr; } }
.msc-editor-side { position: sticky; top: 46px; }
.msc-title-input { width: 100%; font-size: 1.6em; padding: 6px 10px; line-height: 1.3; }
.msc-permalink { display: flex; align-items: center; flex-wrap: wrap; gap: 4px; margin: 10px 0 0; }
.msc-slug-input { min-width: 220px; padding: 0 6px; min-height: 26px; }
.msc-section-title { font-size: 1.2em; margin: 24px 0 12px; }
.msc-question { padding: 0; }
.msc-question.has-issues { border-color: #d63638; }
.msc-question-head { display: flex; align-items: center; gap: 8px; padding: 10px 14px; }
.msc-question-toggle { flex: 1; display: flex; align-items: center; gap: 12px; background: none; border: 0; padding: 4px 0; cursor: pointer; text-align: left; min-width: 0; }
.msc-number { display: grid; place-items: center; flex: 0 0 34px; height: 34px; border-radius: 9px; color: #2457a7; background: #edf4ff; font-weight: 700; }
.msc-question-summary { flex: 1; font-weight: 600; font-size: 14px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.msc-type-label { white-space: nowrap; }
.msc-question-body { border-top: 1px solid #dcdcde; padding: 14px 18px 4px; }
.msc-tools { display: flex; gap: 4px; align-items: center; white-space: nowrap; }
.msc-tools .button-link:disabled { opacity: .35; }
.msc-danger, .msc-danger .dashicons { color: #b32d2e !important; }
.msc-field { margin-bottom: 14px; }
.msc-field > label { display: block; font-weight: 600; margin-bottom: 4px; }
.msc-field select, .msc-field input[type="datetime-local"] { width: 100%; max-width: 100%; }
.msc-row { display: flex; gap: 16px; flex-wrap: wrap; }
.msc-grow { flex: 1; min-width: 240px; }
.msc-answers { margin-bottom: 8px; }
.msc-answers td { vertical-align: top; }
.msc-answers textarea { width: 100%; }
.msc-answers tr.is-correct td { background: #edfaef; }
.msc-note-label { display: block; font-size: 12px; font-weight: 600; margin: 0 0 2px; }
.msc-answers textarea + .msc-note-label { margin-top: 8px; }
.msc-answers textarea.msc-selection-note { background: #fff4cc; }
.msc-issues { margin: 0; padding: 8px 18px 12px 34px; color: #b32d2e; list-style: disc; }
.msc-full { width: 100%; text-align: center; }
.msc-break { word-break: break-all; }
</style>

<script setup>
import { computed, nextTick, reactive, ref } from 'vue';

const props = defineProps({
  quiz: { type: Object, required: true },
  standalone: { type: Boolean, default: false },
  config: { type: Object, default: () => ({}) },
});

const uid = Math.random().toString(36).slice(2, 8);
const questions = props.quiz.questions || [];
const total = questions.length;
const isOpen = props.quiz.availability === 'open';

const selections = reactive(Object.fromEntries(questions.map((q) => [q.id, []])));
const feedback = reactive({});
const currentStep = ref(0);
const submitted = ref(false);
const sending = ref(false);
const result = ref(null);

const root = ref(null);
const cardEls = ref([]);
const resultEl = ref(null);

const eyebrow = props.quiz.eyebrow || 'Preguntas prácticas';
const description = (props.quiz.description || 'Responde estas {n} preguntas y revisa los resultados al final').replace(/\{n\}/g, total);

const answeredCount = computed(() => questions.filter((q) => selections[q.id].length > 0).length);
const percent = computed(() => (total ? Math.round((answeredCount.value / total) * 100) : 0));
const isLast = computed(() => currentStep.value === total - 1);

const resultTitle = computed(() => {
  if (!result.value) return 'Quiz completado';
  if (result.value.score === result.value.total) return '¡Excelente trabajo!';
  return result.value.percentage >= 60 ? '¡Buen trabajo!' : 'Sigue practicando';
});
const resultMessage = computed(() => {
  if (!result.value) return '';
  return result.value.score === result.value.total
    ? 'Has respondido correctamente todas las preguntas.'
    : 'Revisa la retroalimentación de cada pregunta para reforzar tus conocimientos.';
});

function pad(n) {
  return String(n).padStart(2, '0');
}

function letter(index) {
  let s = '';
  for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) s = String.fromCharCode(65 + ((n - 1) % 26)) + s;
  return s;
}

function instruction(q) {
  if (q.instruction) return q.instruction;
  return q.type === 'multiple' ? 'Selecciona todas las respuestas correctas.' : 'Selecciona una respuesta.';
}

function inputName(q) {
  return `msc-quiz-${props.quiz.id}-${uid}-${q.id}`;
}

function isChecked(q, answer) {
  return selections[q.id].includes(answer.id);
}

function toggle(q, answer, event) {
  if (q.type === 'multiple') {
    const list = selections[q.id];
    const at = list.indexOf(answer.id);
    if (event.target.checked && at === -1) list.push(answer.id);
    if (!event.target.checked && at !== -1) list.splice(at, 1);
  } else {
    selections[q.id] = [answer.id];
  }
}

function setFeedback(q, cls, text) {
  feedback[q.id] = { cls, text };
}

function scrollTo(el, block = 'start') {
  if (el) el.scrollIntoView({ behavior: 'smooth', block });
}

function showStep(index) {
  currentStep.value = Math.max(0, Math.min(index, total - 1));
}

async function go(delta) {
  if (delta > 0 && !submitted.value) {
    const q = questions[currentStep.value];
    if (selections[q.id].length === 0) {
      setFeedback(q, 'bad', 'Selecciona una respuesta para continuar.');
      return;
    }
  }
  showStep(currentStep.value + delta);
  await nextTick();
  scrollTo(cardEls.value[currentStep.value]);
}

async function request(body, withNonce = true) {
  const headers = { 'Content-Type': 'application/json' };
  if (withNonce && props.config.nonce) headers['X-WP-Nonce'] = props.config.nonce;
  const response = await fetch(`${props.config.restUrl}/quizzes/${props.quiz.id}/submit`, {
    method: 'POST',
    headers,
    credentials: 'same-origin',
    body: JSON.stringify(body),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    if (withNonce && data.code === 'rest_cookie_invalid_nonce') return request(body, false);
    throw new Error(data.message || 'No se pudo enviar el quiz. Inténtalo de nuevo.');
  }
  return data;
}

async function gradeQuiz() {
  if (submitted.value || sending.value) return;

  const firstIndex = questions.findIndex((q) => selections[q.id].length === 0);
  if (firstIndex !== -1) {
    showStep(firstIndex);
    setFeedback(questions[firstIndex], 'bad', 'Selecciona una respuesta antes de finalizar el quiz.');
    await nextTick();
    scrollTo(cardEls.value[firstIndex], 'center');
    return;
  }

  sending.value = true;
  try {
    const data = await request({ answers: selections });
    data.results.forEach((r) => {
      feedback[r.question_id] = { cls: r.correct ? 'good' : 'bad', text: r.feedback };
    });
    data.details = data.results.map((r, i) => ({ n: i + 1, correct: r.correct }));
    result.value = data;
    submitted.value = true;
    await nextTick();
    scrollTo(resultEl.value);
  } catch (error) {
    setFeedback(questions[currentStep.value], 'bad', error.message);
  } finally {
    sending.value = false;
  }
}

async function resetQuiz() {
  questions.forEach((q) => {
    selections[q.id] = [];
    delete feedback[q.id];
  });
  submitted.value = false;
  result.value = null;
  showStep(0);
  await nextTick();
  if (props.standalone) window.scrollTo({ top: 0, behavior: 'smooth' });
  else scrollTo(root.value);
}

async function review() {
  showStep(0);
  await nextTick();
  scrollTo(cardEls.value[0]);
}
</script>

<template>
  <div ref="root">
    <header class="hero">
      <div class="hero-inner" :class="{ 'has-logo': quiz.logo }">
        <img v-if="quiz.logo" class="hero-logo" :src="quiz.logo" :alt="quiz.site_name || ''" />
        <div class="hero-text">
          <div class="eyebrow">{{ eyebrow }}</div>
          <h1>{{ quiz.title }}</h1>
          <p v-if="description">{{ description }}</p>
        </div>
      </div>
    </header>

    <component :is="standalone ? 'main' : 'div'" class="msc-quiz-main">
      <section v-if="!isOpen" class="result-card show" aria-live="polite">
        <div class="eyebrow" style="color: var(--muted)">{{ quiz.availability === 'expired' ? 'Quiz expirado' : 'Próximamente' }}</div>
        <h2>{{ quiz.availability === 'expired' ? 'Este quiz ya no está disponible' : 'Este quiz aún no está disponible' }}</h2>
        <p>{{ quiz.message }}</p>
      </section>

      <template v-else>
        <section class="progress-card" aria-label="Progreso del cuestionario">
          <div class="progress-top">
            <span>{{ answeredCount }} de {{ total }} preguntas respondidas</span>
            <span>{{ percent }}%</span>
          </div>
          <div class="progress-track"><div class="progress-fill" :style="{ width: percent + '%' }"></div></div>
        </section>

        <form @submit.prevent>
          <section
            v-for="(q, qi) in questions"
            :key="q.id"
            :ref="(el) => (cardEls[qi] = el)"
            class="question-card"
            :class="{ active: qi === currentStep }"
            :data-question="'q' + (qi + 1)"
          >
            <div class="question-heading">
              <div class="number">{{ pad(qi + 1) }}</div>
              <div>
                <h2>{{ q.question }}</h2>
                <p class="instruction">{{ instruction(q) }}</p>
              </div>
            </div>
            <div class="options">
              <template v-for="(a, ai) in q.answers" :key="a.id">
                <label class="option">
                  <input
                    :type="q.type === 'multiple' ? 'checkbox' : 'radio'"
                    :name="inputName(q)"
                    :value="letter(ai)"
                    :checked="isChecked(q, a)"
                    :disabled="submitted"
                    @change="toggle(q, a, $event)"
                  /><span><strong>{{ letter(ai) }}.</strong> {{ a.answer }}</span>
                </label>
                <div v-if="a.selection_note && isChecked(q, a)" class="selection-note" aria-live="polite">{{ a.selection_note }}</div>
              </template>
            </div>
            <div class="feedback" :class="feedback[q.id] ? ['show', feedback[q.id].cls] : []" aria-live="polite">
              {{ feedback[q.id] ? feedback[q.id].text : '' }}
            </div>
          </section>

          <div class="step-nav">
            <button class="secondary" type="button" :disabled="currentStep === 0" @click="go(-1)">Anterior</button>
            <span class="step-note">Pregunta {{ currentStep + 1 }} de {{ total }}</span>
            <button v-if="!isLast" class="primary" type="button" @click="go(1)">Siguiente</button>
          </div>
          <div class="actions">
            <button v-if="isLast && !submitted" class="primary" type="button" :disabled="sending" @click="gradeQuiz">
              {{ sending ? 'Corrigiendo…' : 'Finalizar y corregir' }}
            </button>
            <button class="secondary" type="button" @click="resetQuiz">Reiniciar quiz</button>
          </div>
        </form>

        <section ref="resultEl" class="result-card" :class="{ show: submitted }" aria-live="polite">
          <div class="eyebrow" style="color: var(--muted)">Resultado final</div>
          <h2>{{ resultTitle }}</h2>
          <div class="score">{{ result ? `${result.score}/${result.total} (${result.percentage}%)` : `0/${total}` }}</div>
          <p>{{ resultMessage }}</p>
          <div class="result-details">
            <template v-if="result">
              <p v-for="d in result.details" :key="d.n"><strong>Pregunta {{ d.n }}:</strong> {{ d.correct ? 'Correcta' : 'Incorrecta' }}</p>
            </template>
          </div>
          <div class="actions"><button class="primary" type="button" @click="review">Revisar respuestas</button></div>
        </section>
      </template>
    </component>

    <footer v-if="quiz.footer_text">{{ quiz.footer_text }}</footer>
    <footer v-else>
      MS Consultores © {{ quiz.year || new Date().getFullYear() }}&nbsp;&nbsp;|&nbsp;&nbsp;Website By
      <a href="https://ktech.com.do" target="_blank" rel="noopener">Ktech Solutions</a>
    </footer>
  </div>
</template>

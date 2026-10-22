import { createApp } from 'vue';
import Quiz from './Quiz.vue';
import './quiz.css';

function mountAll() {
  document.querySelectorAll('.msc-quiz[data-msc-quiz]:not([data-mounted])').forEach((el) => {
    let quiz;
    try {
      quiz = JSON.parse(el.dataset.mscQuiz);
    } catch (e) {
      return;
    }
    el.dataset.mounted = '1';
    createApp(Quiz, {
      quiz,
      standalone: el.dataset.standalone === '1',
      config: window.MSC_QUIZ || {},
    }).mount(el);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountAll);
} else {
  mountAll();
}

// Elementor puede insertar el shortcode después de la carga (popups, pestañas).
window.MscQuizMount = mountAll;

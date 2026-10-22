<script setup>
import { onMounted, ref } from 'vue';
import { api } from '../api.js';

const logoId = ref(0);
const logoUrl = ref('');
const saving = ref(false);
const message = ref('');
const error = ref('');

async function save(id) {
  saving.value = true;
  error.value = '';
  message.value = '';
  try {
    const data = await api('/settings', { method: 'POST', body: { logo_id: id } });
    logoId.value = data.logo_id;
    logoUrl.value = data.logo_url;
    message.value = 'Ajustes guardados.';
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
}

function chooseLogo() {
  const frame = window.wp.media({
    title: 'Logo del quiz',
    button: { text: 'Usar este logo' },
    library: { type: 'image' },
    multiple: false,
  });
  frame.on('select', () => save(frame.state().get('selection').first().get('id')));
  frame.open();
}

onMounted(async () => {
  try {
    const data = await api('/settings');
    logoId.value = data.logo_id;
    logoUrl.value = data.logo_url;
  } catch (e) {
    error.value = e.message;
  }
});
</script>

<template>
  <div class="msc-card msc-settings">
    <h2>Ajustes generales</h2>
    <div class="msc-settings-row">
      <div class="msc-logo-preview">
        <img v-if="logoUrl" :src="logoUrl" alt="" />
        <span v-else class="msc-muted">Sin logo</span>
      </div>
      <div>
        <p><strong>Logo de la cabecera del quiz</strong></p>
        <p class="msc-muted">
          Se muestra junto al título (máx. 118 px de ancho). Usa una versión clara, porque va sobre el fondo azul.
          <template v-if="!logoId"> Ahora se usa el logo del sitio.</template>
        </p>
        <p>
          <button type="button" class="button" :disabled="saving" @click="chooseLogo">Elegir logo</button>
          <button v-if="logoId" type="button" class="button-link msc-danger" :disabled="saving" @click="save(0)">Usar el logo del sitio</button>
        </p>
        <p v-if="message" class="msc-ok">{{ message }}</p>
        <p v-if="error" class="msc-danger">{{ error }}</p>
      </div>
    </div>
  </div>
</template>

<style>
.msc-settings-row { display: flex; gap: 20px; align-items: flex-start; }
.msc-settings p { margin: 0 0 8px; }
.msc-settings .button-link { margin-left: 10px; }
.msc-logo-preview { flex: 0 0 150px; height: 120px; display: grid; place-items: center; border-radius: 6px; background: linear-gradient(125deg, #14243a, #2457a7); }
.msc-logo-preview img { max-width: 118px; max-height: 100px; }
.msc-logo-preview .msc-muted { color: #c3d3ee; }
.msc-ok { color: #1a7a36; }
</style>

import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

// Se compila una entrada por ejecución (ENTRY=admin | public) en formato IIFE,
// con nombres fijos para que PHP pueda encolar assets/dist/{entry}.js y .css.
const entry = process.env.ENTRY || 'admin';

export default defineConfig({
  plugins: [vue()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
    __VUE_OPTIONS_API__: 'false',
    __VUE_PROD_DEVTOOLS__: 'false',
    __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
  },
  build: {
    outDir: 'assets/dist',
    emptyOutDir: false,
    cssCodeSplit: false,
    lib: {
      entry: fileURLToPath(new URL(`./src/${entry}/main.js`, import.meta.url)),
      name: entry === 'admin' ? 'MscQuizAdmin' : 'MscQuizPublic',
      formats: ['iife'],
      fileName: () => `${entry}.js`,
      cssFileName: entry,
    },
  },
});

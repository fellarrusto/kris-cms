import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv } from 'vite'

// L'editor compilato va in kris/editor/dist/ ed è versionato: sugli hosting non serve Node.
// index.php legge manifest.json per includere i file con il loro nome con l'impronta.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: './',
    plugins: [vue()],
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    build: {
      outDir: '../kris/editor/dist',
      emptyOutDir: true,
      manifest: 'manifest.json',
      sourcemap: false,
      rollupOptions: {
        // vue.js e kit.js sono punti d'ingresso con le loro esportazioni: i moduli dei plugin li importano
        // (import map di index.php: "vue" e "@kris/editor") e usano la stessa istanza di Vue, Pinia e componenti.
        input: { main: 'src/main.js', kit: 'src/kit.js', vue: 'src/vue.js' },
        preserveEntrySignatures: 'exports-only',
      },
    },
    server: {
      // In sviluppo le richieste all'editor PHP passano dal server di Kris (php -S)
      proxy: env.VITE_PROXY_TARGET ? { '/kris-admin': { target: env.VITE_PROXY_TARGET, changeOrigin: true } } : undefined,
    },
  }
})

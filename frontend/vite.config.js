import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost/pos/backend',
        changeOrigin: true,
      },
      // QZ Tray: proxy sign-message.php to the backend PHP server in dev
      '/pos/backend/sign-message.php': {
        target: 'http://localhost',
        changeOrigin: true,
      },
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('recharts')) return 'charts'
          if (id.includes('zustand')) return 'store'
          if (id.includes('node_modules')) return 'vendor'
        },
      },
    },
  },
})

import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost/real-estate-backend/public',
        changeOrigin: true,
        secure: false,
      },
      '/uploads': {
        target: 'http://localhost/real-estate-backend/public',
        changeOrigin: true,
        secure: false,
      },
    },
  },
})

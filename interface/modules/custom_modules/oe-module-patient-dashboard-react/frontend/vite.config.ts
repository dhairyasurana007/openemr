import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "node:path";

export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
  },
  build: {
    outDir: "../public/assets",
    emptyOutDir: false,
    rollupOptions: {
      input: resolve(__dirname, "src/main.tsx"),
      output: {
        entryFileNames: "dashboard.js",
        assetFileNames: "dashboard.[ext]"
      }
    }
  }
});

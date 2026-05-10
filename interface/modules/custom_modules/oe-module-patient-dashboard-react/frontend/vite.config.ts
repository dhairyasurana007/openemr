import { defineConfig } from "vite";
import { resolve } from "node:path";

export default defineConfig({
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

import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { copyFileSync, mkdirSync } from 'fs';

// https://vitejs.dev/config/
// Relative base so chunks/CSS preload resolve next to admin-shell.js inside the plugin
// (default "/" would request /js/... from the site root and 404 in WordPress).
export default defineConfig(({ mode }) => ({
  base: './',
  plugins: [
    react(),
    {
      name: 'copy-image-files',
      closeBundle() {
        // Create assets directory if it doesn't exist
        const assetsDir = path.resolve(__dirname, 'assets/react-build/assets');
        try {
          mkdirSync(assetsDir, { recursive: true });
        } catch (err) {
          console.error('Error creating assets directory:', err);
        }

        // Copy SVG files
        try {
          copyFileSync(
            path.resolve(__dirname, 'src/assets/images/fkjogo.svg'),
            path.resolve(__dirname, 'assets/react-build/assets/fkjogo.svg')
          );
          copyFileSync(
            path.resolve(__dirname, 'src/assets/images/llmmagnetlogo.svg'),
            path.resolve(__dirname, 'assets/react-build/assets/llmmagnetlogo.svg')
          );
          console.log('SVG files copied successfully');
        } catch (err) {
          console.error('Error copying SVG files:', err);
        }
        
        // Copy banner image
        try {
          copyFileSync(
            path.resolve(__dirname, 'src/assets/images/banner_upgrade.jpg'),
            path.resolve(__dirname, 'assets/react-build/assets/banner_upgrade.jpg')
          );
          console.log('Banner image copied successfully');
        } catch (err) {
          console.error('Error copying banner image:', err);
        }

        // Copy onboarding images with stable names (no hash) so WordPress can serve them via pluginUrl
        const onboardingImages = [
          'onboarding_logo.png',
          'preonboarding.jpg',
          'firstvisit.jpg',
          'llmstxt.jpg',
          'robotstxt.jpg',
          'reports.jpg',
          'step1-llm-visit.png',
          'step2-llms-txt.png',
          'step3-robots.png',
          'step4-email.png',
          'step5-chatgpt.png',
        ];
        const onboardingAssetsDir = path.resolve(__dirname, 'assets/react-build/assets/onboarding');
        try {
          mkdirSync(onboardingAssetsDir, { recursive: true });
          for (const img of onboardingImages) {
            copyFileSync(
              path.resolve(__dirname, `src/assets/onboarding/${img}`),
              path.resolve(__dirname, `assets/react-build/assets/onboarding/${img}`)
            );
          }
          console.log('Onboarding images copied successfully');
        } catch (err) {
          console.error('Error copying onboarding images:', err);
        }
      }
    }
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    outDir: 'assets/react-build',
    emptyOutDir: true,
    sourcemap: mode === 'development',
    rollupOptions: {
      input: {
        index: path.resolve(__dirname, 'src/main.tsx'),
        'admin-shell': path.resolve(__dirname, 'src/admin-shell-main.tsx'),
        dashboard: path.resolve(__dirname, 'src/dashboard-main.tsx'),
        overview: path.resolve(__dirname, 'src/overview-main.tsx'),
        analytics: path.resolve(__dirname, 'src/analytics-main.tsx'),
        pages: path.resolve(__dirname, 'src/pages-main.tsx'),
        products: path.resolve(__dirname, 'src/products-main.tsx'),
        'bot-analytics': path.resolve(__dirname, 'src/bot-analytics-main.tsx'),
        reports: path.resolve(__dirname, 'src/reports-main.tsx'),
        'content-settings': path.resolve(__dirname, 'src/content-settings-main.tsx'),
        'schema-jsonld': path.resolve(__dirname, 'src/schema-jsonld-main.tsx'),
        'system-information': path.resolve(__dirname, 'src/system-information-main.tsx'),
      },
      output: {
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: ({name}) => {
          if (/\.(css)$/.test(name ?? '')) {
            return 'css/[name][extname]';
          }
          return 'assets/[name]-[hash][extname]';
        },
      },
      external: ['chart.js', 'chart.js/auto'],
    },
  },
}));
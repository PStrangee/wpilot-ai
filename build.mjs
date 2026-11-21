import esbuild from 'esbuild';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

/**
 * Maps React imports to WordPress Core's `wp-element` global (`window.wp.element`)
 * so the plugin reuses the bundled React runtime without duplicating it.
 */
const wpElementExternalPlugin = {
  name: 'wp-element-external',
  setup(build) {
    build.onResolve({ filter: /^react$/ }, () => ({ path: 'react', namespace: 'wp-element' }));
    build.onResolve({ filter: /^react-dom\/client$/ }, () => ({ path: 'react-dom-client', namespace: 'wp-element' }));

    build.onLoad({ filter: /^react$/, namespace: 'wp-element' }, () => ({
      contents: `
        const el = (window.wp && window.wp.element) || window.React;
        export default el;
        export const createElement = el.createElement;
        export const Fragment = el.Fragment;
        export const useState = el.useState;
        export const useEffect = el.useEffect;
        export const useRef = el.useRef;
        export const useCallback = el.useCallback;
        export const useMemo = el.useMemo;
      `,
      loader: 'js',
    }));

    build.onLoad({ filter: /^react-dom-client$/, namespace: 'wp-element' }, () => ({
      contents: `
        const el = (window.wp && window.wp.element) || window.ReactDOM;
        export function createRoot(container) {
          if (el && typeof el.createRoot === 'function') {
            return el.createRoot(container);
          }
          return {
            render(node) {
              el.render(node, container);
            },
          };
        }
      `,
      loader: 'js',
    }));
  },
};

fs.mkdirSync(path.join(__dirname, 'assets', 'build'), { recursive: true });

await esbuild.build({
  entryPoints: [path.join(__dirname, 'assets', 'src', 'index.tsx')],
  bundle: true,
  minify: true,
  format: 'iife',
  target: ['es2020'],
  outfile: path.join(__dirname, 'assets', 'build', 'admin.js'),
  plugins: [wpElementExternalPlugin],
  banner: {
    js: '/* WPilot AI WordPress Admin Bundle — built from assets/src/index.tsx */',
  },
});

await esbuild.build({
  entryPoints: [path.join(__dirname, 'assets', 'src', 'styles.css')],
  bundle: true,
  minify: true,
  outfile: path.join(__dirname, 'assets', 'build', 'admin.css'),
});

console.log('Built assets/build/admin.js and assets/build/admin.css');

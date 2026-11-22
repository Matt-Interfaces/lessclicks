import svelte from 'rollup-plugin-svelte';
import resolve from '@rollup/plugin-node-resolve';
import commonjs from '@rollup/plugin-commonjs';
import css from 'rollup-plugin-css-only';
import terser from '@rollup/plugin-terser';

const production = !process.env.ROLLUP_WATCH;

// WordPress.org does not allow references to remote files. jsPDF ships a
// hard-coded cdnjs URL for its "pdfobjectnewwindow" output mode, which this
// plugin never uses. Strip it from the generated bundles.
const stripRemoteReferences = () => ({
    name: 'strip-remote-references',
    generateBundle(options, bundle) {
        for (const file of Object.values(bundle)) {
            if (file.type === 'chunk') {
                file.code = file.code.replace(
                    /https:\/\/cdnjs\.cloudflare\.com\/ajax\/libs\/pdfobject\/[\d.]+\/pdfobject\.min\.js/g,
                    ''
                );
            }
        }
    }
});

export default [
    // Dashboard widget bundle
    {
        input: 'dashboardwidget.js',
        output: {
            sourcemap: !production,
            format: 'iife',
            name: 'LessclicksWidget',
            file: 'public/build/dashboardwidget.js',
            inlineDynamicImports: true
        },
        plugins: [
            svelte({
                compilerOptions: {
                    dev: !production,
                    // Keep CSS inline in JS for the widget
                    css: 'injected'
                },
                emitCss: false
            }),
            resolve({
                browser: true,
                dedupe: ['svelte']
            }),
            commonjs(),
            production && terser(),
            stripRemoteReferences()
        ],
        watch: {
            clearScreen: false
        }
    },
    // Settings page bundle
    {
        input: 'settings.js',
        output: {
            sourcemap: !production,
            format: 'iife',
            name: 'LessclicksSettings',
            file: 'public/build/settings.js',
            inlineDynamicImports: true
        },
        plugins: [
            svelte({
                compilerOptions: {
                    dev: !production
                }
            }),
            css({ output: 'bundle.css' }),
            resolve({
                browser: true,
                dedupe: ['svelte']
            }),
            commonjs(),
            production && terser(),
            stripRemoteReferences()
        ],
        watch: {
            clearScreen: false
        }
    }
];

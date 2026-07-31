const { defineConfig } = require('vitest/config')
const vue2 = require('@vitejs/plugin-vue2')
const path = require('path')

module.exports = defineConfig({
	// Vue 2 SFC transform, so the suite can mount real components. There were zero
	// component tests before, which is why a Sleep button that returns HTTP 400, a
	// permanently-empty Wi-Fi tile and a "Saved" message that visibly reverted all
	// shipped with a green test run.
	plugins: [vue2.default ? vue2.default() : vue2()],
	test: {
		environment: 'happy-dom',
		include: ['src/__tests__/**/*.{spec,test}.{js,ts}'],
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
			// Tests only: the compiler-included build, so a test can stub a component
			// with a `template` string. The webpack build is untouched and still ships
			// the runtime-only bundle.
			vue: path.resolve(__dirname, 'node_modules/vue/dist/vue.esm.js'),
		},
	},
})

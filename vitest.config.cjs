const { defineConfig } = require('vitest/config')
const path = require('path')

module.exports = defineConfig({
	test: {
		environment: 'happy-dom',
		include: ['src/__tests__/**/*.{spec,test}.{js,ts}'],
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
		},
	},
})

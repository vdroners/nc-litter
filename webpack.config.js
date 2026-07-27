const path = require('path')
const { merge } = require('webpack-merge')
const baseConfig = require('@nextcloud/webpack-vue-config')
const webpack = require('webpack')
const pkg = require('./package.json')

module.exports = merge(baseConfig, {
	entry: {
		main: path.resolve(__dirname, 'src', 'main.js'),
		admin: path.resolve(__dirname, 'src', 'admin-settings.js'),
	},
	output: {
		publicPath: 'auto',
		filename: 'nc_litter-[name].js',
		chunkFilename: 'nc_litter-[name].js?v=[contenthash]',
	},
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
		},
	},
	plugins: [
		new webpack.DefinePlugin({
			__NC_LITTER_FRONTEND_VERSION__: JSON.stringify(pkg.version || '0.0.0'),
		}),
	],
	optimization: {
		splitChunks: { chunks: 'async' },
	},
})

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
    ...defaultConfig,
    entry: {
        ...defaultConfig.entry,
		'bolt-sync-manager/index': path.resolve( process.cwd(), 'src/bolt-sync-manager', 'index.js' )
    },
    output : {
        path: path.resolve( __dirname, 'build')
    },
    devtool: 'source-map'
};

/*!
 * Grunt file
 *
 * @package DeletePagesForGood
 */

/* eslint-env node */
module.exports = function ( grunt ) {
	var conf = grunt.file.readJSON( 'extension.json' );

	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-eslint' );

	grunt.initConfig( {
		banana: conf.MessagesDirs,
		eslint: {
			options: {
				cache: true
			},
			all: [
				'**/*.{js,json}',
				'!node_modules/**',
				'!vendor/**'
			]
		}
	} );

	grunt.registerTask( 'lint', [ 'eslint', 'banana' ] );
	grunt.registerTask( 'test', [ 'lint', 'banana' ] );
	grunt.registerTask( 'default', 'test' );
};

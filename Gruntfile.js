/**
 * Grunt tasks: `npx grunt readme` (or `npm run readme`) turns readme.txt into
 * README.md for GitHub, with "Live Preview" (the plugin directory's, in
 * WordPress Playground) and "Docs" badges under the title.
 *
 * @param {Object} grunt Grunt.
 */
const PLAYGROUND_BADGE =
	'[![Live Preview](https://img.shields.io/badge/Live_Preview-WordPress_Playground-3858e9?logo=wordpress)](https://wordpress.org/plugins/eizport/?preview=1)';
const DOCS_BADGE =
	'[![Docs](https://img.shields.io/badge/Docs-eizport.github.io-1d35b4)](https://eizport.github.io/)';

module.exports = function ( grunt ) {
	grunt.initConfig( {
		wp_readme_to_markdown: {
			readme: {
				files: {
					'README.md': 'readme.txt',
				},
				options: {
					// The WordPress.org screenshots, from the repo until the plugin has its directory page.
					screenshot_url: 'https://raw.githubusercontent.com/eizport/eizport/trunk/.wordpress-org/{screenshot}.png',
					post_convert( readme ) {
						return readme.replace( /^(# .+ #\n)/, '$1\n' + PLAYGROUND_BADGE + ' ' + DOCS_BADGE + '\n\n' );
					},
				},
			},
		},
	} );

	grunt.loadNpmTasks( 'grunt-wp-readme-to-markdown' );
	grunt.registerTask( 'readme', [ 'wp_readme_to_markdown' ] );
	grunt.registerTask( 'default', [ 'readme' ] );
};

<?php
/**
 * Release command for Eizport (from the wp-release-command
 * skill's template, for a plugin published on WordPress.org): bumps the
 * version, refuses a release without changelog and upgrade notice, runs the
 * checks (composer check, JS build, README, Plugin Check), then commits,
 * tags (the version, "latest" and "stable", moved from the previous release) and
 * pushes. The version tag runs .github/workflows/release.yml, which builds
 * the zip, publishes the GitHub release and, once enabled, deploys to the
 * WordPress.org SVN.
 *
 * Three kinds of version (the flow is in .github/CONTRIBUTING.md):
 * - X.Y.Z-dev: trunk's next version. Header and constant only; no notes,
 *   no tag, Stable tag untouched ("Start X.Y.Z-dev").
 * - X.Y.Z-rc.N (or -beta.N): a GitHub-only pre-release from release/X.Y.
 *   Tagged, with X.Y.Z's notes; Stable tag, "latest" and "stable" untouched.
 * - X.Y.Z: the release, from release/X.Y. Everything above.
 *
 * Not shipped (bin/ is export-ignored). The POT is kept current by
 * composer check; translations come from translate.wordpress.org.
 *
 * Usage, from the WordPress Studio site root:
 *   studio wp --require=wp-content/plugins/eizport/bin/release.php eizport release patch
 *   … eizport release minor|major|1.2.3
 *   … eizport release 1.2.0-dev          (trunk, after cutting release/1.1)
 *   … eizport release 1.1.0-rc.1         (release/1.1, GitHub only)
 *   … eizport release patch              (from 1.1.0-dev or 1.1.0-rc.1: 1.1.0)
 *   … eizport release patch --no-commit|--no-tag|--no-push
 *   … eizport release patch --no-checks   (skip composer check, build and Plugin Check)
 *
 * @package Eizport
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- a WP-CLI dev tool run before WordPress loads.

const EIZPORT_RELEASE_LATEST_TAG = 'latest';
const EIZPORT_RELEASE_STABLE_TAG = 'stable';

/**
 * Runs a shell command; prints its output; stops the release on failure.
 *
 * @param string      $cmd    Shell command.
 * @param string|null $cwd    Working directory.
 * @param bool        $silent Don't print stdout.
 * @return string Stdout.
 */
function eizport_release_run( string $cmd, ?string $cwd = null, bool $silent = false ): string {
	list( $exit, $stdout, $stderr ) = eizport_release_try_run( $cmd, $cwd );
	if ( ! $silent && '' !== $stdout ) {
		WP_CLI::line( rtrim( $stdout ) );
	}
	if ( 0 !== $exit ) {
		WP_CLI::error( '' !== $stderr ? rtrim( $stderr ) : "Command failed (exit $exit): $cmd" );
	}

	return $stdout;
}

/**
 * Runs a shell command without stopping.
 *
 * @param string      $cmd Shell command.
 * @param string|null $cwd Working directory.
 * @return array{ 0: int, 1: string, 2: string } Exit code, stdout, stderr.
 */
function eizport_release_try_run( string $cmd, ?string $cwd = null ): array {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- runs git, composer, npm and wp for the release.
	$process = proc_open(
		$cmd,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd
	);
	if ( ! is_resource( $process ) ) {
		return array( 1, '', "Failed to start: $cmd" );
	}
	fclose( $pipes[0] );
	$stdout = (string) stream_get_contents( $pipes[1] );
	$stderr = (string) stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array( proc_close( $process ), $stdout, $stderr );
}

/**
 * Whether a command is on PATH.
 *
 * @param string $cmd Command.
 * @return bool
 */
function eizport_release_has_cmd( string $cmd ): bool {
	return 0 === eizport_release_try_run( 'command -v ' . escapeshellarg( $cmd ) )[0];
}

/**
 * The next version.
 *
 * @param string $current Current version (X.Y.Z, or a pre-release).
 * @param string $bump    patch, minor, major or X.Y.Z[-pre].
 * @return string
 */
function eizport_release_bump( string $current, string $bump ): string {
	// An explicit version, maybe a pre-release (GitHub only: WordPress.org takes numbers only): 1.2.3, 1.2.3-beta1.
	if ( preg_match( '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $bump ) ) {
		if ( version_compare( $bump, $current, '<=' ) ) {
			WP_CLI::error( "$bump is not after $current." );
		}
		return $bump;
	}
	// From a dev or pre-release version, patch is the final of its series: 1.1.0-dev → 1.1.0.
	if ( 'patch' === $bump && preg_match( '/^(\d+\.\d+\.\d+)-/', $current, $m ) ) {
		return $m[1];
	}
	if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $current, $m ) ) {
		WP_CLI::error( "Current version $current is not X.Y.Z: give the next version (like 1.2.3), or patch for its final." );
	}
	switch ( $bump ) {
		case 'major':
			return ( $m[1] + 1 ) . '.0.0';
		case 'minor':
			return $m[1] . '.' . ( $m[2] + 1 ) . '.0';
		case 'patch':
			return $m[1] . '.' . $m[2] . '.' . ( $m[3] + 1 );
	}
	WP_CLI::error( "Unknown bump \"$bump\": patch, minor, major or X.Y.Z[-pre]." );
	return '';
}

/**
 * The lines of one version's entry in a readme section.
 *
 * @param string $readme  readme.txt.
 * @param string $section Section title ("Changelog", "Upgrade Notice").
 * @param string $version Version.
 * @return string Non-empty lines, or ''.
 */
function eizport_release_entry( string $readme, string $section, string $version ): string {
	if ( ! preg_match( '/^== ' . preg_quote( $section, '/' ) . ' ==\s*$(.*?)(?=^== |\z)/ms', $readme, $part ) ) {
		return '';
	}
	if ( ! preg_match( '/^= ' . preg_quote( $version, '/' ) . ' =\s*$(.*?)(?=^= |\z)/ms', $part[1], $entry ) ) {
		return '';
	}

	return trim( (string) preg_replace( '/^\s*$\n/m', '', $entry[1] ) );
}

/**
 * Releases Eizport.
 */
class Eizport_Release_Command {

	/**
	 * Bump the version, check, commit, tag and push a release.
	 *
	 * ## OPTIONS
	 *
	 * <bump>
	 * : patch, minor, major, or an explicit X.Y.Z, X.Y.Z-dev or X.Y.Z-rc.N.
	 *
	 * [--commit]
	 * : Commit the release. Default true; --no-commit stops after bumping (implies --no-tag).
	 *
	 * [--tag]
	 * : Tag the release commit. Default true; --no-tag implies --no-push.
	 *
	 * [--push]
	 * : Push the branch and the tag. Default true.
	 *
	 * [--checks]
	 * : Run composer check, the JS build, the README and Plugin Check. Default true.
	 *
	 * ## EXAMPLES
	 *
	 *   studio wp --require=wp-content/plugins/eizport/bin/release.php eizport release patch
	 *   studio wp --require=wp-content/plugins/eizport/bin/release.php eizport release 1.0.0 --no-push
	 *
	 * @when before_wp_load
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		// Declared positive, negated by WP-CLI's --no-<flag>: --no-commit ⇒ --no-tag ⇒ --no-push.
		$commit = false !== ( $assoc_args['commit'] ?? true );
		$tag    = $commit && false !== ( $assoc_args['tag'] ?? true );
		$push   = $tag && false !== ( $assoc_args['push'] ?? true );
		$checks = false !== ( $assoc_args['checks'] ?? true );

		$dir    = dirname( __DIR__ );
		$main   = $dir . '/eizport.php';
		$readme = $dir . '/readme.txt';

		if ( ! eizport_release_has_cmd( 'git' ) ) {
			WP_CLI::error( 'git is required.' );
		}
		// Only the release notes (readme.txt) may be pending: the release commits everything.
		$dirty = array_filter(
			explode( "\n", rtrim( eizport_release_run( 'git status --porcelain', $dir, true ) ) ), // "XY path": keep the leading space.
			function ( $line ) {
				return '' !== $line && 'readme.txt' !== trim( substr( $line, 3 ) );
			}
		);
		if ( $dirty ) {
			WP_CLI::error( "Commit or stash these first; the release commits everything:\n" . implode( "\n", $dirty ) );
		}

		$source = (string) file_get_contents( $main );
		if ( ! preg_match( '/^ \* Version:\s*(\S+)$/m', $source, $m ) ) {
			WP_CLI::error( 'No " * Version:" line in eizport.php.' );
		}
		$current = $m[1];
		$version = eizport_release_bump( $current, (string) ( $args[0] ?? '' ) );
		// The final this version leads to (1.1.0 for 1.1.0-rc.1): its notes are the release's text.
		$final = (string) preg_replace( '/-.*$/', '', $version );
		$dev   = (bool) preg_match( '/-dev$/', $version );
		$pre   = ! $dev && $final !== $version;
		WP_CLI::log( "Eizport $current → $version" . ( $dev ? ' (dev: no tag)' : ( $pre ? ' (pre-release: GitHub only)' : '' ) ) );

		list( $has_remote ) = eizport_release_try_run( 'git remote get-url origin', $dir );
		if ( 0 === $has_remote && ! $dev ) {
			eizport_release_run( 'git fetch origin --tags --quiet', $dir, true );
			if ( 0 === eizport_release_try_run( 'git ls-remote --exit-code origin refs/tags/' . escapeshellarg( $version ), $dir )[0] ) {
				WP_CLI::error( "Tag $version already exists on origin." );
			}
		}

		// Every copy of the version: header, the namespaced constant, and Stable tag for a final only.
		WP_CLI::log( '  → bumping the version' );
		$source = preg_replace( '/^( \* Version:\s*)\S+$/m', '${1}' . $version, $source, 1 );
		$source = preg_replace( "/^const VERSION = '[^']*';$/m", "const VERSION = '$version';", (string) $source, 1, $count );
		if ( 1 !== $count ) {
			WP_CLI::error( "No \"const VERSION = '…';\" line in eizport.php." );
		}
		$text = (string) file_get_contents( $readme );
		if ( ! $dev && ! $pre ) {
			$text = (string) preg_replace( '/^Stable tag:.*$/m', 'Stable tag: ' . $version, $text, 1 );
		}

		// Notes are written before releasing, in both sections; nothing is touched until they are.
		$missing = $dev ? array() : array_filter(
			array( 'Changelog', 'Upgrade Notice' ),
			function ( $section ) use ( $text, $final ) {
				return '' === eizport_release_entry( $text, $section, $final );
			}
		);
		if ( $missing ) {
			WP_CLI::error( "readme.txt has no \"= $final =\" entry with notes under: " . implode( ', ', $missing ) . '.' );
		}
		file_put_contents( $main, $source );
		file_put_contents( $readme, $text );

		if ( $checks ) {
			$this->checks( $dir );
		}

		if ( ! $commit ) {
			WP_CLI::success( "Bumped to $version (--no-commit)." );
			return;
		}
		eizport_release_run( 'git add -A', $dir, true );
		eizport_release_run( 'git commit --quiet -m ' . escapeshellarg( $dev ? "Start $version" : "chore: release $version" ), $dir, true );
		$branch = trim( eizport_release_run( 'git rev-parse --abbrev-ref HEAD', $dir, true ) );
		if ( $dev ) {
			// A dev version is never tagged: trunk just says what comes next.
			if ( ! $push || 0 !== $has_remote ) {
				WP_CLI::success( "Started $version locally (not pushed). Push with: git push origin $branch" );
				return;
			}
			eizport_release_run( 'git push origin ' . escapeshellarg( $branch ) . ' --quiet', $dir, true );
			WP_CLI::success( "Started $version." );
			return;
		}
		if ( ! $tag ) {
			WP_CLI::success( "Committed $version (--no-tag)." );
			return;
		}
		eizport_release_run( 'git tag ' . escapeshellarg( $version ), $dir, true );
		// "latest" and "stable" move with final releases only: a pre-release is GitHub-only and never the download.
		$moved = $pre ? array() : array( EIZPORT_RELEASE_LATEST_TAG, EIZPORT_RELEASE_STABLE_TAG );
		foreach ( $moved as $name ) {
			eizport_release_run( 'git tag --force ' . $name, $dir, true );
		}
		$tags = $moved ? ' (also tagged ' . implode( ' and ', $moved ) . ')' : ' (pre-release)';
		if ( ! $push || 0 !== $has_remote ) {
			WP_CLI::success( "Released $version locally (not pushed)$tags. Push with: git push origin $branch $version" . ( $moved ? ' && git push --force origin ' . implode( ' ', $moved ) : '' ) );
			return;
		}
		eizport_release_run( 'git push origin ' . escapeshellarg( $branch ) . ' --quiet', $dir, true );
		eizport_release_run( 'git push origin ' . escapeshellarg( $version ) . ' --quiet', $dir, true );
		if ( $moved ) {
			eizport_release_run( 'git push --force origin ' . implode( ' ', $moved ) . ' --quiet', $dir, true );
		}
		WP_CLI::success( "Released $version$tags: the version tag runs the Release workflow." );
	}

	/**
	 * The release gates: PHP checks, JS build, README, Plugin Check.
	 *
	 * @param string $dir Plugin folder.
	 */
	private function checks( string $dir ): void {
		WP_CLI::log( '  → composer check' );
		eizport_release_run( 'composer check', $dir, true );

		$package = json_decode( (string) file_get_contents( $dir . '/package.json' ), true );
		foreach ( array( 'build', 'readme' ) as $script ) {
			if ( isset( $package['scripts'][ $script ] ) ) {
				WP_CLI::log( "  → npm run $script" );
				eizport_release_run( 'npm run --silent ' . $script, $dir, true );
			}
		}

		// The plugin's site (docs, screenshots, translations), checked like its deploy does.
		if ( is_file( $dir . '/site/package.json' ) ) {
			WP_CLI::log( '  → site: npm run check' );
			eizport_release_run( 'npm run --silent check', $dir . '/site', true );
		}

		// The site the plugin is in: WordPress Studio's CLI there, else plain wp.
		$site = dirname( $dir, 3 );
		$wp   = eizport_release_has_cmd( 'studio' ) && is_file( $site . '/STUDIO.md' ) ? 'studio wp' : 'wp --path=' . escapeshellarg( $site );
		// On what ships: bin/plugin-check.sh leaves out what .distignore does.
		WP_CLI::log( '  → Plugin Check' );
		list( $exit, $out, $err ) = eizport_release_try_run( 'bash ' . escapeshellarg( $dir . '/bin/plugin-check.sh' ) . ' ' . $wp, $site );
		if ( 0 !== $exit || false === strpos( $out . $err, 'No errors found' ) ) {
			WP_CLI::error( "Plugin Check found problems:\n" . trim( $out . $err ) );
		}
	}
}

WP_CLI::add_command( 'eizport release', 'Eizport_Release_Command' );

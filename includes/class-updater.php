<?php
/**
 * Updates from GitHub releases.
 *
 * The release workflow publishes a versioned ZIP on every version bump, and
 * this points WordPress at it, so client sites update from the Plugins screen
 * like any other plugin instead of someone uploading a file.
 *
 * The repository is public, so no credentials are needed. GitHub does rate
 * limit unauthenticated API calls per IP, which shared hosting can run into,
 * so a token can be supplied if that ever happens.
 *
 * @package BricksMetaEvents
 */

namespace BricksMetaEvents;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin up to its GitHub releases.
 */
class Updater {

	private const REPOSITORY = 'https://github.com/blissguy/bricks-meta-events/';
	private const SLUG       = 'bricks-meta-events';
	private const BRANCH     = 'main';

	/**
	 * Register update checks.
	 *
	 * Silently does nothing when the library is absent, which is what happens
	 * if the plugin is installed from a source that stripped it. Failing to
	 * update is bad; failing to load is worse.
	 *
	 * @param string $plugin_file Main plugin file.
	 */
	public static function register( string $plugin_file ): void {
		$library = DIR . '/lib/plugin-update-checker/plugin-update-checker.php';

		if ( ! is_readable( $library ) ) {
			return;
		}

		require_once $library;

		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';

		if ( ! class_exists( $factory ) ) {
			return;
		}

		$checker = call_user_func(
			array( $factory, 'buildUpdateChecker' ),
			self::REPOSITORY,
			$plugin_file,
			self::SLUG
		);

		$checker->setBranch( self::BRANCH );

		$token = self::token();

		if ( '' !== $token ) {
			$checker->setAuthentication( $token );
		}

		// Match the versioned ZIP the release workflow builds, rather than
		// letting WordPress download GitHub's own source archive. The source
		// archive carries the repository name and the development files, so
		// installing it would rename the plugin folder and break activation.
		$checker->getVcsApi()->enableReleaseAssets(
			'/^bricks-meta-events-[0-9A-Za-z_.-]+\.zip($|[?&#])/i'
		);
	}

	/**
	 * An optional GitHub token.
	 *
	 * Not needed for a public repository. Worth setting only if a host shares
	 * one IP across many sites and starts hitting GitHub's hourly limit for
	 * anonymous requests.
	 */
	private static function token(): string {
		$token = defined( 'BME_GITHUB_TOKEN' ) ? (string) BME_GITHUB_TOKEN : '';

		/**
		 * Filters the GitHub token used for update checks.
		 *
		 * @param string $token Token from the BME_GITHUB_TOKEN constant.
		 */
		return trim( (string) apply_filters( 'bme_github_token', $token ) );
	}
}

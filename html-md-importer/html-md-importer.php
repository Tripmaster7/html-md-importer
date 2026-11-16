<?php
/**
 * Plugin Name:       HTML & Markdown Importer
 * Description:       Imports folders of HTML or Markdown files as pages or posts. Images are uploaded to the Media Library, links are rewritten and the folder structure becomes the page hierarchy. Content is converted to native blocks so the theme controls the design.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Jens with Claude
 * License:          GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       html-md-importer
 * Domain Path:       /languages
 *
 * @package HtmlMdImporter
 */

declare(strict_types=1);

namespace HtmlMdImporter;

defined( 'ABSPATH' ) || exit;

define( 'HMI_VERSION', '0.1.0' );
define( 'HMI_FILE', __FILE__ );
define( 'HMI_DIR', plugin_dir_path( __FILE__ ) );
define( 'HMI_URL', plugin_dir_url( __FILE__ ) );

const CRON_HOOK = 'hmi_cleanup_sessions';

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$name = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$file = HMI_DIR . 'includes/class-' . strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

if ( ! class_exists( 'HMI_Parsedown' ) ) {
	require HMI_DIR . 'lib/parsedown/Parsedown.php';
}

add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'html-md-importer', false, dirname( plugin_basename( HMI_FILE ) ) . '/languages' );
	}
);
add_action( 'rest_api_init', array( Rest::class, 'register' ) );
add_action( CRON_HOOK, array( Session::class, 'cleanup_expired' ) );
if ( is_admin() ) {
	Admin::register();
}

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( ! wp_next_scheduled( CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', CRON_HOOK );
		}
	}
);
register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_clear_scheduled_hook( CRON_HOOK );
	}
);

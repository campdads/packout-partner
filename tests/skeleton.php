<?php
/**
 * The plugin starts, loads the library, and keeps the capability list.
 *
 * Run inside WordPress, with the plugin active and no other Pack-Out Spot
 * plugin:
 *
 *     wp eval-file tests/skeleton.php
 *
 * Set PACKOUT_REQUIRE_TAGGED_CORE=1 to also require that the library is a
 * tagged version. A release does.
 *
 * @package PackOut_Partner
 */

use PackOut\Core\Build;
use PackOut\Core\Capability_Map;
use PackOut\Core\Kernel;
use PackOut\Core\Parity;
use PackOut\Partner\Addresses;
use PackOut\Partner\Installer;
use PackOut\Partner\Plugin;
use PackOut\Partner\Settings;
use PackOut\Partner\Settings_Screen;

require __DIR__ . '/support.php';

$packout_dir  = dirname( PACKOUT_PARTNER_FILE );
$packout_file = plugin_basename( PACKOUT_PARTNER_FILE );
$codes        = static function ( array $problems ): array {
	return array_map(
		static function ( array $problem ): string {
			return $problem['code'] . ':' . $problem['id'];
		},
		$problems
	);
};

WP_CLI::log( 'The plugin starts' );

if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$headers = get_plugins()[ $packout_file ] ?? array();
packout_partner_check( 'it is active', is_plugin_active( $packout_file ) );
packout_partner_check( 'it reports the version in its header', PACKOUT_PARTNER_VERSION === ( $headers['Version'] ?? '' ), $headers['Version'] ?? '' );
packout_partner_check( 'it names the catalog on packout.spot as where it updates from', 'https://packout.spot/plugins/packout-partner' === ( $headers['UpdateURI'] ?? '' ), $headers['UpdateURI'] ?? '' );
packout_partner_check( 'it asks for WordPress 6.8 and PHP 8.2, the versions it checks for itself', Plugin::MINIMUM_WORDPRESS === ( $headers['RequiresWP'] ?? '' ) && Plugin::MINIMUM_PHP === ( $headers['RequiresPHP'] ?? '' ) );
packout_partner_check( 'it requires no other plugin', '' === (string) ( $headers['RequiresPlugins'] ?? '' ) );
packout_partner_check( 'it is not paused', '' === Plugin::paused(), Plugin::paused() );

WP_CLI::log( 'It carries the library' );

$copy   = $packout_dir . '/lib/packout-core';
$lock   = $packout_dir . '/packout-core.lock';
$kernel = Kernel::instance();
packout_partner_check( 'the library is loaded, at a version the plugin works with', defined( 'PACKOUT_CORE_VERSION' ) && version_compare( PACKOUT_CORE_VERSION, Plugin::MINIMUM_CORE, '>=' ) );
packout_partner_check( 'it is the copy inside this plugin', realpath( PACKOUT_CORE_DIR ) === realpath( $copy ), PACKOUT_CORE_DIR );
packout_partner_check( 'the copy is the build packout-core.lock names', '' === Build::problem( $lock, $copy, false ), Build::problem( $lock, $copy, false ) );
if ( '1' === getenv( 'PACKOUT_REQUIRE_TAGGED_CORE' ) ) {
	packout_partner_check( 'and that build is a tagged version', '' === Build::problem( $lock, $copy ), Build::problem( $lock, $copy ) );
}
packout_partner_check( 'the lock names the version that is loaded', PACKOUT_CORE_VERSION === Build::lock( $lock )['version'] );
packout_partner_check( 'the plugin has registered a kernel', $kernel instanceof Kernel );
if ( ! $kernel instanceof Kernel ) {
	WP_CLI::error( 'The library is not registered. Nothing else can be checked.' );
}

WP_CLI::log( 'It needs WordPress alone' );

packout_partner_check( 'no Vectra plugin is loaded on this site', ! class_exists( '\Vectra\Core\Access\Post_Access' ) && ! class_exists( '\Vectra\Ui\Module' ) );
// Code, scripts, and styles: a class, a function, a hook, a handle, a global, or a custom property of something this site does not have.
$outside  = array( '/\bVectra\\\\/', '/\bvectra_[a-z]/i', '/\bvectra-[a-z]/i', '/\bvectra(Ui|\.[a-z])/', '/\bastra_[a-z]/i', '/\bastra-[a-z]/i', '/--ast-/', '/\buagb[\/_-]/i', '/\bWpeCommon\b/' );
$mentions = array();
$scanned  = 0;
foreach ( array( 'src', 'lib', 'assets', 'templates', 'blocks', 'packout-partner.php', 'uninstall.php' ) as $part ) {
	$path = $packout_dir . '/' . $part;
	if ( ! file_exists( $path ) ) {
		continue;
	}
	$files = is_dir( $path ) ? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) ) : array( new SplFileInfo( $path ) );
	foreach ( $files as $file ) {
		$name = substr( $file->getPathname(), strlen( $packout_dir ) + 1 );
		// Libraries the plugin carries as they were published.
		if ( ! in_array( $file->getExtension(), array( 'php', 'js', 'css', 'json', 'html', 'svg' ), true ) || str_contains( $name, '/assets/lib/' ) || str_ends_with( $name, '.min.js' ) ) {
			continue;
		}
		++$scanned;
		// The catalog's address is `vectra/v1` wherever it is installed. That is an address, not code.
		$code = str_replace( 'vectra/v1', '', (string) file_get_contents( $file->getPathname() ) );
		foreach ( $outside as $pattern ) {
			if ( 1 === preg_match( $pattern, $code, $found ) ) {
				$mentions[] = $name . ': ' . $found[0];
				break;
			}
		}
	}
}
packout_partner_check( 'nothing the plugin carries, in code, script, or style, names Vectra, a theme, or a host', array() === $mentions && $scanned > 150, array_slice( $mentions, 0, 12 ) );

WP_CLI::log( 'It describes the site to the library' );

$site = $kernel->site();
packout_partner_check( 'the plugin is packout-partner, at its version', 'packout-partner' === $site->plugin() && PACKOUT_PARTNER_VERSION === $site->version() );
packout_partner_check( 'it is a partner site, not the hub', 'partner' === $site->surface() && ! $site->is_hub() );
packout_partner_check( 'it has reached the phase the plugin file names', PACKOUT_PARTNER_PHASE === $site->phase() && $site->phase() >= 2, $site->phase() );
packout_partner_check( 'the site has a name', get_bloginfo( 'name' ) === $site->name() || '' !== $site->name() );
packout_partner_check( 'a Spot lives under /spot/ until the site chooses otherwise', 'spot' === $site->base( 'spot' ) && 'program' === $site->base( 'program' ) );

foreach ( Kernel::ADAPTERS as $name => $contract ) {
	$adapter = $kernel->adapter( $name );
	packout_partner_check( 'the ' . $name . ' adapter is the plugin\'s own', $adapter instanceof $contract && 0 === strpos( get_class( $adapter ), 'PackOut\\Partner\\Adapters\\' ), get_class( $adapter ) );
}

WP_CLI::log( 'The adapters do their work with WordPress' );

$published = wp_insert_post(
	array(
		'post_title'  => 'Skeleton check, published',
		'post_status' => 'publish',
	)
);
$draft     = wp_insert_post(
	array(
		'post_title'  => 'Skeleton check, draft',
		'post_status' => 'draft',
	)
);
$locked    = wp_insert_post(
	array(
		'post_title'    => 'Skeleton check, password',
		'post_status'   => 'publish',
		'post_password' => 'open sesame',
	)
);
$reader    = wp_insert_user(
	array(
		'user_login' => 'skeleton-reader',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'subscriber',
	)
);
$editor    = wp_insert_user(
	array(
		'user_login' => 'skeleton-editor',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'editor',
	)
);
packout_partner_check( 'a visitor may read a published post', $kernel->access()->can_read( $published, 0 ) );
packout_partner_check( 'a visitor may not read a draft', ! $kernel->access()->can_read( $draft, 0 ) );
packout_partner_check( 'nor may a subscriber', ! $kernel->access()->can_read( $draft, $reader ) );
packout_partner_check( 'an editor may', $kernel->access()->can_read( $draft, $editor ) );
packout_partner_check( 'a visitor may not read a post behind a password', ! $kernel->access()->can_read( $locked, 0 ) );
packout_partner_check( 'nobody may read a post that does not exist', ! $kernel->access()->can_read( PHP_INT_MAX, $editor ) );
wp_delete_post( $published, true );
wp_delete_post( $draft, true );
wp_delete_post( $locked, true );

$kernel->roles()->register(
	array( 'packout_skeleton_cap' => 'Skeleton check' ),
	array(
		'packout_skeleton_role' => array(
			'label' => 'Skeleton role',
			'caps'  => array( 'packout_skeleton_cap' ),
		),
		'editor'                => array(
			'label' => 'Not the editor',
			'caps'  => array( 'packout_skeleton_cap' ),
		),
	)
);
$role = get_role( 'packout_skeleton_role' );
packout_partner_check( 'roles creates a role with its capabilities', $role instanceof WP_Role && $role->has_cap( 'packout_skeleton_cap' ) && $role->has_cap( 'read' ) );
packout_partner_check( 'gives the capability to administrators', get_role( 'administrator' )->has_cap( 'packout_skeleton_cap' ) );
packout_partner_check( 'and leaves a role the site already has as it was', ! get_role( 'editor' )->has_cap( 'packout_skeleton_cap' ) && 'Editor' === wp_roles()->role_names['editor'] );
remove_role( 'packout_skeleton_role' );
get_role( 'administrator' )->remove_cap( 'packout_skeleton_cap' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $reader );
wp_delete_user( $editor );

$palette = $kernel->palette()->colors();
packout_partner_check( 'the palette names the nine colors the library asks for', Kernel::COLORS === array_keys( $palette ), array_keys( $palette ) );
packout_partner_check( 'and each is a color', 9 === count( preg_grep( '/^#[0-9a-f]{3,8}$/i', $palette ) ), $palette );
$recolor = static function ( $colors ) {
	$colors['primary']  = '#123456';
	$colors['invented'] = '#000000';
	return $colors;
};
add_filter( 'packout_partner_palette', $recolor );
$changed = $kernel->palette()->colors();
packout_partner_check( 'a site can change a color, and cannot add one', '#123456' === $changed['primary'] && ! isset( $changed['invented'] ) && 9 === count( $changed ) );
remove_filter( 'packout_partner_palette', $recolor );

$listening = static function (): int {
	global $wp_filter;
	$count = 0;
	foreach ( isset( $wp_filter['phpmailer_init'] ) ? $wp_filter['phpmailer_init']->callbacks : array() as $callbacks ) {
		$count += count( $callbacks );
	}
	return $count;
};
$listeners = $listening();
$sent      = array();
$capture   = static function ( $short, $atts ) use ( &$sent ) {
	$sent = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $capture, 10, 2 );
$mailed = $kernel->mailer()->send( 'skeleton@example.com', 'Skeleton', 'Plain body', array( 'Reply-To: lead@example.com' ) );
packout_partner_check( 'mail goes out through WordPress, with its headers', $mailed && 'skeleton@example.com' === ( $sent['to'] ?? '' ) && 'Plain body' === ( $sent['message'] ?? '' ) && array( 'Reply-To: lead@example.com' ) === ( $sent['headers'] ?? null ), $sent );
$kernel->mailer()->send( 'skeleton@example.com', 'Skeleton', '<p>HTML body</p>', array(), 'Plain alternative' );
packout_partner_check( 'an HTML message is marked as HTML', in_array( 'Content-Type: text/html; charset=UTF-8', (array) ( $sent['headers'] ?? array() ), true ) );
remove_filter( 'pre_wp_mail', $capture, 10 );
packout_partner_check( 'and the plain-text listener is gone once the message is sent', $listeners === $listening() );

$form = $kernel->report_form();
packout_partner_check( 'the report form is the plugin\'s own and is always there', $form->available() && true === $form->status()['ok'] && '' === $form->settings( array() )['rows'] && '' === $form->entry_note( 7 ) );
$markup = $form->render( array( 'before_submit' => '<p id="skeleton-before">x</p>' ) );
packout_partner_check( 'it is a form that posts to its own page, with a nonce', str_contains( $markup, '<form' ) && str_contains( $markup, 'method="post"' ) && str_contains( $markup, 'enctype="multipart/form-data"' ) && str_contains( $markup, 'name="packout_report_nonce"' ) );
packout_partner_check( 'it asks what the hub\'s form asks, less the email address', str_contains( $markup, 'name="packout_report[category]"' ) && str_contains( $markup, 'name="packout_report[description]"' ) && str_contains( $markup, 'name="packout_report[landmark]"' ) && str_contains( $markup, 'name="packout_report_photo"' ) && str_contains( $markup, 'name="packout_report[name]"' ) && ! str_contains( $markup, 'email' ) );
packout_partner_check( 'what the page adds goes before the button', strpos( $markup, 'skeleton-before' ) > 0 && strpos( $markup, 'skeleton-before' ) < strrpos( $markup, '<button' ) );
$selectors = $form->selectors();
packout_partner_check( 'the page\'s script is told where the form keeps the place and the photo', str_contains( $markup, 'name="packout_report[latitude]"' ) && 'input[name="packout_report[latitude]"]' === $selectors['latitude'] && 'input[name="packout_report[longitude]"]' === $selectors['longitude'] && 'input[name="packout_report_photo"]' === $selectors['photo'] && str_contains( $markup, 'class="' . ltrim( $selectors['row'], '.' ) . '"' ) );
packout_partner_check( 'a photo may be as large as the application allows', $form->photo_limit() === \PackOut\Core\App\Photos::limit_mb() && $form->photo_limit() > 0 );

packout_partner_check( 'the screens draw with markup alone: no component library is asked for', ! $kernel->screens()->supports( 'badge' ) && '<p>x</p>' === $kernel->screens()->render( 'badge', array(), '<p>x</p>' ) && array( 'packout-ui' ) === $kernel->screens()->assets()['styles'] && array() === $kernel->screens()->assets()['scripts'] );
packout_partner_check( 'and their styles are the library\'s', wp_style_is( 'packout-ui', 'registered' ) && str_contains( (string) wp_styles()->registered['packout-ui']->src, 'lib/packout-core/assets/css/ui.css' ) );
$icon = $kernel->icons()->svg( 'check', 'skeleton-icon' );
packout_partner_check( 'an icon is the plugin\'s own drawing', str_starts_with( $icon, '<svg' ) && str_contains( $icon, 'class="skeleton-icon"' ) && str_contains( $icon, 'aria-hidden="true"' ) && str_contains( $icon, '<path' ) );
packout_partner_check( 'one it does not have is nothing, and a name cannot reach another file', '' === $kernel->icons()->svg( 'no-such-icon' ) && '' === $kernel->icons()->svg( '../../packout-partner' ) );
// The names the library's screens use: the ones it draws by name, and the ones its menus and overview carry.
$wanted = array( 'institution', 'globe', 'tool', 'shield', 'trending-up', 'seen', 'caution', 'envelope', 'scheduled', 'external', 'pin', 'update', 'post', 'published', 'error' );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $copy . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( 'php' === $file->getExtension() && preg_match_all( '/(?:Admin|self|Ui)::icon\( \'([a-z-]+)\'/', (string) file_get_contents( $file->getPathname() ), $found ) ) {
		$wanted = array_merge( $wanted, $found[1] );
	}
}
$wanted  = array_values( array_unique( $wanted ) );
$missing = array_values(
	array_filter(
		$wanted,
		static function ( string $name ) use ( $kernel ): bool {
			return '' === $kernel->icons()->svg( $name );
		}
	)
);
packout_partner_check( 'every icon the library names is one the plugin has', count( $wanted ) >= 20 && array() === $missing, $missing );
packout_partner_check( 'lists are plain rows: there is no table script to load and no saved view', array() === $kernel->lists()->assets()['scripts'] && array() === $kernel->lists()->assets()['styles'] && null === $kernel->lists()->saved( 'spots', array() ) );

WP_CLI::log( 'Settings' );

$before = get_option( Settings::OPTION );
delete_option( Settings::OPTION );
packout_partner_check( 'a new site takes stable versions and keeps its data', false === Settings::get( 'beta' ) && false === Settings::get( 'remove_data' ) && 'stable' === Settings::channel() );
packout_partner_check( 'a setting that does not exist gives the fallback', 'fallback' === Settings::get( 'no_such_setting', 'fallback' ) && 'fallback' === $kernel->settings()->get( 'no_such_setting', 'fallback' ) );

$clean = Settings::sanitize(
	array(
		'beta'        => 'yes',
		'remove_data' => '',
		'invented'    => 'x',
		'bases'       => array(
			'spot'    => 'Trash Sites!',
			'team'    => array( 'not a string' ),
			'mission' => '',
			'unknown' => 'x',
		),
	)
);
packout_partner_check( 'what is saved is cleaned: ticks become true or false, and unknown names are dropped', true === $clean['beta'] && false === $clean['remove_data'] && ! isset( $clean['invented'] ) );
packout_partner_check( 'a path is made safe for an address, and an empty or wrong one keeps its default', 'trash-sites' === $clean['bases']['spot'] && 'team' === $clean['bases']['team'] && 'mission' === $clean['bases']['mission'] && ! isset( $clean['bases']['unknown'] ) && array_keys( Settings::BASES ) === array_keys( $clean['bases'] ), $clean['bases'] );
packout_partner_check( 'settings that are not an array become the defaults', Settings::defaults() === Settings::sanitize( 'nonsense' ) );

$updates = Plugin::updates();
$kept    = static function () use ( $updates ): int {
	return $updates->status()['checked'];
};
// Give the update client a kept answer, then change the channel.
$answer = static function () {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '{"schema":1,"modules":[]}',
	);
};
add_filter( 'pre_http_request', $answer );
$updates->module( true );
packout_partner_check( 'the update client keeps the catalog\'s answer', $kept() > 0 );
packout_partner_check( 'choosing beta versions is saved', Settings::update( array( 'beta' => true ) ) && 'beta' === Settings::channel() && 'beta' === $updates->channel() );
packout_partner_check( 'and the kept answer is dropped, so the next check asks for the new channel', 0 === $kept() );
$updates->module( true );
Settings::update( array( 'remove_data' => true ) );
packout_partner_check( 'a change that is not the channel keeps the answer', $kept() > 0 && true === Settings::get( 'remove_data' ) && true === Settings::get( 'beta' ) );
Settings::update( array( 'bases' => array( 'spot' => 'site' ) ) );
packout_partner_check( 'a site can rename a path, and the library is told', 'site' === $site->base( 'spot' ) && 'team' === $site->base( 'team' ) );
remove_filter( 'pre_http_request', $answer );
$updates->forget();

$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) $admin[0] );
ob_start();
Settings_Screen::render();
$screen = (string) ob_get_clean();
packout_partner_check( 'the settings screen shows both choices as they are saved', 1 === preg_match( '/name="packout_partner_settings\[beta\]"[^>]*checked/', $screen ) && 1 === preg_match( '/name="packout_partner_settings\[remove_data\]"[^>]*checked/', $screen ) );
packout_partner_check( 'it carries WordPress\'s nonce and saves through options.php', str_contains( $screen, 'action="options.php"' ) && str_contains( $screen, 'name="_wpnonce"' ) && str_contains( $screen, "value='packout_partner'" ) );
packout_partner_check( 'it keeps the renamed path when the form is saved', str_contains( $screen, 'name="packout_partner_settings[bases][spot]" value="site"' ) );
packout_partner_check( 'and says what an update check sends', str_contains( $screen, 'this site’s address' ) );
wp_set_current_user( 0 );
ob_start();
Settings_Screen::render();
packout_partner_check( 'a visitor is shown nothing', '' === (string) ob_get_clean() );

if ( false === $before ) {
	delete_option( Settings::OPTION );
} else {
	update_option( Settings::OPTION, $before );
}
$updates->forget();

WP_CLI::log( 'Where the plugin will not run' );

packout_partner_check( 'it runs on the versions it asks for', '' === Plugin::obstacle( '8.2.0', '6.8', true, false ) && '' === Plugin::obstacle( '8.4.10', '7.1.5', true, false ) );
packout_partner_check( 'it runs on a development version of a WordPress it supports', '' === Plugin::obstacle( '8.3.1', '6.8-alpha-59000', true, false ) && '' === Plugin::obstacle( '8.3.1', '7.2-beta1', true, false ) );
packout_partner_check( 'it pauses on an older PHP', 'php' === Plugin::obstacle( '8.1.29', '6.8', true, false ) );
packout_partner_check( 'it pauses on an older WordPress', 'WordPress' === Plugin::obstacle( '8.2.0', '6.7.2', true, false ) && 'WordPress' === Plugin::obstacle( '8.2.0', '6.7-RC1', true, false ) );
packout_partner_check( 'it pauses when PHP cannot check a signature', 'sodium' === Plugin::obstacle( '8.2.0', '6.8', false, false ) );
packout_partner_check( 'it pauses beside Pack-Out Spot, whatever else is true', 'hub' === Plugin::obstacle( '8.2.0', '6.8', true, true ) && 'hub' === Plugin::obstacle( '7.4.0', '5.0', false, true ) );
packout_partner_check( 'each reason is a sentence that says what the site has', str_contains( Plugin::message( 'php', '8.1.29' ), '8.1.29' ) && str_contains( Plugin::message( 'php', '8.1.29' ), Plugin::MINIMUM_PHP ) && str_contains( Plugin::message( 'WordPress', '8.2.0', '6.7.2' ), '6.7.2' ) && str_contains( Plugin::message( 'sodium' ), 'sodium' ) && str_contains( Plugin::message( 'hub' ), 'cannot run together' ) );
packout_partner_check( 'including the two about the plugin\'s own files', str_contains( Plugin::message( 'library' ), 'Install the plugin again' ) && str_contains( Plugin::message( 'library_old' ), 'older copy' ) && '' === Plugin::message( '' ) && '' === Plugin::message( 'invented' ) );
packout_partner_check( 'the plugin does not translate before WordPress is ready to', false !== has_action( 'init', array( Plugin::class, 'load_textdomain' ) ) && did_action( 'init' ) > 0 );
packout_partner_check( 'activation is allowed on one site', '' === Installer::refusal( false, false ) );
packout_partner_check( 'activation for a whole network is refused', str_contains( Installer::refusal( true, false ), 'whole network' ) );
packout_partner_check( 'activation beside Pack-Out Spot is refused', str_contains( Installer::refusal( false, true ), 'cannot run together' ) );

WP_CLI::log( 'Parity with the capability list' );

$rows     = Capability_Map::rows();
$handlers = Capability_Map::handlers();
$counts   = Parity::counts( $rows );
$due      = array();
foreach ( $rows as $id => $row ) {
	if ( in_array( $row['surface'], array( 'both', 'partner' ), true ) && $row['since']['partner'] <= $site->phase() ) {
		$due[] = $id;
	}
}
packout_partner_check( 'the list is well formed', array() === Parity::malformed( $rows ), $codes( Parity::malformed( $rows ) ) );
packout_partner_check( 'the plugin keeps the list: every act it should have by this phase has a handler that can be called', array() === Parity::check_site( $site ), $codes( Parity::check_site( $site ) ) );
packout_partner_check( 'the handlers are the ones in src/handlers.php', array_keys( $handlers ) === array_keys( require $packout_dir . '/src/handlers.php' ) );
packout_partner_check( 'that is every act due, and no other', count( $handlers ) === count( $due ), count( $handlers ) . ' handlers, ' . count( $due ) . ' due' );
WP_CLI::log( sprintf( '        %d acts: %d on both, %d on the hub alone, %d on a partner site alone. %d due here by phase %d.', $counts['total'], $counts['both'], $counts['hub'], $counts['partner'], count( $due ), $site->phase() ) );

packout_partner_check( 'by phase 5 that is nearly everything a partner can do', $site->phase() < 5 || count( $handlers ) >= 85, count( $handlers ) );
$library = 0;
foreach ( $handlers as $handler ) {
	if ( is_array( $handler ) && is_string( $handler[0] ) && 0 === strpos( $handler[0], 'PackOut\\Core\\App\\' ) ) {
		++$library;
	}
}
packout_partner_check( 'and the library performs all but the report form and the addresses, as it does on the hub', $site->phase() < 5 || count( $handlers ) - 2 === $library, $library . ' of ' . count( $handlers ) );

// The check has to be able to fail. Each of these must be caught.
$later = Parity::check( $rows, $handlers, 'partner', $site->phase() + 1 );
$due   = 0;
foreach ( $rows as $row ) {
	if ( 'hub' !== $row['surface'] && $row['since']['partner'] > $site->phase() ) {
		++$due;
	}
}
if ( $due > 0 ) {
	packout_partner_check( 'when the plugin reaches the next phase the handlers it has now will not be enough', count( $later ) >= 1 && array( 'missing' ) === array_values( array_unique( array_column( $later, 'code' ) ) ), count( $later ) . ' problems' );
} else {
	// Every act of the list is due by now, so the next phase asks for nothing more.
	packout_partner_check( 'nothing in the list waits for a later phase: every act a partner site has is answered', array() === $later, count( $later ) . ' problems' );
}

$at_five = array();
foreach ( $rows as $id => $row ) {
	if ( 'hub' !== $row['surface'] && $row['since']['partner'] <= 5 ) {
		$at_five[ $id ] = '__return_true';
	}
}
packout_partner_check( 'with a handler for every act due by phase 5, the check passes at phase 5', array() === Parity::check( $rows, $at_five, 'partner', 5 ) );
$dropped = array_key_last( $at_five );
unset( $at_five[ $dropped ] );
packout_partner_check( 'take one handler away and the check fails, naming the act', array( 'missing:' . $dropped ) === $codes( Parity::check( $rows, $at_five, 'partner', 5 ) ), $dropped );

$foreign = null;
foreach ( $rows as $id => $row ) {
	if ( 'hub' === $row['surface'] ) {
		$foreign = $id;
		break;
	}
}
Capability_Map::handle( $foreign, '__return_true' );
packout_partner_check( 'a handler for an act that belongs to the hub alone fails it', array( 'foreign:' . $foreign ) === $codes( Parity::check_site( $site ) ), $foreign );
Capability_Map::forget( $foreign );
Capability_Map::handle( 'spot.invented', '__return_true' );
packout_partner_check( 'a handler for an act that is not in the list fails it', array( 'unknown:spot.invented' ) === $codes( Parity::check_site( $site ) ) );
Capability_Map::forget( 'spot.invented' );
// One of the plugin's own acts, given a handler that is not there, then given its own back in the same place.
$swapped = array_key_last( $handlers );
Capability_Map::handle( $swapped, array( Plugin::class, 'no_such_method' ) );
packout_partner_check( 'a handler that cannot be called fails it', in_array( 'uncallable:' . $swapped, $codes( Parity::check_site( $site ) ), true ) );
Capability_Map::handle( $swapped, $handlers[ $swapped ] );
packout_partner_check( 'and with everything put back the plugin keeps the list again', array() === Parity::check_site( $site ) && array_keys( Capability_Map::handlers() ) === array_keys( $handlers ) );

packout_partner_finish();

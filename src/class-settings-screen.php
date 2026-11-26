<?php
/**
 * The Settings screen.
 *
 * @package PackOut_Partner
 */

namespace PackOut\Partner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings, under WordPress's own Settings menu.
 *
 * These are the plugin's own: which versions the site takes, the addresses
 * of its records, and what happens to the records when the plugin is
 * deleted. What the application does is set on the Pack-Out Spot menu's
 * Settings screen.
 */
class Settings_Screen {

	public const PAGE = 'packout-partner';

	/**
	 * Add the screen.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PACKOUT_PARTNER_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Add the menu item.
	 */
	public static function menu(): void {
		add_options_page(
			__( 'Pack-Out Spot', 'packout-partner' ),
			__( 'Pack-Out Spot', 'packout-partner' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Link to the screen from the Plugins list.
	 *
	 * @param mixed $links The plugin's links.
	 * @return mixed
	 */
	public static function action_links( $links ) {
		if ( is_array( $links ) && current_user_can( 'manage_options' ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'packout-partner' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Draw the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::all();
		$updates  = Plugin::updates();
		$status   = null !== $updates ? $updates->status() : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pack-Out Spot', 'packout-partner' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'packout_partner' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Updates', 'packout-partner' ); ?></th>
						<td>
							<label for="packout-partner-beta">
								<input type="checkbox" id="packout-partner-beta" name="<?php echo esc_attr( Settings::OPTION ); ?>[beta]" value="1" <?php checked( $settings['beta'] ); ?>>
								<?php esc_html_e( 'Get beta versions', 'packout-partner' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'A beta version has the newest changes before they are released to everyone. Leave this off on a site people rely on.', 'packout-partner' ); ?>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: the installed version of the plugin. */
									esc_html__( 'This site has version %s.', 'packout-partner' ),
									esc_html( PACKOUT_PARTNER_VERSION )
								);
								if ( is_array( $status ) && $status['checked'] > 0 ) {
									echo ' ';
									if ( $status['failed'] ) {
										esc_html_e( 'The last check for updates did not reach packout.spot.', 'packout-partner' );
									} else {
										printf(
											/* translators: %s: how long ago, such as "2 hours". */
											esc_html__( 'Updates were last checked %s ago.', 'packout-partner' ),
											esc_html( human_time_diff( $status['checked'] ) )
										);
									}
								}
								?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Updates come from packout.spot. Each check sends this plugin’s version and this site’s address.', 'packout-partner' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'When the plugin is deleted', 'packout-partner' ); ?></th>
						<td>
							<label for="packout-partner-remove-data">
								<input type="checkbox" id="packout-partner-remove-data" name="<?php echo esc_attr( Settings::OPTION ); ?>[remove_data]" value="1" <?php checked( $settings['remove_data'] ); ?>>
								<?php esc_html_e( 'Remove all Pack-Out Spot data when the plugin is deleted', 'packout-partner' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Left off, deleting the plugin keeps everything. Deactivating never removes anything. Ticked, deleting the plugin removes everything it made here: Spots and their photos, organizations, missions, campaigns, programs, records of work, invitations, its settings, its roles, and its connection to packout.spot. The pages the setup made are yours and stay, and so do people’s accounts. This cannot be undone. Deleting the plugin does not tell packout.spot. To take your listings off packout.spot, disconnect first, under Pack-Out Spot, Connection.', 'packout-partner' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Addresses', 'packout-partner' ); ?></h2>
				<p>
					<?php esc_html_e( 'The first part of the address of each kind of record. Change one when this site already has something there. The old address forwards to the new one.', 'packout-partner' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<?php foreach ( Addresses::labels() as $kind => $label ) : ?>
						<tr>
							<th scope="row">
								<label for="<?php echo esc_attr( 'packout-partner-base-' . $kind ); ?>"><?php echo esc_html( $label ); ?></label>
							</th>
							<td>
								<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
								<input type="text" class="regular-text code" id="<?php echo esc_attr( 'packout-partner-base-' . $kind ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[bases][' . $kind . ']' ); ?>" value="<?php echo esc_attr( $settings['bases'][ $kind ] ); ?>" maxlength="60" autocapitalize="none" spellcheck="false">
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

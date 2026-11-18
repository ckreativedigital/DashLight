<?php
/**
 * Dashboard widget.
 *
 * @package DashLights
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the status light on the WordPress dashboard.
 */
class DashLights_Widget {

	const CARE_URL = 'https://ckreativedigital.com/website-management/?utm_source=dashlights&utm_medium=plugin&utm_campaign=dashboard-widget';

	/**
	 * Registers hooks.
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
	}

	/**
	 * Adds the widget for people who manage the site.
	 */
	public static function register() {
		if ( current_user_can( 'manage_options' ) ) {
			wp_add_dashboard_widget( 'dashlights_widget', __( 'DashLights', 'dashlights' ), array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Loads the stylesheet on the dashboard and the settings page only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function styles( $hook ) {
		if ( in_array( $hook, array( 'index.php', 'settings_page_' . DashLights_Settings::PAGE ), true ) ) {
			wp_enqueue_style( 'dashlights', DASHLIGHTS_URL . 'assets/admin.css', array(), DASHLIGHTS_VERSION );
		}
	}

	/**
	 * Renders the widget.
	 */
	public static function render() {
		$data    = DashLights_Checks::data();
		$results = $data['results'];

		if ( empty( $results ) ) {
			echo '<p>' . esc_html__( 'DashLights is running its first check. Refresh this page in a minute, or check now.', 'dashlights' ) . '</p>';
			echo '<div class="dashlights-footer">';
			DashLights_Settings::check_now_button( 'button-link' );
			echo '</div>';
			return;
		}

		$overall = DashLights_Checks::overall( $results );
		?>
		<div class="dashlights">
			<p class="dashlights-summary dashlights-<?php echo esc_attr( $overall ); ?>">
				<span class="dashlights-dot" aria-hidden="true"></span>
				<strong><?php echo esc_html( DashLights_Checks::summary( $overall ) ); ?></strong>
			</p>
			<ul class="dashlights-rows">
				<?php
				foreach ( DashLights_Checks::keys() as $key ) :
					if ( empty( $results[ $key ] ) ) {
						continue;
					}
					$result  = $results[ $key ];
					$status  = isset( $result['status'] ) ? $result['status'] : DashLights_Checks::UNKNOWN;
					$message = DashLights_Checks::message( $key, $result );
					?>
					<li class="dashlights-row dashlights-<?php echo esc_attr( $status ); ?>">
						<span class="dashlights-dot" aria-hidden="true"></span>
						<span class="dashlights-label"><?php echo esc_html( DashLights_Checks::label( $key ) ); ?></span>
						<span class="dashlights-text">
							<span class="screen-reader-text"><?php echo esc_html( self::status_name( $status ) ); ?>:</span>
							<?php echo esc_html( $message['text'] ); ?>
							<?php if ( $message['link'] ) : ?>
								<a href="<?php echo esc_url( $message['link'] ); ?>"><?php echo esc_html( $message['link_text'] ); ?> &rarr;</a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="dashlights-footer">
				<span>
					<?php
					if ( $data['checked'] ) {
						/* translators: %s: time since the last check, e.g. "3 hours" */
						printf( esc_html__( 'Last checked %s ago', 'dashlights' ), esc_html( human_time_diff( $data['checked'] ) ) );
					}
					?>
				</span>
				<?php DashLights_Settings::check_now_button( 'button-link' ); ?>
			</div>
			<p class="dashlights-credit">
				<?php esc_html_e( 'DashLights is free from CKreative Digital. Want someone to handle all of this?', 'dashlights' ); ?>
				<a href="<?php echo esc_url( self::CARE_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See our Care Plans', 'dashlights' ); ?> &rarr;</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Status name for screen readers, since the dot's color carries the status visually.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_name( $status ) {
		switch ( $status ) {
			case DashLights_Checks::GOOD:
				return __( 'Good', 'dashlights' );
			case DashLights_Checks::WARNING:
				return __( 'Needs attention soon', 'dashlights' );
			case DashLights_Checks::CRITICAL:
				return __( 'Needs attention now', 'dashlights' );
			default:
				return __( "Couldn't check", 'dashlights' );
		}
	}
}

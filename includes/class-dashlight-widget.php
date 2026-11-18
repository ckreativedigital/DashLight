<?php
/**
 * Dashboard widget.
 *
 * @package DashLight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the status light on the WordPress dashboard.
 */
class DashLight_Widget {

	const CARE_URL = 'https://ckreativedigital.com/website-management/?utm_source=dashlight&utm_medium=plugin&utm_campaign=dashboard-widget';

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
			wp_add_dashboard_widget( 'dashlight_widget', __( 'DashLight', 'dashlight' ), array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Loads the stylesheet on the dashboard and the settings page only.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function styles( $hook ) {
		if ( in_array( $hook, array( 'index.php', 'settings_page_' . DashLight_Settings::PAGE ), true ) ) {
			wp_enqueue_style( 'dashlight', DASHLIGHT_URL . 'assets/admin.css', array(), DASHLIGHT_VERSION );
		}
	}

	/**
	 * Renders the widget.
	 */
	public static function render() {
		$data    = DashLight_Checks::data();
		$results = $data['results'];

		if ( empty( $results ) ) {
			echo '<p>' . esc_html__( 'DashLight is running its first check. Refresh this page in a minute, or check now.', 'dashlight' ) . '</p>';
			echo '<div class="dashlight-footer">';
			DashLight_Settings::check_now_button( 'button-link' );
			echo '</div>';
			return;
		}

		$overall = DashLight_Checks::overall( $results );
		?>
		<div class="dashlight">
			<p class="dashlight-summary dashlight-<?php echo esc_attr( $overall ); ?>">
				<span class="dashlight-dot" aria-hidden="true"></span>
				<strong><?php echo esc_html( DashLight_Checks::summary( $overall ) ); ?></strong>
			</p>
			<ul class="dashlight-rows">
				<?php
				foreach ( DashLight_Checks::keys() as $key ) :
					if ( empty( $results[ $key ] ) ) {
						continue;
					}
					$result  = $results[ $key ];
					$status  = isset( $result['status'] ) ? $result['status'] : DashLight_Checks::UNKNOWN;
					$message = DashLight_Checks::message( $key, $result );
					?>
					<li class="dashlight-row dashlight-<?php echo esc_attr( $status ); ?>">
						<span class="dashlight-dot" aria-hidden="true"></span>
						<span class="dashlight-label"><?php echo esc_html( DashLight_Checks::label( $key ) ); ?></span>
						<span class="dashlight-text">
							<span class="screen-reader-text"><?php echo esc_html( self::status_name( $status ) ); ?>:</span>
							<?php echo esc_html( $message['text'] ); ?>
							<?php if ( $message['link'] ) : ?>
								<a href="<?php echo esc_url( $message['link'] ); ?>"><?php echo esc_html( $message['link_text'] ); ?> &rarr;</a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="dashlight-footer">
				<span>
					<?php
					if ( $data['checked'] ) {
						/* translators: %s: time since the last check, e.g. "3 hours" */
						printf( esc_html__( 'Last checked %s ago', 'dashlight' ), esc_html( human_time_diff( $data['checked'] ) ) );
					}
					?>
				</span>
				<?php DashLight_Settings::check_now_button( 'button-link' ); ?>
			</div>
			<p class="dashlight-credit">
				<?php esc_html_e( 'DashLight is free from CKreative Digital. Want someone to handle all of this?', 'dashlight' ); ?>
				<a href="<?php echo esc_url( self::CARE_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'See our Care Plans', 'dashlight' ); ?> &rarr;</a>
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
			case DashLight_Checks::GOOD:
				return __( 'Good', 'dashlight' );
			case DashLight_Checks::WARNING:
				return __( 'Needs attention soon', 'dashlight' );
			case DashLight_Checks::CRITICAL:
				return __( 'Needs attention now', 'dashlight' );
			default:
				return __( "Couldn't check", 'dashlight' );
		}
	}
}

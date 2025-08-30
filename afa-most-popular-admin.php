<?php

function afa_admin_menu() {

	add_options_page(
		'AFA Most Popular Settings',
		'AFA Most Popular',
		'manage_options',
		'afa-most-popular',
		'afa_most_popular_settings_page'
	);

}
add_action( 'admin_menu', 'afa_admin_menu' );


function afa_admin_init() {

	register_setting( 'afa_most_popular_settings', 'afa_most_popular_ga4_property_id' );
	register_setting( 'afa_most_popular_settings', 'afa_most_popular_client_email' );
	register_setting( 'afa_most_popular_settings', 'afa_most_popular_private_key' );
	register_setting( 'afa_most_popular_settings', 'afa_most_popular_display_post_types' );

}
add_action( 'admin_init', 'afa_admin_init' );

function afa_most_popular_settings_page() {
	?>
	<div class="wrap">
		<h1>AFA Most Popular</h1>
		<h3>Plugin Settings</h3>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'afa_most_popular_settings' );
			do_settings_sections( 'afa_most_popular_settings' );
			?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row">GA4 Property ID</th>
					<td><input type="text" name="afa_most_popular_ga4_property_id" value="<?php echo esc_attr( get_option( 'afa_most_popular_ga4_property_id' ) ); ?>" size="40" /></td>
				</tr>
				<tr valign="top">
					<th scope="row">Service Account Client Email</th>
					<td><input type="text" name="afa_most_popular_client_email" value="<?php echo esc_attr( get_option( 'afa_most_popular_client_email' ) ); ?>" size="60" /></td>
				</tr>
				<tr valign="top">
					<th scope="row">Private Key</th>
					<td>
						<textarea name="afa_most_popular_private_key" rows="10" cols="80"><?php echo esc_textarea( get_option( 'afa_most_popular_private_key' ) ); ?></textarea><br>
						<small>Paste the full private key from your service account JSON.</small>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">Post Types to display in Popular Posts Widget and Shortcode</th>
					<td>
						<?php
						$selected_post_types = get_option( 'afa_most_popular_post_types', array() );
						if ( ! is_array( $selected_post_types ) ) {
							$selected_post_types = array();
						}
						$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
						foreach ( $all_post_types as $post_type ) {
							$checked = checked( in_array( $post_type->name, $selected_post_types ), true, false );
							$value = esc_attr( $post_type->name );
							$title = esc_html( $post_type->labels->name );
							echo "<label><input type='checkbox' name='afa_most_popular_post_types[]' value='{$value}' {$checked}>{$title}</label><br/>";
						}
						?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2>Test Google Analytics Connection</h2>
		<form method="post">
			<?php wp_nonce_field( 'afa_test_connection_nonce' ); ?>
			<input type="submit" name="afa_test_connection" class="button button-secondary" value="Test Connection">
		</form>

	</div>
	<?php
}

function afa_most_popular_add_dashboard_widget() {

	wp_add_dashboard_widget(
		'afa_most_popular_widget',
		'AFA Most Popular Pages (Last 24h)',
		'afa_most_popular_render_dashboard_widget'
	);

}
add_action( 'wp_dashboard_setup', 'afa_most_popular_add_dashboard_widget');

function afa_most_popular_render_dashboard_widget() {

	$force = false;

	// MANUAL REFRESH, USE THE FORCE OPTION
	if ( isset( $_GET['afa_refresh'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'afa_refresh_nonce' ) ) {
		$force = true;
		echo '<div class="notice notice-success inline"><p>Data refreshed.</p></div>';
	}

	$popular = afa_most_popular_fetch_data( $force );

	if ( empty( $popular ) ) {
		echo '<p>No data available.</p>';
		return;
	}

	// BUILD AND DISPLAY DATA
	echo '<table class="widefat striped">';
	echo '<thead><tr><th>Title</th><th>Views</th></tr></thead><tbody>';

	foreach ( $popular as $post ) {
		$title = esc_html( $post['title'] ) ?? '(Unknown)';
		$path = esc_html( $post['path'] );
		$views = $post['views'];
		$url = esc_url( home_url( $path ) );

		echo "<tr><td><a href='{$url}' target='_blank'>{$title}</a></td><td>{$views}</td></tr>";
	}

	echo '</tbody></table>';

	// Show timestamp and refresh button
	if ( $last_fetched ) {
		$last_fetched = date( 'Y-m-d H:i:s', $last_fetched );
		echo "<p><small>Last updated: {$last_fetched}</small></p>";
	}

	echo '<form method="get" action="">';
	echo '<input type="hidden" name="afa_refresh" value="1">';
	echo wp_nonce_field( 'afa_refresh_nonce', '_wpnonce', true, false );
	echo '<input type="submit" class="button" value="Refresh Now">';
	echo '</form>';
}

function afa_handle_test_connection() {

	if ( ! isset( $_POST['afa_test_connection'] ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'afa_test_connection_nonce' ) ) {
		wp_die( 'Unauthorized Request' );
	}

	$result = afa_test_google_connection();

	add_action('admin_notices', function () use ($result) {
		if ($result['success']) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Success:</strong> ' . esc_html($result['message']) . '</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html($result['message']) . '</p></div>';
		}
	});

}
add_action('admin_init', 'afa_handle_test_connection');

function afa_test_google_connection() {

	$property_id = get_option('afa_most_popular_ga4_property_id');
	$client_email = get_option('afa_most_popular_client_email');
	$private_key = get_option('afa_most_popular_private_key');

	if ( ! $property_id || ! $client_email || ! $private_key ) {
		return array( 'success' => false, 'message' => 'Missing credentials. Please save your credentials prior to testing.' );
	}

	$jwt_signed = afa_generate_jwt( $property_id, $client_email, $private_key );

	if ( ! $jwt_signed ) {
		return array( 'success' => false, 'message' => 'OpenSSL failed to properly sign JSON Web Token. Recheck your private key.' );
	}

	$access_token = afa_request_access_token( $jwt_signed );

	if ( ! $access_token ) {
		return array( 'success' => false, 'message' => 'Google did not return an Access Token for these credentials.' );
	}

	// RUN A SMALL TEST REPORT
	$analytics_request = array(
		'dimensions'  => [['name' => 'date']],
		'metrics'     => [['name' => 'activeUsers']],
		'dateRanges'  => [[ 'startDate' => '7daysAgo', 'endDate' => 'today']],
		'limit'       => 1
	);

	$report_url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
    $report_response = afa_http_post_json( $report_url, $analytics_request, $access_token );

    if ( ! isset( $report_response['rows'] ) ) {
        return array( 'success' => false, 'message' => 'Google did not return data when a test report was requested. Check Property ID and permissions for the supplied credentials.' );
    }

    return array( 'success' => true, 'message' => 'Successfully connected and retrieved test data from Google Analytics.' );

}

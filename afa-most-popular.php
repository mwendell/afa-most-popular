<?php
/*
 * AFA Most Popular
 *
 * PHP version 8.0.0
 *
 * @category WordPress_Plugin
 * @package  afa-most-popular
 * @author   Michael Wendell <mwendell@kwyjibo.com>
 * @license  GPL-2.0+ <http://www.gnu.org/licenses/gpl-2.0.txt>
 * @link     https://github.com/mwendell/afa-most-popular/
 * @since    2025-08-29
 *
 * @wordpress-plugin
 * Plugin Name:   AFA Most Popular
 * Plugin URI:    https://github.com/mwendell/afa-most-popular/
 * Description:   Fetch, store, and display Most Popular Posts data from Google Analytics.
 * Version:       1.0.0
 * Author:        Michael Wendell <mwendell@kwyjibo.com>
 * Author URI:    https://www.kwyjibo.com
 * License:       GPL-2.0+
 * License URI:   http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:   afa-most-popular
 * Domain Path:   /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 */
define( 'AFA_MOST_POPULAR_VERSION', '1.0.0' );

// === Main Function ===
function afa_most_popular_fetch_data() {
    $propertyId = get_option('afa_ga4_property_id');
    $clientEmail = get_option('afa_client_email');
    $privateKey = get_option('afa_private_key');

    if (!$propertyId || !$clientEmail || !$privateKey) {
        error_log('AFA Most Popular: GA4 credentials are not fully configured.');
        return;
    }

    // Generate JWT
    $jwtHeader = base64url_encode(json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT'
    ]));

    $now = time();
    $jwtClaimSet = base64url_encode(json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]));

    $unsignedJWT = $jwtHeader . '.' . $jwtClaimSet;
    $signature = '';
    openssl_sign($unsignedJWT, $signature, $privateKey, 'sha256WithRSAEncryption');
    $signedJWT = $unsignedJWT . '.' . base64url_encode($signature);

    // Request Access Token
    $tokenResponse = afa_http_post('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $signedJWT
    ]);

    if (!isset($tokenResponse['access_token'])) {
        error_log('AFA Most Popular: Failed to retrieve access token.');
        return;
    }

    $accessToken = $tokenResponse['access_token'];

    // GA4 Data API request
    $analyticsRequest = [
        'dimensions' => [['name' => 'pagePath']],
        'metrics' => [['name' => 'screenPageViews']],
        'dateRanges' => [[
            'startDate' => 'yesterday',
            'endDate' => 'today'
        ]],
        'orderBys' => [[
            'metric' => ['metricName' => 'screenPageViews'],
            'desc' => true
        ]],
        'limit' => 20
    ];

    $reportUrl = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";
    $reportResponse = afa_http_post_json($reportUrl, $analyticsRequest, $accessToken);

    if (isset($reportResponse['rows'])) {

		$popularPages = [];

		foreach ($reportResponse['rows'] as $row) {
			$path = $row['dimensionValues'][0]['value'];
			$views = $row['metricValues'][0]['value'];
			$url = home_url($path);
			$post_id = url_to_postid($url);
			$post_type = $title = $edit_link = null;

			if ($post_id) {
				$post = get_post($post_id);
				if ($post) {
					$post_type = $post->post_type;
					$title = get_the_title($post_id);
					$edit_link = get_edit_post_link($post_id);
				}
			}

			$popularPages[] = [
				'path' => $path,
				'views' => (int)$views,
				'post_id' => $post_id,
				'post_type' => $post_type,
				'title' => $title,
				'edit_link' => $edit_link
			];
		}

		update_option('afa_most_popular_pages', $popularPages);
		update_option('afa_most_popular_last_fetched', time());



    } else {
        error_log('AFA Most Popular: No rows returned in report.');
    }
}

// === Utility Functions ===
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function afa_http_post($url, $postFields) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

function afa_http_post_json($url, $data, $accessToken) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// === Admin Settings Page ===
add_action('admin_menu', function() {
    add_options_page(
        'AFA Most Popular Settings',
        'AFA Most Popular',
        'manage_options',
        'afa-most-popular',
        'afa_most_popular_settings_page'
    );
});

add_action('admin_init', function() {
    register_setting('afa_most_popular_settings', 'afa_ga4_property_id');
    register_setting('afa_most_popular_settings', 'afa_client_email');
    register_setting('afa_most_popular_settings', 'afa_private_key');
	if (isset($_POST['afa_display_post_types']) && is_array($_POST['afa_display_post_types'])) {
		$sanitized = array_map('sanitize_text_field', $_POST['afa_display_post_types']);
		update_option('afa_display_post_types', $sanitized);
	} else {
		// If nothing selected, store empty array
		update_option('afa_display_post_types', []);
	}
});

function afa_most_popular_settings_page() {
    ?>
    <div class="wrap">
        <h1>AFA Most Popular Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('afa_most_popular_settings');
            do_settings_sections('afa_most_popular_settings');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">GA4 Property ID</th>
                    <td><input type="text" name="afa_ga4_property_id" value="<?php echo esc_attr(get_option('afa_ga4_property_id')); ?>" size="40" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Service Account Client Email</th>
                    <td><input type="text" name="afa_client_email" value="<?php echo esc_attr(get_option('afa_client_email')); ?>" size="60" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Private Key</th>
                    <td>
                        <textarea name="afa_private_key" rows="10" cols="80"><?php echo esc_textarea(get_option('afa_private_key')); ?></textarea><br>
                        <small>Paste the full private key from your service account JSON.</small>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Post Types to display in Popular Posts Widget</th>
                    <td>
					<?php
					$selected_post_types = get_option('afa_display_post_types', ['post', 'article']);
					$all_post_types = get_post_types(['public' => true], 'objects');
					foreach ($all_post_types as $post_type): ?>
						<label>
							<input type="checkbox" name="afa_display_post_types[]" value="<?php echo esc_attr($post_type->name); ?>"
								<?php checked(in_array($post_type->name, $selected_post_types)); ?>>
							<?php echo esc_html($post_type->labels->name); ?>
						</label><br>
					<?php endforeach; ?>                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

		<h2>Test Google Analytics Connection</h2>
		<form method="post">
			<?php wp_nonce_field('afa_test_connection_nonce'); ?>
			<input type="submit" name="afa_test_connection" class="button button-secondary" value="Test Connection">
		</form>

    </div>
    <?php
}

add_action('admin_init', 'afa_handle_test_connection');

function afa_handle_test_connection() {
    if (!isset($_POST['afa_test_connection'])) {
        return;
    }

    if (!current_user_can('manage_options') || !check_admin_referer('afa_test_connection_nonce')) {
        wp_die('Unauthorized request');
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

// === Add Dashboard Widget ===
add_action('wp_dashboard_setup', 'afa_most_popular_add_dashboard_widget');

function afa_most_popular_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'afa_most_popular_widget',
        'AFA Most Popular Pages (Last 24h)',
        'afa_most_popular_render_dashboard_widget'
    );
}

// === Widget Renderer ===
function afa_most_popular_render_dashboard_widget() {
    // Check if user submitted a manual refresh
    if (isset($_GET['afa_refresh']) && current_user_can('manage_options') && check_admin_referer('afa_refresh_nonce')) {
        afa_most_popular_fetch_data(); // force refresh
        echo '<div class="notice notice-success inline"><p>Data refreshed.</p></div>';
    }

    $lastFetched = get_option('afa_most_popular_last_fetched', 0);
    $dataAge = time() - intval($lastFetched);

    if ($dataAge > 12 * HOUR_IN_SECONDS) {
        echo '<p><em>Data is older than 12 hours. Fetching latest data...</em></p>';
        afa_most_popular_fetch_data(); // auto refresh
        $lastFetched = get_option('afa_most_popular_last_fetched', 0);
    }

    $popularPages = get_option('afa_most_popular_pages', []);

    if (empty($popularPages)) {
        echo '<p>No data available.</p>';
        return;
    }

    // Display table
	echo '<table class="widefat striped">';
	echo '<thead><tr><th>Title</th><th>Page</th><th>Views</th></tr></thead><tbody>';

	foreach ($popularPages as $page) {
		$title = $page['title'] ?? '(Unknown)';
		$path = $page['path'];
		$views = $page['views'];
		$url = esc_url(home_url($path));

		if (current_user_can('edit_post', $page['post_id']) && !empty($page['edit_link'])) {
			$title_html = '<a href="' . esc_url($page['edit_link']) . '">' . esc_html($title) . '</a>';
		} else {
			$title_html = esc_html($title);
		}

		echo '<tr>';
		echo '<td>' . $title_html . '</td>';
		echo '<td><a href="' . $url . '" target="_blank">' . esc_html($path) . '</a></td>';
		echo '<td>' . esc_html($views) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

    // Show timestamp and refresh button
    if ($lastFetched) {
        echo '<p><small>Last updated: ' . esc_html(date('Y-m-d H:i:s', $lastFetched)) . '</small></p>';
    }

    echo '<form method="get" action="">';
    echo '<input type="hidden" name="afa_refresh" value="1">';
    echo wp_nonce_field('afa_refresh_nonce', '_wpnonce', true, false);
    echo '<input type="submit" class="button" value="Refresh Now">';
    echo '</form>';
}

function afa_get_title_by_path($path) {
    // Remove leading slash if present
    $path = ltrim($path, '/');

    // Special case for homepage
    if ($path === '') {
        return 'Home';
    }

    // Try to find post by path
    $page = get_page_by_path($path);

    if ($page) {
        return get_the_title($page);
    }

    // Try to match with custom post types or fallback
    $url = home_url($path);
    $post_id = url_to_postid($url);

    if ($post_id) {
        return get_the_title($post_id);
    }

    return '(Unknown Title)';
}

add_shortcode('afa_most_popular', 'afa_render_most_popular_shortcode');

function afa_render_most_popular_shortcode($atts) {
    $popularPages = get_option('afa_most_popular_pages', []);
    $allowed_types = get_option('afa_display_post_types', ['post', 'article']);

    if (empty($popularPages) || empty($allowed_types)) {
        return '<p>No popular posts available.</p>';
    }

    ob_start();
    echo '<table class="afa-most-popular-table">';
    echo '<thead><tr><th>Title</th><th>Page</th><th>Views</th></tr></thead><tbody>';

    foreach ($popularPages as $page) {
        if (!in_array($page['post_type'], $allowed_types, true)) {
            continue;
        }

        $title = $page['title'] ?? '(Unknown)';
        $path = $page['path'];
        $views = $page['views'];
        $url = esc_url(home_url($path));

        echo '<tr>';
        echo '<td>' . esc_html($title) . '</td>';
        echo '<td><a href="' . $url . '">' . esc_html($path) . '</a></td>';
        echo '<td>' . esc_html($views) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    return ob_get_clean();
}

add_action('wp_head', function () {
    echo '<style>
        .afa-most-popular-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1em 0;
        }
        .afa-most-popular-table th,
        .afa-most-popular-table td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        .afa-most-popular-table th {
            background-color: #f4f4f4;
            text-align: left;
        }
    </style>';
});

function afa_test_google_connection() {
    $propertyId = get_option('afa_ga4_property_id');
    $clientEmail = get_option('afa_client_email');
    $privateKey = get_option('afa_private_key');

    if (!$propertyId || !$clientEmail || !$privateKey) {
        return ['success' => false, 'message' => 'Missing credentials.'];
    }

    // Generate JWT
    $jwtHeader = base64url_encode(json_encode([
        'alg' => 'RS256',
        'typ' => 'JWT'
    ]));

    $now = time();
    $jwtClaimSet = base64url_encode(json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now
    ]));

    $unsignedJWT = $jwtHeader . '.' . $jwtClaimSet;
    $signature = '';
    $success = openssl_sign($unsignedJWT, $signature, $privateKey, 'sha256WithRSAEncryption');
    if (!$success) {
        return ['success' => false, 'message' => 'Failed to sign JWT. Check private key.'];
    }

    $signedJWT = $unsignedJWT . '.' . base64url_encode($signature);

    // Get access token
    $tokenResponse = afa_http_post('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $signedJWT
    ]);

    if (empty($tokenResponse['access_token'])) {
        return ['success' => false, 'message' => 'Unable to retrieve access token.'];
    }

    // Minimal report
    $analyticsRequest = [
        'dimensions' => [['name' => 'date']],
        'metrics' => [['name' => 'activeUsers']],
        'dateRanges' => [[
            'startDate' => '7daysAgo',
            'endDate' => 'today'
        ]],
        'limit' => 1
    ];

    $reportUrl = "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport";
    $reportResponse = afa_http_post_json($reportUrl, $analyticsRequest, $tokenResponse['access_token']);

    if (!isset($reportResponse['rows'])) {
        return ['success' => false, 'message' => 'Report request failed. Check Property ID and permissions.'];
    }

    return ['success' => true, 'message' => 'Connection successful! Retrieved data from Google Analytics.'];
}

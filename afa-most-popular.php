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

// CODE FOR ADMIN SCREENS
include 'afa-most-popular-admin.php';

// CODE FOR PUBLIC FACING DISPLAY
include 'afa-most-popular-public.php';

// FETCH THE DATA FROM GOOGLE
function afa_most_popular_fetch_data() {

	$property_id = get_option( 'afa_ga4_property_id' );
	$client_email = get_option( 'afa_client_email' );
	$private_key = get_option( 'afa_private_key' );

	if ( ! $property_id || ! $client_email || ! $private_key ) {
		return;
	}

	$jwt_signed = afa_generate_jwt( $property_id, $client_email, $private_key );

	$access_token = afa_request_access_token( $jwt_signed );

	// GA4 DATA API REQUEST ARRAY
	$analytics_request = array(
		'dimensions' => [[ 'name' => 'pagePath' ]],
		'metrics'    => [[ 'name' => 'screenPageViews' ]],
		'dateRanges' => [[ 'startDate' => 'yesterday', 'endDate' => 'today' ]],
		'orderBys'   => [[ 'metric' => [ 'metricName' => 'screenPageViews' ], 'desc' => true ]],
		'limit'      => 20
	);

	$report_url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";
	$report_response = afa_http_post_json( $report_url, $analytics_request, $access_token );

	if ( isset( $report_response['rows'] ) ) {

		$popular = array();

		foreach ( $report_response['rows'] as $row ) {
			$path = $row['dimensionValues'][0]['value'];
			$views = $row['metricValues'][0]['value'];
			$url = home_url( $path );
			$post_id = url_to_postid( $url );
			$post_type = $title = $edit_link = null;

			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( $post ) {
					$post_type = $post->post_type;
					$title = get_the_title( $post_id );
					$edit_link = get_edit_post_link( $post_id );
				}
			}

			$popular[] = array(
				'path'      => $path,
				'views'     => (int)$views,
				'post_id'   => $post_id,
				'post_type' => $post_type,
				'title'     => $title,
				'edit_link' => $edit_link
			);
		}

		update_option( 'afa_most_popular_pages', $popular );
		update_option( 'afa_most_popular_last_fetched', time() );

	}
}


// GENERATE JSON WEB TOKEN USED TO REQUEST AN ACCESS TOKEN FROM GOOGLE
function afa_generate_jwt( $property_id = false, $client_email = false, $private_key = false ) {

	if ( ! $property_id || ! $client_email || ! $private_key ) {
		return false;
	}

	$jwt_header_settings = array(
		'alg' => 'RS256',
		'typ' => 'JWT',
	);
	$jwt_header = afa_base64url_encode( json_encode( $jwt_header_settings ) );

	$now = time();
	$jwt_claim_settings = array(
		'iss'   => $client_email,
		'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
		'aud'   => 'https://oauth2.googleapis.com/token',
		'exp'   => $now + 3600,
		'iat'   => $now,
	);
	$jwt_claim_set = afa_base64url_encode( json_encode( $jwt_claim_settings ) );

	$jwt_unsigned = $jwt_header . '.' . $jwt_claim_set;
	$signature = '';
	$success = openssl_sign( $jwt_unsigned, $signature, $private_key, 'sha256WithRSAEncryption' );

    if ( ! $success ) {
        return false;
    }

	$jwt_signed = $jwt_unsigned . '.' . afa_base64url_encode( $signature );

	return $jwt_signed;

}

// REQUEST ACCESS TOKEN FROM GOOGLE
function afa_request_access_token( $jwt_signed = false ) {

	$token_response_settings = array(
		'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
		'assertion' => $jwt_signed,
	);

	$token_response = afa_http_post( 'https://oauth2.googleapis.com/token', $token_response_settings );

	if ( ! isset( $token_response['access_token'] ) || empty( $token_response['access_token'] ) ) {
		return false;
	}

	$access_token = $token_response['access_token'];

	return $access_token;
}

function afa_base64url_encode( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

function afa_http_post( $url, $post_fields ) {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $post_fields ) );
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/x-www-form-urlencoded' ) );

	$response = curl_exec( $ch );
	curl_close( $ch );

	return json_decode( $response, true );
}

function afa_http_post_json( $url, $data, $access_token ) {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $data ) );
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json', 'Authorization: Bearer ' . $access_token ) );

	$response = curl_exec( $ch );
	curl_close( $ch );

	return json_decode( $response, true );
}

function afa_get_title_by_path( $path ) {

	$path = ltrim($path, '/');

	if ( $path === '' ) {
		return 'Home';
	}

	$page = get_page_by_path( $path );

	if ( $page ) {
		return get_the_title( $page );
	}

	$url = home_url( $path );

	$post_id = url_to_postid( $url );

	if ( $post_id ) {
		return get_the_title( $post_id );
	}

	return '(Unknown Title)';

}

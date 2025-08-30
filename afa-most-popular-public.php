<?php
function afa_render_most_popular_shortcode($atts) {

	$popular = get_option( 'afa_most_popular_posts', array() );
	$allowed_types = get_option( 'afa_most_popular_post_types', array( 'post', 'article' ) );

	if ( empty( $popular ) || empty( $allowed_types ) ) {
		return;
	}

	wp_enqueue_style( 'afa-most-popular-shortcode-css', 'afa-most-popular.css' );

	ob_start();
	echo '<div class="afa-most-popular"><ul>';

	foreach ( $popular as $post ) {

		echo "<!--";
		echo print_r( $post, 1 );
		echo "-->";

		if ( ! in_array( $post['post_type'], $allowed_types, true ) ) {
			continue;
		}

		$title = $post['title'];
		$url = esc_url( home_url( $post['path'] ) );
		//$views = $post['views'];

		if ( ! $title || ! $url ) {
			continue;
		}

		echo "<li><a href='{$url}'>{$title}</li>";
	}

	echo '</ul></div>';
	return ob_get_clean();
}
add_shortcode('afa_most_popular', 'afa_render_most_popular_shortcode');

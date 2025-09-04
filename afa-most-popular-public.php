<?php
function afa_render_most_popular_shortcode( $atts ) {

	$defaults = array(
		'count' => 4,
	);

    $attributes = shortcode_atts( $defaults, $atts );

	afa_most_popular_render( $attributes['count'] );

}
add_shortcode( 'afa_most_popular', 'afa_render_most_popular_shortcode' );

function afa_most_popular_enqueue_css() {
    wp_enqueue_style(
        'afa-most-popular-css',
        plugins_url( 'afa-most-popular.css', __FILE__ ),
        array(),
        '1.0.0',
        'all'
    );
}
add_action( 'wp_enqueue_scripts', 'afa_most_popular_enqueue_css' );

function afa_most_popular_render( $count = 4, $echo = true ) {

	$popular = afa_most_popular_fetch_data();
	$allowed_types = get_option( 'afa_most_popular_post_types', array( 'post', 'article' ) );

	if ( empty( $popular ) || empty( $allowed_types ) ) {
		return;
	}

	$basis = intval( 100/$count );

	$output = "<div class='afa-most-popular'><h3>Trending Stories</h3><div class='afa-most-popular-container'>";

	$i = 0;
	foreach ( $popular as $post ) {

		if ( ! in_array( $post['post_type'], $allowed_types, true ) ) {
			continue;
		}

		$title = $post['title'];
		$url = $post['path'];
		$image = $post['thumbnail'];

		if ( ! $title || ! $url ) {
			continue;
		}

		$i++;
		$output .= "<a href='{$url}' rel='bookmark' title='{$title}' class='afa-most-popular-story' style='flex-basis:{$basis}%;background-image:url({$image})'>";
		$output .= "<h4>{$title}</h4>";
		$output .= "</a>";

		if ( $i == $count ) {
			break;
		}

	}

	$output .=  '</div></div>';

	if ( $echo ) {
		echo $output;
	} else {
		return $output;
	}


}


/*
 <!--Array
(
    [path] => /air-force-officials-solve-valley-of-death-acquisition/
    [views] => 105516
    [post_id] => 251794
    [post_type] => post
    [title] => Air Force Officials Say They’re Poised to Solve the Longstanding ‘Valley of Death’
    [edit_link] => https://airandspaceforces.com.ddev.site/wp/wp-admin/post.php?post=251794&amp;action=edit
    [thumbnail] => https://airandspaceforces.com.ddev.site/app/uploads/2025/08/9080408-200x200.jpg
)
-->
*/

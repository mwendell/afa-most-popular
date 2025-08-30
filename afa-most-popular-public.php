<?php
function afa_render_most_popular_shortcode( $atts ) {

	$defaults = array(
		'count' => 3,
	);

    $attributes = shortcode_atts( $defaults, $atts );

	echo afa_most_popular_render( $attributes['count'] );

}
add_shortcode( 'afa_most_popular', 'afa_render_most_popular_shortcode' );

function afa_most_popular_render( $count = 3, $echo = false ) {

	$popular = get_option( 'afa_most_popular_posts', array() );
	$allowed_types = get_option( 'afa_most_popular_post_types', array( 'post', 'article' ) );

	if ( empty( $popular ) || empty( $allowed_types ) ) {
		return;
	}

	$output = "<div class='items grid-container background-gray trending-stories'><h3 class='sidebar-title col-sm-12'>Most popular</h3>";

	$i = 0;
	foreach ( $popular as $post ) {

		if ( ! in_array( $post['post_type'], $allowed_types, true ) ) {
			continue;
		}

		$title = $post['title'];
		$url = esc_url( home_url( $post['path'] ) );
		$image = esc_url( home_url( $post['thumbnail'] ) );

		if ( ! $title || ! $url ) {
			continue;
		}

		$i++;
		$output .= "<div class='item article-trending-stories grid-container'><div class='col-sm-2 col-lg-4 col-no-pad col-no-margin image-wrapper'>";
		$output .= "<a href='{$url}' title='{$title}'><img width='150' height='150' src='{$image}' class='attachment-thumbnail size-thumbnail wp-post-image' alt='' decoding='async' loading='lazy'></a></div>";
		$output .= "<div class='col-sm-10 col-lg-8 col-no-pad col-no-margin'>";
		$output .= "<h4 class='post-title'><a href='{$url}' rel='bookmark' title='{$title}'>{$title}</a></h4>";
		$output .= "</div></div>";

		if ( $i == $count ) {
			break;
		}

	}

	$output .=  '</div>';

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
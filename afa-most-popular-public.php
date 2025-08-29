<?php

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
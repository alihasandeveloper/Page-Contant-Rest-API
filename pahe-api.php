
<?php
/**
 * Plugin Name: Gutenberg & CSS REST API
 * Description: Exposes Gutenberg content, page CSS, and enqueued CSS files via REST API.
 * Version: 1.1
 * Author: Boomdevs
 * Author URI: https://boomdevs.com
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Register API endpoint for Gutenberg content
function get_gutenberg_content($request) {
    $page_id = $request['id'];
    $post = get_post($page_id);

    if (!$post) {
        return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
    }

    return rest_ensure_response([
        'id' => $post->ID,
        'title' => get_the_title($post),
        'content' => parse_blocks($post->post_content),
    ]);
}

// Register API endpoint for page-specific CSS
function get_page_css($request) {
    $page_id = $request['id'];
    $post = get_post($page_id);

    if (!$post) {
        return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
    }

    ob_start();
    wp_head(); // Capture styles
    $head_content = ob_get_clean();

    preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $head_content, $matches);
    $css = implode("\n", $matches[1]);

    return rest_ensure_response([
        'id' => $post->ID,
        'css' => $css,
    ]);
}

// NEW FUNCTION: Get all enqueued CSS files for a specific page
function get_enqueued_css_files($request) {
    $page_id = $request['id'];
    $post = get_post($page_id);

    if (!$post) {
        return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
    }

    // Set up the WordPress environment for this page
    global $wp_scripts, $wp_styles;
    $wp_scripts = new WP_Scripts();
    $wp_styles = new WP_Styles();

    // Load the page to get its enqueued styles
    setup_postdata($post);

    // Force WordPress to enqueue all styles for this page
    ob_start();
    wp_head();
    ob_get_clean();

    // Get all enqueued stylesheets
    $enqueued_styles = [];

    if (!empty($wp_styles->queue)) {
        foreach ($wp_styles->queue as $handle) {
            if (isset($wp_styles->registered[$handle])) {
                $style = $wp_styles->registered[$handle];
                $src = $style->src;

                // Convert relative URLs to absolute
                if (strpos($src, '//') === false && strpos($src, 'http') !== 0) {
                    $src = site_url($src);
                }

                $enqueued_styles[] = [
                    'handle' => $handle,
                    'src' => $src,
                    'deps' => $style->deps,
                    'version' => $style->ver,
                    'media' => $style->args
                ];
            }
        }
    }

    wp_reset_postdata();

    return rest_ensure_response([
        'id' => $post->ID,
        'title' => get_the_title($post),
        'css_files' => $enqueued_styles,
    ]);
}

function register_custom_rest_routes() {
    register_rest_route('gutenberg/v2', '/page-content/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_gutenberg_content',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('gutenberg/v2', '/page-css/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_page_css',
        'permission_callback' => '__return_true',
    ]);

    // New route for enqueued CSS files
    register_rest_route('gutenberg/v2', '/page-css-files/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_enqueued_css_files',
        'permission_callback' => '__return_true',
    ]);
}

add_action('rest_api_init', 'register_custom_rest_routes');
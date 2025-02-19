<?php
/**
 * Plugin Name: Gutenberg & CSS REST API
 * Description: Exposes Gutenberg content and page CSS via REST API.
 * Version: 1.0
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
}

add_action('rest_api_init', 'register_custom_rest_routes');

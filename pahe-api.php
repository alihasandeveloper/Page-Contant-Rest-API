<?php
/**
 * Plugin Name: Gutenberg & CSS REST API (Extended)
 * Description: Exposes Gutenberg content, page CSS, and enqueued CSS/JS files via REST API, including third-party block styles/scripts.
 * Version: 1.2
 * Author: Boomdevs
 * Author URI: https://boomdevs.com
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Fetch Gutenberg Content
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

// Fetch All Enqueued CSS & JS Files for Page
function get_enqueued_assets($request) {
    $page_id = $request['id'];
    $post = get_post($page_id);
    if (!$post) {
        return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
    }

    global $wp_styles, $wp_scripts;
    $wp_styles = new WP_Styles();
    $wp_scripts = new WP_Scripts();

    setup_postdata($post);
    ob_start();
    wp_head();
    wp_footer();
    $head_foot_content = ob_get_clean();

    $enqueued_styles = [];
    foreach ($wp_styles->queue as $handle) {
        if (isset($wp_styles->registered[$handle])) {
            $style = $wp_styles->registered[$handle];
            $src = $style->src;
            if (strpos($src, '//') === false && strpos($src, 'http') !== 0) {
                $src = site_url($src);
            }
            $enqueued_styles[] = ['handle' => $handle, 'src' => $src, 'deps' => $style->deps, 'version' => $style->ver, 'media' => $style->args];
        }
    }

    $enqueued_scripts = [];
    foreach ($wp_scripts->queue as $handle) {
        if (isset($wp_scripts->registered[$handle])) {
            $script = $wp_scripts->registered[$handle];
            $src = $script->src;
            if (strpos($src, '//') === false && strpos($src, 'http') !== 0) {
                $src = site_url($src);
            }
            $enqueued_scripts[] = ['handle' => $handle, 'src' => $src, 'deps' => $script->deps, 'version' => $script->ver, 'footer' => $script->extra['group'] == 1];
        }
    }

    preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $head_foot_content, $style_matches);
    $inline_css = implode("\n", $style_matches[1]);

    preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $head_foot_content, $script_matches);
    $inline_js = implode("\n", $script_matches[1]);

    wp_reset_postdata();

    return rest_ensure_response([
        'id' => $post->ID,
        'title' => get_the_title($post),
        'css_files' => $enqueued_styles,
        'js_files' => $enqueued_scripts,
        'inline_css' => $inline_css,
        'inline_js' => $inline_js,
    ]);
}

// Register REST Routes
function register_custom_rest_routes() {
    register_rest_route('gutenberg/v2', '/page-content/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_gutenberg_content',
        'permission_callback' => '__return_true',
    ]);

    register_rest_route('gutenberg/v2', '/page-assets/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_enqueued_assets',
        'permission_callback' => '__return_true',
    ]);
}
add_action('rest_api_init', 'register_custom_rest_routes');

<?php
/**
 * Plugin Name: Gutenberg & CSS REST API
 * Description: Exposes Gutenberg content, page CSS, and enqueued CSS/JS files via REST API, including third-party block styles/scripts and fonts.
 * Version: 1.0
 * Author: Boomdevs
 * Author URI: https://boomdevs.com
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Add theme support for required features
function add_theme_support_features() {
    add_theme_support('wp-block-styles');
    add_theme_support('responsive-embeds');
    add_theme_support('align-wide');
}
add_action('after_setup_theme', 'add_theme_support_features');

// Fetch Gutenberg Content with Enhanced Block Rendering
function get_gutenberg_content($request) {
    $page_id = $request['id'];
    $post = get_post($page_id);
    if (!$post) {
        return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
    }

    // Set up post data to ensure proper context
    setup_postdata($post);

    // Get parsed blocks
    $blocks = parse_blocks($post->post_content);

    // Render blocks with proper context
    $rendered_blocks = array_map(function($block) {
        return [
            'blockName' => $block['blockName'],
            'attrs' => $block['attrs'],
            'innerBlocks' => $block['innerBlocks'],
            'innerHTML' => $block['innerHTML'],
            'rendered' => render_block($block)
        ];
    }, $blocks);

    wp_reset_postdata();

    return rest_ensure_response([
        'id' => $post->ID,
        'title' => get_the_title($post),
        'content' => $rendered_blocks,
    ]);
}

// Fetch All Enqueued CSS, JS & Fonts for Page with Enhanced Asset Collection
function get_enqueued_assets($request) {
    $page_id = $request['id'];
    $post = get_post($page_id);
    if (!$post) {
        return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
    }

    // Reset and initialize WordPress styles and scripts
    global $wp_styles, $wp_scripts;
    $wp_styles = new WP_Styles();
    $wp_scripts = new WP_Scripts();

    // Ensure all Gutenberg block assets are loaded
    do_action('enqueue_block_assets');
    do_action('enqueue_block_editor_assets');

    // Set up post data and capture output
    setup_postdata($post);
    ob_start();
    wp_head();
    the_content(); // Important: Actually render the content to catch dynamic block styles
    wp_footer();
    $head_foot_content = ob_get_clean();

    // Process styles with proper URL handling
    $enqueued_styles = [];
    foreach ($wp_styles->queue as $handle) {
        if (isset($wp_styles->registered[$handle])) {
            $style = $wp_styles->registered[$handle];
            $src = $style->src;

            // Handle relative URLs and protocol-relative URLs
            if (strpos($src, '//') === 0) {
                $src = 'https:' . $src;
            } elseif (strpos($src, '/') === 0) {
                $src = site_url($src);
            } elseif (strpos($src, 'http') !== 0) {
                $src = site_url('/' . $src);
            }

            // Include inline styles associated with this handle
            $inline_styles = $wp_styles->get_data($handle, 'after') ?: [];

            $enqueued_styles[] = [
                'handle' => $handle,
                'src' => $src,
                'deps' => $style->deps,
                'version' => $style->ver,
                'media' => $style->args,
                'inline_styles' => $inline_styles
            ];
        }
    }

    // Process scripts with proper URL handling and dependencies
    $enqueued_scripts = [];
    foreach ($wp_scripts->queue as $handle) {
        if (isset($wp_scripts->registered[$handle])) {
            $script = $wp_scripts->registered[$handle];
            $src = $script->src;

            // Handle relative URLs and protocol-relative URLs
            if (strpos($src, '//') === 0) {
                $src = 'https:' . $src;
            } elseif (strpos($src, '/') === 0) {
                $src = site_url($src);
            } elseif (strpos($src, 'http') !== 0) {
                $src = site_url('/' . $src);
            }

            // Include inline scripts and configuration
            $before_scripts = $wp_scripts->get_data($handle, 'before') ?: [];
            $after_scripts = $wp_scripts->get_data($handle, 'after') ?: [];
            $translations = $wp_scripts->get_data($handle, 'translations');

            $enqueued_scripts[] = [
                'handle' => $handle,
                'src' => $src,
                'deps' => $script->deps,
                'version' => $script->ver,
                'footer' => isset($script->extra['group']) && $script->extra['group'] == 1,
                'before' => $before_scripts,
                'after' => $after_scripts,
                'translations' => $translations
            ];
        }
    }

    // Extract inline styles and scripts with improved regex
    preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $head_foot_content, $style_matches);
    $inline_css = implode("\n", array_filter($style_matches[1]));

    preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $head_foot_content, $script_matches);
    $inline_js = implode("\n", array_filter($script_matches[1]));

    // Extract fonts with comprehensive matching
    preg_match_all('/<link[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $head_foot_content, $font_matches);
    $fonts = array_filter($font_matches[1], function ($url) {
        return strpos($url, 'fonts.googleapis.com') !== false
            || strpos($url, 'fonts.gstatic.com') !== false
            || strpos($url, '/wp-content/themes/') !== false
            || strpos($url, '/wp-content/plugins/') !== false;
    });

    // Add default theme styles override
    add_theme_support('editor-styles');
    $theme_css = '.wp-block { max-width: 100%; margin: 0; padding: 0; }
                  .wp-block-group { margin: 0; padding: 0; }
                  .entry-content { margin: 0; padding: 0; }';

    wp_reset_postdata();

    return rest_ensure_response([
        'id' => $post->ID,
        'title' => get_the_title($post),
        'css_files' => $enqueued_styles,
        'js_files' => $enqueued_scripts,
        'inline_css' => $inline_css . "\n" . $theme_css,
        'inline_js' => $inline_js,
        'fonts' => array_values(array_unique($fonts)),
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

// Ensure block styles are properly loaded
function enqueue_block_assets() {
    wp_enqueue_style('wp-block-library');
    wp_enqueue_style('wp-block-library-theme');
}
add_action('wp_enqueue_scripts', 'enqueue_block_assets');
<?php
/**
 * Plugin Name: Gutenberg & CSS REST API
 * Description: Exposes Gutenberg content, page CSS, and enqueued CSS files via REST API including font-family information.
 * Version: 1.2
 * Author: Boomdevs
 * Author URI: https://boomdevs.com
 */

if (!defined('ABSPATH')) {
    exit;
}

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

function extract_font_families($css) {
    preg_match_all('/font-family\s*:\s*([^;}]+)[;}]/', $css, $matches);
    $font_families = [];

    if (!empty($matches[1])) {
        foreach ($matches[1] as $font) {
            $font = trim($font);
            // Remove quotes if present
            $font = preg_replace('/[\'"]/', '', $font);
            $font_families[] = $font;
        }
    }

    return array_unique($font_families);
}

function get_page_css($request) {
    $page_id = $request['id'];
    $post = get_post($page_id);

    if (!$post) {
        return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
    }

    ob_start();
    wp_head();
    $head_content = ob_get_clean();

    preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $head_content, $matches);
    $css = implode("\n", $matches[1]);
    $font_families = extract_font_families($css);

    return rest_ensure_response([
        'id' => $post->ID,
        'css' => $css,
        'font_families' => $font_families,
    ]);
}

function get_external_css_content($url) {
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return '';
    }
    return wp_remote_retrieve_body($response);
}

function get_all_css_files($request) {
    $page_id = $request['id'];
    $post = get_post($page_id);

    if (!$post) {
        return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
    }

    global $wp_styles;
    $wp_styles = new WP_Styles();

    setup_postdata($post);

    ob_start();
    wp_head();
    $head_content = ob_get_clean();

    preg_match_all('/<link[^>]+rel=["\']stylesheet["\'][^>]+>/i', $head_content, $link_matches);
    preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $head_content, $style_matches);

    $css_files = [];
    $all_font_families = [];

    // Process external CSS files
    foreach ($link_matches[0] as $link_tag) {
        preg_match('/href=["\']([^"\']+)["\']/i', $link_tag, $url_match);
        if (!empty($url_match[1])) {
            $css_url = $url_match[1];
            if (strpos($css_url, 'http') === false && strpos($css_url, '://') === false) {
                $css_url = site_url($css_url);
            }

            $css_content = get_external_css_content($css_url);
            $font_families = extract_font_families($css_content);

            $css_files[] = [
                'type' => 'external',
                'url' => $css_url,
                'font_families' => $font_families
            ];

            $all_font_families = array_merge($all_font_families, $font_families);
        }
    }

    // Process inline styles
    foreach ($style_matches[1] as $inline_style) {
        $font_families = extract_font_families($inline_style);
        $css_files[] = [
            'type' => 'inline',
            'css' => $inline_style,
            'font_families' => $font_families
        ];

        $all_font_families = array_merge($all_font_families, $font_families);
    }

    wp_reset_postdata();

    return rest_ensure_response([
        'id' => $post->ID,
        'title' => get_the_title($post),
        'css_files' => $css_files,
        'all_font_families' => array_unique($all_font_families)
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

    register_rest_route('gutenberg/v2', '/page-css-files/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'get_all_css_files',
        'permission_callback' => '__return_true',
    ]);
}

add_action('rest_api_init', 'register_custom_rest_routes');
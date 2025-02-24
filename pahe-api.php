<?php
/**
 * Plugin Name: Gutenberg & CSS REST API
 * Description: Exposes Gutenberg content, page CSS, and enqueued CSS/JS files via REST API, including third-party block styles/scripts and fonts.
 * Version: 1.0
 * Author: Boomdevs
 * Author URI: https://boomdevs.com
 */

 if (!defined('ABSPATH')) {
     exit;
 }
 
 class GutenbergCSSRestAPI {
     
     public function __construct() {
         add_action('after_setup_theme', [$this, 'add_theme_support_features']);
         add_action('rest_api_init', [$this, 'register_custom_rest_routes']);
         add_action('wp_enqueue_scripts', [$this, 'enqueue_block_assets']);
     }
 
     public function add_theme_support_features() {
         add_theme_support('wp-block-styles');
         add_theme_support('responsive-embeds');
         add_theme_support('align-wide');
     }
 
     public function get_gutenberg_content($request) {
         $page_id = $request['id'];
         $post = get_post($page_id);
         if (!$post) {
             return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
         }
 
         setup_postdata($post);
 
         $blocks = parse_blocks($post->post_content);
 
         $rendered_blocks = array_map(function($block) {
             return [
                 'blockName' => $block['blockName'],
                 'attrs' => $block['attrs'],
                 'innerBlocks' => $block['innerBlocks'],
                 'innerHTML' => $block['innerHTML'],
                 'rendered' => render_block($block),
                 'blockCssFile' => isset($block['attrs']['blockID']) 
                     ? home_url('/wp-content/uploads/frontis-blocks/' . $block['attrs']['blockID'] . '.css') 
                     : null, 
             ];
         }, $blocks);
 
         wp_reset_postdata();
 
         return rest_ensure_response([
             'id' => $post->ID,
             'title' => get_the_title($post),
             'content' => $rendered_blocks,
         ]);
     }
 
     public function get_enqueued_assets($request) {
         $page_id = $request['id'];
         $post = get_post($page_id);
         if (!$post) {
             return new WP_Error('no_post', 'Invalid page ID', ['status' => 404]);
         }
 
         global $wp_styles, $wp_scripts;
         $wp_styles = new WP_Styles();
         $wp_scripts = new WP_Scripts();
 
         do_action('enqueue_block_assets');
         do_action('enqueue_block_editor_assets');
 
         setup_postdata($post);
         ob_start();
         wp_head();
         the_content();
         wp_footer();
         $head_foot_content = ob_get_clean();
 
         $enqueued_styles = [];
         foreach ($wp_styles->queue as $handle) {
             if (isset($wp_styles->registered[$handle])) {
                 $style = $wp_styles->registered[$handle];
                 $src = $style->src;
 
                 if (strpos($src, '//') === 0) {
                     $src = 'https:' . $src;
                 } elseif (strpos($src, '/') === 0) {
                     $src = site_url($src);
                 } elseif (strpos($src, 'http') !== 0) {
                     $src = site_url('/' . $src);
                 }
 
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
 
         $enqueued_scripts = [];
         foreach ($wp_scripts->queue as $handle) {
             if (isset($wp_scripts->registered[$handle])) {
                 $script = $wp_scripts->registered[$handle];
                 $src = $script->src;
 
                 if (strpos($src, '//') === 0) {
                     $src = 'https:' . $src;
                 } elseif (strpos($src, '/') === 0) {
                     $src = site_url($src);
                 } elseif (strpos($src, 'http') !== 0) {
                     $src = site_url('/' . $src);
                 }
 
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
 
         preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $head_foot_content, $style_matches);
         $inline_css = implode("\n", array_filter($style_matches[1]));
 
         preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $head_foot_content, $script_matches);
         $inline_js = implode("\n", array_filter($script_matches[1]));
 
         preg_match_all('/<link[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $head_foot_content, $font_matches);
         $fonts = array_filter($font_matches[1], function ($url) {
             return strpos($url, 'fonts.googleapis.com') !== false
                 || strpos($url, 'fonts.gstatic.com') !== false
                 || strpos($url, '/wp-content/themes/') !== false
                 || strpos($url, '/wp-content/plugins/') !== false;
         });
 
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
 
     public function register_custom_rest_routes() {
         register_rest_route('gutenberg/v2', '/page-content/(?P<id>\d+)', [
             'methods' => 'GET',
             'callback' => [$this, 'get_gutenberg_content'],
             'permission_callback' => '__return_true',
         ]);
 
         register_rest_route('gutenberg/v2', '/page-assets/(?P<id>\d+)', [
             'methods' => 'GET',
             'callback' => [$this, 'get_enqueued_assets'],
             'permission_callback' => '__return_true',
         ]);
     }
 
     public function enqueue_block_assets() {
         wp_enqueue_style('wp-block-library');
         wp_enqueue_style('wp-block-library-theme');
     }
 
     public function get_used_block_files($request) {
         $blocks_dir = WP_CONTENT_DIR . '/plugins/frontis-blocks/build/blocks/';
         if (!is_dir($blocks_dir)) {
             return new WP_Error('no_blocks', 'Blocks directory not found', ['status' => 404]);
         }
         
         $blocks = scandir($blocks_dir);
         $block_data = [];
         
         foreach ($blocks as $block) {
             if ($block === '.' || $block === '..') continue;
             
             $block_path = $blocks_dir . $block;
             if (!is_dir($block_path)) continue;
             
             $files = scandir($block_path);
             $js_files = [];
             $css_files = [];
             $php_files = [];
             
             foreach ($files as $file) {
                 if ($file === 'block.json') continue;
                 
                 $file_path = site_url(str_replace(WP_CONTENT_DIR, 'wp-content', $block_path . '/' . $file));
                 
                 if (strpos($file, '.js') !== false) {
                     $js_files[] = $file_path;
                 } elseif (strpos($file, '.css') !== false) {
                     $css_files[] = $file_path;
                 } elseif (strpos($file, '.php') !== false) {
                     $php_files[] = $file_path;
                 }
             }
             
             $block_data[$block] = [
                 'js' => $js_files,
                 'css' => $css_files,
                 'php' => $php_files,
             ];
         }
         
         return rest_ensure_response($block_data);
     }
 
     public function register_block_file_api() {
         register_rest_route('gutenberg/v2', '/blocks-js/', [
             'methods' => 'GET',
             'callback' => [$this, 'get_used_block_files'],
             'permission_callback' => '__return_true',
         ]);
     }
 
     public function get_uploaded_css_files($request) {
         $uploads_dir = WP_CONTENT_DIR . '/uploads/frontis-blocks/';
         if (!is_dir($uploads_dir)) {
             return new WP_Error('no_uploads', 'Uploads directory not found', ['status' => 404]);
         }
         
         $css_files = [];
         $files = scandir($uploads_dir);
         
         foreach ($files as $file) {
             if (strpos($file, '.css') !== false) {
                 $css_files[] = site_url(str_replace(WP_CONTENT_DIR, 'wp-content', $uploads_dir . $file));
             }
         }
         
         return rest_ensure_response(['css_files' => $css_files]);
     }
 
     public function register_uploads_css_api() {
         register_rest_route('gutenberg/v2', '/blocks-css/', [
             'methods' => 'GET',
             'callback' => [$this, 'get_uploaded_css_files'],
             'permission_callback' => '__return_true',
         ]);
     }
 }
 
 new GutenbergCSSRestAPI();
 
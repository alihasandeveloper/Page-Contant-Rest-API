<?php
/*
 * Plugin Name: Block Page Rest API
 * Version: 1.0.0
 * Description: REST API for WordPress pages with Gutenberg blocks and CSS
 * Author: Boomdevs
 * Author URI: https://boomdevs.com
 * Text Domain: block-page-rest-api
 * */

if (!defined('ABSPATH')) {
    exit;
}

class Block_Page_Rest_API {
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_all_rest_routes'));
    }

    public function register_all_rest_routes() {
        // Combined route for page content and CSS
        register_rest_route('page-data/v1', '/page-full/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_page_content_and_css'),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0;
                    },
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
    }

    /**
     * Get both page content and CSS files
     */
    public function get_page_content_and_css($request) {
        $page_id = absint($request['id']);
        $page = get_post($page_id);

        if (empty($page) || $page->post_type !== 'page' || $page->post_status !== 'publish') {
            return new WP_Error('no_page', 'Page not found or not published', array('status' => 404));
        }

        // Setup post data
        setup_postdata($page);

        // Get page content and meta data
        $content_data = $this->prepare_page_data($page);

        // Force load common styles
        wp_enqueue_style('wp-block-library');
        wp_enqueue_style('wp-block-library-theme');

        // Process the content to ensure all block styles are enqueued
        do_blocks($page->post_content);

        // Get theme styles
        if (wp_style_is('wp-block-library-theme', 'registered')) {
            wp_enqueue_style('wp-block-library-theme');
        }

        // Allow theme and plugins to enqueue their styles
        do_action('wp_enqueue_scripts');

        // Process all styles
        wp_styles()->do_items();

        // Get all enqueued styles
        global $wp_styles;
        $styles = [];

        foreach ($wp_styles->queue as $handle) {
            if (isset($wp_styles->registered[$handle])) {
                $style = $wp_styles->registered[$handle];
                $src = $style->src;

                // Convert to absolute URL if needed
                if (strpos($src, '//') === false && strpos($src, 'http') !== 0) {
                    $src = site_url($src);
                }

                // Get inline CSS if any
                $inline_css = '';
                $before_css = $wp_styles->get_data($handle, 'before');
                $after_css = $wp_styles->get_data($handle, 'after');

                if ($before_css) {
                    $inline_css .= implode("\n", $before_css) . "\n";
                }
                if ($after_css) {
                    $inline_css .= implode("\n", $after_css);
                }

                $styles[] = [
                    'handle' => $handle,
                    'src' => $src,
                    'version' => $style->ver,
                    'deps' => $style->deps,
                    'media' => $style->args,
                    'inline_css' => $inline_css
                ];
            }
        }

        // Get global styles
        $global_styles = '';
        if (function_exists('wp_get_global_stylesheet')) {
            $global_styles = wp_get_global_stylesheet();
        }

        // Reset post data
        wp_reset_postdata();

        // Combine all data
        $response = array_merge($content_data, [
            'styles' => [
                'files' => $styles,
                'global_styles' => $global_styles,
                'block_specific_css' => $this->get_block_styles($page_id)
            ]
        ]);

        return rest_ensure_response($response);
    }

    /**
     * Extract block data recursively
     */
    private function extract_block_data($blocks) {
        $result = [];

        foreach ($blocks as $block) {
            $block_data = [
                'blockName' => $block['blockName'],
                'attrs' => $block['attrs'],
                'innerHTML' => $block['innerHTML'],
                'innerContent' => $block['innerContent'],
            ];

            if (!empty($block['innerBlocks'])) {
                $block_data['innerBlocks'] = $this->extract_block_data($block['innerBlocks']);
            }

            $result[] = $block_data;
        }

        return $result;
    }

    /**
     * Get block styles from a post
     */
    private function get_block_styles($post_id) {
        $content = get_post_field('post_content', $post_id);
        if (empty($content)) {
            return '';
        }

        $blocks = parse_blocks($content);
        if (empty($blocks)) {
            return '';
        }

        return $this->process_blocks_for_css($blocks);
    }

    /**
     * Process blocks recursively to extract CSS
     */
    private function process_blocks_for_css($blocks) {
        $css = '';

        foreach ($blocks as $block) {
            // Get CSS from current block
            if (!empty($block['attrs']['style'])) {
                if (is_array($block['attrs']['style'])) {
                    $css .= $this->process_style_object($block['attrs']['style'], $block['blockName']);
                } else if (is_string($block['attrs']['style'])) {
                    $css .= $block['attrs']['style'] . "\n";
                }
            }

            // Check for customCSS attribute
            if (!empty($block['attrs']['customCSS'])) {
                $css .= $block['attrs']['customCSS'] . "\n";
            }

            // Process inner blocks recursively
            if (!empty($block['innerBlocks'])) {
                $css .= $this->process_blocks_for_css($block['innerBlocks']);
            }
        }

        return $css;
    }

    /**
     * Process style object to CSS string
     */
    private function process_style_object($style, $block_name) {
        if (empty($block_name)) {
            return '';
        }

        $css = '';
        $block_id = str_replace('/', '-', substr($block_name, 1));

        if (!empty($style['color'])) {
            if (!empty($style['color']['text'])) {
                $css .= ".block-{$block_id} { color: {$style['color']['text']}; }\n";
            }
            if (!empty($style['color']['background'])) {
                $css .= ".block-{$block_id} { background-color: {$style['color']['background']}; }\n";
            }
            if (!empty($style['color']['gradient'])) {
                $css .= ".block-{$block_id} { background: {$style['color']['gradient']}; }\n";
            }
        }

        if (!empty($style['typography'])) {
            $typography_css = '';
            if (!empty($style['typography']['fontSize'])) {
                $typography_css .= "font-size: {$style['typography']['fontSize']}; ";
            }
            if (!empty($style['typography']['lineHeight'])) {
                $typography_css .= "line-height: {$style['typography']['lineHeight']}; ";
            }
            if (!empty($style['typography']['fontWeight'])) {
                $typography_css .= "font-weight: {$style['typography']['fontWeight']}; ";
            }
            if (!empty($style['typography']['fontStyle'])) {
                $typography_css .= "font-style: {$style['typography']['fontStyle']}; ";
            }
            if (!empty($style['typography']['textTransform'])) {
                $typography_css .= "text-transform: {$style['typography']['textTransform']}; ";
            }

            if (!empty($typography_css)) {
                $css .= ".block-{$block_id} { $typography_css }\n";
            }
        }

        return $css;
    }

    /**
     * Prepare page data for response
     */
    private function prepare_page_data($page) {
        // Get the featured image if exists
        $featured_image = null;
        if (has_post_thumbnail($page->ID)) {
            $featured_image = array(
                'id' => get_post_thumbnail_id($page->ID),
                'url' => get_the_post_thumbnail_url($page->ID, 'full'),
                'alt' => get_post_meta(get_post_thumbnail_id($page->ID), '_wp_attachment_image_alt', true)
            );
        }

        // Get blocks for this page
        $blocks = parse_blocks($page->post_content);
        $parsed_blocks = $this->extract_block_data($blocks);

        // Prepare response
        $response = array(
            'id' => $page->ID,
            'title' => array(
                'rendered' => get_the_title($page),
                'raw' => $page->post_title,
            ),
            'content' => array(
                'rendered' => apply_filters('the_content', $page->post_content),
                'raw' => $page->post_content,
                'blocks' => $parsed_blocks,
            ),
            'excerpt' => array(
                'rendered' => get_the_excerpt($page),
                'raw' => $page->post_excerpt,
            ),
            'slug' => $page->post_name,
            'link' => get_permalink($page->ID),
            'featured_image' => $featured_image,
        );

        // Add ACF fields if ACF plugin is active
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($page->ID);
            if ($acf_fields) {
                $response['acf'] = $acf_fields;
            }
        }

        return $response;
    }
}

// Initialize plugin
new Block_Page_Rest_API();
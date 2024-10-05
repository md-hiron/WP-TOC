<?php
/**
 * Plugin Name: BS24 TOC Generator
 * Description: Generates a table of contents based on <h2> and <h3> headings automatically.
 * Version: 1.0
 * Author: Md Hiron Mia
 * Text Domain: bs24_tos
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('BS24_TOS_DIR', plugin_dir_path(__FILE__));
define('BS24_TOS_URL', plugin_dir_url(__FILE__));

/**
 * Making translateable
 */
add_action( 'plugins_loaded', array( $this, 'bs24_load_textdomain' ) );

function bs24_load_textdomain(){
    load_plugin_textdomain( 'bs24_tos', false, BS24_TOS_URL . 'languages/' );
}

/**
 * Enqueue external script files
 */
add_action( 'wp_enqueue_scripts', 'bs24_registered_scripts' );

function bs24_registered_scripts(){
    wp_enqueue_style( 'bs24_tos_main', BS24_TOS_URL . 'assets/css/main.css', array(), '1.0' );
}

/**
 * Shortcode for the TOS
 */
add_shortcode('toc-generator', 'bs24_toc_shortcode');

function bs24_toc_shortcode( $atts ) {
    // Get the current post's content
    global $post;
    
    // Ensure the global $post object is available
    if (!$post) {
        return '';
    }

    $content = sanitize_post_field('post_content', $post->post_content, $post->ID, 'display');
    // Generate the Table of Contents
    $toc = bs24_create_toc($content);
    
    return $toc;
}

/**
 * Create table of contents from wp content
 * 
 * @param string post content of wp page and post
 * 
 * @return string html data of table of content
 */
function bs24_create_toc( $content ) {
    // Use regex to find all <h2> and <h3> tags
    preg_match_all('/<h2.*?>(.*?)<\/h2>|<h3.*?>(.*?)<\/h3>/', $content, $matches, PREG_SET_ORDER);

    if (empty($matches)) {
        return ''; // No headings found, no TOC needed.
    }

    $toc = '<div class="toc-generator" role="navigation" aria-labelledby="toc-title"><h3>'. __( 'Inhaltsverzeichnis', 'bs24_tos' ) .'</h3>
                <ul>';
    $h2_count = 0;
    $h3_count = 0;
    $current_h2_index = 0;

    // Loop through matches and build the TOC
    foreach ($matches as $match) {
        if (!empty($match[1])) {
            // It's an <h2>
            $h2_count++;
            $h3_count = 0; // Reset h3 count for each new h2
            $current_h2_index = $h2_count;
            $heading_text = strip_tags($match[1]);
            $heading_anchor = preg_replace('/[^a-zA-Z0-9]/', '-', sanitize_title( $heading_text ));

            $toc .= '<li>' . esc_html( $h2_count ) . '. <a href="'. esc_url( '#'. $heading_anchor, ['https'] ) . '" aria-label="Go to section: '. esc_attr( $heading_text ) .'">' . esc_html( $heading_text ) . '</a></li>';
        } elseif (!empty($match[2])) {
            // It's an <h3>
            $h3_count++;
            $heading_text = strip_tags($match[2]);
            $heading_anchor = preg_replace('/[^a-zA-Z0-9]/', '-', sanitize_title( $heading_text ) );
            $toc .= '<li class="toc-sub-item">' . esc_html( $h2_count . '.'.$h3_count ) .' <a href="' . esc_url( '#'. $heading_anchor, ['https'] ) . '" aria-label="Go to section: '. esc_attr( $heading_text ) .'">' . esc_html( $heading_text )  . '</a></li>';
        }
    }

    $toc .= '</ul></div>';

    // Return the generated TOC
    return $toc;
}


/**
 * Add acchor to the wp content
 */
add_filter('the_content', 'bs24_add_anchors');

function bs24_add_anchors( $content ) {
    // Get the current post type
    if (!is_singular(['post', 'page'])) {
        return $content; // If not the specified post types, return the content unmodified
    }

    preg_match_all('/<h2.*?>(.*?)<\/h2>|<h3.*?>(.*?)<\/h3>|<div.*?class="(.*?)gb-headline(.*?)".*?>.*?<h2.*?>(.*?)<\/h2>|<div.*?class="(.*?)gb-headline(.*?)".*?>.*?<h3.*?>(.*?)<\/h3>/', $content, $matches, PREG_SET_ORDER);

    if ( empty( $matches ) ) {
        return $content; // No headings found, no TOC needed.
    }

    $h2_count = 0;
    $h3_count = 0;

    foreach ($matches as $match) {
        if (!empty($match[1]) || !empty($match[5]) ) {
            // It's an <h2>
            $h2_count++;
            $h3_count = 0; // Reset h3 count for each new h2
            $heading_text = strip_tags(!empty($match[1]) ? $match[1] : $match[5]);
            $heading_anchor = preg_replace('/[^a-zA-Z0-9]/', '-', sanitize_title( $heading_text ) );
            $content = preg_replace('/<h2.*?>' . preg_quote($match[1], '/') . '<\/h2>/', '<h2 id="' . esc_attr( $heading_anchor ) . '">' . esc_html( $match[1] ) . '</h2>', $content, 1);
            $content = preg_replace('/<div.*?class="(.*?)gb-headline(.*?)".*?>.*?<h2.*?>' . preg_quote($heading_text, '/') . '<\/h2>/', '<div class="$1gb-headline$2"><h2 id="' . esc_attr($heading_anchor) . '">' . esc_html($heading_text) . '</h2>', $content, 1);
        } elseif ( !empty($match[2]) || !empty( $match[8] ) ) {
            // It's an <h3>
            $h3_count++;
            $heading_text = strip_tags(!empty( $match[2] ) ? $match[2] : $match[8]);
            $heading_anchor = preg_replace('/[^a-zA-Z0-9]/', '-', sanitize_title( $heading_text ) );
            $content = preg_replace('/<h3.*?>' . preg_quote($match[2], '/') . '<\/h3>/', '<h3 id="' . $heading_anchor . '">' . $match[2] . '</h3>', $content, 1);
            $content = preg_replace('/<div.*?class="(.*?)gb-headline(.*?)".*?>.*?<h3.*?>' . preg_quote($heading_text, '/') . '<\/h3>/', '<div class="$1gb-headline$2"><h3 id="' . esc_attr($heading_anchor) . '">' . esc_html($heading_text) . '</h3>', $content, 1);
        }
    }

    return $content;
}



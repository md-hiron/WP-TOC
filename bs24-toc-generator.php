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
add_action( 'plugins_loaded', 'bs24_load_textdomain' );

function bs24_load_textdomain(){
    load_plugin_textdomain( 'bs24_tos', false, BS24_TOS_DIR . 'languages' );
}

/**
 * Enqueue external script files
 */
add_action( 'wp_enqueue_scripts', 'bs24_registered_scripts' );

function bs24_registered_scripts(){
    if ( has_shortcode( get_post()->post_content, 'toc-generator' ) ) {
        wp_enqueue_style( 'bs24_tos_main', BS24_TOS_URL . 'assets/css/main.css', array(), '1.0' );
    }
    
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

    $post_id = $post->ID;
    $post_modified = $post->post_modified;

    // Generate a unique cache key based on post ID and last modified time
    $cache_key = 'bs24_toc_' . $post_id . '_' . md5($post_modified);

    // Try to retrieve the cached TOC from the transient
    $cached_toc = get_transient($cache_key);
    
    if ($cached_toc !== false) {
        // If a cached TOC exists, return it
        return $cached_toc;
    }

    $content = sanitize_post_field('post_content', $post->post_content, $post->ID, 'display');
    // Generate the Table of Contents
    $toc = bs24_create_toc($content);
    
    //if we found toc Item we will store it in transient
    if( !empty( $toc ) ){
        set_transient( $cache_key, $toc, 24 * HOUR_IN_SECONDS );

        return $toc;
    }

    return '';
    
}

/**
 * Create table of contents from wp content
 * 
 * @param string post content of wp page and post
 * 
 * @return string html data of table of content
 */
function bs24_create_toc( $content ) {

    if( empty( $content ) ){
        return '';
    }

    // Create a new DOMDocument
    $dom = new DOMDocument();

    // Suppress errors due to malformed HTML
    libxml_use_internal_errors(true);

    // Load the post content into the DOMDocument
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);

    // Get the body element of the DOM
    $body = $dom->getElementsByTagName('body')->item(0);

    if ( empty( $body ) ) {
        return ''; // Return empty string if there's no body tag (rare, but possible)
    }

    // Initialize counters for headings
    $h2_count = 0;
    $h3_count = 0;

    // Initialize TOC array to store the links
    $toc = [];

    // Traverse all the <h2> and <h3> tags
    foreach ($body->getElementsByTagName('*') as $element) {
        // Check for h2 tags that do not have the "gb-headline" class (for GenerateBlocks headings)
        if ($element->nodeName === 'h2' && (!$element->hasAttribute('class') || strpos($element->getAttribute('class'), 'gb-headline') === false)) {
            $h2_count++;
            $h3_count = 0; // Reset h3 count for each new h2

            $heading_text = trim($element->textContent);
            $heading_anchor = sanitize_title($heading_text);

            // Add the heading to the TOC
            $toc[] = '<li>' . esc_html($h2_count) . '. <a href="#' . esc_attr($heading_anchor) . '" aria-label="Go to section: '. esc_attr( $heading_text ) .'">' . esc_html($heading_text) . '</a></li>';

        } elseif ($element->nodeName === 'h3' && (!$element->hasAttribute('class') || strpos($element->getAttribute('class'), 'gb-headline') === false)) {
            $h3_count++;

            $heading_text = trim($element->textContent);
            $heading_anchor = sanitize_title($heading_text);

            // Add the sub-heading to the TOC
            $toc[] = '<li class="toc-sub-item">' . esc_html($h2_count . '.' . $h3_count) . ' <a href="#' . esc_attr($heading_anchor) . '"  aria-label="Go to section: '. esc_attr( $heading_text ) .'">' . esc_html($heading_text) . '</a></li>';
        }
    }

    // Convert the updated HTML back to string
    $updated_content = $dom->saveHTML($body);

    // Return TOC HTML if headings were found
    if (!empty($toc)) {
        return '<div class="toc-generator" role="navigation" aria-labelledby="toc-title"><h3>'. __( 'Inhaltsverzeichnis', 'bs24_tos' ) .'</h3><ul>' . implode('', $toc) . '</ul></div>';
    }

    return ''; // Return an empty string if no headings were found
}


/**
 * Add acchor to the wp content
 */
add_filter('the_content', 'bs24_add_anchors');

function bs24_add_anchors( $content ) {
    // Create a new DOMDocument
    $dom = new DOMDocument();

    // Suppress errors due to malformed HTML
    libxml_use_internal_errors(true);

    // Load the post content into the DOMDocument
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);

    // Get the body element of the DOM
    $body = $dom->getElementsByTagName('body')->item(0);

    //return the actual content if DOM is empty
    if( empty( $body ) ){
        return $content;
    }

    // Traverse all the <h2> and <h3> tags
    foreach ($body->getElementsByTagName('*') as $element) {
        // Check for h2 or h3 tags and add an ID if it's not a GenerateBlocks heading
        if (($element->nodeName === 'h2' || $element->nodeName === 'h3') &&
            (!$element->hasAttribute('class') || strpos($element->getAttribute('class'), 'gb-headline') === false)) {
            $heading_text = trim($element->textContent);
            $heading_anchor = sanitize_title($heading_text);

            // Add ID attribute to the heading element
            $element->setAttribute('id', $heading_anchor);
        }
    }

    // Convert the updated HTML back to string
    return $dom->saveHTML($body);
    
}

/**
 * Clear transient during save post
 */
add_action('save_post', 'bs24_clear_toc_cache');

function bs24_clear_toc_cache($post_id) {
    $cache_key = 'bs24_toc_' . $post_id . '_' . md5(get_post_field('post_modified', $post_id));
    delete_transient($cache_key);
}



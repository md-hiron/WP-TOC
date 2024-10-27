<?php
/**
 * Plugin Name: BS24 TOC Generator
 * Description: Generates a table of contents based on <h2> and <h3> headings automatically.
 * Version: 1.0
 * Author: Md Hiron Mia
 * Text Domain: bs24_toc
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('BS24_TOC_DIR', plugin_dir_path(__FILE__));
define('BS24_TOC_URL', plugin_dir_url(__FILE__));

/**
 * Making translateable
 */
add_action( 'plugins_loaded', 'bs24_load_textdomain' );

function bs24_load_textdomain(){
    load_plugin_textdomain( 'bs24_toc', false, BS24_TOC_DIR . 'languages' );
}

/**
 * Enqueue external script files
 */
add_action( 'wp_enqueue_scripts', 'bs24_registered_scripts' );

function bs24_registered_scripts(){
    if ( is_singular() ) {
		wp_enqueue_style( 'bs24_toc_main', BS24_TOC_URL . 'assets/css/main.css', array(), '1.3' );
	}
    
}



/**
 * Shortcode for the TOC
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

    // Suppress errors due to malformed HTML
    libxml_use_internal_errors(true);

    // Create a new DOMDocument
    $dom = new DOMDocument();

    try {
        // Attempt to load the content into DOMDocument
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
    } catch (Exception $e) {
        // Log the error message if there's a failure in loading HTML
        error_log('Error loading HTML in bs24_create_toc: ' . $e->getMessage());
        return ''; // Return empty string if there's an issue
    }
    
    // Clear libxml errors after the attempt to load HTML
    libxml_clear_errors();

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
    // Array to keep track of generated anchors
    $anchor_counts = [];

    // Traverse all the <h2> and <h3> tags
    foreach ($body->getElementsByTagName('*') as $element) {
        // Check for h2 tags that do not have the "gb-headline" class (for GenerateBlocks headings)
        if ($element->nodeName === 'h2' && (!$element->hasAttribute('class') || strpos($element->getAttribute('class'), 'gb-headline') === false)) {
            $h2_count++;
            $h3_count = 0; // Reset h3 count for each new h2

            $heading_text = trim($element->textContent);
            $heading_anchor = sanitize_title( replace_umlauts( $heading_text ) );

            // Ensure unique anchors by appending a counter if needed
            if (isset($anchor_counts[$heading_anchor])) {
                $anchor_counts[$heading_anchor]++;
                $heading_anchor .= '-' . $anchor_counts[$heading_anchor];
            } else {
                $anchor_counts[$heading_anchor] = 1;
            }

            // Add the heading to the TOC
            $toc[] = '<li>' . esc_html($h2_count) . '. <a href="#' . esc_attr($heading_anchor) . '" aria-label="Go to section: '. esc_attr( $heading_text ) .'">' . esc_html($heading_text) . '</a></li>';

        } elseif ($element->nodeName === 'h3' && (!$element->hasAttribute('class') || strpos($element->getAttribute('class'), 'gb-headline') === false)) {
            $h3_count++;

            $heading_text = trim($element->textContent);
            $heading_anchor = sanitize_title( replace_umlauts( $heading_text ) );

            // Ensure unique anchors by appending a counter if needed
            if (isset($anchor_counts[$heading_anchor])) {
                $anchor_counts[$heading_anchor]++;
                $heading_anchor .= '-' . $anchor_counts[$heading_anchor];
            } else {
                $anchor_counts[$heading_anchor] = 1;
            }

            // Add the sub-heading to the TOC
            $toc[] = '<li class="toc-sub-item">' . esc_html($h2_count . '.' . $h3_count) . ' <a href="#' . esc_attr($heading_anchor) . '"  aria-label="Go to section: '. esc_attr( $heading_text ) .'">' . esc_html($heading_text) . '</a></li>';
        }
    }

    // Convert the updated HTML back to string
    $updated_content = $dom->saveHTML($body);

    // Return TOC HTML if headings were found
    if (!empty($toc)) {
        return '<div class="toc-generator" role="navigation" aria-labelledby="toc-title"><h3>'. __( 'Inhaltsverzeichnis', 'bs24_toc' ) .'</h3><nav><ol>' . implode('', $toc) . '</ol></nav></div>';
    }

    return ''; // Return an empty string if no headings were found
}


/**
 * Add acchor to the wp content
 */
add_filter('the_content', 'bs24_add_anchors');

function bs24_add_anchors( $content ) {

    // Suppress errors due to malformed HTML
    libxml_use_internal_errors(true);

    // Create a new DOMDocument
    $dom = new DOMDocument();

    try {
        // Attempt to load the content into DOMDocument
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
    } catch (Exception $e) {
        // Log the error message if there's a failure in loading HTML
        error_log('Error loading HTML in bs24_create_toc: ' . $e->getMessage());
        return ''; // Return empty string if there's an issue
    }
    
    // Clear libxml errors after the attempt to load HTML
    libxml_clear_errors();

    // Get the body element of the DOM
    $body = $dom->getElementsByTagName('body')->item(0);

    //return the actual content if DOM is empty
    if( empty( $body ) ){
        return $content;
    }

    // Array to keep track of generated anchors
    $anchor_counts = [];

    // Traverse all the <h2> and <h3> tags
    foreach ($body->getElementsByTagName('*') as $element) {
        // Check for h2 or h3 tags and add an ID if it's not a GenerateBlocks heading
        if (($element->nodeName === 'h2' || $element->nodeName === 'h3') &&
            (!$element->hasAttribute('class') || strpos($element->getAttribute('class'), 'gb-headline') === false)) {
            $heading_text = trim($element->textContent);
            $heading_anchor = sanitize_title( replace_umlauts( $heading_text ) );

            // Ensure unique anchors by appending a counter if needed
            if (isset($anchor_counts[$heading_anchor])) {
                $anchor_counts[$heading_anchor]++;
                $heading_anchor .= '-' . $anchor_counts[$heading_anchor];
            } else {
                $anchor_counts[$heading_anchor] = 1;
            }

            // Add ID attribute to the heading element
            $element->setAttribute('id', esc_attr( $heading_anchor ));
        }
    }

    // Convert the updated HTML back to string
    return $dom->saveHTML($body);
    
}

/**
 * Clear transient during save post
 */
add_action('save_post', 'bs24_clear_single_toc_cache');

function bs24_clear_single_toc_cache($post_id) {
    $cache_key = 'bs24_toc_' . $post_id . '_' . md5(get_post_field('post_modified', $post_id));
    delete_transient($cache_key);
}


// Hook to run on plugin activation
register_activation_hook(__FILE__, 'bs24_clear_toc_cache_on_activation');
function bs24_clear_toc_cache_on_activation() {
    bs24_clear_toc_cache();  // Call the cache clearing function
}

// Hook to run on plugin deactivation
register_deactivation_hook(__FILE__, 'bs24_clear_toc_cache_on_deactivation');
function bs24_clear_toc_cache_on_deactivation() {
    bs24_clear_toc_cache();  // Call the cache clearing function
}

// Function to clear all cached TOCs securely
function bs24_clear_toc_cache() {
    global $wpdb;

    // Check if the current user has the capability to manage options (admin-level permission)
    if (!current_user_can('manage_options')) {
        return;
    }

    // Prepare and sanitize the SQL query to delete transient options for TOC cache
    $query_transients = $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('_transient_bs24_toc_') . '%'
    );

    $transients = $wpdb->get_results(
        $wpdb->prepare( "SELECT option_name from {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('_transient_bs24_toc_') . '%' )
    );

    if( !empty( $transients ) ){
        foreach( $transients as $transient ){
            $transient_name = str_replace('_transient_', '', $transient->option_name);

            //delete transient
            delete_option( '_transient_'. $transient_name );

            //delete transient timeout data
            delete_option( '_transient_timeout_'. $transient_name );
        }
    }
}


//function to replace German umlauts consistently
function replace_umlauts($text) {
    $umlaut_map = [
        'ä' => 'ae',
        'ö' => 'oe',
        'ü' => 'ue',
        'ß' => 'ss',
        'Ä' => 'Ae',
        'Ö' => 'Oe',
        'Ü' => 'Ue'
    ];
    return strtr($text, $umlaut_map);
}

<?php
/**
 * Plugin Name: Dual-Native API
 * Description: The agentic data layer for WordPress. Provides Machine Representation (MR), Safe Write API (Atomic Mutations), and a built-in Model Context Protocol (MCP) server.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Antun Jurkovikj
 * License: GPL v2 or later
 * Text Domain: wp-dual-native
 */

if (!defined('ABSPATH')) { exit; }

define('DNI_VERSION', '0.1.0');
define('DNI_DIR', plugin_dir_path(__FILE__));

require_once DNI_DIR . 'includes/class-dni-mr.php';
require_once DNI_DIR . 'includes/class-dni-cid.php';
require_once DNI_DIR . 'includes/class-dni-rest.php';
require_once DNI_DIR . 'includes/class-dni-admin.php';

// Invalidate any cached MR/CID on content changes
add_action('save_post', function($post_id, $post, $update){
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    delete_post_meta($post_id, '_dni_cid');
    // Optional: delete_transient("dni_mr_$post_id");
}, 10, 3);

// Gutenberg editor panel with example AI action (stubbed block insert)
add_action('enqueue_block_editor_assets', function(){
    wp_enqueue_script(
        'dni-editor',
        plugins_url('assets/dni-editor.js', __FILE__),
        array('wp-plugins','wp-edit-post','wp-components','wp-element','wp-data','wp-api-fetch'),
        DNI_VERSION,
        true
    );
    wp_localize_script('dni-editor','DNI', array(
        'restBase' => esc_url_raw( rest_url('dual-native/v1/') ),
        'nonce'    => wp_create_nonce('wp_rest'),
    ));
});

// Content-Digest (RFC 9530) computed over final bytes at serve time for Dual-Native routes
add_filter('rest_pre_serve_request', function($served, $result, $request, $server){
    try {
        if (!($request instanceof WP_REST_Request)) return $served;
        $route = (string) $request->get_route();
        if (strpos($route, '/dual-native/v1/') !== 0) return $served;
        $status = (int) $result->get_status();
        if ($status < 200 || $status >= 300 || $status === 204) return $served;
        // Compute the exact bytes the server will send
        $data = $server->response_to_data($result, false);
        $headers = $result->get_headers();
        $ctype = '';
        if (is_array($headers) && isset($headers['Content-Type'])) { $ctype = (string)$headers['Content-Type']; }
        $is_json = (stripos($ctype, 'application/json') !== false) || is_array($data) || is_object($data);
        if ($is_json){
            $json_options = apply_filters('rest_json_encode_options', 0, $request);
            $body = wp_json_encode($data, $json_options);
            if ($body !== false && $body !== null){
                header('Content-Digest: sha-256=:' . base64_encode(hash('sha256', (string)$body, true)) . ':', true);
            }
            // Let core handle output for JSON
            return $served;
        } else {
            // Treat as raw bytes (e.g., Markdown)
            $body = is_string($data) ? $data : (is_scalar($data) ? (string)$data : wp_json_encode($data));
            if ($body !== false && $body !== null){
                header('Content-Digest: sha-256=:' . base64_encode(hash('sha256', (string)$body, true)) . ':', true);
                echo (string)$body;
                return true; // we served it
            }
        }
    } catch (Throwable $e) {
        // fail-closed for digest; do not disrupt response
    }
    return $served;
}, 11, 4);

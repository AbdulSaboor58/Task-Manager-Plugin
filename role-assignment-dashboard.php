<?php
/**
 * Plugin Name: Role Assignment Dashboard
 * Description: Frontend dashboards for User, Teacher & Student with assignments, chat & notifications.
 * Version: 1.0.0
 * Author: Abdul Saboor
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * ============================================================
 * LOAD PLUGIN MODULES
 * ============================================================
 */

// ✅ Snippet 3 — Assignments CPT + Meta + Status + Notifications Engine
require_once plugin_dir_path(__FILE__) . 'includes/assignment-post-types.php';

// ✅ Snippet 2 — Teacher / Student Dashboard + Chat + Notifications
require_once plugin_dir_path(__FILE__) . 'includes/teacher-student-dashboard.php';

// ✅ Snippet 1 — User / Subscriber Dashboard + All Assignments + Chat
require_once plugin_dir_path(__FILE__) . 'includes/user-dashboard.php';
// Add Assignment tab separated file
require_once plugin_dir_path(__FILE__) . 'includes/tabs/add-assignment.php';

add_filter('login_redirect', function($redirect_to, $requested_redirect_to, $user){
    if ( is_wp_error($user) || ! $user ) return $redirect_to;

    // ✅ Admin -> wp-admin
    if ( user_can($user, 'manage_options') ) {
        return admin_url();
    }

    return $redirect_to;
}, 10, 3);


/**
 * ============================================================
 * ACTIVATION / DEACTIVATION
 * ============================================================
 */
register_activation_hook(__FILE__, function () {
	flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
	flush_rewrite_rules();
});


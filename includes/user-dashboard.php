<?php
/**
 * Front-end Dashboard (Posts + Users + Assignments) via Shortcodes (SAFE)
 * ✅ FIX: My Dashboard tab => summary only
 * ✅ FIX: All Posts tab => posts table only
 * ✅ CSS theme same + added summary cards + status badges styles
 */

if ( ! defined('ABSPATH') ) exit;

/** Roles */
if ( ! function_exists('sd_register_custom_roles') ) {
	function sd_register_custom_roles() {
		if ( ! get_role('teacher') ) add_role('teacher', 'Teacher', array('read' => true));
		if ( ! get_role('student') ) add_role('student', 'Student', array('read' => true));
	}
	add_action('init', 'sd_register_custom_roles', 5);
}

/** Access */
if ( ! function_exists('sd_current_user_can_access_dashboard') ) {
	function sd_current_user_can_access_dashboard() {
		if ( ! is_user_logged_in() ) return false;
		$user = wp_get_current_user();
		if ( ! $user ) return false;
		$roles = (array) $user->roles;
		return in_array('administrator', $roles, true) || in_array('subscriber', $roles, true);
	}
}

if ( ! function_exists('sd_require_login_and_subscriber') ) {
	function sd_require_login_and_subscriber() {
		if ( ! is_user_logged_in() ) {
			$login_url = wp_login_url( esc_url( $_SERVER['REQUEST_URI'] ?? home_url('/') ) );
			return '<div class="sd-notice sd-notice-warn">Login required. <a href="' . esc_url( $login_url ) . '">Click here to login</a>.</div>';
		}
		if ( ! sd_current_user_can_access_dashboard() ) {
			return '<div class="sd-notice sd-notice-error">Access denied.</div>';
		}
		return '';
	}
}

if ( ! function_exists('sd_get_message_html') ) {
	function sd_get_message_html() {
		if ( empty($_GET['sd_msg']) ) return '';
		$msg_key = sanitize_text_field( wp_unslash($_GET['sd_msg']) );

		$map = array(
			'deleted'      => 'Post deleted successfully.',
			'created'      => 'Post created successfully.',
			'updated'      => 'Post updated successfully.',
			'user_created' => 'User created successfully.',
			'user_deleted' => 'User deleted successfully.',
			'user_error'   => 'User could not be created. Please check fields.',
			'delete_user_error' => 'User could not be deleted.',
			'error'        => 'Something went wrong. Please try again.',

			// ✅ Assignments messages
			'assignment_created' => 'Assignment created successfully.',
			'assignment_updated' => 'Assignment updated successfully.',
			'assignment_deleted' => 'Assignment deleted successfully.',
			'assignment_error'   => 'Assignment could not be saved. Please check fields.',
		);

		if ( ! isset($map[$msg_key]) ) return '';

		$cls = 'sd-notice-success';
		if ( in_array($msg_key, array('user_error','delete_user_error','error','assignment_error'), true) ) $cls = 'sd-notice-error';

		return '<div class="sd-notice ' . esc_attr($cls) . '">' . esc_html($map[$msg_key]) . '</div>';
	}
}

/** Delete Post */
if ( ! function_exists('sd_maybe_handle_delete') ) {
	function sd_maybe_handle_delete() {
		if ( ! is_user_logged_in() ) return;
		if ( empty($_GET['sd_action']) || $_GET['sd_action'] !== 'delete' ) return;
		if ( empty($_GET['post_id']) ) return;
		if ( ! sd_current_user_can_access_dashboard() ) wp_die('Access denied.');

		$post_id = absint($_GET['post_id']);

		$nonce = isset($_GET['_wpnonce']) ? sanitize_text_field( wp_unslash($_GET['_wpnonce']) ) : '';
		if ( ! wp_verify_nonce($nonce, 'sd_delete_post_' . $post_id) ) wp_die('Security check failed.');

		$post = get_post($post_id);
		if ( ! $post ) {
			wp_safe_redirect( add_query_arg('sd_msg','error', remove_query_arg(array('sd_action','post_id','_wpnonce'))) );
			exit;
		}

		if ( (int) $post->post_author !== (int) get_current_user_id() ) wp_die('You can only delete your own posts.');

		$deleted  = wp_delete_post($post_id, true);
		$redirect = remove_query_arg(array('sd_action','post_id','_wpnonce'));
		$redirect = add_query_arg('sd_msg', $deleted ? 'deleted' : 'error', $redirect);

		wp_safe_redirect($redirect);
		exit;
	}
	add_action('template_redirect', 'sd_maybe_handle_delete');
}

/** Create/Update Post */
if ( ! function_exists('sd_maybe_handle_form_submit') ) {
	function sd_maybe_handle_form_submit() {
		if ( ! is_user_logged_in() ) return;
		if ( empty($_POST['sd_action']) ) return;
		if ( ! sd_current_user_can_access_dashboard() ) wp_die('Access denied.');

		$action = sanitize_text_field( wp_unslash($_POST['sd_action']) );
		if ( ! in_array($action, array('create_post','update_post'), true) ) return;

		$nonce = isset($_POST['sd_nonce']) ? sanitize_text_field( wp_unslash($_POST['sd_nonce']) ) : '';
		if ( ! wp_verify_nonce($nonce, 'sd_post_form') ) wp_die('Security check failed.');

		$title   = isset($_POST['sd_title']) ? sanitize_text_field( wp_unslash($_POST['sd_title']) ) : '';
		$content = isset($_POST['sd_content']) ? wp_kses_post( wp_unslash($_POST['sd_content']) ) : '';
		$cat_id  = isset($_POST['sd_category']) ? absint($_POST['sd_category']) : 0;

		if ( $title === '' || $content === '' ) {
			wp_safe_redirect( add_query_arg('sd_msg','error', wp_get_referer() ? wp_get_referer() : home_url('/')) );
			exit;
		}

		$user_id = get_current_user_id();

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'post',
			'post_author'  => $user_id,
		);

		if ( $cat_id ) $postarr['post_category'] = array($cat_id);

		if ( $action === 'create_post' ) {
			$post_id = wp_insert_post($postarr, true);
		} else {
			$edit_id = isset($_POST['sd_post_id']) ? absint($_POST['sd_post_id']) : 0;
			$post    = $edit_id ? get_post($edit_id) : null;
			if ( ! $post || (int) $post->post_author !== (int) $user_id ) wp_die('You can only edit your own posts.');
			$postarr['ID'] = $edit_id;
			$post_id = wp_update_post($postarr, true);
		}

		if ( ! is_wp_error($post_id) && ! empty($_FILES['sd_featured_image']['name']) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_id = media_handle_upload('sd_featured_image', $post_id);
			if ( ! is_wp_error($attachment_id) ) set_post_thumbnail($post_id, $attachment_id);
		}

		$redirect = wp_get_referer() ? wp_get_referer() : home_url('/');
		$redirect = add_query_arg('sd_msg', is_wp_error($post_id) ? 'error' : ($action==='create_post' ? 'created' : 'updated'), $redirect);

		wp_safe_redirect($redirect);
		exit;
	}
	add_action('template_redirect', 'sd_maybe_handle_form_submit');
}

/** Create User */
/**
 * ✅ FINAL: CREATE USER (admin-post.php) — Reliable
 * - Saves in wp_users + wp_usermeta
 * - Validations (email/username exists)
 * - Only teacher/student role allowed (as per your requirement)
 */
if ( ! function_exists('sd_handle_create_user_admin_post') ) {

	function sd_handle_create_user_admin_post() {

		if ( ! is_user_logged_in() ) wp_die('Login required.');
		if ( ! sd_current_user_can_access_dashboard() ) wp_die('Access denied.');

		// Nonce
		$nonce = isset($_POST['sd_user_nonce']) ? sanitize_text_field( wp_unslash($_POST['sd_user_nonce']) ) : '';
		if ( ! wp_verify_nonce($nonce, 'sd_user_form') ) wp_die('Security check failed.');

		// Redirect back
		$redirect = ! empty($_POST['sd_redirect']) ? esc_url_raw( wp_unslash($_POST['sd_redirect']) ) : ( wp_get_referer() ? wp_get_referer() : home_url('/') );

		$username    = isset($_POST['sd_username']) ? sanitize_user( wp_unslash($_POST['sd_username']), true ) : '';
		$email       = isset($_POST['sd_email']) ? sanitize_email( wp_unslash($_POST['sd_email']) ) : '';
		$first_name  = isset($_POST['sd_first_name']) ? sanitize_text_field( wp_unslash($_POST['sd_first_name']) ) : '';
		$last_name   = isset($_POST['sd_last_name']) ? sanitize_text_field( wp_unslash($_POST['sd_last_name']) ) : '';
		$website     = isset($_POST['sd_website']) ? esc_url_raw( wp_unslash($_POST['sd_website']) ) : '';
		$password    = isset($_POST['sd_password']) ? (string) wp_unslash($_POST['sd_password']) : '';
		$role        = isset($_POST['sd_role']) ? sanitize_key( wp_unslash($_POST['sd_role']) ) : 'student';
		$send_notify = ! empty($_POST['sd_send_notify']);

		// Basic validation
		if ( $username === '' || $email === '' ) {
			wp_safe_redirect( add_query_arg('sd_msg','user_error', $redirect) );
			exit;
		}

		if ( ! is_email($email) ) {
			wp_safe_redirect( add_query_arg('sd_msg','user_error', $redirect) );
			exit;
		}

		// Duplicate checks (THIS is the big missing thing)
		if ( username_exists($username) || email_exists($email) ) {
			wp_safe_redirect( add_query_arg('sd_msg','user_error', $redirect) );
			exit;
		}

		// ✅ Allow only teacher/student (your requirement)
		$allow_roles = array('teacher','student');
		if ( ! in_array($role, $allow_roles, true) ) {
			$role = 'student';
		}

		if ( $password === '' ) {
			$password = wp_generate_password(12, true);
		}

		$user_id = wp_insert_user(array(
			'user_login' => $username,
			'user_email' => $email,
			'user_pass'  => $password,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'user_url'   => $website,
			'role'       => $role,
		));

		if ( is_wp_error($user_id) || ! $user_id ) {
			wp_safe_redirect( add_query_arg('sd_msg','user_error', $redirect) );
			exit;
		}

		// Track who created
		update_user_meta($user_id, 'sd_created_by', get_current_user_id());

		// Optional email notification
		if ( $send_notify ) {
			wp_new_user_notification($user_id, null, 'both');
		}

		// ✅ Go to All Users tab after creating (better UX)
		$redirect = add_query_arg('sd_tab','users', remove_query_arg('sd_tab', $redirect));
		$redirect = add_query_arg('sd_msg','user_created', $redirect);

		wp_safe_redirect($redirect);
		exit;
	}

	add_action('admin_post_sd_create_user', 'sd_handle_create_user_admin_post');
}
/** ✅ Delete User (FIXED) */
if ( ! function_exists('sd_maybe_handle_user_delete') ) {
	function sd_maybe_handle_user_delete() {

		if ( ! is_user_logged_in() ) return;
		if ( empty($_GET['sd_action']) || $_GET['sd_action'] !== 'delete_user' ) return;
		if ( empty($_GET['user_id']) ) return;

		if ( ! sd_current_user_can_access_dashboard() ) wp_die('Access denied.');

		$user_id = absint($_GET['user_id']);
		if ( ! $user_id ) return;

		$nonce = isset($_GET['_wpnonce']) ? sanitize_text_field( wp_unslash($_GET['_wpnonce']) ) : '';
		if ( ! wp_verify_nonce($nonce, 'sd_delete_user_' . $user_id) ) wp_die('Security check failed.');

		$current_user_id = (int) get_current_user_id();

		// ✅ Only delete users created by current subscriber
		$created_by = (int) get_user_meta($user_id, 'sd_created_by', true);
		if ( $created_by !== $current_user_id ) {
			wp_safe_redirect( add_query_arg('sd_msg','delete_user_error', wp_get_referer() ? wp_get_referer() : home_url('/')) );
			exit;
		}

		$target = get_user_by('id', $user_id);
		if ( ! $target ) {
			wp_safe_redirect( add_query_arg('sd_msg','delete_user_error', wp_get_referer() ? wp_get_referer() : home_url('/')) );
			exit;
		}

		// ✅ Never allow deleting admins from frontend
		if ( in_array('administrator', (array) $target->roles, true) ) {
			wp_safe_redirect( add_query_arg('sd_msg','delete_user_error', wp_get_referer() ? wp_get_referer() : home_url('/')) );
			exit;
		}

		// ✅ IMPORTANT: load required file for wp_delete_user()
		if ( ! function_exists('wp_delete_user') ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$deleted = wp_delete_user( $user_id );

		$redirect = wp_get_referer() ? wp_get_referer() : home_url('/');
		$redirect = add_query_arg(array(
			'sd_tab' => 'users',
			'sd_msg' => ( $deleted ? 'user_deleted' : 'delete_user_error' ),
		), $redirect);

		wp_safe_redirect($redirect);
		exit;
	}
	add_action('template_redirect', 'sd_maybe_handle_user_delete');
}


/** CSS */
if ( ! function_exists('sd_css_block') ) {
	function sd_css_block() {
		return '
		<style>
		:root{
		  --sd-bg:#07070b;
		  --sd-panel:#0d0f17;
		  --sd-panel2:#111428;
		  --sd-line:rgba(255,255,255,.10);
		  --sd-line2:rgba(255,255,255,.14);
		  --sd-text:#f5f7ff;
		  --sd-muted:rgba(245,247,255,.65);
		  --sd-ac1:#3b82f6;
		  --sd-ac2:#7c3aed;
		  --sd-danger:#ef4444;
		  --sd-ok:#22c55e;
		  --sd-shadow:0 28px 80px rgba(0,0,0,.70);
		  --sd-shadow2:0 14px 40px rgba(0,0,0,.55);
		}

		.sd-wrap{
		  max-width:1250px;
		  margin:22px auto;
		  padding:0 14px;
		  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
		  color:var(--sd-text);
		}

		.sd-grid{
		  display:grid;
		  grid-template-columns:320px 1fr;
		  gap:18px;
		}

		.sd-sidebar{
		  position:sticky;
		  top:18px;
		  height:fit-content;
		  border-radius:22px;
		  padding:18px;
		  background:
			radial-gradient(900px 500px at 10% 0%, rgba(59,130,246,.10), transparent 60%),
			radial-gradient(900px 500px at 95% 10%, rgba(124,58,237,.10), transparent 60%),
			linear-gradient(180deg, var(--sd-panel2), var(--sd-panel));
		  border:1px solid var(--sd-line2);
		  box-shadow:var(--sd-shadow);
		}

		.sd-sidebar a{
		  display:block;
		  padding:12px 12px;
		  margin-bottom:10px;
		  border-radius:16px;
		  text-decoration:none;
		  color:var(--sd-text);
		  font-weight:900;
		  font-size:13px;
		  background:rgba(0,0,0,.28);
		  border:1px solid rgba(255,255,255,.08);
		  transition:.18s ease;
		}
		.sd-sidebar a:hover{
		  transform:translateX(5px);
		  border-color:rgba(59,130,246,.35);
		  background:rgba(59,130,246,.08);
		}

		.sd-content{
		  border-radius:22px;
		  padding:20px;
		  background:
			radial-gradient(900px 500px at 85% 0%, rgba(59,130,246,.08), transparent 60%),
			radial-gradient(900px 500px at 20% 20%, rgba(124,58,237,.08), transparent 60%),
			linear-gradient(180deg, var(--sd-panel2), var(--sd-panel));
		  border:1px solid var(--sd-line2);
		  box-shadow:var(--sd-shadow);
		}

		.sd-notice{
		  padding:12px 14px;
		  border-radius:16px;
		  margin:14px 0;
		  font-weight:900;
		  font-size:13px;
		  background:rgba(0,0,0,.30);
		  border:1px solid rgba(255,255,255,.10);
		  box-shadow:var(--sd-shadow2);
		  color:var(--sd-text);
		}
		.sd-notice-warn{
		  background:rgba(245,158,11,.14);
		  border-color:rgba(245,158,11,.24);
		  color:#ffedd5;
		}
		.sd-notice-error{
		  background:rgba(239,68,68,.12);
		  border-color:rgba(239,68,68,.28);
		  color:#ffe4e6;
		}
		.sd-notice-success{
		  background:rgba(34,197,94,.10);
		  border-color:rgba(34,197,94,.22);
		  color:#dcfce7;
		}

		/* auto hide message */
		.sd-notice{
		  animation:sdFadeOut .6s ease forwards;
		  animation-delay:4.5s
		}
		@keyframes sdFadeOut{to{opacity:0;transform:translateY(-6px)}}

		.sd-table{
		  width:100%;
		  border-collapse:separate;
		  border-spacing:0;
		  border-radius:18px;
		  overflow:hidden;
		  background:rgba(0,0,0,.25);
		  border:1px solid rgba(255,255,255,.10);
		  box-shadow:var(--sd-shadow2);
		  margin-top:12px;
		}
		.sd-table thead{
		  background:rgba(0,0,0,.35);
		}
		.sd-table th{
		  text-align:left;
		  padding:12px;
		  font-size:12px;
		  letter-spacing:.35px;
		  color:rgba(245,247,255,.72);
		  border-bottom:1px solid rgba(255,255,255,.08);
		}
		.sd-table td{
		  padding:12px;
		  border-bottom:1px solid rgba(255,255,255,.07);
		  font-size:13px;
		  color:var(--sd-text);
		}
		.sd-table tr:hover td{
		  background:rgba(59,130,246,.06);
		}

		.sd-actions a{margin-right:8px}

		.sd-btn{
		  display:inline-block;
		  padding:8px 14px;
		  border-radius:999px;
		  text-decoration:none;
		  font-size:13px;
		  font-weight:1000;
		  border:0;
		  background:linear-gradient(90deg, rgba(59,130,246,1), rgba(124,58,237,1));
		  color:#fff;
		  box-shadow:0 12px 28px rgba(59,130,246,.18);
		  transition:.18s ease;
		}
		.sd-btn:hover{transform:translateY(-1px);filter:brightness(1.06)}
		.sd-btn-danger{
		  background:rgba(239,68,68,.92);
		  box-shadow:0 12px 28px rgba(239,68,68,.16);
		}

		.sd-form label{
		  display:block;
		  margin:14px 0 6px;
		  font-size:12px;
		  font-weight:900;
		  color:rgba(245,247,255,.72);
		}
		.sd-form input[type="text"], .sd-form textarea, .sd-form select{
		  width:100%;
		  padding:12px 12px;
		  border-radius:14px;
		  border:1px solid rgba(255,255,255,.10);
		  background:rgba(0,0,0,.45);
		  color:var(--sd-text);
		  outline:none;
		  transition:.18s ease;
		}
		.sd-form input[type="text"]::placeholder, .sd-form textarea::placeholder{color:rgba(245,247,255,.45)}
		.sd-form input[type="text"]:focus, .sd-form textarea:focus, .sd-form select:focus{
		  border-color:rgba(59,130,246,.55);
		  box-shadow:0 0 0 5px rgba(59,130,246,.16);
		}
		.sd-form textarea{min-height:170px}

		/* ✅ status badges (same dark style) */
		.sd-badge{
		  display:inline-block;
		  padding:7px 10px;
		  border-radius:999px;
		  font-size:12px;
		  font-weight:1000;
		  line-height:1;
		  border:1px solid rgba(255,255,255,.12)
		}
		.sd-b-start_working{background:rgba(59,130,246,.16);color:#dbeafe;border-color:rgba(59,130,246,.28)}
		.sd-b-need_more_instructions{background:rgba(245,158,11,.14);color:#ffedd5;border-color:rgba(245,158,11,.24)}
		.sd-b-submitted_to_teacher{background:rgba(124,58,237,.16);color:#ede9fe;border-color:rgba(124,58,237,.28)}
		.sd-b-approved{background:rgba(34,197,94,.14);color:#dcfce7;border-color:rgba(34,197,94,.24)}
		.sd-b-rejected{background:rgba(239,68,68,.14);color:#fee2e2;border-color:rgba(239,68,68,.24)}
		.sd-b-assign_to_student{background:rgba(148,163,184,.12);color:#e5e7eb;border-color:rgba(148,163,184,.22)}

		/* ✅ dashboard summary cards */
		.sd-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:14px 0 6px}
		.sd-card{padding:14px;border-radius:18px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.10);box-shadow:var(--sd-shadow2);display:flex;align-items:center;justify-content:space-between;gap:12px}
		.sd-card strong{font-size:12px;color:rgba(245,247,255,.72);display:block;margin-bottom:6px}
		.sd-num{font-size:26px;font-weight:1000;line-height:1}
		.sd-chip{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:1000;border:1px solid rgba(255,255,255,.12);white-space:nowrap}

		@media (max-width:900px){
		  .sd-grid{grid-template-columns:1fr}
		  .sd-sidebar{position:relative;top:auto}
		  .sd-cards{grid-template-columns:1fr}
		}
		</style>';
	}
}

/** DB-level strict posts */
if ( ! function_exists('sd_get_current_user_post_ids') ) {
	function sd_get_current_user_post_ids( $limit = 50 ) {
		global $wpdb;
		$user_id = get_current_user_id();
		$limit = max(1, min(200, (int)$limit));

		$allowed_status = array('publish','draft','pending','private');
		$placeholders = implode(',', array_fill(0, count($allowed_status), '%s'));

		$sql = "
			SELECT ID FROM {$wpdb->posts}
			WHERE post_type = %s
			  AND post_author = %d
			  AND post_status IN ($placeholders)
			ORDER BY post_date DESC
			LIMIT %d
		";

		$params = array_merge( array('post', $user_id), $allowed_status, array($limit) );
		$query  = $wpdb->prepare($sql, $params);

		$ids = $wpdb->get_col($query);
		return array_map('intval', (array)$ids);
	}
}

/* ============================================================
 * ✅ ASSIGNMENTS: status options (same)
 * ============================================================ */
if ( ! function_exists('sd_assignment_status_options') ) {
	function sd_assignment_status_options() {
		return array(
			'start_working'          => 'Start Working',
			'need_more_instructions' => 'Need More Instructions',
			'submitted_to_teacher'   => 'Submitted To Teacher',
			'approved'               => 'Approved',
			'rejected'               => 'Rejected',
			'assign_to_student'      => 'Assign To Student',
		);
	}
}

if ( ! function_exists('sd_status_badge_html') ) {
	function sd_status_badge_html( $key ) {
		$map = sd_assignment_status_options();
		if ( empty($key) || ! isset($map[$key]) ) return '—';
		return '<span class="sd-badge sd-b-' . esc_attr($key) . '">' . esc_html($map[$key]) . '</span>';
	}
}

/* ============================================================
 * ✅ DASHBOARD SUMMARY (status wise)
 * - for Subscriber's created assignments (author)
 * ============================================================ */
if ( ! function_exists('sd_get_assignment_status_counts_for_author') ) {
	function sd_get_assignment_status_counts_for_author( $author_id ) {
		$author_id = (int) $author_id;
		$map = sd_assignment_status_options();

		$counts = array();
		foreach ( $map as $k => $lbl ) $counts[$k] = 0;
		$counts['_total'] = 0;

		$q = new WP_Query(array(
			'post_type'      => 'assignment',
			'post_status'    => array('publish','private','draft','pending'),
			'posts_per_page' => 400,
			'fields'         => 'ids',
			'author'         => $author_id,
			'no_found_rows'  => true,
		));

		if ( ! empty($q->posts) ) {
			foreach ( $q->posts as $aid ) {
				$counts['_total']++;
				$st = (string) get_post_meta($aid, 'track_status', true);
				if ( $st && isset($counts[$st]) ) $counts[$st]++;
			}
		}
		return $counts;
	}
}

if ( ! function_exists('sd_assignment_summary_cards_html') ) {
	function sd_assignment_summary_cards_html( $counts ) {
		$map = sd_assignment_status_options();
		$counts = is_array($counts) ? $counts : array();

		$total = isset($counts['_total']) ? (int)$counts['_total'] : 0;

		$html = '<div class="sd-cards">';
		$html .= '<div class="sd-card"><div><strong>Total Assignments</strong><div class="sd-num">'. $total .'</div></div><div class="sd-chip" style="background:rgba(255,255,255,.06);">All</div></div>';

		foreach ( $map as $k => $label ) {
			$n = isset($counts[$k]) ? (int)$counts[$k] : 0;
			$html .= '<div class="sd-card"><div><strong>'. esc_html($label) .'</strong><div class="sd-num">'. $n .'</div></div><div class="sd-chip">'. sd_status_badge_html($k) .'</div></div>';
		}

		$html .= '</div>';
		return $html;
	}
}



if ( ! function_exists('sd_maybe_handle_assignment_delete') ) {
	function sd_maybe_handle_assignment_delete() {
		if ( ! is_user_logged_in() ) return;
		if ( empty($_GET['sd_action']) || $_GET['sd_action'] !== 'delete_assignment' ) return;
		if ( empty($_GET['assignment_id']) ) return;
		if ( ! sd_current_user_can_access_dashboard() ) wp_die('Access denied.');

		$aid = absint($_GET['assignment_id']);

		$nonce = isset($_GET['_wpnonce']) ? sanitize_text_field( wp_unslash($_GET['_wpnonce']) ) : '';
		if ( ! wp_verify_nonce($nonce, 'sd_delete_assignment_' . $aid) ) wp_die('Security check failed.');

		$post = get_post($aid);
		if ( ! $post || $post->post_type !== 'assignment' ) wp_die('Invalid assignment.');
		if ( (int)$post->post_author !== (int)get_current_user_id() ) wp_die('You can only delete your own assignments.');

		$deleted = wp_delete_post($aid, true);

		// ✅ Stay on All Assignments tab after delete
		$redirect = remove_query_arg(array('sd_action','assignment_id','_wpnonce'), wp_get_referer() ? wp_get_referer() : home_url('/'));
		$redirect = add_query_arg(array(
			'sd_tab' => 'assignments',
			'sd_msg' => ($deleted ? 'assignment_deleted' : 'assignment_error'),
		), $redirect);

		wp_safe_redirect($redirect);
		exit;
	}
	add_action('template_redirect', 'sd_maybe_handle_assignment_delete');
}

/* ============================================================
 * Shortcodes (re-register safely)
 * ============================================================ */
add_action('init', function () {
	remove_shortcode('subscriber_dashboard');
	remove_shortcode('subscriber_posts_table');
	remove_shortcode('subscriber_post_form');
	remove_shortcode('subscriber_posts_cards');
	remove_shortcode('sd_user_form');
	remove_shortcode('sd_users_table');
	remove_shortcode('sd_assignment_form');
	remove_shortcode('sd_assignments_table');

	add_shortcode('subscriber_dashboard', function () {
		$guard = sd_require_login_and_subscriber();
		if ( $guard ) return sd_css_block() . '<div class="sd-wrap">' . $guard . '</div>';

		// ✅ Default = dashboard (not posts)
		$tab = isset($_GET['sd_tab']) ? sanitize_text_field(wp_unslash($_GET['sd_tab'])) : 'dashboard';

		// ✅ assignment edit => keep add_assignment tab active
		if ( isset($_GET['sd_action']) && $_GET['sd_action'] === 'edit_assignment' ) {
			$tab = 'add_assignment';
		}

		$base_url = remove_query_arg(array('sd_tab','sd_action','post_id','_wpnonce','user_id','assignment_id','sd_msg'));


		$dashboard_url = add_query_arg('sd_tab','dashboard', $base_url);
		$posts_url     = add_query_arg('sd_tab','posts', $base_url);
		$new_url       = add_query_arg('sd_tab','new', $base_url);
		$add_user_url  = add_query_arg('sd_tab','add_user', $base_url);
		$users_url     = add_query_arg('sd_tab','users', $base_url);
		$add_assignment_url = add_query_arg('sd_tab','add_assignment', $base_url);
		$assignments_url    = add_query_arg('sd_tab','assignments', $base_url);

		$logout_url = wp_logout_url( site_url('/login/') );

		$content = sd_get_message_html();

		// ✅ My Dashboard = summary only
		if ( $tab === 'dashboard' ) {
			$u = wp_get_current_user();
			$content .= '<h2 style="margin:6px 0 8px;font-size:22px;font-weight:1000;">My Dashboard</h2>';
			$content .= '<p style="color:rgba(245,247,255,.65);margin:0 0 12px;">Welcome, <strong>'. esc_html($u->display_name ?: $u->user_login) .'</strong></p>';

			$counts = sd_get_assignment_status_counts_for_author( get_current_user_id() );
			$content .= sd_assignment_summary_cards_html( $counts );
			$content .= '<p style="color:rgba(245,247,255,.55);margin-top:10px;">Tip: Assignments status changes will update these counts automatically.</p>';
		}
		// ✅ All Posts tab = posts table only
		elseif ( $tab === 'posts' ) {
			$content .= do_shortcode('[subscriber_posts_table]');
		}
		elseif ( $tab === 'new' ) {
			$content .= do_shortcode('[subscriber_post_form]');
		}
		elseif ( $tab === 'edit' ) {
			$content .= do_shortcode('[subscriber_post_form mode="edit"]');
		}
		elseif ( $tab === 'add_user' ) {
			$content .= do_shortcode('[sd_user_form]');
		}
		elseif ( $tab === 'users' ) {
			$content .= do_shortcode('[sd_users_table]');
		}
		elseif ( $tab === 'add_assignment' ) {
			if ( isset($_GET['sd_action']) && $_GET['sd_action'] === 'edit_assignment' ) {
				$content .= do_shortcode('[sd_assignment_form mode="edit"]');
			} else {
				$content .= do_shortcode('[sd_assignment_form]');
			}
		}
		elseif ( $tab === 'assignments' ) {
			$content .= do_shortcode('[sd_assignments_table]');
		}
		else {
			$content .= do_shortcode('[subscriber_posts_table]');
		}

		$html  = sd_css_block();
		$html .= '<div class="sd-wrap"><div class="sd-grid">';
		$html .= '<div class="sd-sidebar">';
		$html .= '<a href="' . esc_url($dashboard_url) . '">My Dashboard</a>';
		// $html .= '<a href="' . esc_url($posts_url) . '">All Posts</a>';
		// $html .= '<a href="' . esc_url($new_url) . '">Add New Post</a>';
		$html .= '<a href="' . esc_url($add_user_url) . '">Add User</a>';
		$html .= '<a href="' . esc_url($users_url) . '">All Users</a>';
		$html .= '<a href="' . esc_url($add_assignment_url) . '">Add Assignment</a>';
		$html .= '<a href="' . esc_url($assignments_url) . '">All Assignments</a>';
		$html .= '<a href="' . esc_url($logout_url) . '">Logout</a>';
		$html .= '</div><div class="sd-content">' . $content . '</div></div></div>';

		return $html;
	});

	/* ---------------- POSTS TABLE ---------------- */
	add_shortcode('subscriber_posts_table', function () {
		$guard = sd_require_login_and_subscriber();
		if ( $guard ) return $guard;

		$user_id = get_current_user_id();
		$ids = sd_get_current_user_post_ids(50);
		if ( empty($ids) ) return '<div class="sd-notice sd-notice-warn">No posts found.</div>';

		$base_url = remove_query_arg(array('sd_action','post_id','_wpnonce','user_id','assignment_id'));

		$out  = '<table class="sd-table"><thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $ids as $post_id ) {
			if ( (int) get_post_field('post_author', $post_id) !== (int) $user_id ) continue;

			$view_url = get_permalink($post_id);

			// ✅ open edit form in dashboard tab "edit"
			$edit_url = add_query_arg(array('sd_tab'=>'edit','post_id'=>$post_id), $base_url);

			$delete_url = wp_nonce_url(
				add_query_arg(array('sd_action'=>'delete','post_id'=>$post_id), $base_url),
				'sd_delete_post_' . $post_id
			);

			$out .= '<tr>';
			$out .= '<td>' . esc_html(get_the_title($post_id)) . '</td>';
			$out .= '<td>' . esc_html(get_the_date('', $post_id)) . '</td>';
			$out .= '<td>' . esc_html(get_post_status($post_id)) . '</td>';
			$out .= '<td class="sd-actions">';
			$out .= '<a class="sd-btn" href="' . esc_url($view_url) . '">View</a> ';
			$out .= '<a class="sd-btn" href="' . esc_url($edit_url) . '">Edit</a> ';
			$out .= '<a class="sd-btn sd-btn-danger" href="' . esc_url($delete_url) . '" onclick="return confirm(\'Delete this post?\')">Delete</a>';
			$out .= '</td></tr>';
		}
		$out .= '</tbody></table>';
		return $out;
	});

	/* ---------------- POST FORM ---------------- */
	add_shortcode('subscriber_post_form', function ($atts = array()) {
		$guard = sd_require_login_and_subscriber();
		if ( $guard ) return $guard;

		$atts = shortcode_atts(array('mode'=>'create'), $atts, 'subscriber_post_form');
		$mode = $atts['mode'];

		$user_id = get_current_user_id();
		$edit_post_id = 0;
		$edit_post = null;

		if ( $mode === 'edit' ) {
			$edit_post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
			$edit_post = $edit_post_id ? get_post($edit_post_id) : null;
			if ( ! $edit_post || (int) $edit_post->post_author !== (int) $user_id ) {
				return '<div class="sd-notice sd-notice-error">Invalid post for editing.</div>';
			}
		}

		$title_val   = $edit_post ? $edit_post->post_title : '';
		$content_val = $edit_post ? $edit_post->post_content : '';
		$categories  = get_categories(array('hide_empty'=>false));

		$out  = '<form class="sd-form" method="post" enctype="multipart/form-data">';
		$out .= '<input type="hidden" name="sd_action" value="' . esc_attr($mode==='edit' ? 'update_post' : 'create_post') . '">';
		$out .= '<input type="hidden" name="sd_nonce" value="' . esc_attr(wp_create_nonce('sd_post_form')) . '">';
		if ( $mode === 'edit' ) $out .= '<input type="hidden" name="sd_post_id" value="' . esc_attr($edit_post_id) . '">';

		$out .= '<label>Title</label><input type="text" name="sd_title" value="' . esc_attr($title_val) . '" required>';
		$out .= '<label>Content</label><textarea name="sd_content" required>' . esc_textarea($content_val) . '</textarea>';

		$out .= '<label>Category</label><select name="sd_category"><option value="0">— Select Category —</option>';
		foreach ( $categories as $cat ) $out .= '<option value="' . esc_attr($cat->term_id) . '">' . esc_html($cat->name) . '</option>';
		$out .= '</select>';

		$out .= '<label>Featured Image</label><input type="file" name="sd_featured_image" accept="image/*">';
		$out .= '<p style="margin-top:12px;"><button class="sd-btn" type="submit">' . esc_html($mode==='edit' ? 'Update Post' : 'Publish Post') . '</button></p>';
		$out .= '</form>';

		return $out;
	});

	/* ---------------- USER FORM ---------------- */
	add_shortcode('sd_user_form', function () {
	$guard = sd_require_login_and_subscriber();
	if ( $guard ) return $guard;

	global $wp_roles;
	$roles = $wp_roles ? $wp_roles->roles : array();

	// ✅ only show teacher/student in dropdown
	$allowed = array('teacher','student');

	$current_url = ( is_ssl() ? 'https://' : 'http://' ) . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
	$current_url = esc_url( remove_query_arg(array('sd_msg'), $current_url) );

	$out  = '<div class="sd-form">';
	$out .= sd_get_message_html();

	// ✅ admin-post submit
	$out .= '<form method="post" action="'. esc_url( admin_url('admin-post.php') ) .'">';
	$out .= '<input type="hidden" name="action" value="sd_create_user">';
	$out .= '<input type="hidden" name="sd_redirect" value="'. esc_attr($current_url) .'">';
	$out .= '<input type="hidden" name="sd_user_nonce" value="' . esc_attr(wp_create_nonce('sd_user_form')) . '">';

	$out .= '<label>Username</label><input type="text" name="sd_username" required>';
	$out .= '<label>Email</label><input type="text" name="sd_email" required>';
	$out .= '<label>First Name</label><input type="text" name="sd_first_name">';
	$out .= '<label>Last Name</label><input type="text" name="sd_last_name">';
	$out .= '<label>Website</label><input type="text" name="sd_website" placeholder="https://...">';
	$out .= '<label>Password (leave empty to auto-generate)</label><input type="text" name="sd_password">';

	$out .= '<label>Role</label><select name="sd_role" required>';
	foreach ( $roles as $rk => $rd ) {
		if ( ! in_array($rk, $allowed, true) ) continue;
		$out .= '<option value="' . esc_attr($rk) . '">' . esc_html($rd['name']) . '</option>';
	}
	$out .= '</select>';

	$out .= '<label style="display:flex;gap:10px;align-items:center;margin-top:12px;">';
	$out .= '<input type="checkbox" name="sd_send_notify" value="1"> Send user notification email';
	$out .= '</label>';

	$out .= '<p style="margin-top:12px;"><button class="sd-btn" type="submit">Create User</button></p>';
	$out .= '</form></div>';

	return $out;
});


	add_shortcode('sd_users_table', function () {
		$guard = sd_require_login_and_subscriber();
		if ( $guard ) return $guard;

		$uid = get_current_user_id();
		$q = new WP_User_Query(array(
			'number'     => 200,
			'orderby'    => 'registered',
			'order'      => 'DESC',
			'meta_key'   => 'sd_created_by',
			'meta_value' => $uid,

			// ✅ MUST: only show teacher/student created by you
			'role__in'   => array('teacher','student'),
		));

		$users = $q->get_results();
		if ( empty($users) ) return '<div class="sd-notice sd-notice-warn">No users found (created by you).</div>';

		$base_url = remove_query_arg(array('sd_action','user_id','_wpnonce','post_id','assignment_id'));

		$out  = '<table class="sd-table"><thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Registered</th><th>Action</th></tr></thead><tbody>';
		foreach ( $users as $u ) {
			$roles = ! empty($u->roles) ? implode(', ', $u->roles) : '—';

			$del_url = wp_nonce_url(
				add_query_arg(array('sd_action'=>'delete_user','user_id'=>(int)$u->ID), $base_url),
				'sd_delete_user_' . (int)$u->ID
			);

			$out .= '<tr>';
			$out .= '<td>' . esc_html($u->user_login) . '</td>';
			$out .= '<td>' . esc_html($u->user_email) . '</td>';
			$out .= '<td>' . esc_html($roles) . '</td>';
			$out .= '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($u->user_registered))) . '</td>';
			$out .= '<td><a class="sd-btn sd-btn-danger" href="' . esc_url($del_url) . '" onclick="return confirm(\'Delete this user?\')">Delete</a></td>';
			$out .= '</tr>';
		}
		$out .= '</tbody></table>';
		return $out;
	});



	/* ============================================================
	 * ✅ ASSIGNMENTS TABLE SHORTCODE (Status column + colors)
	 * ============================================================ */
	add_shortcode('sd_assignments_table', function(){
		$guard = sd_require_login_and_subscriber();
		if ( $guard ) return $guard;

		$user_id = get_current_user_id();
		$base_url = remove_query_arg(array('sd_action','assignment_id','_wpnonce','sd_tab'));

		$q = new WP_Query(array(
			'post_type'      => 'assignment',
			'post_status'    => array('publish','private','draft','pending'),
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'author'         => $user_id,
			'no_found_rows'  => true,
		));

		if ( ! $q->have_posts() ) return '<div class="sd-notice sd-notice-warn">No assignments found.</div>';

		$out  = '<table class="sd-table"><thead><tr>';
		$out .= '<th>Title</th><th>Date</th><th>Teacher</th><th>Student</th><th>Status</th><th>Actions</th>';
		$out .= '</tr></thead><tbody>';

		while($q->have_posts()){
			$q->the_post();
			$aid = get_the_ID();

			$teacher_id = (int)get_post_meta($aid,'teacher_user_id',true);
			$student_id = (int)get_post_meta($aid,'student_user_id',true);
			$status_key = (string)get_post_meta($aid,'track_status',true);

			$t = $teacher_id ? get_user_by('id',$teacher_id) : null;
			$s = $student_id ? get_user_by('id',$student_id) : null;

			$t_name = $t ? ($t->display_name ?: $t->user_login) : '—';
			$s_name = $s ? ($s->display_name ?: $s->user_login) : '—';

			$view_url = get_permalink($aid);

			// edit inside dashboard
			$edit_url = add_query_arg(array('sd_tab'=>'add_assignment','sd_action'=>'edit_assignment','assignment_id'=>$aid), $base_url);

			$del_url = wp_nonce_url(
				add_query_arg(array('sd_action'=>'delete_assignment','assignment_id'=>$aid), $base_url),
				'sd_delete_assignment_' . $aid
			);

			$out .= '<tr>';
			$out .= '<td>'.esc_html(get_the_title()).'</td>';
			$out .= '<td>'.esc_html(get_the_date()).'</td>';
			$out .= '<td>'.esc_html($t_name).'</td>';
			$out .= '<td>'.esc_html($s_name).'</td>';
			$out .= '<td>'. sd_status_badge_html($status_key) .'</td>';
			$out .= '<td class="sd-actions">';
			$out .= '<a class="sd-btn" href="'.esc_url($view_url).'">View</a> ';
			$out .= '<a class="sd-btn" href="'.esc_url($edit_url).'">Edit</a> ';
			$out .= '<a class="sd-btn sd-btn-danger" href="'.esc_url($del_url).'" onclick="return confirm(\'Delete this assignment?\')">Delete</a>';
			$out .= '</td>';
			$out .= '</tr>';
		}

		wp_reset_postdata();

		$out .= '</tbody></table>';
		return $out;
	});

}, 99);


/**
 * Redirect Subscriber to the front-end dashboard after login
 */
if ( ! function_exists('sd_get_dashboard_url') ) {
	function sd_get_dashboard_url() {
		$page = get_page_by_title('Subscriber Dashboard');
		if ( $page ) return get_permalink( $page->ID );
		return home_url('/');
	}
}

if ( ! function_exists('sd_login_redirect_to_dashboard') ) {
	function sd_login_redirect_to_dashboard( $redirect_to, $requested_redirect_to, $user ) {
		if ( ! $user || is_wp_error( $user ) ) return $redirect_to;

		$roles = (array) $user->roles;
		if ( in_array('subscriber', $roles, true) ) {
			return sd_get_dashboard_url();
		}
		return $redirect_to;
	}
	add_filter('login_redirect', 'sd_login_redirect_to_dashboard', 10, 3);
}
if ( ! defined('ABSPATH') ) exit;

/**
 * ============================================================
 * FINAL: Subscriber Dashboard "All Assignments" VIEW + CHAT (AJAX ✅)
 * ✅ Shows Custom Fields saved on assignment (_sd_cf_schema + post_meta)
 * ✅ Sends chat without reload (AJAX)
 * ============================================================
 */

/** ✅ Ensure Assignment supports comments (needed for chat) */
add_action('init', function(){
	if ( post_type_exists('assignment') ) {
		add_post_type_support('assignment', array('comments'));
	}
}, 20);

/** ✅ Access (author OR assigned teacher/student OR admin) */
if ( ! function_exists('sd_user_can_access_assignment_plus_author') ) {
	function sd_user_can_access_assignment_plus_author( $assignment_id, $user_id ) {
		$assignment_id = (int) $assignment_id;
		$user_id       = (int) $user_id;

		if ( current_user_can('manage_options') ) return true;

		$post = get_post($assignment_id);
		if ( $post && (int) $post->post_author === $user_id ) return true;

		$teacher_id = (int) get_post_meta($assignment_id, 'teacher_user_id', true);
		$student_id = (int) get_post_meta($assignment_id, 'student_user_id', true);

		return ( $teacher_id === $user_id ) || ( $student_id === $user_id );
	}
}

/** ✅ Fetch chat comments */
if ( ! function_exists('sd_get_assignment_chat_comments') ) {
	function sd_get_assignment_chat_comments( $assignment_id, $limit = 200 ) {
		return get_comments(array(
			'post_id' => (int)$assignment_id,
			'status'  => 'approve',
			'type'    => 'assignment_chat',
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
			'number'  => max(1, min(500, (int)$limit)),
		));
	}
}

/**
 * ✅ Render Custom Fields snapshot from assignment post_meta
 * - Schema: _sd_cf_schema (array)
 * - Values: each field key saved as normal post_meta on assignment
 */
if ( ! function_exists('sd_render_assignment_custom_fields_snapshot') ) {
	function sd_render_assignment_custom_fields_snapshot( $assignment_id ) {

		$assignment_id = absint($assignment_id);
		if ( ! $assignment_id ) return '';

		$schema = get_post_meta($assignment_id, '_sd_cf_schema', true);
		$schema = is_array($schema) ? $schema : array();
		if ( empty($schema) ) return '';

		$out  = '<hr style="margin:18px 0;border:0;border-top:1px solid rgba(255,255,255,.12);">';
		$out .= '<h3 style="margin:0 0 12px;color:#8AADC0;">Additional Details</h3>';

		$out .= '<table class="sd-table" style="margin-top:10px">';
		$out .= '<thead><tr><th style="width:35%">Field</th><th>Value</th></tr></thead><tbody>';

		$printed = 0;

		foreach ( $schema as $f ) {

			$label = isset($f['label']) ? sanitize_text_field($f['label']) : '';
			$key   = isset($f['key']) ? sanitize_key($f['key']) : '';
			$type  = isset($f['type']) ? sanitize_key($f['type']) : 'text';

			if ( $label === '' || $key === '' ) continue;

			$val = get_post_meta($assignment_id, $key, true);

			if ( is_array($val) ) {
				$val = array_values(array_filter(array_map('trim', array_map('strval', $val))));
				$display = ! empty($val) ? implode(', ', $val) : '';
			} else {
				$val = is_scalar($val) ? trim((string)$val) : '';
				if ( $type === 'checkbox' ) {
					$display = ($val === '1' || strtolower($val) === 'yes') ? 'Yes' : 'No';
				} else {
					$display = $val;
				}
			}

			if ( $display === '' ) continue;

			$out .= '<tr>';
			$out .= '<td><strong>' . esc_html($label) . '</strong></td>';
			$out .= '<td>' . esc_html($display) . '</td>';
			$out .= '</tr>';

			$printed++;
		}

		$out .= '</tbody></table>';

		return ($printed > 0) ? $out : '';
	}
}

/**
 * ✅ AJAX HANDLER — action: sd_subdash_send_assignment_chat
 */
if ( ! function_exists('sd_subdash_ajax_send_assignment_chat') ) {

	add_action('wp_ajax_sd_subdash_send_assignment_chat', 'sd_subdash_ajax_send_assignment_chat');
	add_action('wp_ajax_nopriv_sd_subdash_send_assignment_chat', 'sd_subdash_ajax_send_assignment_chat');

	function sd_subdash_ajax_send_assignment_chat() {

		if ( ! is_user_logged_in() ) {
			wp_send_json_error(array('message' => 'Login required.'));
		}

		$uid  = (int) get_current_user_id();
		$user = wp_get_current_user();

		$aid   = isset($_POST['assignment_id']) ? absint($_POST['assignment_id']) : 0;
		$text  = isset($_POST['chat_text']) ? wp_unslash($_POST['chat_text']) : '';
		$text  = trim( wp_strip_all_tags($text) );
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

		if ( ! $aid || $text === '' ) {
			wp_send_json_error(array('message' => 'Missing data.'));
		}

		if ( ! wp_verify_nonce($nonce, 'sd_send_chat_' . $aid) ) {
			wp_send_json_error(array('message' => 'Security check failed.'));
		}

		if ( ! sd_user_can_access_assignment_plus_author($aid, $uid) ) {
			wp_send_json_error(array('message' => 'Access denied.'));
		}

		$post = get_post($aid);
		if ( ! $post || $post->post_type !== 'assignment' ) {
			wp_send_json_error(array('message' => 'Assignment not found.'));
		}

		$cid = wp_insert_comment( wp_slash(array(
			'comment_post_ID'      => $aid,
			'comment_content'      => $text,
			'user_id'              => $uid,
			'comment_type'         => 'assignment_chat',
			'comment_approved'     => 1,
			'comment_author'       => $user->display_name ? $user->display_name : $user->user_login,
			'comment_author_email' => $user->user_email,
		)));

		if ( ! $cid || is_wp_error($cid) ) {
			wp_send_json_error(array('message' => 'Message could not be sent.'));
		}

		$c = get_comment($cid);

		$teacher_id = (int) get_post_meta($aid, 'teacher_user_id', true);
		$student_id = (int) get_post_meta($aid, 'student_user_id', true);

		$label = '';
		if ( $uid === $teacher_id ) $label = 'Teacher';
		elseif ( $uid === $student_id ) $label = 'Student';
		elseif ( (int)$post->post_author === $uid ) $label = 'Assigner';
		elseif ( current_user_can('manage_options') ) $label = 'Admin';

		wp_send_json_success(array(
			'author'  => $c->comment_author,
			'label'   => $label,
			'time'    => mysql2date('d M Y, h:i A', $c->comment_date),
			'content' => $c->comment_content,
			'side'    => 'right',
		));
	}
}

/**
 * ✅ Render assignment view (inside dashboard) + custom fields + chat
 */
if ( ! function_exists('sd_render_assignment_view_with_chat') ) {
	function sd_render_assignment_view_with_chat( $aid ) {

		$aid = (int) $aid;
		$uid = (int) get_current_user_id();

		$post = get_post($aid);
		if ( ! $post || $post->post_type !== 'assignment' ) {
			return '<div class="sd-notice sd-notice-error">Assignment not found.</div>';
		}

		if ( ! sd_user_can_access_assignment_plus_author($aid, $uid) ) {
			return '<div class="sd-notice sd-notice-error">Access denied.</div>';
		}

		$teacher_id = (int) get_post_meta($aid, 'teacher_user_id', true);
		$student_id = (int) get_post_meta($aid, 'student_user_id', true);

		$t = $teacher_id ? get_user_by('id', $teacher_id) : null;
		$s = $student_id ? get_user_by('id', $student_id) : null;

		$t_name = $t ? ($t->display_name ?: $t->user_login) : '—';
		$s_name = $s ? ($s->display_name ?: $s->user_login) : '—';

		$status_key = (string) get_post_meta($aid, 'track_status', true);
		$badge = function_exists('sd_status_badge_html') ? sd_status_badge_html($status_key) : esc_html($status_key ?: '—');

		$back_url = remove_query_arg(array('assignment_id','sd_action'), wp_get_referer() ? wp_get_referer() : home_url('/'));
		$back_url = add_query_arg('sd_tab', 'assignments', $back_url);

		$chat_comments = sd_get_assignment_chat_comments($aid, 250);
		$ajax_nonce = wp_create_nonce('sd_send_chat_' . $aid);

		$html  = '<a class="sd-btn" href="'. esc_url($back_url) .'">← Back</a>';
		$html .= '<h2 style="margin-top:14px;">' . esc_html( get_the_title($aid) ) . '</h2>';
		$html .= '<p><strong>Teacher:</strong> '. esc_html($t_name) .' &nbsp; | &nbsp; <strong>Student:</strong> '. esc_html($s_name) .'</p>';
		$html .= '<p><strong>Status:</strong> ' . $badge . '</p>';

		if ( has_post_thumbnail($aid) ) {
			$html .= '<div style="margin:12px 0;">' . get_the_post_thumbnail($aid, 'large', array('style'=>'max-width:100%;height:auto;border-radius:16px;') ) . '</div>';
		}

		$html .= '<div style="margin-top:12px;">' . wpautop( wp_kses_post($post->post_content) ) . '</div>';

		/** ✅ HERE: Show custom fields saved on assignment */
		$html .= sd_render_assignment_custom_fields_snapshot($aid);

		/** ✅ CHAT UI */
		$html .= '<div class="sd-chat" style="margin-top:18px;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.22);overflow:hidden">';
		$html .= '<div style="padding:12px 14px;background:rgba(0,0,0,.35);border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between">';
		$html .= '<div style="font-weight:1000">Assignment Chat</div>';
		$html .= '<div style="color:rgba(245,247,255,.55);font-size:12px;">Teacher / Student / Assigner</div>';
		$html .= '</div>';

		$html .= '<div class="sd-chat-body" id="sd-chat-body" style="padding:14px;max-height:360px;overflow:auto">';
		if ( empty($chat_comments) ) {
			$html .= '<div class="sd-notice" style="margin:0;">No messages yet.</div>';
		} else {
			foreach ( $chat_comments as $c ) {
				$sender_id = (int) $c->user_id;
				$is_me = ( $sender_id === $uid );
				$side = $is_me ? 'right' : 'left';

				$sender_name = $c->comment_author ? $c->comment_author : 'User';
				$time = mysql2date('d M Y, h:i A', $c->comment_date);

				$label = '';
				if ( $sender_id === $teacher_id ) $label = 'Teacher';
				elseif ( $sender_id === $student_id ) $label = 'Student';
				elseif ( (int)$post->post_author === $sender_id ) $label = 'Assigner';
				elseif ( user_can($sender_id, 'manage_options') ) $label = 'Admin';

				$wrap_style = $side === 'right' ? 'display:flex;justify-content:flex-end;margin:10px 0' : 'display:flex;margin:10px 0';
				$bub_style  = $side === 'right'
					? 'max-width:78%;padding:10px 12px;border-radius:16px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.12)'
					: 'max-width:78%;padding:10px 12px;border-radius:16px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.06)';

				$html .= '<div class="sd-msg '.$side.'" style="'.$wrap_style.'">';
				$html .= '<div class="sd-bubble" style="'.$bub_style.'">';
				$html .= '<div class="sd-meta" style="font-size:11px;color:rgba(245,247,255,.65);margin-bottom:6px;display:flex;gap:10px;flex-wrap:wrap">';
				$html .= '<span><strong>'. esc_html($sender_name) .'</strong></span>';
				if ( $label ) $html .= '<span style="opacity:.85">('. esc_html($label) .')</span>';
				$html .= '<span>'. esc_html($time) .'</span>';
				$html .= '</div>';
				$html .= '<div class="sd-text" style="font-size:13px;line-height:1.35;white-space:pre-wrap;word-break:break-word">'. esc_html($c->comment_content) .'</div>';
				$html .= '</div></div>';
			}
		}
		$html .= '</div>';

		$html .= '<div class="sd-chat-foot" style="padding:12px 14px;border-top:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.35)">';
		$html .= '<form class="sd-chat-form" data-aid="'. esc_attr($aid) .'" data-nonce="'. esc_attr($ajax_nonce) .'">';
		$html .= '<label style="display:block;margin-bottom:8px;font-weight:900;color:rgba(245,247,255,.72);font-size:12px;">Your message</label>';
		$html .= '<textarea name="chat_text" required placeholder="Type your message..." style="width:100%;min-height:72px;border-radius:14px;padding:12px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.45);color:#fff;"></textarea>';
		$html .= '<div style="display:flex;justify-content:flex-end;margin-top:10px"><button type="submit" style="padding:10px 14px;border:0;border-radius:14px;cursor:pointer;background:linear-gradient(90deg,#3b82f6,#7c3aed);color:#fff;font-weight:800">Send</button></div>';
		$html .= '</form>';
		$html .= '</div>';

		$html .= '</div>';

		$html .= '<script>
		(function(){
		  var body = document.getElementById("sd-chat-body");
		  if(body){ body.scrollTop = body.scrollHeight; }

		  var form = document.querySelector(".sd-chat-form");
		  if(!form || !body) return;

		  function appendMsg(d){
			var notice = body.querySelector(".sd-notice");
			if(notice && notice.textContent && notice.textContent.toLowerCase().indexOf("no messages") !== -1){
			  notice.remove();
			}

			var wrap = document.createElement("div");
			wrap.style.display="flex";
			wrap.style.justifyContent="flex-end";
			wrap.style.margin="10px 0";

			var bubble = document.createElement("div");
			bubble.style.maxWidth="78%";
			bubble.style.padding="10px 12px";
			bubble.style.borderRadius="16px";
			bubble.style.border="1px solid rgba(59,130,246,.24)";
			bubble.style.background="rgba(59,130,246,.12)";

			var meta = document.createElement("div");
			meta.style.fontSize="11px";
			meta.style.color="rgba(245,247,255,.65)";
			meta.style.marginBottom="6px";
			meta.style.display="flex";
			meta.style.gap="10px";
			meta.style.flexWrap="wrap";

			var nm = document.createElement("span");
			var st = document.createElement("strong");
			st.textContent = d.author || "User";
			nm.appendChild(st);
			meta.appendChild(nm);

			if(d.label){
			  var lb = document.createElement("span");
			  lb.style.opacity=".85";
			  lb.textContent = "(" + d.label + ")";
			  meta.appendChild(lb);
			}

			var tm = document.createElement("span");
			tm.textContent = d.time || "";
			meta.appendChild(tm);

			var tx = document.createElement("div");
			tx.style.fontSize="13px";
			tx.style.lineHeight="1.35";
			tx.style.whiteSpace="pre-wrap";
			tx.style.wordBreak="break-word";
			tx.textContent = d.content || "";

			bubble.appendChild(meta);
			bubble.appendChild(tx);
			wrap.appendChild(bubble);
			body.appendChild(wrap);

			body.scrollTop = body.scrollHeight;
		  }

		  form.addEventListener("submit", function(e){
			e.preventDefault();

			var ta = form.querySelector("textarea[name=chat_text]");
			var btn = form.querySelector("button[type=submit]");
			if(!ta || !btn) return;

			var msg = (ta.value || "").trim();
			if(!msg) return;

			var aid = form.getAttribute("data-aid");
			var nonce = form.getAttribute("data-nonce");

			var old = btn.textContent;
			btn.disabled = true;
			btn.textContent = "Sending...";

			var fd = new FormData();
			fd.append("action", "sd_subdash_send_assignment_chat");
			fd.append("assignment_id", aid);
			fd.append("chat_text", msg);
			fd.append("nonce", nonce);

			fetch("'. esc_url( admin_url("admin-ajax.php") ) .'", {
			  method: "POST",
			  credentials: "same-origin",
			  body: fd
			})
			.then(function(r){ return r.json(); })
			.then(function(res){
			  if(res && res.success){
				appendMsg(res.data);
				ta.value = "";
			  } else {
				alert((res && res.data && res.data.message) ? res.data.message : "Message could not be sent.");
			  }
			})
			.catch(function(){ alert("Network error. Try again."); })
			.finally(function(){
			  btn.disabled = false;
			  btn.textContent = old;
			});
		  });
		})();
		</script>';

		return $html;
	}
}

/**
 * ✅ Override shortcode: sd_assignments_table
 * - list view + inside-dashboard view
 */
add_action('init', function(){

	if ( shortcode_exists('sd_assignments_table') ) {
		remove_shortcode('sd_assignments_table');
	}

	add_shortcode('sd_assignments_table', function(){

		if ( function_exists('sd_require_login_and_subscriber') ) {
			$guard = sd_require_login_and_subscriber();
			if ( $guard ) return $guard;
		}

		$user_id = (int) get_current_user_id();

		// ✅ View inside dashboard
		$view_id = isset($_GET['assignment_id']) ? absint($_GET['assignment_id']) : 0;
		if ( $view_id ) {
			return sd_render_assignment_view_with_chat($view_id);
		}

		$base_url = remove_query_arg(array('sd_action','assignment_id','_wpnonce','sd_tab'));

		$q = new WP_Query(array(
			'post_type'      => 'assignment',
			'post_status'    => array('publish','private','draft','pending'),
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'author'         => $user_id,
			'no_found_rows'  => true,
		));

		if ( ! $q->have_posts() ) {
			return '<div class="sd-notice sd-notice-warn">No assignments found.</div>';
		}

		$out  = '<table class="sd-table"><thead><tr>';
		$out .= '<th>Title</th><th>Date</th><th>Teacher</th><th>Student</th><th>Status</th><th>Actions</th>';
		$out .= '</tr></thead><tbody>';

		while ( $q->have_posts() ) {
			$q->the_post();
			$aid = get_the_ID();

			$teacher_id = (int) get_post_meta($aid, 'teacher_user_id', true);
			$student_id = (int) get_post_meta($aid, 'student_user_id', true);
			$status_key = (string) get_post_meta($aid, 'track_status', true);

			$t = $teacher_id ? get_user_by('id', $teacher_id) : null;
			$s = $student_id ? get_user_by('id', $student_id) : null;

			$t_name = $t ? ($t->display_name ?: $t->user_login) : '—';
			$s_name = $s ? ($s->display_name ?: $s->user_login) : '—';

			$view_inside = add_query_arg(array(
				'sd_tab'        => 'assignments',
				'assignment_id' => $aid,
			), $base_url);

			$del_url = wp_nonce_url(
				add_query_arg(array(
					'sd_action'     => 'delete_assignment',
					'assignment_id' => $aid,
				), $base_url),
				'sd_delete_assignment_' . $aid
			);

			$out .= '<tr>';
			$out .= '<td>' . esc_html(get_the_title()) . '</td>';
			$out .= '<td>' . esc_html(get_the_date()) . '</td>';
			$out .= '<td>' . esc_html($t_name) . '</td>';
			$out .= '<td>' . esc_html($s_name) . '</td>';
			$out .= '<td>' . ( function_exists('sd_status_badge_html') ? sd_status_badge_html($status_key) : esc_html($status_key ?: '—') ) . '</td>';
			$out .= '<td class="sd-actions">';
			$out .= '<a class="sd-btn" href="' . esc_url($view_inside) . '">View</a> ';
			$out .= '<a class="sd-btn sd-btn-danger" href="' . esc_url($del_url) . '" onclick="return confirm(\'Delete this assignment?\')">Delete</a>';
			$out .= '</td>';
			$out .= '</tr>';
		}

		wp_reset_postdata();

		$out .= '</tbody></table>';
		return $out;
	});

}, 999);

/**
 * ✅ Shortcode override: sd_assignments_table
 */
/**
 * ✅ Shortcode override: sd_assignments_table (View + Delete)
 */
add_action('init', function(){

	if ( shortcode_exists('sd_assignments_table') ) {
		remove_shortcode('sd_assignments_table');
	}

	add_shortcode('sd_assignments_table', function(){

		if ( function_exists('sd_require_login_and_subscriber') ) {
			$guard = sd_require_login_and_subscriber();
			if ( $guard ) return $guard;
		}

		$user_id = (int) get_current_user_id();

		// ✅ If view mode inside dashboard
		$view_id = isset($_GET['assignment_id']) ? absint($_GET['assignment_id']) : 0;
		if ( $view_id ) {
			if ( function_exists('sd_render_assignment_view_with_chat') ) {
				return sd_render_assignment_view_with_chat($view_id);
			}
			return '<div class="sd-notice sd-notice-error">View function missing.</div>';
		}

		$base_url = remove_query_arg(array('sd_action','assignment_id','_wpnonce','sd_tab'));

		$q = new WP_Query(array(
			'post_type'      => 'assignment',
			'post_status'    => array('publish','private','draft','pending'),
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'author'         => $user_id,
			'no_found_rows'  => true,
		));

		if ( ! $q->have_posts() ) {
			return '<div class="sd-notice sd-notice-warn">No assignments found.</div>';
		}

		$out  = '<table class="sd-table"><thead><tr>';
		$out .= '<th>Title</th><th>Date</th><th>Teacher</th><th>Student</th><th>Status</th><th>Actions</th>';
		$out .= '</tr></thead><tbody>';

		while ( $q->have_posts() ) {
			$q->the_post();
			$aid = get_the_ID();

			$teacher_id = (int) get_post_meta($aid, 'teacher_user_id', true);
			$student_id = (int) get_post_meta($aid, 'student_user_id', true);
			$status_key = (string) get_post_meta($aid, 'track_status', true);

			$t = $teacher_id ? get_user_by('id', $teacher_id) : null;
			$s = $student_id ? get_user_by('id', $student_id) : null;

			$t_name = $t ? ($t->display_name ?: $t->user_login) : '—';
			$s_name = $s ? ($s->display_name ?: $s->user_login) : '—';

			$view_inside = add_query_arg(array(
				'sd_tab'        => 'assignments',
				'assignment_id' => $aid,
			), $base_url);

			$del_url = wp_nonce_url(
				add_query_arg(array(
					'sd_action'     => 'delete_assignment',
					'assignment_id' => $aid,
				), $base_url),
				'sd_delete_assignment_' . $aid
			);

			$out .= '<tr>';
			$out .= '<td>' . esc_html(get_the_title()) . '</td>';
			$out .= '<td>' . esc_html(get_the_date()) . '</td>';
			$out .= '<td>' . esc_html($t_name) . '</td>';
			$out .= '<td>' . esc_html($s_name) . '</td>';
			$out .= '<td>' . ( function_exists('sd_status_badge_html') ? sd_status_badge_html($status_key) : esc_html($status_key ?: '—') ) . '</td>';
			$out .= '<td class="sd-actions">';
			$out .= '<a class="sd-btn" href="' . esc_url($view_inside) . '">View</a> ';
			$out .= '<a class="sd-btn sd-btn-danger" href="' . esc_url($del_url) . '" onclick="return confirm(\'Delete this assignment?\')">Delete</a>';
			$out .= '</td>';
			$out .= '</tr>';
		}

		wp_reset_postdata();

		$out .= '</tbody></table>';
		return $out;
	});

}, 999);


/**
 * ============================================================
 * FIXED PATCH: Only show Teacher + Student in User Dashboard (Front-end)
 * - Works even if dropdown uses wp_roles()->roles / role_names
 * - Also forces user list to only teacher/student
 * - Also validates Add User POST so no other role can be assigned
 * ============================================================
 */

if ( ! defined('ABSPATH') ) exit;

/** 1) Allowed roles */
if ( ! function_exists('rd_allowed_frontend_roles') ) {
	function rd_allowed_frontend_roles() {
		return array('teacher', 'student');
	}
}

/**
 * 2) HARD LIMIT roles on FRONTEND only
 * This fixes cases where code builds dropdown from wp_roles() directly.
 */
add_action('init', function () {

	if ( is_admin() ) return; // ✅ do not touch wp-admin

	$allow = rd_allowed_frontend_roles();
	$roles_obj = wp_roles();

	if ( ! $roles_obj || empty($roles_obj->roles) ) return;

	// Remove all roles except allowed
	foreach ( array_keys($roles_obj->roles) as $rk ) {
		if ( ! in_array($rk, $allow, true) ) {
			unset($roles_obj->roles[$rk]);
		}
	}

	// Also trim role_names (used by some dropdowns)
	if ( ! empty($roles_obj->role_names) && is_array($roles_obj->role_names) ) {
		foreach ( array_keys($roles_obj->role_names) as $rk ) {
			if ( ! in_array($rk, $allow, true) ) {
				unset($roles_obj->role_names[$rk]);
			}
		}
	}
}, 5);

/**
 * 3) If dashboard users list uses get_users() without role filter,
 * force it to only teacher/student (frontend only).
 */
add_action('pre_user_query', function($query){

	if ( is_admin() ) return;

	// If already filtered by role, don't override
	if ( ! empty($query->query_vars['role']) || ! empty($query->query_vars['role__in']) ) return;

	$query->query_vars['role__in'] = rd_allowed_frontend_roles();

}, 10, 1);

/**
 * 4) Server-side protection: If your front-end "Add User" form posts role,
 * block anything except teacher/student (so inspect element cannot bypass).
 *
 * NOTE: yahan 'sd_action' / 'action' tumhare form ke hidden field ke mutabiq change ho sakta hai.
 * Main ne 2 common keys check kar diye hain.
 */
add_filter('wp_pre_insert_user_data', function($data, $update, $user_id, $userdata){

	if ( is_admin() ) return $data;

	// Detect your front-end add user submit (adjust if your action name is different)
	$possible_action = '';
	if ( isset($_POST['sd_action']) ) $possible_action = sanitize_text_field(wp_unslash($_POST['sd_action']));
	if ( isset($_POST['action']) )    $possible_action = sanitize_text_field(wp_unslash($_POST['action']));

	// if it's not add user submit, ignore
	// (Add here your exact action name if you know it)
	$is_add_user = in_array($possible_action, array('add_user','sd_add_user','create_user','add_new_user'), true);

	if ( ! $is_add_user ) return $data;

	$allow = rd_allowed_frontend_roles();

	// role from form
	$form_role = '';
	if ( isset($_POST['new_user_role']) ) $form_role = sanitize_text_field(wp_unslash($_POST['new_user_role']));
	if ( isset($_POST['role']) )          $form_role = sanitize_text_field(wp_unslash($_POST['role']));

	// If someone tries other role, force to student (or you can wp_die)
	if ( ! in_array($form_role, $allow, true) ) {
		$form_role = 'student';
	}

	// set role in user data if present
	$data['role'] = $form_role;

	return $data;

}, 10, 4);

/**
 * ============================================================
 * BLOCK ADMIN FROM FRONTEND USER DASHBOARD
 * (Admin -> wp-admin only)
 * ============================================================
 */
add_action('template_redirect', function () {

    // WordPress fully loaded here
    if ( is_admin() ) return;
    if ( ! is_user_logged_in() ) return;

    // Agar admin frontend user dashboard open kare
    if ( is_page('user-dashboard') && current_user_can('manage_options') ) {
        wp_safe_redirect( admin_url() );
        exit;
    }

}, 20);

/**
 * ✅ Fix: Assignment delete hone par related users ki rd_notifications se
 * us assignment wali notifications remove kar do
 * (teacher/student/subscriber author)
 */

if ( ! function_exists('sd_remove_rd_notifications_for_assignment') ) {
	function sd_remove_rd_notifications_for_assignment( $assignment_id ) {

		$assignment_id = absint($assignment_id);
		if ( ! $assignment_id ) return;

		$author_id  = (int) get_post_field('post_author', $assignment_id);
		$teacher_id = (int) get_post_meta($assignment_id, 'teacher_user_id', true);
		$student_id = (int) get_post_meta($assignment_id, 'student_user_id', true);

		$user_ids = array_unique(array_filter(array($author_id, $teacher_id, $student_id)));

		foreach ( $user_ids as $uid ) {

			$list = get_user_meta($uid, 'rd_notifications', true);
			$list = is_array($list) ? $list : array();

			if ( empty($list) ) continue;

			$new = array();

			foreach ( $list as $n ) {

				// notification me assignment id usually 'assignment' key me hoti hai
				$nid_aid = 0;
				if ( is_array($n) && isset($n['assignment']) ) {
					$nid_aid = (int) $n['assignment'];
				}

				// ✅ jis assignment ko delete kiya, uski notif skip
				if ( $nid_aid !== $assignment_id ) {
					$new[] = $n;
				}
			}

			update_user_meta($uid, 'rd_notifications', array_values($new));
		}
	}
}

/**
 * ✅ Auto-run whenever an assignment is deleted (dashboard OR wp-admin)
 */
add_action('before_delete_post', function($post_id){

	$p = get_post($post_id);
	if ( $p && $p->post_type === 'assignment' ) {
		sd_remove_rd_notifications_for_assignment($post_id);
	}

}, 10, 1);


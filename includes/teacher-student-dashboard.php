<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * ============================================================
 * 0) ENABLE COMMENTS FOR ASSIGNMENTS (for chat)
 * ============================================================
 */
add_action('init', function(){
	// Ensure assignment post type supports comments
	if ( post_type_exists('assignment') ) {
		add_post_type_support('assignment', array('comments'));
	}
}, 20);

// Keep comments open on assignment (optional safety)
add_filter('comments_open', function($open, $post_id){
	$p = get_post($post_id);
	if ( $p && $p->post_type === 'assignment' ) return true;
	return $open;
}, 10, 2);


/**
 * ============================================================
 * ✅ AJAX: Send Assignment Chat (NO reload)
 * ============================================================
 */
add_action('wp_ajax_rd_send_assignment_chat', 'rd_ajax_send_assignment_chat');
add_action('wp_ajax_nopriv_rd_send_assignment_chat', 'rd_ajax_send_assignment_chat');

function rd_ajax_send_assignment_chat() {

	if ( ! is_user_logged_in() ) {
		wp_send_json_error(array('message' => 'Login required.'));
	}

	$user = wp_get_current_user();

	$aid   = isset($_POST['assignment_id']) ? absint($_POST['assignment_id']) : 0;
	$text  = isset($_POST['chat_text']) ? wp_unslash($_POST['chat_text']) : '';
	$text  = trim( wp_strip_all_tags($text) );

	$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

	if ( ! $aid || $text === '' ) {
		wp_send_json_error(array('message' => 'Missing data.'));
	}

	// Nonce verify
	if ( ! wp_verify_nonce($nonce, 'rd_send_chat_' . $aid) ) {
		wp_send_json_error(array('message' => 'Security check failed.'));
	}

	// Access check
	if ( ! function_exists('rd_user_can_access_assignment') || ! rd_user_can_access_assignment($aid, $user->ID) ) {
		wp_send_json_error(array('message' => 'Access denied.'));
	}

	$post = get_post($aid);
	if ( ! $post || $post->post_type !== 'assignment' ) {
		wp_send_json_error(array('message' => 'Assignment not found.'));
	}

	$cid = wp_insert_comment( wp_slash(array(
		'comment_post_ID'      => $aid,
		'comment_content'      => $text,
		'user_id'              => (int)$user->ID,
		'comment_type'         => 'assignment_chat',
		'comment_approved'     => 1,
		'comment_author'       => $user->display_name ?: $user->user_login,
		'comment_author_email' => $user->user_email,
	)));

	if ( ! $cid || is_wp_error($cid) ) {
		wp_send_json_error(array('message' => 'Message could not be sent.'));
	}

	$c = get_comment($cid);

	$teacher_id = (int) get_post_meta($aid, 'teacher_user_id', true);
	$student_id = (int) get_post_meta($aid, 'student_user_id', true);

	$label = '';
	if ( (int)$user->ID === $teacher_id ) $label = 'Teacher';
	elseif ( (int)$user->ID === $student_id ) $label = 'Student';
	elseif ( user_can($user, 'manage_options') ) $label = 'Admin';

	wp_send_json_success(array(
		'comment_id' => (int)$cid,
		'author'     => $c->comment_author,
		'label'      => $label,
		'time'       => mysql2date('d M Y, h:i A', $c->comment_date),
		'content'    => $c->comment_content,
		'side'       => 'right',
	));
}


/**
 * ============================================================
 * 1) CUSTOM LOGIN SHORTCODE
 * ============================================================
 */
add_shortcode('custom_login', function($atts){

	$atts = shortcode_atts(array(
		'teacher_url'    => site_url('/teacher-dashboard/'),
		'student_url'    => site_url('/student-dashboard/'),
		'subscriber_url' => site_url('/user-dashboard/'),
		'admin_url'      => admin_url(),
	), $atts);

	$css = '
	<style>
		:root{
		  --cl-bg:#07070b; --cl-card:#0d0f17; --cl-line:rgba(255,255,255,.12);
		  --cl-text:#f5f7ff; --cl-muted:rgba(245,247,255,.65);
		  --cl-ac1:#3b82f6; --cl-ac2:#7c3aed;
		  --cl-shadow:0 28px 80px rgba(0,0,0,.70);
		}
		.cl-page{min-height:calc(100vh - 60px);display:flex;align-items:center;justify-content:center;padding:30px 14px;
		  background:
			radial-gradient(900px 500px at 15% 10%, rgba(59,130,246,.12), transparent 60%),
			radial-gradient(900px 500px at 90% 20%, rgba(124,58,237,.12), transparent 60%),
			linear-gradient(180deg, var(--cl-bg), #05050a);
		}
		.cl-wrap{width:100%;max-width:450px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial}
		.cl-card{background:linear-gradient(180deg, rgba(17,20,40,.92), rgba(13,15,23,.92));
		  border:1px solid var(--cl-line);border-radius:22px;padding:22px;box-shadow:var(--cl-shadow)}
		.cl-title{font-size:22px;font-weight:1000;margin:0 0 6px;color:var(--cl-text)}
		.cl-sub{color:var(--cl-muted);margin:0 0 18px;font-size:13px}
		.cl-label{display:block;font-size:12px;font-weight:900;color:rgba(245,247,255,.72);margin:12px 0 6px}
		.cl-input{width:100%;padding:12px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.45);color:var(--cl-text);outline:none}
		.cl-input:focus{border-color:rgba(59,130,246,.55);box-shadow:0 0 0 5px rgba(59,130,246,.16)}
		.cl-check{display:flex;gap:10px;align-items:center;color:rgba(245,247,255,.75);font-size:13px;margin-top:10px}
		.cl-btn{width:100%;margin-top:14px;padding:12px 14px;border:0;border-radius:14px;background:linear-gradient(90deg,var(--cl-ac1),var(--cl-ac2));color:#fff;font-weight:1000;cursor:pointer}
		.cl-btn:hover{filter:brightness(1.06)}
		.cl-notice{padding:12px 14px;border-radius:16px;margin:12px 0;font-weight:900;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.30);color:var(--cl-text)}
		.cl-err{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.28);color:#ffe4e6}
		.cl-auto-hide{animation:clFadeOut .6s ease forwards;animation-delay:5s}
		@keyframes clFadeOut{to{opacity:0;transform:translateY(-6px)}}
	</style>';

	if ( is_user_logged_in() ) {
		$u = wp_get_current_user();
		if ( user_can($u, 'manage_options') ) { wp_safe_redirect($atts['admin_url']); exit; }
		$roles = (array)$u->roles;
		if ( in_array('teacher', $roles, true) ) { wp_safe_redirect($atts['teacher_url']); exit; }
		if ( in_array('student', $roles, true) ) { wp_safe_redirect($atts['student_url']); exit; }
		wp_safe_redirect($atts['subscriber_url']); exit;
	}

	$out = '';
	if ( isset($_POST['cl_action']) && $_POST['cl_action'] === 'login' ) {
		if ( !isset($_POST['cl_nonce']) || !wp_verify_nonce($_POST['cl_nonce'], 'cl_login') ) {
			$out .= '<div class="cl-notice cl-err cl-auto-hide">Security check failed.</div>';
		} else {
			$creds = array(
				'user_login'    => sanitize_text_field($_POST['username'] ?? ''),
				'user_password' => $_POST['password'] ?? '',
				'remember'      => !empty($_POST['remember']),
			);
			$user = wp_signon($creds, is_ssl());
			if ( is_wp_error($user) ) {
				$out .= '<div class="cl-notice cl-err cl-auto-hide">Invalid username/email or password.</div>';
			} else {
				if ( user_can($user, 'manage_options') ) { wp_safe_redirect($atts['admin_url']); exit; }
				$roles = (array)$user->roles;
				if ( in_array('teacher', $roles, true) ) { wp_safe_redirect($atts['teacher_url']); exit; }
				if ( in_array('student', $roles, true) ) { wp_safe_redirect($atts['student_url']); exit; }
				wp_safe_redirect($atts['subscriber_url']); exit;
			}
		}
	}

	ob_start();
	echo $css;
	?>
	<div class="cl-page">
	  <div class="cl-wrap">
		<div class="cl-card">
		  <h2 class="cl-title">Login</h2>
		  <p class="cl-sub">Enter your username/email and password to continue.</p>

		  <?php echo $out; ?>

		  <form method="post">
			<label class="cl-label">Username or Email</label>
			<input class="cl-input" type="text" name="username" required>

			<label class="cl-label">Password</label>
			<input class="cl-input" type="password" name="password" required>

			<label class="cl-check">
			  <input type="checkbox" name="remember"> Remember me
			</label>

			<input type="hidden" name="cl_action" value="login">
			<?php wp_nonce_field('cl_login', 'cl_nonce'); ?>

			<button class="cl-btn" type="submit">Login</button>
		  </form>
		</div>
	  </div>
	</div>
	<script>
	  setTimeout(function(){
		document.querySelectorAll(".cl-auto-hide").forEach(function(el){ el.style.display="none"; });
	  }, 5600);
	</script>
	<?php
	return ob_get_clean();
});


/* ============================================================
 * Helpers
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
		return '<span class="rd-badge rd-b-' . esc_attr($key) . '">' . esc_html($map[$key]) . '</span>';
	}
}

/**
 * Access check for assignment (teacher/student assigned OR admin)
 */
if ( ! function_exists('rd_user_can_access_assignment') ) {
	function rd_user_can_access_assignment( $assignment_id, $user_id ) {
		if ( current_user_can('manage_options') ) return true;
		$teacher_id = (int) get_post_meta($assignment_id, 'teacher_user_id', true);
		$student_id = (int) get_post_meta($assignment_id, 'student_user_id', true);
		return ( (int)$teacher_id === (int)$user_id ) || ( (int)$student_id === (int)$user_id );
	}
}

/**
 * Fetch chat comments for assignment
 */
if ( ! function_exists('rd_get_assignment_chat_comments') ) {
	function rd_get_assignment_chat_comments( $assignment_id, $limit = 200 ) {
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
 * ✅ FINAL: Render subscriber custom fields from assignment snapshot
 * - Schema: _sd_cf_schema (post_meta on assignment)
 * - Values: meta keys (same as schema key) on same assignment (post_meta)
 */
if ( ! function_exists('rd_render_assignment_custom_fields_from_snapshot') ) {
	function rd_render_assignment_custom_fields_from_snapshot($assignment_id) {

		$assignment_id = absint($assignment_id);
		if ( ! $assignment_id ) return '';

		$schema = get_post_meta($assignment_id, '_sd_cf_schema', true);
		$schema = is_array($schema) ? $schema : array();

		if ( empty($schema) ) return '';

		$out  = '<hr style="margin:18px 0;border:0;border-top:1px solid rgba(255,255,255,.12);">';
		$out .= '<h3 style="margin:0 0 12px;color:#8AADC0;">Additional Details</h3>';

		$out .= '<table class="rd-table" style="margin-top:10px">';
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
 * ============================================================
 * ✅ NOTIFICATIONS HELPERS (User Meta)
 * ============================================================
 */
if ( ! function_exists('rd_get_user_notifications') ) {
	function rd_get_user_notifications( $user_id ) {
		$list = get_user_meta((int)$user_id, 'rd_notifications', true);
		return is_array($list) ? $list : array();
	}
}

if ( ! function_exists('rd_save_user_notifications') ) {
	function rd_save_user_notifications( $user_id, $list ) {
		if ( ! is_array($list) ) $list = array();
		update_user_meta((int)$user_id, 'rd_notifications', array_values($list));
	}
}

if ( ! function_exists('rd_unread_notifications_count') ) {
	function rd_unread_notifications_count( $user_id ) {
		$list = rd_get_user_notifications($user_id);
		$c = 0;
		foreach($list as $n){
			if ( empty($n['read']) ) $c++;
		}
		return (int)$c;
	}
}


/**
 * ============================================================
 * NEW: Assignment Status Summary (Counts for Dashboard)
 * ============================================================
 */
if ( ! function_exists('rd_get_assignment_status_counts') ) {
	function rd_get_assignment_status_counts( $user_id, $role_slug ) {

		$user_id = (int) $user_id;
		$role_slug = (string) $role_slug;

		$meta_key = ( $role_slug === 'teacher' ) ? 'teacher_user_id' : 'student_user_id';

		$wanted = array();
		if ( $role_slug === 'student' ) {
			$wanted = array('start_working','need_more_instructions','submitted_to_teacher');
		} elseif ( $role_slug === 'teacher' ) {
			$wanted = array('submitted_to_teacher','approved','rejected');
		} else {
			return array();
		}

		$counts = array();
		foreach ($wanted as $s) $counts[$s] = 0;

		$ids = get_posts(array(
			'post_type'      => 'assignment',
			'post_status'    => array('publish','private','draft','pending'),
			'posts_per_page' => 500,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'     => $meta_key,
					'value'   => $user_id,
					'compare' => '=',
					'type'    => 'NUMERIC'
				)
			),
			'no_found_rows'  => true,
		));

		if ( empty($ids) ) return $counts;

		foreach ( $ids as $aid ) {
			$st = (string) get_post_meta($aid, 'track_status', true);
			if ( isset($counts[$st]) ) $counts[$st]++;
		}

		return $counts;
	}
}

if ( ! function_exists('rd_assignment_summary_html') ) {
	function rd_assignment_summary_html( $role_slug, $counts ) {

		if ( empty($counts) || ! is_array($counts) ) return '';

		$map = sd_assignment_status_options();

		$html  = '<div class="rd-sum-wrap">';
		$html .= '<h3 style="margin-top:6px;">Assignment Summary</h3>';
		$html .= '<div class="rd-sum-grid">';

		foreach ( $counts as $key => $num ) {
			$label = isset($map[$key]) ? $map[$key] : $key;

			if ( $role_slug === 'teacher' && $key === 'submitted_to_teacher' ) {
				$label = 'Pending Review';
			}

			$html .= '<div class="rd-sum-card">';
			$html .= '<div class="rd-sum-top">';
			$html .= '<div class="rd-sum-title">'. esc_html($label) .'</div>';
			$html .= '<div class="rd-sum-badge">'. sd_status_badge_html($key) .'</div>';
			$html .= '</div>';
			$html .= '<div class="rd-sum-num">'. (int)$num .'</div>';
			$html .= '</div>';
		}

		$html .= '</div></div>';

		return $html;
	}
}


/**
 * ============================================================
 * 2) ROLE DASHBOARD SHORTCODE (FINAL)
 * ============================================================
 */
add_shortcode('role_dashboard', function($atts){

	$atts = shortcode_atts(array(
		'role'       => 'subscriber',
		'login_page' => '/login/',
	), $atts);

	$required_role  = sanitize_text_field($atts['role']);
	$login_page_raw = trim( (string) $atts['login_page'] );

	$login_page_url = ( strpos($login_page_raw, 'http') === 0 )
		? esc_url($login_page_raw)
		: esc_url( site_url( '/' . ltrim($login_page_raw, '/') ) );

	if ( ! is_user_logged_in() ) {
		$current = esc_url_raw( (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
		$go = add_query_arg('redirect_to', rawurlencode($current), $login_page_url);
		wp_safe_redirect($go);
		exit;
	}

	$user  = wp_get_current_user();
	$roles = (array) $user->roles;

	// ❌ Admin not allowed on frontend dashboard
	if ( current_user_can('manage_options') ) {
		wp_safe_redirect( admin_url() );
		exit;
	}

	// Only teacher/student allowed
	if ( ! in_array($required_role, $roles, true) ) {
		return '<div class="notice notice-error">Access denied for this dashboard.</div>';
	}

	$section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : 'dashboard';

	// ✅ Messages
	$msg = '';
	if ( ! empty($_GET['rd_msg']) ) {
		$k = sanitize_text_field(wp_unslash($_GET['rd_msg']));
		if ( $k === 'status_updated' ) $msg = '<div class="notice notice-success">Status updated successfully.</div>';
		if ( $k === 'status_error' )   $msg = '<div class="notice notice-error">Status update failed.</div>';
		if ( $k === 'access_denied' )  $msg = '<div class="notice notice-error">Access denied.</div>';
		if ( $k === 'notif_read' )     $msg = '<div class="notice notice-success">Notification marked as read.</div>';
		if ( $k === 'notif_error' )    $msg = '<div class="notice notice-error">Could not update notification.</div>';
	}

	$base_url = esc_url( remove_query_arg(array('section','assignment_id','rd_msg','nid')) );
	$link = function($sec) use ($base_url){
		return esc_url( add_query_arg('section', $sec, $base_url) );
	};

	/* ✅ Handle "Mark as Read" */
	if ( isset($_POST['rd_action']) && $_POST['rd_action'] === 'mark_notification_read' ) {

		$nid = isset($_POST['nid']) ? sanitize_text_field(wp_unslash($_POST['nid'])) : '';
		$ok = false;

		if ( $nid && isset($_POST['rd_notif_nonce']) && wp_verify_nonce($_POST['rd_notif_nonce'], 'rd_mark_notif_' . $nid) ) {

			$list = rd_get_user_notifications($user->ID);
			foreach($list as &$n){
				if ( isset($n['id']) && $n['id'] === $nid ) {
					$n['read'] = 1;
					$ok = true;
					break;
				}
			}
			unset($n);

			if ( $ok ) rd_save_user_notifications($user->ID, $list);
		}

		$go = add_query_arg('rd_msg', $ok ? 'notif_read' : 'notif_error', remove_query_arg(array('rd_msg')) );
		wp_safe_redirect($go);
		exit;
	}

	// ✅ Handle profile update
	if ( isset($_POST['rd_action']) && $_POST['rd_action'] === 'update_profile' ) {
		if ( ! isset($_POST['rd_nonce']) || ! wp_verify_nonce($_POST['rd_nonce'], 'rd_update_profile') ) {
			$msg = '<div class="notice notice-error">Security check failed.</div>';
		} else {
			$new_email = sanitize_email($_POST['email'] ?? '');
			$new_login = sanitize_user($_POST['username'] ?? '');
			$new_pass  = $_POST['password'] ?? '';

			$userdata = array('ID' => $user->ID);
			if ( $new_email && is_email($new_email) ) $userdata['user_email'] = $new_email;
			if ( ! empty($new_pass) && strlen($new_pass) >= 6 ) $userdata['user_pass'] = $new_pass;

			$updated = wp_update_user($userdata);

			if ( is_wp_error($updated) ) {
				$msg = '<div class="notice notice-error">'. esc_html($updated->get_error_message()) .'</div>';
			} else {
				$msg = '<div class="notice notice-success">Profile updated successfully.</div>';
				$user = wp_get_current_user();
			}

			if ( ! empty($new_login) && $new_login !== $user->user_login ) {
				$msg .= '<div class="notice">Note: Username usually cannot be changed safely. Email/password updated.</div>';
			}
		}
	}

	// ✅ Handle status update (teacher/student only)
	if ( isset($_POST['rd_action']) && $_POST['rd_action'] === 'update_assignment_status' ) {

		$aid = isset($_POST['assignment_id']) ? absint($_POST['assignment_id']) : 0;
		$new_status = isset($_POST['track_status']) ? sanitize_text_field(wp_unslash($_POST['track_status'])) : '';
		$ok = false;

		if ( $aid && isset($_POST['rd_status_nonce']) && wp_verify_nonce($_POST['rd_status_nonce'], 'rd_update_status_' . $aid) ) {

			$post = get_post($aid);
			if ( $post && $post->post_type === 'assignment' ) {
				$teacher_id = (int) get_post_meta($aid, 'teacher_user_id', true);
				$student_id = (int) get_post_meta($aid, 'student_user_id', true);

				$allowed_all = sd_assignment_status_options();
				$allowed_for_me = array();

				if ( in_array('student', $roles, true) && (int)$student_id === (int)$user->ID ) {
					$allowed_for_me = array('start_working','need_more_instructions','submitted_to_teacher');
				}

				if ( in_array('teacher', $roles, true) && (int)$teacher_id === (int)$user->ID ) {
					$allowed_for_me = array('approved','rejected');
				}

				if ( ! empty($allowed_for_me) && isset($allowed_all[$new_status]) && in_array($new_status, $allowed_for_me, true) ) {
					update_post_meta($aid, 'track_status', $new_status);
					$ok = true;
				}
			}
		}

		$go = add_query_arg('rd_msg', $ok ? 'status_updated' : 'status_error', remove_query_arg(array('rd_msg')) );
		wp_safe_redirect($go);
		exit;
	}

	$logout_url = wp_logout_url( $login_page_url );

	/* ✅ Notifications badge count for menu */
	rd_prune_user_notifications($user->ID);
	$unread = rd_unread_notifications_count($user->ID);
	$notif_label = 'Notifications';
	if ( $unread > 0 ) {
		$notif_label .= ' <span class="rd-notif-badge">'. (int)$unread .'</span>';
	}

	// ✅ DASHBOARD CSS
	$html  = '<style>
	:root{
	  --rd-bg:#07070b;
	  --rd-panel:#0d0f17;
	  --rd-panel2:#111428;
	  --rd-line:rgba(255,255,255,.10);
	  --rd-line2:rgba(255,255,255,.14);
	  --rd-text:#f5f7ff;
	  --rd-muted:rgba(245,247,255,.65);
	  --rd-ac1:#3b82f6;
	  --rd-ac2:#7c3aed;
	  --rd-shadow:0 28px 80px rgba(0,0,0,.70);
	  --rd-shadow2:0 14px 40px rgba(0,0,0,.55);
	}
	.rd-wrap{max-width:1260px;margin:22px auto;padding:16px;display:grid;grid-template-columns:320px 1fr;gap:18px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--rd-text)}
	.rd-side{position:sticky;top:18px;height:fit-content;padding:18px;border-radius:22px;background:
	  radial-gradient(900px 500px at 10% 0%, rgba(59,130,246,.10), transparent 60%),
	  radial-gradient(900px 500px at 95% 10%, rgba(124,58,237,.10), transparent 60%),
	  linear-gradient(180deg, var(--rd-panel2), var(--rd-panel));
	  border:1px solid var(--rd-line2);box-shadow:var(--rd-shadow)}
	.rd-user{font-weight:1000;letter-spacing:.2px;margin-bottom:6px;font-size:16px}
	.rd-sub{color:var(--rd-muted);margin-bottom:14px;font-size:13px;line-height:1.35}
	.rd-menu a{display:flex;align-items:center;justify-content:space-between;padding:12px 12px;margin-bottom:10px;border-radius:16px;text-decoration:none;color:var(--rd-text);font-weight:900;font-size:13px;background:rgba(0,0,0,.28);border:1px solid rgba(255,255,255,.08);transition:.18s ease}
	.rd-menu a:hover{transform:translateX(5px);border-color:rgba(59,130,246,.35);background:rgba(59,130,246,.08)}
	.rd-main{padding:20px;border-radius:22px;background:
	  radial-gradient(900px 500px at 85% 0%, rgba(59,130,246,.08), transparent 60%),
	  radial-gradient(900px 500px at 20% 20%, rgba(124,58,237,.08), transparent 60%),
	  linear-gradient(180deg, var(--rd-panel2), var(--rd-panel));
	  border:1px solid var(--rd-line2);box-shadow:var(--rd-shadow)}
	.rd-main h2{margin:6px 0 10px;font-size:22px;font-weight:1000;letter-spacing:.2px}
	.rd-main h3{margin:16px 0 10px;font-size:15px;font-weight:1000;letter-spacing:.2px}
	.notice{padding:12px 14px;border-radius:16px;margin:12px 0;background:rgba(0,0,0,.30);border:1px solid rgba(255,255,255,.10);box-shadow:var(--rd-shadow2);font-weight:900;font-size:13px;color:var(--rd-text)}
	.notice-error{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.28);color:#ffe4e6}
	.notice-success{background:rgba(34,197,94,.10);border-color:rgba(34,197,94,.22);color:#dcfce7}
	.rd-table{width:100%;border-collapse:separate;border-spacing:0;border-radius:18px;overflow:hidden;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.10);box-shadow:var(--rd-shadow2);margin-top:12px}
	.rd-table thead th{padding:12px;text-align:left;font-size:12px;letter-spacing:.35px;color:rgba(245,247,255,.72);background:rgba(0,0,0,.35);border-bottom:1px solid rgba(255,255,255,.08)}
	.rd-table td{padding:12px;border-bottom:1px solid rgba(255,255,255,.07);font-size:13px;color:var(--rd-text)}
	.rd-table tr:hover td{background:rgba(59,130,246,.06)}
	input[type=text],input[type=password],input[type=email],select,textarea{width:100%;padding:12px 12px;border-radius:14px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.45);color:var(--rd-text);outline:none;transition:.18s ease}
	input:focus,select:focus,textarea:focus{border-color:rgba(59,130,246,.55);box-shadow:0 0 0 5px rgba(59,130,246,.16)}
	button{padding:10px 14px;border:0;border-radius:14px;cursor:pointer;background:linear-gradient(90deg, rgba(59,130,246,1), rgba(124,58,237,1));color:#fff;font-weight:1000;box-shadow:0 12px 28px rgba(59,130,246,.18);transition:.18s ease}
	button:hover{transform:translateY(-1px);filter:brightness(1.06)}
	.rd-btn{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;text-decoration:none;background:linear-gradient(90deg, rgba(59,130,246,1), rgba(124,58,237,1));color:#fff;font-weight:1000;font-size:13px;box-shadow:0 12px 28px rgba(59,130,246,.18);transition:.18s ease}
	.rd-btn:hover{transform:translateY(-1px);filter:brightness(1.06)}
	.rd-badge{display:inline-block;padding:7px 10px;border-radius:999px;font-size:12px;font-weight:1000;line-height:1;border:1px solid rgba(255,255,255,.12)}
	.rd-b-start_working{background:rgba(59,130,246,.16);color:#dbeafe;border-color:rgba(59,130,246,.28)}
	.rd-b-need_more_instructions{background:rgba(245,158,11,.14);color:#ffedd5;border-color:rgba(245,158,11,.24)}
	.rd-b-submitted_to_teacher{background:rgba(124,58,237,.16);color:#ede9fe;border-color:rgba(124,58,237,.28)}
	.rd-b-approved{background:rgba(34,197,94,.14);color:#dcfce7;border-color:rgba(34,197,94,.24)}
	.rd-b-rejected{background:rgba(239,68,68,.14);color:#fee2e2;border-color:rgba(239,68,68,.24)}
	.rd-b-assign_to_student{background:rgba(148,163,184,.12);color:#e5e7eb;border-color:rgba(148,163,184,.22)}
	.rd-notif-badge{
	  display:inline-flex;align-items:center;justify-content:center;
	  min-width:22px;height:22px;padding:0 8px;border-radius:999px;
	  background:rgba(239,68,68,.18);border:1px solid rgba(239,68,68,.35);
	  color:#ffe4e6;font-size:12px;font-weight:1000;margin-left:10px;
	}
	/* CHAT UI */
	.rd-chat{margin-top:18px;border-radius:18px;border:1px solid rgba(255,255,255,.10);background:rgba(0,0,0,.22);box-shadow:var(--rd-shadow2);overflow:hidden}
	.rd-chat-head{padding:12px 14px;background:rgba(0,0,0,.35);border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:space-between}
	.rd-chat-title{font-weight:1000}
	.rd-chat-body{padding:14px;max-height:360px;overflow:auto}
	.rd-msg{display:flex;margin:10px 0}
	.rd-msg.right{justify-content:flex-end}
	.rd-bubble{max-width:78%;padding:10px 12px;border-radius:16px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.06)}
	.rd-msg.right .rd-bubble{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.24)}
	.rd-meta{font-size:11px;color:rgba(245,247,255,.65);margin-bottom:6px;display:flex;gap:10px;flex-wrap:wrap}
	.rd-text{font-size:13px;line-height:1.35;color:var(--rd-text);white-space:pre-wrap;word-break:break-word}
	.rd-chat-foot{padding:12px 14px;border-top:1px solid rgba(255,255,255,.08);background:rgba(0,0,0,.35)}
	.rd-chat-foot textarea{min-height:72px;resize:vertical}
	.rd-chat-actions{display:flex;justify-content:flex-end;margin-top:10px}
	/* SUMMARY CARDS */
	.rd-sum-wrap{margin-top:16px}
	.rd-sum-grid{display:grid;grid-template-columns:repeat(3, minmax(0,1fr));gap:12px;margin-top:12px}
	.rd-sum-card{padding:14px;border-radius:18px;background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.10);box-shadow:var(--rd-shadow2)}
	.rd-sum-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
	.rd-sum-title{font-weight:1000;font-size:13px;color:rgba(245,247,255,.85);line-height:1.25}
	.rd-sum-num{margin-top:10px;font-size:26px;font-weight:1000;letter-spacing:.3px}
	@media (max-width: 980px){.rd-wrap{grid-template-columns:1fr}.rd-side{position:relative;top:auto}.rd-sum-grid{grid-template-columns:1fr}}
	</style>';

	$html .= '<div class="rd-wrap">';
	$html .= '<div class="rd-side">';
	$html .= '<div class="rd-user">Welcome, '. esc_html($user->display_name ?: $user->user_login) .'</div>';
	$html .= '<div class="rd-sub">Manage your account and assignments</div>';
	$html .= '<div class="rd-menu">';
	$html .= '<a href="'.$link('dashboard').'">Dashboard</a>';
	$html .= '<a href="'.$link('notifications').'">'.$notif_label.'</a>';
	$html .= '<a href="'.$link('my_assignments').'">My Assignments</a>';
	$html .= '<a href="'.$link('profile').'">Profile</a>';
	$html .= '<a href="'.$link('update_profile').'">Update Profile</a>';
	$html .= '<a href="'. esc_url($logout_url) .'">Logout</a>';
	$html .= '</div></div>';

	$html .= '<div class="rd-main">';
	$html .= $msg;

	/* SECTION: Dashboard */
	if ( $section === 'dashboard' ) {

		$html .= '<h2>Dashboard</h2>';
		$html .= '<p>Role: <strong>'. esc_html($required_role) .'</strong></p>';
		$html .= '<p>Welcome to your dashboard.</p>';

		$counts = rd_get_assignment_status_counts( $user->ID, $required_role );
		$html  .= rd_assignment_summary_html( $required_role, $counts );

	/* SECTION: Notifications */
	} elseif ( $section === 'notifications' ) {

		$list = rd_get_user_notifications($user->ID);
		$list = array_reverse($list);

		$html .= '<h2>Notifications</h2>';

		if ( empty($list) ) {
			$html .= '<div class="notice">No notifications yet.</div>';
		} else {

			$html .= '<table class="rd-table">';
			$html .= '<thead><tr><th>Assignment</th><th>'. ( in_array('teacher',$roles,true) ? 'Student' : 'Teacher' ) .'</th><th>Assigned By</th><th>Date</th><th>Action</th></tr></thead><tbody>';

			foreach($list as $n){

				$aid = isset($n['assignment']) ? (int)$n['assignment'] : 0;
				if ( ! $aid ) continue;

				$p = get_post($aid);
				if ( ! $p || $p->post_type !== 'assignment' ) continue;

				$teacher_id = (int) get_post_meta($aid, 'teacher_user_id', true);
				$student_id = (int) get_post_meta($aid, 'student_user_id', true);

				$other_id = in_array('teacher',$roles,true) ? $student_id : $teacher_id;
				$other_u = $other_id ? get_user_by('id', $other_id) : null;
				$other_name = $other_u ? ($other_u->display_name ?: $other_u->user_login) : '—';

				$by_id = isset($n['assigned_by']) ? (int)$n['assigned_by'] : 0;
				$by_u = $by_id ? get_user_by('id', $by_id) : null;
				$by_name = $by_u ? ($by_u->display_name ?: $by_u->user_login) : 'System';

				$date = !empty($n['created']) ? date_i18n('d M Y, h:i A', (int)$n['created']) : '—';
				$is_read = ! empty($n['read']);

				$view_link = esc_url( add_query_arg(array('section'=>'my_assignments','assignment_id'=>$aid), $base_url) );

				$html .= '<tr>';
				$html .= '<td><a class="rd-btn" href="'.$view_link.'">'. esc_html(get_the_title($aid)) .'</a></td>';
				$html .= '<td>'. esc_html($other_name) .'</td>';
				$html .= '<td>'. esc_html($by_name) .'</td>';
				$html .= '<td>'. esc_html($date) .'</td>';

				if ( $is_read ) {
					$html .= '<td><span class="rd-badge" style="background:rgba(34,197,94,.10);border-color:rgba(34,197,94,.22);color:#dcfce7">Read</span></td>';
				} else {
					$nid = esc_attr($n['id']);
					$html .= '<td>
						<form method="post" style="margin:0;">
						  <input type="hidden" name="rd_action" value="mark_notification_read">
						  <input type="hidden" name="nid" value="'.$nid.'">
						  '. wp_nonce_field('rd_mark_notif_' . $nid, 'rd_notif_nonce', true, false) .'
						  <button type="submit">Mark as Read</button>
						</form>
					</td>';
				}

				$html .= '</tr>';
			}

			$html .= '</tbody></table>';
		}

	/* SECTION: My Assignments */
	} elseif ( $section === 'my_assignments' ) {

		$uid = (int) $user->ID;
		$view_id = isset($_GET['assignment_id']) ? absint($_GET['assignment_id']) : 0;

		if ( $view_id ) {

			$post = get_post($view_id);
			if ( ! $post || $post->post_type !== 'assignment' ) {
				$html .= '<div class="notice notice-error">Assignment not found.</div>';
			} else {

				$teacher_id = (int) get_post_meta($view_id, 'teacher_user_id', true);
				$student_id = (int) get_post_meta($view_id, 'student_user_id', true);

				if ( ! rd_user_can_access_assignment($view_id, $uid) ) {
					$html .= '<div class="notice notice-error">Access denied.</div>';
				} else {

					$status_key = (string) get_post_meta($view_id, 'track_status', true);

					$html .= '<a class="rd-btn" href="' . esc_url( $link('my_assignments') ) . '">← Back</a>';
					$html .= '<h2 style="margin-top:14px;">' . esc_html( get_the_title($view_id) ) . '</h2>';
					$html .= '<p><strong>Status:</strong> ' . sd_status_badge_html($status_key) . '</p>';

					if ( has_post_thumbnail($view_id) ) {
						$html .= '<div style="margin:12px 0;">' . get_the_post_thumbnail($view_id, 'large', array('style'=>'max-width:100%;height:auto;border-radius:16px;') ) . '</div>';
					}

					$html .= '<div style="margin-top:12px;">' . wpautop( wp_kses_post($post->post_content) ) . '</div>';

					// ✅ SHOW subscriber custom fields from assignment snapshot + post_meta values
					$html .= rd_render_assignment_custom_fields_from_snapshot($view_id);

					$cats = wp_get_object_terms($view_id, 'assignment_category', array('fields'=>'names'));
					$tags = wp_get_object_terms($view_id, 'assignment_tag', array('fields'=>'names'));
					$html .= '<div style="margin-top:14px;">';
					$html .= '<p><strong>Categories:</strong> ' . esc_html( $cats ? implode(', ', $cats) : '—' ) . '</p>';
					$html .= '<p><strong>Tags:</strong> ' . esc_html( $tags ? implode(', ', $tags) : '—' ) . '</p>';
					$html .= '</div>';

					// STATUS UPDATE
					$allowed_all = sd_assignment_status_options();
					$allowed_for_me = array();

					if ( in_array('student', $roles, true) && $student_id === $uid ) {
						$allowed_for_me = array('start_working','need_more_instructions','submitted_to_teacher');
					} elseif ( in_array('teacher', $roles, true) && $teacher_id === $uid ) {
						$allowed_for_me = array('approved','rejected');
					}

					if ( ! empty($allowed_for_me) ) {
						$html .= '<hr style="margin:18px 0;border:0;border-top:1px solid rgba(255,255,255,.12);">';
						$html .= '<h3>Update Status</h3>';
						$html .= '<form method="post">';
						$html .= '<input type="hidden" name="rd_action" value="update_assignment_status">';
						$html .= '<input type="hidden" name="assignment_id" value="' . esc_attr($view_id) . '">';
						$html .= wp_nonce_field('rd_update_status_' . $view_id, 'rd_status_nonce', true, false);

						$html .= '<p><label><strong>Status</strong></label><br>';
						$html .= '<select name="track_status" required>';
						foreach ( $allowed_for_me as $k ) {
							if ( ! isset($allowed_all[$k]) ) continue;
							$html .= '<option value="' . esc_attr($k) . '" ' . selected($status_key, $k, false) . '>' . esc_html($allowed_all[$k]) . '</option>';
						}
						$html .= '</select></p>';

						$html .= '<p><button type="submit">Save Status</button></p>';
						$html .= '</form>';
					}

					/* CHAT SECTION ✅ */
					$chat_comments = rd_get_assignment_chat_comments($view_id, 250);

					$html .= '<div class="rd-chat">';
					$html .= '<div class="rd-chat-head">';
					$html .= '<div class="rd-chat-title">Assignment Chat</div>';
					$html .= '<div style="color:rgba(245,247,255,.55);font-size:12px;">Only assigned teacher & student</div>';
					$html .= '</div>';

					$html .= '<div class="rd-chat-body" id="rd-chat-body">';
					if ( empty($chat_comments) ) {
						$html .= '<div class="notice" style="margin:0;">No messages yet. Start the conversation.</div>';
					} else {
						foreach ( $chat_comments as $c ) {
							$sender_id = (int) $c->user_id;
							$is_me = ( $sender_id === (int)$uid );
							$side = $is_me ? 'right' : 'left';

							$sender_name = $c->comment_author ? $c->comment_author : 'User';
							$time = mysql2date('d M Y, h:i A', $c->comment_date);

							$label = '';
							if ( $sender_id === $teacher_id ) $label = 'Teacher';
							elseif ( $sender_id === $student_id ) $label = 'Student';
							elseif ( user_can($sender_id, 'manage_options') ) $label = 'Admin';

							$html .= '<div class="rd-msg '.$side.'">';
							$html .= '<div class="rd-bubble">';
							$html .= '<div class="rd-meta"><span><strong>'. esc_html($sender_name) .'</strong></span>';
							if ( $label ) $html .= '<span style="opacity:.85">('. esc_html($label) .')</span>';
							$html .= '<span>'. esc_html($time) .'</span></div>';
							$html .= '<div class="rd-text">'. esc_html($c->comment_content) .'</div>';
							$html .= '</div></div>';
						}
					}
					$html .= '</div>';

					$ajax_nonce = wp_create_nonce('rd_send_chat_' . $view_id);
					$html .= '<div class="rd-chat-foot">';
					$html .= '<form method="post" class="rd-chat-form" data-assignment="'. esc_attr($view_id) .'" data-nonce="'. esc_attr($ajax_nonce) .'">';
					$html .= '<label style="display:block;margin-bottom:8px;font-weight:900;color:rgba(245,247,255,.72);font-size:12px;">Your message</label>';
					$html .= '<textarea name="chat_text" required placeholder="Type your message..."></textarea>';
					$html .= '<div class="rd-chat-actions"><button type="submit">Send</button></div>';
					$html .= '</form>';
					$html .= '</div>';

					$html .= '</div>';

					$html .= '<script>
					  (function(){
						var el = document.getElementById("rd-chat-body");
						if(el){ el.scrollTop = el.scrollHeight; }
					  })();

					  (function(){
						var form = document.querySelector(".rd-chat-form");
						var body = document.getElementById("rd-chat-body");
						if(!form || !body) return;

						function appendMsg(data){
						  var wrap = document.createElement("div");
						  wrap.className = "rd-msg " + (data.side || "right");
						  var bubble = document.createElement("div");
						  bubble.className = "rd-bubble";

						  var meta = document.createElement("div");
						  meta.className = "rd-meta";

						  var labelHtml = "";
						  if (data.label){
							labelHtml = "<span style=\\"opacity:.85\\">(" + (data.label || "") + ")</span>";
						  }

						  meta.innerHTML =
							"<span><strong>" + (data.author || "User") + "</strong></span>" +
							labelHtml +
							"<span>" + (data.time || "") + "</span>";

						  var text = document.createElement("div");
						  text.className = "rd-text";
						  text.textContent = data.content || "";

						  bubble.appendChild(meta);
						  bubble.appendChild(text);
						  wrap.appendChild(bubble);

						  var firstNotice = body.querySelector(".notice");
						  if(firstNotice && firstNotice.textContent && firstNotice.textContent.indexOf("No messages") !== -1){
							firstNotice.remove();
						  }

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

						  var aid = form.getAttribute("data-assignment");
						  var nonce = form.getAttribute("data-nonce");

						  var oldBtn = btn.textContent;
						  btn.disabled = true;
						  btn.textContent = "Sending...";

						  var fd = new FormData();
						  fd.append("action", "rd_send_assignment_chat");
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
						  .catch(function(){
							alert("Network error. Try again.");
						  })
						  .finally(function(){
							btn.disabled = false;
							btn.textContent = oldBtn;
						  });
						});
					  })();
					</script>';
				}
			}

		} else {

			$meta_key = in_array('teacher', $roles, true) ? 'teacher_user_id' : 'student_user_id';

			$q = new WP_Query(array(
				'post_type'      => 'assignment',
				'post_status'    => array('publish','private','draft','pending'),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => $meta_key,
						'value' => $uid,
						'compare' => '=',
						'type' => 'NUMERIC'
					)
				),
				'no_found_rows'  => true,
			));

			$html .= '<h2>My Assignments</h2>';

			if ( ! $q->have_posts() ) {
				$html .= '<div class="notice">No assignments found.</div>';
			} else {
				$html .= '<table class="rd-table">';
				$html .= '<thead><tr><th>Title</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>';

				while($q->have_posts()){
					$q->the_post();
					$aid = get_the_ID();
					$status_key = (string) get_post_meta($aid, 'track_status', true);
					$view_link = esc_url( add_query_arg(array('section'=>'my_assignments','assignment_id'=>$aid), $base_url) );

					$html .= '<tr>';
					$html .= '<td>' . esc_html(get_the_title()) . '</td>';
					$html .= '<td>' . esc_html(get_the_date()) . '</td>';
					$html .= '<td>' . sd_status_badge_html($status_key) . '</td>';
					$html .= '<td><a class="rd-btn" href="' . $view_link . '">View</a></td>';
					$html .= '</tr>';
				}

				wp_reset_postdata();

				$html .= '</tbody></table>';
				$html .= '<p style="margin-top:10px;color:rgba(245,247,255,.65);">Note: You can update status + chat here (no edit/delete).</p>';
			}
		}

	/* SECTION: Profile */
	} elseif ( $section === 'profile' ) {
		$html .= '<h2>Profile</h2>';
		$html .= '<table class="rd-table">';
		$html .= '<tr><td><strong>Username</strong></td><td>'. esc_html($user->user_login) .'</td></tr>';
		$html .= '<tr><td><strong>Name</strong></td><td>'. esc_html($user->display_name) .'</td></tr>';
		$html .= '<tr><td><strong>Email</strong></td><td>'. esc_html($user->user_email) .'</td></tr>';
		$html .= '<tr><td><strong>Role</strong></td><td>'. esc_html(implode(', ', $roles)) .'</td></tr>';
		$html .= '</table>';

	/* SECTION: Update Profile */
	} elseif ( $section === 'update_profile' ) {
		$html .= '<h2>Update Profile</h2>';
		$html .= '<form method="post">';
		$html .= '<p><label>Email</label><br><input type="email" name="email" value="'. esc_attr($user->user_email) .'"></p>';
		$html .= '<p><label>Username (login) - recommended NOT to change</label><br><input type="text" name="username" value="'. esc_attr($user->user_login) .'"></p>';
		$html .= '<p><label>New Password (min 6 chars)</label><br><input type="password" name="password" placeholder="Leave blank to keep current"></p>';
		$html .= '<input type="hidden" name="rd_action" value="update_profile">';
		$html .= wp_nonce_field('rd_update_profile', 'rd_nonce', true, false);
		$html .= '<p><button type="submit">Save Changes</button></p>';
		$html .= '</form>';

	} else {
		$html .= '<h2>Dashboard</h2><p>Invalid section.</p>';
	}

	$html .= '</div></div>';
	return $html;
});


/**
 * ============================================================
 * Force ALL users to custom login page after logout
 * ============================================================
 */
add_filter('logout_redirect', function($redirect_to, $requested_redirect_to, $user){
	return site_url('/login/');
}, 10, 3);

if ( ! function_exists('rd_prune_user_notifications') ) {
	function rd_prune_user_notifications( $user_id ) {

		$list = get_user_meta((int)$user_id, 'rd_notifications', true);
		$list = is_array($list) ? $list : array();

		if ( empty($list) ) return;

		$seen = array();
		$clean = array();

		foreach ( $list as $n ) {

			if ( ! is_array($n) ) continue;

			$aid = isset($n['assignment']) ? (int)$n['assignment'] : 0;
			if ( ! $aid ) continue;

			// ✅ remove if assignment deleted or not assignment post type
			$p = get_post($aid);
			if ( ! $p || $p->post_type !== 'assignment' ) {
				continue;
			}

			// ✅ de-duplication (same assignment + same timestamp + same assigned_by)
			$key = $aid . '|' . (isset($n['created']) ? (int)$n['created'] : 0) . '|' . (isset($n['assigned_by']) ? (int)$n['assigned_by'] : 0);
			if ( isset($seen[$key]) ) continue;
			$seen[$key] = 1;

			$clean[] = $n;
		}

		update_user_meta((int)$user_id, 'rd_notifications', array_values($clean));
	}
}


if ( ! defined('ABSPATH') ) exit;

/**
 * ============================================================
 * FINAL FIX: Frontend Add User -> ONLY Teacher/Student + Always show in table
 * - Forces role teacher/student even if form sends subscriber or nothing
 * - Ensures sd_created_by meta is saved (so your table finds it)
 * ============================================================
 */

/** 1) Force allowed roles ONLY */
add_filter('wp_pre_insert_user_data', function($data, $update, $user_id, $userdata){

	if ( is_admin() ) return $data;

	// Only target your frontend create user submit (sd_action=create_user)
	$action = isset($_POST['sd_action']) ? sanitize_text_field(wp_unslash($_POST['sd_action'])) : '';
	if ( $action !== 'create_user' ) return $data;

	$allow = array('teacher','student');

	$form_role = isset($_POST['sd_role']) ? sanitize_text_field(wp_unslash($_POST['sd_role'])) : '';
	if ( ! in_array($form_role, $allow, true) ) $form_role = 'student';

	$data['role'] = $form_role;

	return $data;

}, 10, 4);


/** 2) After user created, save "created_by" meta so table can show it */
add_action('user_register', function($new_user_id){

	if ( is_admin() ) return;

	$action = isset($_POST['sd_action']) ? sanitize_text_field(wp_unslash($_POST['sd_action'])) : '';
	if ( $action !== 'create_user' ) return;

	$creator = get_current_user_id();
	if ( $creator ) {
		update_user_meta((int)$new_user_id, 'sd_created_by', (int)$creator);
	}

}, 10, 1);


/** 3) OPTIONAL: Frontend role dropdown ONLY teacher/student (even if wp_roles used) */
add_filter('editable_roles', function($roles){

	if ( is_admin() ) return $roles;

	$allow = array('teacher','student');

	foreach ( array_keys($roles) as $rk ) {
		if ( ! in_array($rk, $allow, true) ) {
			unset($roles[$rk]);
		}
	}

	return $roles;

}, 10, 1);

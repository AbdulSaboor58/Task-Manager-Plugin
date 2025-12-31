<?php
/**
 * SNIPPET 3 — Assignments CPT + Taxonomies + Meta Fields + Notifications Engine ✅
 * - Teacher dropdown (teacher_user_id)
 * - Student dropdown (student_user_id)
 * - Track Status dropdown (track_status)
 * - Admin columns (Teacher/Student/Status)
 * - ✅ NEW: Notifications when teacher/student assigned (works for admin + frontend meta updates)
 * - ✅ NEW: HARD VALIDATION => teacher_user_id can ONLY be teacher, student_user_id can ONLY be student
 */

if ( ! defined('ABSPATH') ) exit;

/* ============================================================
 * 1) REGISTER CPT: assignment
 * ============================================================ */
add_action('init', function () {

    $labels = array(
        'name'               => 'Assignments',
        'singular_name'      => 'Assignment',
        'menu_name'          => 'Assignments',
        'name_admin_bar'     => 'Assignment',
        'add_new'            => 'Add New',
        'add_new_item'       => 'Add New Assignment',
        'new_item'           => 'New Assignment',
        'edit_item'          => 'Edit Assignment',
        'view_item'          => 'View Assignment',
        'all_items'          => 'All Assignments',
        'search_items'       => 'Search Assignments',
        'not_found'          => 'No assignments found.',
        'not_found_in_trash' => 'No assignments found in Trash.',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'exclude_from_search'=> true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_admin_bar'  => true,
        'show_in_rest'       => true,
        'menu_position'      => 25,
        'menu_icon'          => 'dashicons-welcome-write-blog',
        'supports'           => array('title', 'editor', 'thumbnail', 'excerpt', 'revisions'),
        'has_archive'        => false,
        'rewrite'            => array('slug' => 'assignment'),
        'capability_type'    => 'post',
        'map_meta_cap'       => true,
    );

    register_post_type('assignment', $args);

}, 0);

/* ============================================================
 * 2) REGISTER TAXONOMIES
 * ============================================================ */
add_action('init', function () {

    register_taxonomy('assignment_category', array('assignment'), array(
        'labels' => array(
            'name'          => 'Assignment Categories',
            'singular_name' => 'Assignment Category',
            'menu_name'     => 'Assignment Categories',
        ),
        'hierarchical'      => true,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => array('slug' => 'assignment-category'),
    ));

    register_taxonomy('assignment_tag', array('assignment'), array(
        'labels' => array(
            'name'          => 'Assignment Tags',
            'singular_name' => 'Assignment Tag',
            'menu_name'     => 'Assignment Tags',
        ),
        'hierarchical'      => false,
        'show_ui'           => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => array('slug' => 'assignment-tag'),
    ));

}, 0);

/* ============================================================
 * 3) STATUS OPTIONS
 * ============================================================ */
if ( ! function_exists('sd_assignment_status_options') ) {
    function sd_assignment_status_options() {
        return array(
            'start_working'         => 'Start Working',
            'need_more_instructions'=> 'Need More Instructions',
            'submitted_to_teacher'  => 'Submitted To Teacher',
            'approved'              => 'Approved',
            'rejected'              => 'Rejected',
            'assign_to_student'     => 'Assign To Student',
        );
    }
}

/* ============================================================
 * ✅ 3.2) HARD VALIDATION: ONLY teacher/student IDs can be stored
 * - Blocks wrong IDs coming from FRONT-END code
 * ============================================================ */
if ( ! function_exists('rd_user_has_role') ) {
    function rd_user_has_role( $user_id, $role_slug ) {
        $u = get_user_by('id', (int)$user_id);
        if ( ! $u ) return false;
        return in_array($role_slug, (array) $u->roles, true);
    }
}

if ( ! function_exists('rd_is_assignment_post') ) {
    function rd_is_assignment_post( $post_id ) {
        $p = get_post((int)$post_id);
        return ( $p && $p->post_type === 'assignment' );
    }
}

if ( ! function_exists('rd_validate_assignee_meta_value') ) {
    function rd_validate_assignee_meta_value( $meta_key, $meta_value ) {
        $uid = (int) $meta_value;
        if ( $uid <= 0 ) return 0; // allow clearing

        if ( $meta_key === 'teacher_user_id' ) {
            return rd_user_has_role($uid, 'teacher') ? $uid : -1;
        }
        if ( $meta_key === 'student_user_id' ) {
            return rd_user_has_role($uid, 'student') ? $uid : -1;
        }
        return $uid;
    }
}

/**
 * ✅ Block invalid meta updates (update_post_meta / add_post_meta)
 * Note: returning true here short-circuits and prevents DB write.
 */
add_filter('update_post_metadata', function($check, $object_id, $meta_key, $meta_value, $prev_value){

    if ( $meta_key !== 'teacher_user_id' && $meta_key !== 'student_user_id' ) return $check;
    if ( ! rd_is_assignment_post($object_id) ) return $check;

    $validated = rd_validate_assignee_meta_value($meta_key, $meta_value);

    // -1 means invalid user role => block update
    if ( $validated === -1 ) {
        return true; // stop update
    }

    // if validated value differs, force correct value
    if ( (int)$validated !== (int)$meta_value ) {
        // prevent recursion loop by allowing normal update to proceed with corrected value:
        // we do it by short-circuiting current update and doing our own safe update.
        remove_filter('update_post_metadata', __FUNCTION__, 10);
        update_post_meta((int)$object_id, $meta_key, (int)$validated);
        add_filter('update_post_metadata', __FUNCTION__, 10, 5);
        return true;
    }

    return $check;
}, 10, 5);

add_filter('add_post_metadata', function($check, $object_id, $meta_key, $meta_value, $unique){

    if ( $meta_key !== 'teacher_user_id' && $meta_key !== 'student_user_id' ) return $check;
    if ( ! rd_is_assignment_post($object_id) ) return $check;

    $validated = rd_validate_assignee_meta_value($meta_key, $meta_value);

    if ( $validated === -1 ) {
        return true; // stop add
    }

    if ( (int)$validated !== (int)$meta_value ) {
        // force corrected value
        remove_filter('add_post_metadata', __FUNCTION__, 10);
        add_post_meta((int)$object_id, $meta_key, (int)$validated, (bool)$unique);
        add_filter('add_post_metadata', __FUNCTION__, 10, 5);
        return true;
    }

    return $check;
}, 10, 5);


/* ============================================================
 * ✅ 3.1) NOTIFICATIONS ENGINE (User Meta)
 * ============================================================ */
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

if ( ! function_exists('rd_add_user_notification') ) {
    function rd_add_user_notification( $to_user_id, $assignment_id, $assigned_by_user_id = 0 ) {
        $to_user_id = (int)$to_user_id;
        $assignment_id = (int)$assignment_id;
        $assigned_by_user_id = (int)$assigned_by_user_id;

        if ( $to_user_id <= 0 || $assignment_id <= 0 ) return false;

        $list = rd_get_user_notifications($to_user_id);

        $nid = 'n_' . wp_generate_uuid4();
        $list[] = array(
            'id'          => $nid,
            'assignment'  => $assignment_id,
            'assigned_by' => $assigned_by_user_id,
            'created'     => (int) current_time('timestamp'),
            'read'        => 0,
        );

        if ( count($list) > 300 ) {
            $list = array_slice($list, -300);
        }

        rd_save_user_notifications($to_user_id, $list);
        return $nid;
    }
}

if ( ! function_exists('rd_notify_on_assignment_assign') ) {
    function rd_notify_on_assignment_assign( $assignment_id, $assigned_by_user_id = 0 ) {
        $assignment_id = (int)$assignment_id;
        if ( $assignment_id <= 0 ) return;

        $post = get_post($assignment_id);
        if ( ! $post || $post->post_type !== 'assignment' ) return;

        $teacher_id = (int) get_post_meta($assignment_id, 'teacher_user_id', true);
        $student_id = (int) get_post_meta($assignment_id, 'student_user_id', true);

        $last_t = (int) get_post_meta($assignment_id, 'rd_last_notified_teacher_id', true);
        $last_s = (int) get_post_meta($assignment_id, 'rd_last_notified_student_id', true);

        if ( $teacher_id > 0 && $teacher_id !== $last_t ) {
            rd_add_user_notification($teacher_id, $assignment_id, $assigned_by_user_id);
            update_post_meta($assignment_id, 'rd_last_notified_teacher_id', $teacher_id);
        }

        if ( $student_id > 0 && $student_id !== $last_s ) {
            rd_add_user_notification($student_id, $assignment_id, $assigned_by_user_id);
            update_post_meta($assignment_id, 'rd_last_notified_student_id', $student_id);
        }
    }
}

/* Catch assignments created/updated from ADMIN metabox save */
add_action('save_post_assignment', function ($post_id) {

    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    rd_notify_on_assignment_assign($post_id, get_current_user_id());

}, 50, 1);

/* Catch assignments where meta is set/updated from FRONT-END code */
add_action('updated_post_meta', function($meta_id, $object_id, $meta_key, $meta_value){
    if ( $meta_key !== 'teacher_user_id' && $meta_key !== 'student_user_id' ) return;
    $p = get_post((int)$object_id);
    if ( ! $p || $p->post_type !== 'assignment' ) return;

    rd_notify_on_assignment_assign((int)$object_id, get_current_user_id());
}, 10, 4);

add_action('added_post_meta', function($meta_id, $object_id, $meta_key, $meta_value){
    if ( $meta_key !== 'teacher_user_id' && $meta_key !== 'student_user_id' ) return;
    $p = get_post((int)$object_id);
    if ( ! $p || $p->post_type !== 'assignment' ) return;

    rd_notify_on_assignment_assign((int)$object_id, get_current_user_id());
}, 10, 4);


/* ============================================================
 * 4) META BOX: Teacher + Student + Track Status
 * ============================================================ */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'assignment_assignee_meta',
        'Assign To (Teacher / Student) + Track Status',
        'assignment_assignee_metabox_cb',
        'assignment',
        'side',
        'default'
    );
});

function assignment_assignee_metabox_cb($post) {
    wp_nonce_field('assignment_assignee_save', 'assignment_assignee_nonce');

    $teacher_id = (int) get_post_meta($post->ID, 'teacher_user_id', true);
    $student_id = (int) get_post_meta($post->ID, 'student_user_id', true);
    $status     = (string) get_post_meta($post->ID, 'track_status', true);

    // ✅ Only teacher role
    $teachers = get_users(array(
        'role'    => 'teacher',
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID', 'display_name', 'user_login'),
        'number'  => 999,
    ));

    // ✅ Only student role
    $students = get_users(array(
        'role'    => 'student',
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID', 'display_name', 'user_login'),
        'number'  => 999,
    ));

    $status_options = sd_assignment_status_options();

    echo '<p style="margin:8px 0 4px;"><strong>Teacher</strong></p>';
    echo '<select name="teacher_user_id" style="width:100%;">';
    echo '<option value="">— Select Teacher —</option>';
    foreach ($teachers as $u) {
        $name = $u->display_name ? $u->display_name : $u->user_login;
        printf(
            '<option value="%d" %s>%s</option>',
            (int) $u->ID,
            selected($teacher_id, (int) $u->ID, false),
            esc_html($name)
        );
    }
    echo '</select>';

    echo '<p style="margin:12px 0 4px;"><strong>Student</strong></p>';
    echo '<select name="student_user_id" style="width:100%;">';
    echo '<option value="">— Select Student —</option>';
    foreach ($students as $u) {
        $name = $u->display_name ? $u->display_name : $u->user_login;
        printf(
            '<option value="%d" %s>%s</option>',
            (int) $u->ID,
            selected($student_id, (int) $u->ID, false),
            esc_html($name)
        );
    }
    echo '</select>';

    echo '<p style="margin:12px 0 4px;"><strong>Track Status</strong></p>';
    echo '<select name="track_status" style="width:100%;">';
    echo '<option value="">— Select Status —</option>';
    foreach ( $status_options as $key => $label ) {
        printf(
            '<option value="%s" %s>%s</option>',
            esc_attr($key),
            selected($status, (string)$key, false),
            esc_html($label)
        );
    }
    echo '</select>';

    echo '<p style="margin-top:10px;color:#666;font-size:12px;">';
    echo 'Status values are controlled for dashboards (student/teacher).';
    echo '</p>';
}

/* ============================================================
 * 5) SAVE META (secure + validation)
 * ============================================================ */
add_action('save_post_assignment', function ($post_id) {

    if ( ! isset($_POST['assignment_assignee_nonce']) || ! wp_verify_nonce($_POST['assignment_assignee_nonce'], 'assignment_assignee_save') ) {
        return;
    }

    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( wp_is_post_revision($post_id) ) return;
    if ( ! current_user_can('edit_post', $post_id) ) return;

    // Teacher
    $teacher_id = isset($_POST['teacher_user_id']) ? (int) $_POST['teacher_user_id'] : 0;
    if ($teacher_id > 0) {
        $u = get_user_by('id', $teacher_id);
        if ($u && in_array('teacher', (array) $u->roles, true)) {
            update_post_meta($post_id, 'teacher_user_id', $teacher_id);
        } else {
            delete_post_meta($post_id, 'teacher_user_id');
        }
    } else {
        delete_post_meta($post_id, 'teacher_user_id');
    }

    // Student
    $student_id = isset($_POST['student_user_id']) ? (int) $_POST['student_user_id'] : 0;
    if ($student_id > 0) {
        $u = get_user_by('id', $student_id);
        if ($u && in_array('student', (array) $u->roles, true)) {
            update_post_meta($post_id, 'student_user_id', $student_id);
        } else {
            delete_post_meta($post_id, 'student_user_id');
        }
    } else {
        delete_post_meta($post_id, 'student_user_id');
    }

    // Track Status
    $allowed = sd_assignment_status_options();
    $track_status = isset($_POST['track_status']) ? sanitize_text_field( wp_unslash($_POST['track_status']) ) : '';

    if ( $track_status === '' ) {
        delete_post_meta($post_id, 'track_status');
    } else {
        if ( isset($allowed[$track_status]) ) {
            update_post_meta($post_id, 'track_status', $track_status);
        } else {
            delete_post_meta($post_id, 'track_status');
        }
    }

}, 10, 1);

/* ============================================================
 * 6) ADMIN LIST COLUMNS: Teacher / Student / Status
 * ============================================================ */
add_filter('manage_assignment_posts_columns', function ($columns) {
    $new = array();
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ($key === 'title') {
            $new['assigned_teacher'] = 'Teacher';
            $new['assigned_student'] = 'Student';
            $new['track_status']     = 'Status';
        }
    }
    return $new;
});

add_action('manage_assignment_posts_custom_column', function ($column, $post_id) {

    if ($column === 'assigned_teacher') {
        $teacher_id = (int) get_post_meta($post_id, 'teacher_user_id', true);
        if ($teacher_id) {
            $u = get_user_by('id', $teacher_id);
            echo $u ? esc_html($u->display_name ?: $u->user_login) : '—';
        } else {
            echo '—';
        }
    }

    if ($column === 'assigned_student') {
        $student_id = (int) get_post_meta($post_id, 'student_user_id', true);
        if ($student_id) {
            $u = get_user_by('id', $student_id);
            echo $u ? esc_html($u->display_name ?: $u->user_login) : '—';
        } else {
            echo '—';
        }
    }

    if ($column === 'track_status') {
        $status = (string) get_post_meta($post_id, 'track_status', true);
        $map = sd_assignment_status_options();
        echo $status && isset($map[$status]) ? esc_html($map[$status]) : '—';
    }

}, 10, 2);

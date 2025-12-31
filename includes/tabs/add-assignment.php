<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * ============================================================
 * TAB: ADD ASSIGNMENT (Handler + Shortcode UI)
 * ✅ Vanilla JS Form Builder (NO AJAX, NO jQuery required)
 * ✅ Schema saved on submit:
 *    - user_meta:  sd_assignment_custom_fields_schema
 *    - post_meta:  _sd_cf_schema (array) + _sd_cf_schema_json (json)
 * ✅ Values saved in post_meta table:
 *    - meta_key = custom field key
 * ============================================================
 */

if ( ! defined('SD_CF_SCHEMA_KEY') ) {
	define('SD_CF_SCHEMA_KEY', 'sd_assignment_custom_fields_schema');
}

/* ============================================================
 * ✅ HELPERS
 * ============================================================ */
if ( ! function_exists('sd_cf_normalize_schema') ) {
	function sd_cf_normalize_schema($schema) {
		if ( ! is_array($schema) ) return array();

		$allowed_types = array('text','textarea','number','email','date','select','radio','checkbox','checkbox_group');

		$out = array();
		$seen_keys = array();

		foreach ($schema as $f) {
			if ( ! is_array($f) ) continue;

			$id    = isset($f['id']) ? sanitize_text_field($f['id']) : '';
			$label = isset($f['label']) ? sanitize_text_field($f['label']) : '';
			$key   = isset($f['key']) ? sanitize_key($f['key']) : '';
			$type  = isset($f['type']) ? sanitize_key($f['type']) : 'text';

			$required    = ! empty($f['required']) ? 1 : 0;
			$placeholder = isset($f['placeholder']) ? sanitize_text_field($f['placeholder']) : '';

			$options = array();
			if ( isset($f['options']) ) {
				if ( is_array($f['options']) ) {
					$options = array_values(array_filter(array_map('sanitize_text_field', $f['options'])));
				} else {
					$parts = array_map('trim', explode(',', (string) $f['options']));
					$options = array_values(array_filter(array_map('sanitize_text_field', $parts)));
				}
			}

			if ( $label === '' || $key === '' ) continue;
			if ( ! in_array($type, $allowed_types, true) ) $type = 'text';

			// prevent collisions with existing admin field names
			$blocked = array(
				'sd_action','sd_assignment_nonce','sd_assignment_id',
				'sd_a_title','sd_a_content','sd_a_teacher','sd_a_student',
				'sd_a_status','sd_a_categories','sd_a_tags','sd_a_featured_image',
				'sd_cf','sd_cf_schema_json'
			);
			if ( in_array($key, $blocked, true) ) continue;

			// unique key
			if ( isset($seen_keys[$key]) ) continue;
			$seen_keys[$key] = true;

			if ( $id === '' ) $id = 'cf_' . wp_generate_password(8, false, false);

			$out[] = array(
				'id'          => $id,
				'label'       => $label,
				'key'         => $key,
				'type'        => $type,
				'required'    => $required,
				'placeholder' => $placeholder,
				'options'     => $options,
			);
		}

		return $out;
	}
}

if ( ! function_exists('sd_cf_get_schema') ) {
	function sd_cf_get_schema($user_id) {
		$schema = get_user_meta((int)$user_id, SD_CF_SCHEMA_KEY, true);
		return is_array($schema) ? $schema : array();
	}
}

if ( ! function_exists('sd_cf_sanitize_value') ) {
	function sd_cf_sanitize_value($type, $val) {
		switch ($type) {
			case 'email':
				return sanitize_email((string)$val);

			case 'number':
				$v = is_array($val) ? '' : (string)$val;
				return preg_replace('/[^0-9\.\-]/', '', $v);

			case 'date':
				return sanitize_text_field((string)$val);

			case 'textarea':
				return sanitize_textarea_field((string)$val);

			case 'checkbox':
				return ! empty($val) ? '1' : '0';

			case 'checkbox_group':
				if ( ! is_array($val) ) return array();
				return array_values(array_filter(array_map('sanitize_text_field', $val)));

			case 'select':
			case 'radio':
			case 'text':
			default:
				return sanitize_text_field(is_array($val) ? '' : (string)$val);
		}
	}
}

/* ============================================================
 * ✅ SUBMIT HANDLER: create/update + save schema + save values
 * ============================================================ */
if ( ! function_exists('sd_maybe_handle_assignment_submit') ) {
	function sd_maybe_handle_assignment_submit() {

		if ( ! is_user_logged_in() ) return;
		if ( empty($_POST['sd_action']) ) return;

		if ( ! function_exists('sd_current_user_can_access_dashboard') || ! sd_current_user_can_access_dashboard() ) {
			wp_die('Access denied.');
		}

		$action = sanitize_text_field( wp_unslash($_POST['sd_action']) );
		if ( ! in_array($action, array('create_assignment','update_assignment'), true) ) return;

		$nonce = isset($_POST['sd_assignment_nonce']) ? sanitize_text_field( wp_unslash($_POST['sd_assignment_nonce']) ) : '';
		if ( ! wp_verify_nonce($nonce, 'sd_assignment_form') ) wp_die('Security check failed.');

		$title   = isset($_POST['sd_a_title']) ? sanitize_text_field( wp_unslash($_POST['sd_a_title']) ) : '';
		$content = isset($_POST['sd_a_content']) ? wp_kses_post( wp_unslash($_POST['sd_a_content']) ) : '';

		$teacher_id = isset($_POST['sd_a_teacher']) ? absint($_POST['sd_a_teacher']) : 0;
		$student_id = isset($_POST['sd_a_student']) ? absint($_POST['sd_a_student']) : 0;

		$status_key = isset($_POST['sd_a_status']) ? sanitize_text_field( wp_unslash($_POST['sd_a_status']) ) : '';
		$allowed_status = function_exists('sd_assignment_status_options') ? sd_assignment_status_options() : array();
		if ( $status_key !== '' && ! isset($allowed_status[$status_key]) ) $status_key = '';

		$cat_ids = isset($_POST['sd_a_categories']) ? (array) $_POST['sd_a_categories'] : array();
		$cat_ids = array_values(array_filter(array_map('absint', $cat_ids)));

		$tags_raw = isset($_POST['sd_a_tags']) ? sanitize_text_field( wp_unslash($_POST['sd_a_tags']) ) : '';

		if ( $title === '' || $content === '' ) {
			$redirect = wp_get_referer() ? wp_get_referer() : home_url('/');
			wp_safe_redirect( add_query_arg('sd_msg','assignment_error', $redirect) );
			exit;
		}

		$user_id = get_current_user_id();

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => 'assignment',
			'post_author'  => $user_id,
		);

		if ( $action === 'create_assignment' ) {
			$assignment_id = wp_insert_post($postarr, true);
		} else {
			$edit_id = isset($_POST['sd_assignment_id']) ? absint($_POST['sd_assignment_id']) : 0;
			$post = $edit_id ? get_post($edit_id) : null;

			if ( ! $post || $post->post_type !== 'assignment' ) wp_die('Invalid assignment.');
			if ( (int) $post->post_author !== (int) $user_id ) wp_die('You can only edit your own assignments.');

			$postarr['ID'] = $edit_id;
			$assignment_id = wp_update_post($postarr, true);
		}

		if ( is_wp_error($assignment_id) || ! $assignment_id ) {
			$redirect = wp_get_referer() ? wp_get_referer() : home_url('/');
			wp_safe_redirect( add_query_arg('sd_msg','assignment_error', $redirect) );
			exit;
		}

		// featured image
		if ( ! empty($_FILES['sd_a_featured_image']['name']) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_id = media_handle_upload('sd_a_featured_image', $assignment_id);
			if ( ! is_wp_error($attachment_id) ) set_post_thumbnail($assignment_id, $attachment_id);
		}

		// core meta
		update_post_meta($assignment_id, 'teacher_user_id', $teacher_id);
		update_post_meta($assignment_id, 'student_user_id', $student_id);

		if ( $status_key === '' ) delete_post_meta($assignment_id, 'track_status');
		else update_post_meta($assignment_id, 'track_status', $status_key);

		// taxonomies
		wp_set_object_terms($assignment_id, $cat_ids, 'assignment_category', false);

		$tags = array();
		if ( $tags_raw !== '' ) {
			$parts = array_map('trim', explode(',', $tags_raw));
			$tags  = array_values(array_filter($parts));
		}
		wp_set_object_terms($assignment_id, $tags, 'assignment_tag', false);

		/**
		 * ✅ SCHEMA SAVE (NO AJAX)
		 * hidden input: sd_cf_schema_json
		 */
		$schema_json = isset($_POST['sd_cf_schema_json']) ? wp_unslash($_POST['sd_cf_schema_json']) : '';
		$schema_arr  = array();

		if ( is_string($schema_json) && trim($schema_json) !== '' ) {
			$schema_arr = json_decode($schema_json, true);
			if ( json_last_error() !== JSON_ERROR_NONE ) $schema_arr = array();
		}

		// if still empty, fallback to user_meta
		$schema = ! empty($schema_arr) ? sd_cf_normalize_schema($schema_arr) : sd_cf_normalize_schema( sd_cf_get_schema($user_id) );

		// ✅ save schema in BOTH places
		update_user_meta($user_id, SD_CF_SCHEMA_KEY, $schema);
		update_post_meta($assignment_id, '_sd_cf_schema', $schema);
		update_post_meta($assignment_id, '_sd_cf_schema_json', wp_json_encode($schema));

		/**
		 * ✅ SAVE VALUES (post_meta table)
		 */
		$posted_cf = isset($_POST['sd_cf']) ? (array) $_POST['sd_cf'] : array();

		if ( ! empty($schema) ) {
			foreach ($schema as $f) {
				if ( empty($f['key']) || empty($f['type']) ) continue;

				$key  = sanitize_key($f['key']);
				$type = sanitize_key($f['type']);

				// handle unchecked checkbox (not posted)
				if ( $type === 'checkbox' && ! isset($posted_cf[$key]) ) {
					$val = '0';
				} else {
					$val = isset($posted_cf[$key]) ? wp_unslash($posted_cf[$key]) : '';
				}

				$clean = sd_cf_sanitize_value($type, $val);
				update_post_meta($assignment_id, $key, $clean);
			}
		}

		$redirect = wp_get_referer() ? wp_get_referer() : home_url('/');
		$redirect = add_query_arg('sd_msg', ($action === 'create_assignment' ? 'assignment_created' : 'assignment_updated'), $redirect);
		wp_safe_redirect($redirect);
		exit;
	}
	add_action('template_redirect', 'sd_maybe_handle_assignment_submit');
}

/* ============================================================
 * ✅ SHORTCODE: Assignment Form (UI + Vanilla Builder)
 * ============================================================ */
add_action('init', function () {

	if ( shortcode_exists('sd_assignment_form') ) {
		remove_shortcode('sd_assignment_form');
	}

	add_shortcode('sd_assignment_form', function($atts=array()){

		if ( function_exists('sd_require_login_and_subscriber') ) {
			$guard = sd_require_login_and_subscriber();
			if ( $guard ) return $guard;
		}

		$atts = shortcode_atts(array('mode'=>'create'), $atts, 'sd_assignment_form');
		$mode = $atts['mode'];

		$user_id = get_current_user_id();
		$edit_id = 0;
		$edit_post = null;

		if ( $mode === 'edit' ) {
			$edit_id = isset($_GET['assignment_id']) ? absint($_GET['assignment_id']) : 0;
			$edit_post = $edit_id ? get_post($edit_id) : null;
			if ( ! $edit_post || $edit_post->post_type !== 'assignment' || (int)$edit_post->post_author !== (int)$user_id ) {
				return '<div class="sd-notice sd-notice-error">Invalid assignment for editing.</div>';
			}
		}

		$title_val   = $edit_post ? $edit_post->post_title : '';
		$content_val = $edit_post ? $edit_post->post_content : '';

		$teacher_val = $edit_post ? (int)get_post_meta($edit_id,'teacher_user_id',true) : 0;
		$student_val = $edit_post ? (int)get_post_meta($edit_id,'student_user_id',true) : 0;
		$status_val  = $edit_post ? (string)get_post_meta($edit_id,'track_status',true) : 'assign_to_student';

		$cats = get_terms(array('taxonomy'=>'assignment_category','hide_empty'=>false));
		$selected_cats = $edit_post ? wp_get_object_terms($edit_id,'assignment_category',array('fields'=>'ids')) : array();

		$tags = $edit_post ? wp_get_object_terms($edit_id,'assignment_tag',array('fields'=>'names')) : array();
		$tags_val = $tags ? implode(', ', $tags) : '';

		$teachers = get_users(array('role'=>'teacher','orderby'=>'display_name','order'=>'ASC'));
		$students = get_users(array('role'=>'student','orderby'=>'display_name','order'=>'ASC'));

		$status_options = function_exists('sd_assignment_status_options') ? sd_assignment_status_options() : array();

		// ✅ schema source
		$schema = sd_cf_get_schema($user_id);
		if ( $mode === 'edit' && $edit_id ) {
			$snap = get_post_meta($edit_id, '_sd_cf_schema', true);
			if ( is_array($snap) && ! empty($snap) ) $schema = $snap;
		}
		$schema = sd_cf_normalize_schema($schema);

		// ✅ values (edit mode prefill)
		$values = array();
		if ( $mode === 'edit' && $edit_id && ! empty($schema) ) {
			foreach($schema as $f){
				if ( empty($f['key']) ) continue;
				$k = sanitize_key($f['key']);
				$values[$k] = get_post_meta($edit_id, $k, true);
			}
		}

		// ✅ CSS
$out = '<style>
.sd-add-assignment-layout{display:flex;gap:16px;align-items:flex-start;margin-top:14px}
.sd-assignment-col2{flex:1 1 auto;min-width:0}
.sd-assignment-col3{width:30%;min-width:280px;background:#111;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px;position:sticky;top:16px}
.sd-builder-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
.sd-builder-head h3{margin:0;font-size:16px;color:#fff}
.sd-builder-body label{display:block;margin-top:10px;color:#ddd;font-size:13px}
.sd-builder-body input[type=text],.sd-builder-body select{width:100%;margin-top:6px;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.12);background:#0b0b0b;color:#fff;outline:none}
.sd-hint{display:block;margin-top:6px;font-size:12px;color:rgba(255,255,255,.6)}
.sd-inline{display:flex!important;align-items:center;gap:8px;margin-top:10px}
.sd-builder-actions{display:flex;gap:10px;margin-top:12px}

.sd-custom-fields-area{margin-top:14px;border-top:1px dashed rgba(255,255,255,.12);padding-top:12px}
.sd-cf-empty{padding:10px;border:1px dashed rgba(255,255,255,.18);border-radius:10px;opacity:.9;color:#e8eef2}

/* ✅ FIELD CARD BG + TEXT */
.sd-cf-field{
  padding:12px;
  border:1px solid rgba(138,173,192,.25);
  border-radius:12px;
  margin-bottom:10px;
  background:#0E101A;
  color:#e8eef2;
}

.sd-cf-top{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px}

/* ✅ HEADING COLOR */
.sd-cf-label{font-weight:700;color:#8AADC0}

/* actions */
.sd-cf-actions{display:flex;gap:8px}
.sd-cf-actions .sd-btn{
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.14);
  color:#e8eef2;
}

/* ✅ INPUTS ON DARK BG */
.sd-cf-input input[type=text],
.sd-cf-input input[type=number],
.sd-cf-input input[type=email],
.sd-cf-input input[type=date],
.sd-cf-input select,
.sd-cf-input textarea{
  width:100%;
  margin-top:6px;
  background:#0b0d14;
  border:1px solid rgba(138,173,192,.25);
  color:#e8eef2;
  border-radius:10px;
  padding:10px;
  outline:none;
}

.sd-cf-input input::placeholder,
.sd-cf-input textarea::placeholder{color:rgba(232,238,242,.55)}

.sd-btn-sm{padding:6px 10px;font-size:12px}
.sd-btn-xs{padding:4px 8px;font-size:12px}
.sd-btn-ghost{background:transparent;border:1px solid rgba(255,255,255,.18);color:#fff}
.sd-btn-danger{background:#b00020;border-color:#b00020;color:#fff}

.sd-opt-wrap{display:flex;flex-direction:column;gap:6px;margin-top:8px;color:#e8eef2}
.sd-opt{display:flex;align-items:center;gap:8px;color:#e8eef2}

@media (max-width:900px){
  .sd-add-assignment-layout{flex-direction:column}
  .sd-assignment-col3{width:100%;position:relative;top:auto}
}
</style>';


		$out .= '<form class="sd-form" method="post" enctype="multipart/form-data">';
		$out .= '<input type="hidden" name="sd_action" value="' . esc_attr($mode==='edit' ? 'update_assignment' : 'create_assignment') . '">';
		$out .= '<input type="hidden" name="sd_assignment_nonce" value="' . esc_attr(wp_create_nonce('sd_assignment_form')) . '">';
		if ( $mode === 'edit' ) $out .= '<input type="hidden" name="sd_assignment_id" value="' . esc_attr($edit_id) . '">';

		// ✅ schema hidden (THIS is what saves schema on submit)
		$out .= '<input type="hidden" name="sd_cf_schema_json" id="sd_cf_schema_json" value="">';

		$out .= '<div class="sd-add-assignment-layout">';
		$out .= '<div class="sd-assignment-col2">';

		$out .= '<label>Title</label><input type="text" name="sd_a_title" value="' . esc_attr($title_val) . '" required>';
		$out .= '<label>Description</label><textarea name="sd_a_content" required>' . esc_textarea($content_val) . '</textarea>';

		$out .= '<label>Select Teacher</label><select name="sd_a_teacher"><option value="0">— Select Teacher —</option>';
		foreach($teachers as $u){
			$name = $u->display_name ?: $u->user_login;
			$out .= '<option value="'.(int)$u->ID.'" '.selected($teacher_val,(int)$u->ID,false).'>'.esc_html($name).'</option>';
		}
		$out .= '</select>';

		$out .= '<label>Select Student</label><select name="sd_a_student"><option value="0">— Select Student —</option>';
		foreach($students as $u){
			$name = $u->display_name ?: $u->user_login;
			$out .= '<option value="'.(int)$u->ID.'" '.selected($student_val,(int)$u->ID,false).'>'.esc_html($name).'</option>';
		}
		$out .= '</select>';

		$out .= '<label>Track Status</label><select name="sd_a_status"><option value="">— Select Status —</option>';
		foreach($status_options as $k=>$label){
			$out .= '<option value="'.esc_attr($k).'" '.selected($status_val,$k,false).'>'.esc_html($label).'</option>';
		}
		$out .= '</select>';

		$out .= '<label>Assignment Categories</label>';
		$out .= '<select name="sd_a_categories[]" multiple size="5">';
		if ( ! empty($cats) && ! is_wp_error($cats) ) {
			foreach($cats as $c){
				$sel = in_array((int)$c->term_id, array_map('intval',(array)$selected_cats), true) ? ' selected' : '';
				$out .= '<option value="'.(int)$c->term_id.'"'.$sel.'>'.esc_html($c->name).'</option>';
			}
		}
		$out .= '</select>';

		$out .= '<label>Assignment Tags (comma separated)</label><input type="text" name="sd_a_tags" value="'.esc_attr($tags_val).'" placeholder="tag1, tag2">';
		$out .= '<label>Featured Image</label><input type="file" name="sd_a_featured_image" accept="image/*">';

		$out .= '<div id="sd-custom-fields-area" class="sd-custom-fields-area"></div>';

		$out .= '<p style="margin-top:12px;"><button class="sd-btn" type="submit">'.esc_html($mode==='edit' ? 'Update Assignment' : 'Publish Assignment').'</button></p>';

		$out .= '</div>'; // col2

		$out .= '<aside class="sd-assignment-col3">';
		$out .= '  <div class="sd-builder-head">';
		$out .= '    <h3>Form Builder</h3>';
		$out .= '    <button type="button" class="sd-btn sd-btn-sm" id="sd-add-field-btn">+ Add Field</button>';
		$out .= '  </div>';

		$out .= '  <div class="sd-builder-body" id="sd-builder-body" style="display:none;">';
		$out .= '    <input type="hidden" id="sd-editing-id" value="">';

		$out .= '    <label>Label</label><input type="text" id="sd-f-label" placeholder="e.g. Project Deadline">';
		$out .= '    <label>Meta Key (slug)</label><input type="text" id="sd-f-key" placeholder="e.g. project_deadline">';
		$out .= '    <small class="sd-hint">Unique, lowercase, underscore only.</small>';

		$out .= '    <label>Type</label>';
		$out .= '    <select id="sd-f-type">
						<option value="text">Text</option>
						<option value="textarea">Textarea</option>
						<option value="number">Number</option>
						<option value="email">Email</option>
						<option value="date">Date</option>
						<option value="select">Select</option>
						<option value="radio">Radio</option>
						<option value="checkbox">Checkbox</option>
						<option value="checkbox_group">Checkbox Group</option>
					  </select>';

		$out .= '    <div id="sd-f-placeholder-wrap">
						<label>Placeholder</label>
						<input type="text" id="sd-f-placeholder" placeholder="optional">
					  </div>';

		$out .= '    <div id="sd-f-options-wrap" style="display:none;">
						<label>Options (comma separated)</label>
						<input type="text" id="sd-f-options" placeholder="Option 1, Option 2, Option 3">
					  </div>';

		$out .= '    <label class="sd-inline">
						<input type="checkbox" id="sd-f-required" value="1"> Required
					  </label>';

		$out .= '    <div class="sd-builder-actions">
						<button type="button" class="sd-btn" id="sd-save-field">Save Field</button>
						<button type="button" class="sd-btn sd-btn-ghost" id="sd-cancel-field">Cancel</button>
					  </div>';

		$out .= '  </div>';
		$out .= '</aside>';

		$out .= '</div></form>';

		// ✅ Vanilla JS (schema + render + sync hidden + open on click)
		$out .= '<script>
  const AJAX_URL = "'. esc_url( admin_url('admin-ajax.php') ) .'";
  const SCHEMA_NONCE = "'. esc_js( wp_create_nonce('sd_cf_schema_save') ) .'";

(function(){
  const INITIAL_SCHEMA = ' . wp_json_encode($schema) . ';
  const INITIAL_VALUES = ' . wp_json_encode($values) . ';

  let schema = Array.isArray(INITIAL_SCHEMA) ? INITIAL_SCHEMA : [];

  const area = document.getElementById("sd-custom-fields-area");
  const panel = document.getElementById("sd-builder-body");
  const addBtn = document.getElementById("sd-add-field-btn");
  const hiddenSchema = document.getElementById("sd_cf_schema_json");

  const editingId = document.getElementById("sd-editing-id");
  const labelIn = document.getElementById("sd-f-label");
  const keyIn = document.getElementById("sd-f-key");
  const typeIn = document.getElementById("sd-f-type");
  const phIn = document.getElementById("sd-f-placeholder");
  const optIn = document.getElementById("sd-f-options");
  const reqIn = document.getElementById("sd-f-required");
  const phWrap = document.getElementById("sd-f-placeholder-wrap");
  const optWrap = document.getElementById("sd-f-options-wrap");
  const saveBtn = document.getElementById("sd-save-field");
  const cancelBtn = document.getElementById("sd-cancel-field");

  if(!area || !panel || !addBtn || !hiddenSchema) return;

  function esc(s){
    return String(s||"").replace(/[&<>"\']/g, m => ({
      "&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;","\'":"&#039;"
    })[m]);
  }

  function slugify(str){
    return (str||"").toLowerCase().trim()
      .replace(/\\s+/g,"_")
      .replace(/[^a-z0-9_]/g,"")
      .replace(/_+/g,"_");
  }

  function syncHidden(){
    hiddenSchema.value = JSON.stringify(schema || []);
  }

  function saveSchemaToDB(){
    try{
      const fd = new FormData();
      fd.append("action", "sd_save_assignment_cf_schema");
      fd.append("nonce", SCHEMA_NONCE);
      fd.append("schema", JSON.stringify(schema || []));

      fetch(AJAX_URL, {
        method: "POST",
        credentials: "same-origin",
        body: fd
      }).then(r=>r.json()).then(res=>{
        // optional: console.log(res);
      }).catch(()=>{ /* ignore */ });
    }catch(e){}
  }

  function toggleFields(){
    const t = typeIn.value;
    const showOptions = (t==="select"||t==="radio"||t==="checkbox_group");
    optWrap.style.display = showOptions ? "block" : "none";

    const hidePH = (t==="select"||t==="radio"||t==="checkbox"||t==="checkbox_group");
    phWrap.style.display = hidePH ? "none" : "block";
  }

  function resetBuilder(){
    editingId.value = "";
    labelIn.value = "";
    keyIn.value = "";
    typeIn.value = "text";
    phIn.value = "";
    optIn.value = "";
    reqIn.checked = false;
    toggleFields();
  }

  function openBuilder(field){
    panel.style.display = "block";
    if(!field){ resetBuilder(); return; }

    editingId.value = field.id || "";
    labelIn.value = field.label || "";
    keyIn.value = field.key || "";
    typeIn.value = field.type || "text";
    phIn.value = field.placeholder || "";
    optIn.value = (field.options || []).join(", ");
    reqIn.checked = !!field.required;
    toggleFields();
  }

  function closeBuilder(){
    panel.style.display = "none";
    resetBuilder();
  }

  function valueOf(key){
    return (typeof INITIAL_VALUES[key] === "undefined") ? "" : INITIAL_VALUES[key];
  }

  function inputHTML(f){
    const name = "sd_cf[" + f.key + "]";
    const req = f.required ? "required" : "";
    const ph  = f.placeholder ? "placeholder=\\"" + esc(f.placeholder) + "\\"" : "";
    const val = valueOf(f.key);

    if(f.type==="textarea"){
      return "<textarea name=\\"" + name + "\\" " + req + ">" + esc(val) + "</textarea>";
    }

    if(f.type==="select"){
      let opts = "<option value=\\"\\">— Select —</option>";
      (f.options||[]).forEach(o=>{
        const sel = (String(o)===String(val)) ? "selected" : "";
        opts += "<option value=\\"" + esc(o) + "\\" " + sel + ">" + esc(o) + "</option>";
      });
      return "<select name=\\"" + name + "\\" " + req + ">" + opts + "</select>";
    }

    if(f.type==="radio"){
      let html = "<div class=\\"sd-opt-wrap\\">";
      (f.options||[]).forEach(o=>{
        const checked = (String(o)===String(val)) ? "checked" : "";
        html += "<label class=\\"sd-opt\\"><input type=\\"radio\\" name=\\"" + name + "\\" value=\\"" + esc(o) + "\\" " + checked + " " + req + "> " + esc(o) + "</label>";
      });
      html += "</div>";
      return html;
    }

    if(f.type==="checkbox"){
      const checked = (String(val)==="1") ? "checked" : "";
      return "<label class=\\"sd-opt\\"><input type=\\"checkbox\\" name=\\"" + name + "\\" value=\\"1\\" " + checked + "> " + esc(f.label) + "</label>";
    }

    if(f.type==="checkbox_group"){
      const cur = Array.isArray(val) ? val.map(String) : [];
      let html = "<div class=\\"sd-opt-wrap\\">";
      (f.options||[]).forEach(o=>{
        const checked = cur.includes(String(o)) ? "checked" : "";
        html += "<label class=\\"sd-opt\\"><input type=\\"checkbox\\" name=\\"" + name + "[]\\" value=\\"" + esc(o) + "\\" " + checked + "> " + esc(o) + "</label>";
      });
      html += "</div>";
      return html;
    }

    return "<input type=\\"" + esc(f.type) + "\\" name=\\"" + name + "\\" value=\\"" + esc(val) + "\\" " + ph + " " + req + ">";
  }

  function render(){
    if(!schema.length){
      area.innerHTML = "<div class=\\"sd-cf-empty\\">No custom fields yet. Use “+ Add Field”.</div>";
      syncHidden();
      return;
    }

    let html = "";
    schema.forEach(f=>{
      html += `
        <div class="sd-cf-field" data-id="${esc(f.id)}">
          <div class="sd-cf-top">
            <div class="sd-cf-label">${esc(f.label)}</div>
            <div class="sd-cf-actions">
              <button type="button" class="sd-btn sd-btn-xs sd-edit" data-id="${esc(f.id)}">Edit</button>
              <button type="button" class="sd-btn sd-btn-xs sd-btn-danger sd-del" data-id="${esc(f.id)}">Delete</button>
            </div>
          </div>
          <div class="sd-cf-input">${inputHTML(f)}</div>
        </div>
      `;
    });

    area.innerHTML = html;
    syncHidden();
  }

  // ✅ events
  addBtn.addEventListener("click", function(e){
    e.preventDefault();
    openBuilder(null);
  });

  typeIn.addEventListener("change", toggleFields);

  labelIn.addEventListener("input", function(){
    if(!keyIn.value.trim()){
      keyIn.value = slugify(labelIn.value);
    }
  });

  cancelBtn.addEventListener("click", function(e){
    e.preventDefault();
    closeBuilder();
  });

  saveBtn.addEventListener("click", function(e){
    e.preventDefault();

    const id = (editingId.value||"").trim();
    const label = (labelIn.value||"").trim();
    let key = slugify((keyIn.value||"").trim() || label);
    const type = typeIn.value;
    const placeholder = (phIn.value||"").trim();
    const required = reqIn.checked ? 1 : 0;

    if(!label) return alert("Label is required.");
    if(!key) return alert("Meta key is required.");

    let options = [];
    if(type==="select"||type==="radio"||type==="checkbox_group"){
      options = (optIn.value||"").split(",").map(s=>s.trim()).filter(Boolean);
      if(!options.length) return alert("Options are required for this field type.");
    }

    // unique key
    const dup = schema.find(f => f.key === key && f.id !== id);
    if(dup) return alert("Meta key must be unique.");

    if(id){
      schema = schema.map(f => f.id===id ? ({...f,label,key,type,placeholder,required,options}) : f);
    } else {
      schema.push({id:"cf_"+Math.random().toString(16).slice(2), label, key, type, placeholder, required, options});
    }

    render();
    closeBuilder();
    saveSchemaToDB();

  });

  area.addEventListener("click", function(e){
    const btn = e.target;

    if(btn.classList.contains("sd-del")){
      e.preventDefault();
      const id = btn.getAttribute("data-id");
      if(!confirm("Delete this field?")) return;
      schema = schema.filter(f => f.id !== id);
      render();
      saveSchemaToDB();
      return;
    }

    if(btn.classList.contains("sd-edit")){
      e.preventDefault();
      const id = btn.getAttribute("data-id");
      const f = schema.find(x=>x.id===id);
      if(f) openBuilder(f);
      return;
    }
  });

  // ✅ IMPORTANT: ensure hidden schema is not empty on submit
  const form = area.closest("form");
  if(form){
    form.addEventListener("submit", function(){
      syncHidden();
    });
  }

  toggleFields();
  render();
})();
</script>';

		return $out;
	});

}, 99);
/* ============================================================
 * ✅ AJAX: SAVE SCHEMA instantly (so delete/edit persists on reload)
 * action: sd_save_assignment_cf_schema
 * ============================================================ */
add_action('wp_ajax_sd_save_assignment_cf_schema', function () {

	if ( ! is_user_logged_in() ) {
		wp_send_json_error(array('message' => 'Login required.'));
	}

	if ( ! function_exists('sd_current_user_can_access_dashboard') || ! sd_current_user_can_access_dashboard() ) {
		wp_send_json_error(array('message' => 'Access denied.'));
	}

	$uid = get_current_user_id();

	$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
	if ( ! wp_verify_nonce($nonce, 'sd_cf_schema_save') ) {
		wp_send_json_error(array('message' => 'Security check failed.'));
	}

	$schema_json = isset($_POST['schema']) ? wp_unslash($_POST['schema']) : '';
	$schema_arr  = json_decode($schema_json, true);
	if ( ! is_array($schema_arr) ) $schema_arr = array();

	$schema = sd_cf_normalize_schema($schema_arr);

	update_user_meta($uid, SD_CF_SCHEMA_KEY, $schema);

	wp_send_json_success(array('message' => 'Schema saved.'));
});

/* ============================================================
 * ✅ OPTIONAL: show custom fields on teacher/student side
 * [sd_assignment_custom_fields assignment_id="123"] OR ?assignment_id=
 * ============================================================ */
add_shortcode('sd_assignment_custom_fields', function($atts){
	$atts = shortcode_atts(array('assignment_id'=>0), $atts, 'sd_assignment_custom_fields');
	$assignment_id = absint($atts['assignment_id']);
	if ( ! $assignment_id && isset($_GET['assignment_id']) ) $assignment_id = absint($_GET['assignment_id']);
	if ( ! $assignment_id ) return '<div class="sd-notice sd-notice-error">Assignment ID missing.</div>';

	$schema = get_post_meta($assignment_id, '_sd_cf_schema', true);
	if ( ! is_array($schema) || empty($schema) ) return '<div class="sd-notice">No custom fields found.</div>';

	$html = '<div class="sd-cf-view" style="margin-top:12px;">';
	foreach($schema as $f){
		$label = isset($f['label']) ? $f['label'] : '';
		$key   = isset($f['key']) ? sanitize_key($f['key']) : '';
		if ( ! $key ) continue;

		$val = get_post_meta($assignment_id, $key, true);
		if ( is_array($val) ) $val = implode(', ', array_map('sanitize_text_field', $val));

		$html .= '<div style="padding:10px;border:1px solid rgba(0,0,0,.12);border-radius:10px;margin-bottom:10px;background:#fff;">';
		$html .= '<div style="font-weight:700;margin-bottom:6px;">'.esc_html($label).'</div>';
		$html .= '<div>'.esc_html((string)$val).'</div>';
		$html .= '</div>';
	}
	$html .= '</div>';
	return $html;
});

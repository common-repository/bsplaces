<?php
/**
 * vim: set expandtab sw=4 ts=4 sts=4 foldmethod=indent:
 *
 * Create meta box for editing pages in WordPress
 *
 * Compatible with custom post types since WordPress 3.0
 * Support input types: text, textarea, checkbox, checkbox list, radio box, select, wysiwyg, file, image, date, time, color
 *
 * @author: Rilwis
 * @url: http://www.deluxeblogtips.com/2010/04/how-to-create-meta-box-wordpress-post.html
 * @usage: please read document at project homepage and meta-box-usage.php file
 * @version: 3.0.1
 */

/**
 * Meta Box class
 */
if(!class_exists('BSMetaBox')):
class BSMetaBox
{
	protected $_meta_box;
	protected $_fields;

	// Create meta box based on given data
	function __construct($meta_box)
	{
		if (!is_admin()) return;

		// assign meta box values to local variables and add it's missed values
		$this->_meta_box = $meta_box;
		$this->_fields = $this->_meta_box['fields'];
		$this->add_missed_values();

		add_action('add_meta_boxes', array(&$this, 'add'));	// add meta box
		add_action('save_post', array(&$this, 'save'));	// save meta box's data
	}

	// Add meta box for multiple post types
	function add()
	{
		foreach ($this->_meta_box['post_types'] as $postType)
		{
			add_meta_box(
				$this->_meta_box['id'],
				$this->_meta_box['title'],
				array(&$this, 'show'), $postType,
				$this->_meta_box['context'],
				$this->_meta_box['priority']);
		}
	}

	// Callback function to show fields in meta box
	function show()
	{
		global $post;

		wp_nonce_field(basename(__FILE__), 'rw_meta_box_nonce');
		echo '<table class="form-table">';

		foreach ($this->_fields as $field)
		{
			$meta = get_post_meta($post->ID, $field['id']);
			$meta = !empty($meta) ? $meta : $field['std'];
			echo '<tr>';
			// call separated methods for displaying each type of field
			call_user_func(array(&$this, 'show_field_' . $field['type']), $field, $meta);
			echo '</tr>';
		}
		echo '</table>';
	}

	function show_field_begin($field, $meta) {
		echo "<th style='width:20%'><label for='{$field['id']}'>{$field['name']}</label></th><td>";
	}

	function show_field_end($field, $meta) {
		echo "<br />{$field['desc']}</td>";
	}

	function show_field_text($field, $meta)
	{
		$this->show_field_begin($field, $meta);
		echo "<input type='text' name='{$field['id']}' id='{$field['id']}' value='$meta[0]' size='30' style='width:97%' />";
        if (is_array($field['hints']))
        {
            // prepare array of hints
            $hints = '"' . implode('", "',  $field['hints']) . '"';
            echo '<script>' . "\n";
            echo '  jQuery(function() { ' . "\n";
            echo '    var availableTags' . $field['id'] . ' = [ ' . $hints . ' ];' . "\n";
            echo '    jQuery("#' . $field['id'] . '").autocomplete({ source: availableTags' . $field['id'] . ' });' . "\n";
            echo '  });' . "\n";
            echo '</script>' . "\n";
        }
		$this->show_field_end($field, $meta);
	}

	function show_field_textarea($field, $meta) {
		$this->show_field_begin($field, $meta);
		echo "<textarea name='{$field['id']}' cols='60' rows='5' style='width:97%'>$meta[0]</textarea>";
		$this->show_field_end($field, $meta);
	}

	function show_field_select($field, $meta) {
		if (!is_array($meta)) $meta = (array) $meta;
		$this->show_field_begin($field, $meta);
		echo "<select name='{$field['id']}'>";
		foreach ($field['options'] as $key => $value) {
			echo "<option value='$key'" . selected(in_array($key, $meta), true, false) . ">$value</option>";
		}
		echo "</select>";
		$this->show_field_end($field, $meta);
	}

	function show_field_wysiwyg($field, $meta) {
		$this->show_field_begin($field, $meta);
		echo "<textarea name='{$field['id']}' class='theEditor' cols='60' rows='15' style='width:97%'>$meta</textarea>";
		$this->show_field_end($field, $meta);
	}

	// Save data from meta box
	function save($post_id)
    {
		$post_type_object = get_post_type_object($_POST['post_type']);

		if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)			// check autosave
		|| (!isset($_POST['post_ID']) || $post_id != $_POST['post_ID'])		// check revision
		|| (!in_array($_POST['post_type'], $this->_meta_box['post_types']))	// check if current post type is supported
		|| (!check_admin_referer(basename(__FILE__), 'rw_meta_box_nonce'))	// verify nonce
		|| (!current_user_can($post_type_object->cap->edit_post, $post_id))) {	// check permission
			return $post_id;
		}

		foreach ($this->_fields as $field) {
			$name = $field['id'];
			$type = $field['type'];
			$old = get_post_meta($post_id, $name, true);
			$new = isset($_POST[$name]) ? $_POST[$name] : '';

			// validate meta value
			if (class_exists('BSMetaBoxValidate') && method_exists('BSMetaBoxValidate', $field['validate_func'])) {
				$new = call_user_func(array('BSMetaBoxValidate', $field['validate_func']), $new);
			}

			// call defined method to save meta value, if there's no methods, call common one
			$save_func = 'save_field_' . $type;
			if (method_exists($this, $save_func)) {
				call_user_func(array(&$this, 'save_field_' . $type), $post_id, $field, $old, $new);
			} else {
				$this->save_field($post_id, $field, $old, $new);
			}
		}
	}

	// Common functions for saving field
	function save_field($post_id, $field, $old, $new)
	{
		$name = $field['id'];

		// single value
		if ('' != $new && $new != $old) {
			update_post_meta($post_id, $name, $new);
		} elseif ('' == $new) {
			delete_post_meta($post_id, $name, $old);
		}
	}

	function save_field_textarea($post_id, $field, $old, $new) {
		$new = htmlspecialchars($new);
		$this->save_field($post_id, $field, $old, $new);
	}

	function save_field_wysiwyg($post_id, $field, $old, $new) {
		$new = wpautop($new);
		$this->save_field($post_id, $field, $old, $new);
	}

	// Add missed values for meta box
	function add_missed_values() {
		// default values for meta box

		$this->_meta_box = array_merge(array(
			'context' => 'normal',
			'priority' => 'high',
			'post_types' => array('post')
		), $this->_meta_box);

		// default values for fields
		foreach ($this->_fields as $key => $field)
		{
			$std = '';
			$format = 'date' == $field['type'] ? 'yy-mm-dd' : ('time' == $field['type'] ? 'hh:mm' : '');
			$this->_fields[$key] = array_merge(array(
				'std' => $std,
				'desc' => '',
				'format' => $format,
				'validate_func' => ''
			), $field);
		}
	}
}
endif;
?>

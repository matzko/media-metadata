<?php
/*
Plugin Name: Media Meta-data
Plugin URI: http://austinmatzko.com
Description: Add meta-data to media objects.
Author: Austin Matzko
Author URI: http://austinmatzko.com
Version: 1.0
*/

class Filosofo_Media_Metadata {

	var $current_obj_date = ''; // the date of the current media object, to be used in finding previous and next objects
	var $current_obj_id = 0; // the id of the current media object

	function Filosofo_Media_Metadata() {
		return $this->__construct();
	}

	function __construct() {
		add_action('init', array(&$this, 'init'));
		add_action('edited_term', array(&$this, 'save_tag_order_edit'), 10, 2);
		add_action('edit_tag_form', array(&$this, 'edit_tag_order'));

		add_filter('attachment_link', array(&$this, 'filter_attachment_link'), 99, 2);
		add_filter('get_ordered_tags', array(&$this, 'get_ordered_tags'));
		add_filter('wp_get_attachment_link', array(&$this, 'filter_wp_get_attachment_link'), 99, 6);
	}

	/**
	 * Initialize things 
	 */
	function init() {
		add_filter('attachment_fields_to_edit', array(&$this, 'image_attachment_fields_to_edit'), 10, 2);
		add_filter('attachment_fields_to_save', array(&$this, 'image_attachment_fields_to_save'), 1, 2);
		
		// using taxonomy to save tag meta-info, in this case tag order
		register_taxonomy( 'tag_order', 'tag', array('hierarchical' => false, 'rewrite' => false, 'query_var' => false) );
		load_plugin_textdomain('media-metadata');

		add_rewrite_tag('%media-tag%', 'whatever');
		add_rewrite_rule('media-tag/([^/]+)/([^/]+)/?$', 'index.php?media-tag=$matches[1]&attachment=$matches[2]', 'top');
	}

	function filter_attachment_link($permalink = '', $id = 0)
	{
		global $wp_query;
		$post = get_post($id);
		$media_tag = get_query_var('media-tag');
		// if this is a tag page and we're making a link for an attachment object, I'm going to assume (for the sake of speed, not robustness) that this object is tagged with the queried tag
		if ( is_tag() && 'attachment' == $post->post_type ) {
			$tag_id = $wp_query->get_queried_object_id();
			$term = get_term($tag_id, 'post_tag');
			if ( ! empty( $term->slug ) ) {
				$permalink = get_bloginfo('url') . '/media-tag/' . $term->slug . '/' . $post->post_name . '/';
			}

		// likewise, if on a single "media-tag"-ed attachment page, going to assume that links to other attachments will be of the same tag
		} elseif ( ! empty( $media_tag ) ) {
			$permalink = get_bloginfo('url') . '/media-tag/' . $media_tag . '/' . $post->post_name . '/';
		}
		return $permalink;
	}

	/**
	 * Filter attachment links.  Mainly for changing the previous / next links for an attachment page so as to make them in context.
	 * @param string $link_markup The existing markup for the attachment link.
	 * @param int $id The id of the attachment.
	 * @param int $size The size of the attachment.
	 * @param bool $permalink Whether to include a URL of the permalink of the attachment.
	 * @param bool $icon Whether to print an icon.
	 * @param bool $text Whether to show text rather than thumbnail.
	 * @return string The markup of the attachment link.
	 */
	function filter_wp_get_attachment_link($link_markup = '', $id = 0, $size = 0, $permalink = false, $icon = false, $text = false)
	{
		$media_tag = get_query_var('media-tag');
		/*
		d('media tag:' . $media_tag);
	d($link_markup);
	d($id);
	d($size);
	d($permalink);
	d($icon);
	d($text);
	*/
		return $link_markup;
	}

	/**
	 * Retrieve an array of tags as ordered 
	 * @param array The arry of tags in order
	 */
	function get_ordered_tags() {
		$ordered_tags = array();
		// order_terms are the terms acting as order value carriers for their object tags
		$order_terms = get_terms('tag_order', array('order_by' => 'name', 'hide_empty' => false));
		// so let's get the tags associated with these terms
		foreach( (array) $order_terms as $term ) {
			$terms = (array) get_objects_in_term($term->term_id, 'tag_order');
			if ( ! empty( $terms ) ) {
				$ordered_tags[$term->name] = array_shift($terms);
			}
		}
		return array_map('intval', $ordered_tags);
	}
	
	/**
	 * Custom attachment fields for images
	 * @param array $form_fields The existing form fields
	 * @param object $post The attachment object
	 * @return array The array of form fields.
	 */
	function image_attachment_fields_to_edit($form_fields, $post) {
		if ( substr($post->post_mime_type, 0, 5) == 'image' ) {
			$cats = wp_get_post_categories($post->ID);
			$dropdown_args = array(
				'hide_empty' => false,
				'name' => "attachments[{$post->ID}][media-metadata-category]",
				'show_option_none' => __('No category selected', 'media-metadata'),
			);
			if ( is_array($cats) && ! empty($cats) ) {
				$dropdown_args['selected'] = (int) array_shift($cats);
			}
			wp_dropdown_categories($dropdown_args);

			$form_fields['media_url'] = array(
				'label' => __('URL for this media item', 'media-metadata'),
				'input' => 'html',
				'helps' => __('Enter a URL with which to associate this media item.', 'media-metadata'),
				'value' => get_post_meta($post->ID, '_media-url', true),
			);
		}
		return $form_fields;
	}
	
	/**
	 * Save custom fields for images
	 * @param array $post The post array of potential items to save 
	 * @param array $attachment The array of items sent to be saved.
	 * @return array The post array of items to be saved.
	 */
	function image_attachment_fields_to_save($post, $attachment) {
		$image_id = (int) $post['ID'];
		if ( isset($attachment['media_url']) ) {
			$url = filter_var($attachment['media_url'], FILTER_SANITIZE_URL);
			update_post_meta($image_id, '_media-url', $url);
		}

		if ( isset($attachment['media-metadata-category']) ) {
			$image_object = get_post($image_id);
			$cat_id = (int) $attachment['media-metadata-category'];	
			$image_object->post_category = array($cat_id);
			wp_update_post($image_object);
		}

		return $post;
	}

	/**
	 * Add a tag order item
	 * @param object $tag The tag object
	 */
	function edit_tag_order($tag = null) {
		$tag_data = get_term($tag, 'post_tag');
		$tag_name = $tag_data->name;
		?>	
		<table class="form-table">
			<tr class="form-field">
				<th scope="row" valign="top"><label for="tag_order"><?php _e('Add a tag order') ?></label></th>
				<td>
				<p><?php _e('Current tag order:', 'media-metadata'); ?></p>
				<table>
					<tr><th><?php _e('Tag Order', 'media-metadata'); ?></th><th><?php _e('Tag Name', 'media-metadata'); ?></th></tr>
				<?php 
					$ordered = $this->get_ordered_tags();
					foreach( $ordered as $ord => $tag_id ) {
						$listtag = get_term($tag_id, 'post_tag');
						if ( empty( $listtag ) ) {
							continue;
						}
						$name = $listtag->name;
						$link = sprintf('<a href="%s">%s</a>', 'edit-tags.php?action=edit&tag_ID=' . $tag_id, $name);
						echo "<tr><td>{$ord}</td><td>{$link}</td></tr>\n";
					}
				?>
				</table>
				
				<p><?php printf(__('Set the order for "%s":', 'media-metadata'), $tag_name); ?></p>
				<input name="tag_order" id="tag_order" type="text" value="<?php echo intval($this->get_tag_order($tag->term_id)); ?>" size="10" />
				</td>
			</tr>
		</table>
		<?php 
	}

	/**
	 * Get the order of a particular tag
	 * @param int $tag_id The id of the tag for which to retreive the order
	 * @return int The order of the tag
	 */
	function get_tag_order($tag_id = 0) {
		$tag_id = (int) $tag_id;
		$orders = wp_get_object_terms($tag_id, 'tag_order', array('fields' => 'names'));
		if ( is_array($orders) ) {
			return intval(array_shift($orders));
		}
		return 0;
	}

	/**
	 * Save the order of a tag
	 * @param int $tag_id The id of the tag for which to save an order
	 * @param int $order The order which should be assigned to the tag
	 * @return array|WP_Error Array of term taxonomy ids if the saving was successful, WP_Error if not
	 */
	function save_tag_order($tag_id = 0, $order = 0) {
		$tag_id = (int) $tag_id;
		$order = (int) $order;
		// first, let's find out if any other tags are set for that order position.
		$current_ordered_tags = $this->get_ordered_tags();
		$new_ordered_tags = array();
		// set up an array with the new order
		foreach( (array) $current_ordered_tags as $key => $val ) {
			if ( $val == $tag_id ) {
				continue;
			}
			if ( $key < $order ) {
				$new_ordered_tags[$key] = $val;
			} else {
				$new_ordered_tags[$key + 1] = $val;	
			}
		}

		// $order of 0 means we're de-ordering this tag
		if ( ! empty( $order ) ) {
			$new_ordered_tags[$order] = $tag_id;
		}
		ksort($new_ordered_tags);

		$new_ordered_tags = array_values($new_ordered_tags);

		foreach( $current_ordered_tags as $ord => $ordered_tag_id ) {
			// if that ordered tag's order is not already set
			if ( ! empty( $ordered_tag_id ) && $ordered_tag_id != intval($new_ordered_tags[$ord]) ) {
				// let's delete its current order association
				wp_delete_object_term_relationships( $ordered_tag_id, 'tag_order' );
			}
		}
		foreach( $new_ordered_tags as $ord => $ordered_tag_id ) {
			// create a new order association, if applicable
			wp_set_object_terms($ordered_tag_id, array((string) $ord), 'tag_order');
		}
		return true;
	}

	/**
	 * Save tag order from edit
	 * @param int $tag_id The tag id for which an order should be saved
	 */
	function save_tag_order_edit($tag_id = 0) {
		if ( isset($_POST['action']) && 'editedtag' == $_POST['action'] && isset($_POST['tag_order']) ) {
			check_admin_referer('update-tag_' . $tag_id);
			$this->save_tag_order($tag_id, intval($_POST['tag_order']));
		}
	}


} // end class Filosofo_Media_Metadata

$filosofo_media_metadata = new Filosofo_Media_Metadata();

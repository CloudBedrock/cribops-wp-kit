<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * WPLKManager Class
 *
 * @since 1.0.0
 */
class CWPKManager {
	/**
     * Constructor
     *
     * @since 1.0.0
     * @access public
     */
	public function __construct() {
		add_action('plugins_loaded', array($this, 'launchkit_manager_load_textdomain'));
		add_action('admin_menu', array($this, 'launchkit_manager_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('wp_ajax_get_plugins_list', array($this, 'ajax_get_plugins_list'));
		add_action('wp_ajax_download_plugin_list', array($this, 'ajax_download_plugin_list'));
		add_action('wp_ajax_upload_plugin_list', array($this, 'ajax_upload_plugin_list'));
		add_action('wp_ajax_launchkit_manage_plugins', array($this, 'ajax_manage_plugins'));
		add_action('admin_head', array($this, 'manager_css'));
		add_action('admin_footer', array($this, 'manager_js'));
	}

	// Load the plugin's text domain
	public function launchkit_manager_load_textdomain() {
		load_plugin_textdomain('launchkit-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
	}

	// Add the manager submenu
	public function launchkit_manager_menu() {
		$parent_slug = 'cwpk'; // The slug of the LaunchKit plugin's main menu
		$page_slug = 'launchkit-manager'; // The slug for the submenu page
		$capability = 'manage_options';
		add_submenu_page(
			$parent_slug,
			__('Plugin Manager', 'launchkit-manager'),
			__('Plugin Manager', 'launchkit-manager'),
			$capability,
			$page_slug,
			array($this, 'launchkit_manager_page')
		);
		// Add a unique CSS class to the hidden submenu item
		add_action('admin_head', array($this, 'hide_manager_submenu_item'));
	}

	// Hide the empty space of the hidden packages submenu item
	public function hide_manager_submenu_item() {
		global $submenu;
		$parent_slug = 'cwpk';
		$page_slug = 'launchkit-manager';
		if (isset($submenu[$parent_slug])) {
			foreach ($submenu[$parent_slug] as &$item) {
				if ($item[2] === $page_slug) {
					$item[4] = 'launchkit-manager-hidden';
					break;
				}
			}
		}
		echo '<style>.launchkit-manager-hidden { display: none !important; }</style>';
	}

	// Enqueue scripts and styles
	public function enqueue_scripts($hook) {
		// Check if we're on the correct page and tab
		if ($hook !== 'toplevel_page_wplk' || !isset($_GET['tab']) || $_GET['tab'] !== 'manager') {
			return;
		}

		wp_enqueue_script('jquery');
		wp_localize_script('jquery', 'launchkitManager', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('launchkit-manager-nonce'),
		));
	}

	// Render the plugin's admin page
	public function launchkit_manager_page() {
?>
<h1><?php _e('CribOps WP-Kit Manager', 'launchkit-manager'); ?></h1>
<div class="wrap">
	<div id="lk-notification" class="notice" style="display: none;"></div>
	<div id="launchkit-manager-container">
		<!-- Content will be loaded via AJAX -->
	</div>
</div>
<?php
											 }

	// AJAX handler for getting plugins list
	public function ajax_get_plugins_list() {
		check_ajax_referer('launchkit-manager-nonce', 'nonce');

		if (!current_user_can('activate_plugins')) {
			wp_send_json_error('You do not have sufficient permissions to manage plugins on this site.');
		}

		$plugins = get_plugins();
		ob_start();
?>
<form id="launchkit-manager-form">
	<div id="recipe_wrap">
		<h3>Plugin Recipes</h3>
		<p>Choose ONE method to load recipe, then use Activate/Deactivate Buttons:</p>
		(1) Either select plugin checkboxes below<br/>
		(2) Or paste <strong>one plugin name per line</strong> like this:
		<pre>admin-columns-pro<br/>advanced-custom-fields<br/>woocommerce</pre>

		<div class="control_buttons">
			<textarea name="plugin_list" id="plugin_list" rows="4" cols="50" placeholder="List one plugin name per line"></textarea>
		</div>

		(3) Or upload a Plugin Recipe .txt file<br/>
		<div class="control_buttons">
			<input type="file" id="upload_file" accept=".txt">
			<button type="button" id="upload_list" class="button">Upload Recipe List</button>
		</div>
	</div>

	<div id="recipe_wrap">
		(*Optional) Name and download your Plugin Recipe for later use<br/>
		<div class="control_buttons">
			<input type="text" id="download_filename" placeholder="Enter filename (optional)">
			<button type="button" id="download_list" class="button">Download Plugin Recipe</button>
		</div>
	</div>

	<div class="control_buttons">
		<button type="submit" name="action" value="activate" class="button button-primary">Activate Selected Plugins</button>
		<button type="submit" name="action" value="deactivate" class="button">Deactivate Selected Plugins</button>
            <button id="launchkit-cleanup-button" class="button button-secondary"><?php _e('Cleanup All Inactive Plugins', 'lk'); ?></button>
            <div id="launchkit-progress"></div>
            <div id="launchkit-summary"></div>

<script type="text/javascript">
document.getElementById('launchkit-cleanup-button').addEventListener('click', function() {
    document.getElementById('launchkit-progress').textContent = '<?php _e('Cleaning up inactive plugins...', 'lk'); ?>';
    document.getElementById('launchkit-summary').innerHTML = '';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

    xhr.onload = function() {
        if (xhr.status === 200) {
            var response = JSON.parse(xhr.responseText);
            if (response.success) {
                document.getElementById('launchkit-progress').textContent = '<?php _e('Cleanup complete.', 'lk'); ?>';

                // Combine both deleted and manually deleted plugins into a single list
                var allDeletedPlugins = response.data.deleted.concat(response.data.manual);

                // Format the output with each plugin on a new line
                var deletedPluginsList = '<p><?php _e("Deleted Plugins:", "lk"); ?></p><ul>';
                allDeletedPlugins.forEach(function(plugin) {
                    deletedPluginsList += '<li>' + plugin + '</li>';
                });
                deletedPluginsList += '</ul>';

                document.getElementById('launchkit-summary').innerHTML = deletedPluginsList;

                // Show the "Click to Refresh" link in red
                var refreshLink = '<br/><a href="#" id="launchkit-refresh-link" style="color: red; text-decoration: underline;"><?php _e("Click To Refresh Plugin List", "lk"); ?></a>';
                document.getElementById('launchkit-summary').innerHTML += refreshLink;

                // Add event listener for the refresh link
                document.getElementById('launchkit-refresh-link').addEventListener('click', function(e) {
                    e.preventDefault(); // Prevent default link behavior
                    window.location.reload(); // Reload the page when the link is clicked
                });

            } else {
                document.getElementById('launchkit-progress').textContent = '<?php _e("Error:", "lk"); ?> ' + response.data;
            }
        } else {
            document.getElementById('launchkit-progress').textContent = '<?php _e("An error occurred.", "lk"); ?>';
        }
    };

    xhr.send('action=launchkit_cleanup_plugins&security=<?php echo wp_create_nonce('launchkit_cleanup_plugins_nonce'); ?>');
});
</script>


	</div>

	<div id="operation-progress" style="display: none;">
		<p class="status"></p>
	</div>

	<div class="lk-table-container">
		<table class="wp-list-table widefat plugins">
			<thead>
				<tr>
					<td class="manage-column check-column">
						<label class="screen-reader-text" for="cb-select-all">Select All</label>
						<input id="cb-select-all" type="checkbox">
					</td>
					<th scope="col" class="manage-column column-name column-primary sortable asc">
						<a href="#" class="sort-column" data-sort="name">
							<span>Plugin</span>
							<span class="sorting-indicator"></span>
						</a>
					</th>
					<th scope="col" class="manage-column column-description sortable desc">
						<a href="#" class="sort-column" data-sort="status">
							<span>Status</span>
							<span class="sorting-indicator"></span>
						</a>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($plugins as $plugin_file => $plugin_data) : 
		$plugin_folder = explode('/', $plugin_file)[0];
				?>
				<tr class="<?php echo is_plugin_active($plugin_file) ? 'active' : 'inactive'; ?>" data-plugin-name="<?php echo esc_attr(strtolower($plugin_data['Name'])); ?>" data-plugin-status="<?php echo is_plugin_active($plugin_file) ? '1' : '0'; ?>">
					<th scope="row" class="check-column">
						<label class="screen-reader-text" for="checkbox_<?php echo esc_attr($plugin_file); ?>">
							Select <?php echo esc_html($plugin_data['Name']); ?>
						</label>
						<input type="checkbox" name="checked[]" value="<?php echo esc_attr($plugin_folder); ?>" id="checkbox_<?php echo esc_attr($plugin_file); ?>">
					</th>
					<td class="plugin-title column-primary">
						<strong><?php echo esc_html($plugin_data['Name']); ?></strong>
						<div class="plugin-folder"><code><?php echo esc_html($plugin_folder); ?></code></div>
					</td>
					<td class="column-description desc">
						<div class="plugin-description">
							<p><?php echo esc_html($plugin_data['Description']); ?></p>
						</div>
						<div class="active second plugin-activation-status">
							<?php if (is_plugin_active($plugin_file)) : ?>
							Status: <span class="active">Active</span>
							<?php else : ?>
							Status: <span class="inactive">Inactive</span>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</form>
<?php
		$content = ob_get_clean();
		wp_send_json_success($content);
	}

	// AJAX handler for downloading plugin list
	public function ajax_download_plugin_list() {
		check_ajax_referer('launchkit-manager-nonce', 'nonce');

		if (!current_user_can('activate_plugins')) {
			wp_send_json_error('You do not have sufficient permissions to manage plugins on this site.');
		}

		$plugin_list = isset($_POST['plugin_list']) ? sanitize_textarea_field($_POST['plugin_list']) : '';
		$checked_plugins = isset($_POST['checked_plugins']) ? (array) $_POST['checked_plugins'] : array();
		$filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : 'plugin_list';

		$filename = preg_replace('/\.txt$/', '', $filename);
		$filename .= '-recipe.txt';

		$plugin_list = implode("\n", array_filter(array_map('trim', explode("\n", $plugin_list))));
		$checked_plugins = array_filter(array_map('trim', $checked_plugins));

		$content = $plugin_list;
		if (!empty($checked_plugins)) {
			$content .= ($content ? "\n" : "") . implode("\n", $checked_plugins);
		}

		$content = trim($content);

		wp_send_json_success(array(
			'content' => $content,
			'filename' => $filename
		));
	}

	// AJAX handler for uploading plugin list
	public function ajax_upload_plugin_list() {
		// Verify nonce
		check_ajax_referer('launchkit-manager-nonce', 'nonce');

		// Check user capabilities
		if (!current_user_can('activate_plugins')) {
			wp_send_json_error(esc_html__('You do not have sufficient permissions to manage plugins on this site.', 'cwpk'));
		}

		// Check if file was uploaded
		if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
			wp_send_json_error(esc_html__('File upload failed.', 'cwpk'));
		}

		// Validate file type
		$allowed_types = array('text/plain');
		$file_type = wp_check_filetype(basename($_FILES['file']['name']), null);
		if (!in_array($file_type['type'], $allowed_types)) {
			wp_send_json_error(esc_html__('Invalid file type. Only .txt files are allowed.', 'cwpk'));
		}

		// Validate file size (e.g., max 1MB)
		$max_size = 1 * 1024 * 1024; // 1MB
		if ($_FILES['file']['size'] > $max_size) {
			wp_send_json_error(esc_html__('File is too large. Maximum size is 1MB.', 'cwpk'));
		}

		// Read file content safely
		$file_content = file_get_contents($_FILES['file']['tmp_name']);
		if ($file_content === false) {
			wp_send_json_error(esc_html__('Failed to read file content.', 'cwpk'));
		}

		// Sanitize and validate file content
		$lines = explode("\n", $file_content);
		$sanitized_lines = array();
		foreach ($lines as $line) {
			$sanitized_line = sanitize_text_field(trim($line));
			if (!empty($sanitized_line)) {
				$sanitized_lines[] = $sanitized_line;
			}
		}

		// Join sanitized lines
		$sanitized_content = implode("\n", $sanitized_lines);

		wp_send_json_success(array('content' => $sanitized_content));
	}

	// AJAX handler for managing plugins
	public function ajax_manage_plugins() {
		check_ajax_referer('launchkit-manager-nonce', 'nonce');

		if (!current_user_can('activate_plugins')) {
			wp_send_json_error('You do not have sufficient permissions to manage plugins on this site.');
		}

		$plugins = isset($_POST['plugins']) ? (array) $_POST['plugins'] : array();
		$action = isset($_POST['action_type']) ? $_POST['action_type'] : '';
		$active_plugins = get_option('active_plugins');
		$all_plugins = get_plugins();

		$plugin_files = array();
		foreach ($plugins as $plugin_name) {
			$plugin_name = trim($plugin_name);
			foreach ($all_plugins as $file => $data) {
				if (strpos($file, $plugin_name) === 0 || strpos($data['Name'], $plugin_name) !== false) {
					$plugin_files[] = $file;
					break;
				}
			}
		}

		if ($action === 'activate') {
			foreach ($plugin_files as $plugin) {
				if (!in_array($plugin, $active_plugins)) {
					$active_plugins[] = $plugin;
				}
			}
			$message = 'Plugins activated successfully';
		} elseif ($action === 'deactivate') {
			$active_plugins = array_diff($active_plugins, $plugin_files);
			$message = 'Plugins deactivated successfully';
		} else {
			wp_send_json_error('Invalid action');
		}

		update_option('active_plugins', $active_plugins);

		wp_cache_delete('alloptions', 'options');
		wp_cache_delete('active_plugins', 'options');

		// Get the updated status of each plugin
		$plugins_data = array();
		foreach ($all_plugins as $file => $data) {
			$plugins_data[] = array(
				'file' => $file,
				'name' => $data['Name'],
				'status' => in_array($file, $active_plugins) ? 'active' : 'inactive',
			);
		}

		wp_send_json_success(array(
			'message' => $message,
			'plugins_data' => $plugins_data
		));
	}

	// Inline CSS
	public function manager_css() {
?>
<style>
	#operation-progress {
		margin-top: 10px;
		margin-bottom: 10px;
	}
	.plugin-activation-status .active {
		color: #46b450;
	}
	.plugin-activation-status .inactive {
		color: #dc3232;
	}
	.lk-table-container {
		max-width: 1100px;
		overflow-x: auto;
	}
	#lk-notification {
		margin-top: 15px;
		margin-bottom: 15px;
	}
	#plugin_list {
		width: 100%;
		max-width: 500px;
	}
	.plugin-folder {
		margin-top: 5px;
		font-size: 12px;
		color: #666;
	}
	.plugin-folder code {
		background: #f0f0f1;
		padding: 2px 5px;
		border-radius: 3px;
	}
	#download_filename {
		margin-right: 10px;
	}
	#upload_file {
		margin-right: 10px;
	}
	.lk-action-buttons {
		display: flex;
		justify-content: space-between;
		margin-bottom: 20px;
	}
	.lk-action-buttons > div {
		width: 48%;
	}
	.lk-action-buttons input[type="text"],
	.lk-action-buttons input[type="file"] {
		width: calc(100% - 130px);
		margin-right: 10px;
	}
	.lk-action-buttons button {
		width: 120px;
	}
	#upload_file, #download_filename {
		width: 250px;
	}
	button#upload_list, button#download_list {
		width: 235px;
	}
	.control_buttons {margin:10px 0}  
	.sortable .sorting-indicator {
		display: none
	}
	.sortable.asc .sorting-indicator:before,
	.sortable.desc .sorting-indicator:before {
		display: inline-block;
		font: normal 20px/1 dashicons;
		content: "\f142";
		margin-left: 5px;
	}
	.sortable.desc .sorting-indicator:before {
		content: "\f140";
	}
	.sortable:hover .sorting-indicator:before {
		display: inline-block;
		font: normal 20px/1 dashicons;
		content: "\f142";
		margin-left: 5px;
		color: #999;
	}
	#plugin_list::placeholder {
		color: #999;
		opacity: 1;
	}
	#plugin_list:-ms-input-placeholder {
		color: #999;
	}
	#plugin_list::-ms-input-placeholder {
		color: #999;
	}
	div#recipe_wrap {
		border: 3px solid #dedede;
		border-radius: 10px;
		padding: 14px;
		max-width:500px;
		margin: 10px 0;
	}
</style>
<?php
	}

	// Inline JavaScript
	public function manager_js() {
		// Check if we're on the correct page and tab
		if (!isset($_GET['page']) || $_GET['page'] !== 'cwpk' || !isset($_GET['tab']) || $_GET['tab'] !== 'manager') {
			return;
		}
?>
<script>
	(function($) {
		$(document).ready(function() {
			if (typeof launchkitManager === 'undefined') {
				console.error('launchkitManager is not defined');
				return;
			}

			function loadPluginsList() {
				$.ajax({
					url: launchkitManager.ajax_url,
					type: 'POST',
					data: {
						action: 'get_plugins_list',
						nonce: launchkitManager.nonce
					},
					success: function(response) {
						if (response.success) {
							$('#launchkit-manager-container').html(response.data);
							bindEvents();
						} else {
							showNotification('error', 'Error loading plugins list: ' + response.data);
						}
					},
					error: function() {
						showNotification('error', 'An error occurred while loading the plugins list.');
					}
				});
			}

			function bindEvents() {
				$('#cb-select-all').on('change', function() {
					$('input[name="checked[]"]').prop('checked', this.checked).change();
				});

				$('#launchkit-manager-form').on('submit', function(e) {
					e.preventDefault();
					var selectedPlugins = $('input[name="checked[]"]:checked').map(function() {
						return this.value;
					}).get();

					var pastedPlugins = $('#plugin_list').val().split('\n').filter(function(plugin) {
						return plugin.trim() !== '';
					});

					var plugins = pastedPlugins.length > 0 ? pastedPlugins : selectedPlugins;

					if (plugins.length === 0) {
						showNotification('error', 'Please select at least one plugin or paste a list of plugins to manage.');
						return;
					}

					var action = $(e.originalEvent.submitter).val();
					var $status = $('#operation-progress .status');
					$('#operation-progress').show();
					$status.text(action === 'activate' ? 'Activating plugins...' : 'Deactivating plugins...');

					$.ajax({
						url: launchkitManager.ajax_url,
						type: 'POST',
						data: {
							action: 'launchkit_manage_plugins',
							plugins: plugins,
							action_type: action,
							nonce: launchkitManager.nonce
						},
						success: function(response) {
							if (response.success) {
								$status.text(response.data.message);
								setTimeout(function() {
									loadPluginsList();
									showNotification('success', response.data.message);
								}, 1000);

								// Update the status of each plugin in the table
								$.each(response.data.plugins_data, function(index, plugin) {
									var $row = $('tr[data-plugin-name="' + plugin.name.toLowerCase() + '"]');
									$row.removeClass('active inactive').addClass(plugin.status);
									$row.find('.plugin-activation-status .active, .plugin-activation-status .inactive').text(plugin.status.charAt(0).toUpperCase() + plugin.status.slice(1));
								});
							} else {
								showNotification('error', 'Error: ' + response.data);
							}
						},
						error: function() {
							showNotification('error', 'An error occurred while managing plugins.');
						}
					});
				});

				$('#download_list').on('click', function() {
					var pluginList = $('#plugin_list').val();
					var filename = $('#download_filename').val();
					var checkedPlugins = $('input[name="checked[]"]:checked').map(function() {
						return this.value;
					}).get();

					$.ajax({
						url: launchkitManager.ajax_url,
						type: 'POST',
						data: {
							action: 'download_plugin_list',
							plugin_list: pluginList,
							checked_plugins: checkedPlugins,
							filename: filename,
							nonce: launchkitManager.nonce
						},
						success: function(response) {
							if (response.success) {
								downloadTextFile(response.data.content, response.data.filename);
							} else {
								showNotification('error', 'Error: ' + response.data);
							}
						},
						error: function() {
							showNotification('error', 'An error occurred while preparing the download.');
						}
					});
				});

				$('#upload_list').on('click', function() {
					var fileInput = $('#upload_file')[0];
					if (fileInput.files.length === 0) {
						showNotification('error', 'Please select a file to upload.');
						return;
					}

					var formData = new FormData();
					formData.append('action', 'upload_plugin_list');
					formData.append('nonce', launchkitManager.nonce);
					formData.append('file', fileInput.files[0]);

					$.ajax({
						url: launchkitManager.ajax_url,
						type: 'POST',
						data: formData,
						processData: false,
						contentType: false,
						success: function(response) {
							if (response.success) {
								$('#plugin_list').val(response.data.content);
								showNotification('success', 'Recipe list uploaded successfully.');
							} else {
								showNotification('error', 'Error: ' + response.data);
							}
						},
						error: function() {
							showNotification('error', 'An error occurred while uploading the file.');
						}
					});
				});

				$('.sort-column').on('click', function(e) {
					e.preventDefault();
					var $this = $(this);
					var sortBy = $this.data('sort');
					var $table = $('.wp-list-table');
					var $rows = $table.find('tbody > tr').get();

					// Toggle sort order
					var sortOrder = $this.closest('.sortable').hasClass('asc') ? -1 : 1;

					$rows.sort(function(a, b) {
						var aValue = $(a).data('plugin-' + sortBy);
						var bValue = $(b).data('plugin-' + sortBy);

						if (sortBy === 'name') {
							return aValue.localeCompare(bValue) * sortOrder;
						} else {
							return (aValue - bValue) * sortOrder;
						}
					});

					$.each($rows, function(index, row) {
						$table.children('tbody').append(row);
					});

					// Remove sorting classes from all columns
					$('.sortable').removeClass('asc desc');

					// Add appropriate class to the clicked column
					$this.closest('.sortable').addClass(sortOrder === 1 ? 'asc' : 'desc');
				});
			}
			function downloadTextFile(content, filename) {
				var element = document.createElement('a');
				element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(content));
				element.setAttribute('download', filename);
				element.style.display = 'none';
				document.body.appendChild(element);
				element.click();
				document.body.removeChild(element);
			}

			function showNotification(type, message) {
				var $notification = $('#lk-notification');
				$notification.removeClass('notice-success notice-error').addClass('notice-' + type);
				$notification.html('<p>' + message + '</p>').show();

				$('html, body').animate({ scrollTop: 0 }, 'slow');

				setTimeout(function() {
					$notification.fadeOut('slow');
				}, 5000);
			}

			loadPluginsList();
		});
	})(jQuery);
</script>
<?php
	}

} // closing tag for WPLKManager class

// Instantiate the class outside of the class definition
$cwpk_manager = new CWPKManager();
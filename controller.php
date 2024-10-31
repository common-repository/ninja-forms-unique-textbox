<?php
/**
 * Plugin Name: Ninja Forms Unique Textbox
 * Plugin URI: http://wordpress.org/plugins/ninja-forms-unique-textbox/
 * Description: Extend Ninja Forms' functionality by allowing textbox field to have unique values.
 * Version: 1.0
 * Author: Fineswap
 * Author URI: http://fineswap.com/open-source/?utm_source=wordpress&utm_medium=plugin&utm_term=comment&utm_campaign=ninja-forms-unique-textbox
 * License: GPLv2
 *
 * Copyright (c) 2014 Fineswap. All rights reserved.
 * This plugin comes with NO WARRANTY WHATSOEVER. Use at your own risk.
 */

// Using namespaces is highly recommended.
namespace Org\Fineswap\OpenSource\NinjaFormsUniqueTextbox;

// Constants reused throughout the plugin.
define(__NAMESPACE__.'\L10N', 'ninja-forms-unique-textbox');
define(__NAMESPACE__.'\SLUG', 'ninja_forms_unique_textbox');
define(__NAMESPACE__.'\NAME', __('Ninja Forms Unique Textbox', L10N));
define(__NAMESPACE__.'\PATH', dirname(__FILE__) . '/');
define(__NAMESPACE__.'\TABLE', SLUG);
define(__NAMESPACE__.'\FIELD', '_text');
define(__NAMESPACE__.'\CHUNK', 100);

// Enclose all logic in one class.
class Controller {
  /**
   * Singleton instance.
   * @var Controller
   */
  private static $instance;

  /**
   * Get an singleton instance of this class.
   * @return Controller
   */
  static function &kickoff() {
    if(!self::$instance) {
      self::$instance = new self;
    }
    return self::$instance;
  }

  /**
   * Constructor.
   */
  private function __construct() {
    // Add a Rescan link in Plugins page.
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'rescan_link'));

    // Register activation/deactivation hooks.
    register_activation_hook(__FILE__, array($this, 'on_activate'));
    register_deactivation_hook(__FILE__, array($this, 'on_deactivate'));

    // Make sure that Ninja Forms is actually installed.
    add_action('admin_notices', array($this, 'admin_notices'));

    // Override internal implementation of textbox field.
    add_action('init', array($this, 'ninja_forms_register_field_textbox'), 15);

    // Extend Labels to support new ones.
    add_action('init', array($this, 'ninja_forms_register_label_settings_metabox'), 15);

    // Automatically populate or remove hash keys for unique fields.
    add_action('admin_init', array($this, 'ninja_forms_register_tab_field_settings'), 15);

    // Run a pre-process on the submitted values.
    add_action('ninja_forms_pre_process', array($this, 'ninja_forms_pre_process'));

    // Support Delete operations.
    add_action('wp_ajax_ninja_forms_delete_sub', 'ninja_forms_delete_sub', 5, 0);
  }

  /**
   * Inject a Rescan link right in the Plugins page.
   *
   * @param Array $links original links array
   * @return Array updated links array
   */
  function rescan_link($links) {
    if($this->is_rescan_requested()) {
      // Request rescan of the forms.
      $this->rescan_forms();
    }
    array_unshift($links, '<a href="plugins.php?action=' . SLUG . '" title="' . __('Rescan all Ninja Forms submissions') . '">' . __('Rescan') . '</a>');
    return $links;
  }

  /**
   * Prepare few options when plugin is activated.
   *
   * @global \wpdb $wpdb WordPress Database Access Abstraction Object.
   */
  function on_activate() {
    global $wpdb;

    // Get Ninja Forms' plugin settings.
    $settings = get_option('ninja_forms_settings');

    // If settings exist.
    if(!empty($settings)) {
      // Extend the settings.
      $settings = array_merge($settings, array(
        'unique_div_label' => __('One or more values in this form were previously submitted.', L10N),
        'unique_field_label' => __('This value has already been submitted.', L10N)
      ));

      // Update new settings.
      update_option('ninja_forms_settings', $settings);

      // Table name.
      $tableName = $wpdb->prefix . TABLE;

      // Needed for proper upgrading.
      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

      // Create the table.
      dbDelta(implode(' ', array(
        'CREATE TABLE IF NOT EXISTS',
          $tableName,
        '(',
          'user_id INT UNSIGNED DEFAULT NULL,',
          'field_id INT UNSIGNED NOT NULL,',
          'field_hash1 BIGINT UNSIGNED NOT NULL,',
          'field_hash2 BIGINT UNSIGNED NOT NULL,',
          'field_hash3 BIGINT UNSIGNED NOT NULL,',
          'field_hash4 BIGINT UNSIGNED NOT NULL,',
          'PRIMARY KEY (field_id, field_hash1, field_hash2, field_hash3, field_hash4)',
        ')'
      )));

      // Scan all existing forms and record hashes for all unique fields.
      // This could happen if the plugin was deactivated and then activated.
      $this->rescan_forms();
    }
  }

  /**
   * Prepare few options when plugin is activated.
   *
   * @global \wpdb $wpdb WordPress Database Access Abstraction Object.
   */
  function on_deactivate() {
    global $wpdb;

    // Remove previously added table.
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . TABLE);
  }

  /**
   * Add a notice near the top when Ninja Forms is not installed.
   */
  function admin_notices() {
    // Show a notice that Rescan is successful.
    if($this->is_rescan_requested()) {
?>
<div class="updated">
  <p><?php _e('Rescan finished successfully.', L10N); ?></p>
</div>
<?php
    }

    // Retrieve a list of all active plugins.
    $activePlugins = get_option('active_plugins', array());

    // Cycle through the plugins list.
    foreach($activePlugins as $plugin) {
      // Bail out (successfully) when detecting Ninja Forms installation.
      if(0 === strpos($plugin, 'ninja-forms/')) {
        return;
      }
    }

    // Otherwise, present an error at the top of the page.
?>
<div class="error">
  <p><span class="plugin-name"><?php echo NAME; ?>: </span><?php _e('Ninja Forms plugin is not installed.', L10N); ?></p>
</div>
<?php
  }

  /**
   * Extend of Ninja Forms' textbox field by adding additional options.
   *
   * @global Array $ninja_forms_fields Fields definition for all supported types.
   */
  function ninja_forms_register_field_textbox() {
    global $ninja_forms_fields;

    // Shortcut pointer.
    $fieldDef = &$ninja_forms_fields[FIELD];

    // Define a new field to appear first.
    array_unshift($fieldDef['edit_options'], array(
      'type' => 'checkbox',
      'name' => 'unique',
      'label' => __('Should this field accept unique values only?', L10N),
    ));
  }

  /**
   * Add additional entries in Labels tab.
   */
  function ninja_forms_register_label_settings_metabox() {
    // Add a new entry.
    ninja_forms_register_tab_metabox_options(array(
      'page' => 'ninja-forms-settings',
      'tab' => 'label_settings',
      'slug' => 'label_labels',
      'settings' => array(
        array(
          'name' => 'unique_div_label',
          'type' => 'text',
          'label' => __('Unique Field General Error', L10N),
          'desc' => __('General error message to show when one of the submission values has previously been submitted for a particular field.', 'ninja-forms'),
          'save_function' => ''
        ),
        array(
          'name' => 'unique_field_label',
          'type' => 'text',
          'label' => __('Unique Field Error', L10N),
          'desc' => __('Show this error message near each unique field when trying to submit a previously-submitted value.', 'ninja-forms'),
          'save_function' => ''
        ),
      )
    ));
  }

  /**
   * Change implementation of save fields in the backend.
   *
   * @global Array $ninja_forms_tabs Definitions of all tabs for a form.
   */
  function ninja_forms_register_tab_field_settings() {
    global $ninja_forms_tabs;

    // Shortcut pointer.
    $formSettings = &$ninja_forms_tabs['ninja-forms']['field_settings'];

    // Redirect save implementation to our own.
    $formSettings['save_function'] = array($this, 'ninja_forms_save_field_settings');
  }

  /**
   * Check for unique values when a Ninja Forms' form is submitted.
   *
   * @param Integer $formId Current form's id.
   * @param Array $data Definition of this form's fields.
   * @return String Updated message to display.
   */
  function ninja_forms_save_field_settings($formId, $data) {
    // Normalize the form id.
    $formId = @intval($formId);

    // Only existing forms should be treated.
    if(0 < $formId) {
      // Prepare a list of fields for inspection by rescan_form() method.
      $formFields = array();
      foreach($data as $fieldId => $fieldDef) {
        // The field id.
        $fieldId = str_replace('ninja_forms_field_', '', $fieldId);

        // Get existing definition for this field.
        $fieldMeta = ninja_forms_get_field_by_id($fieldId);

        // Update checkbox's value.
        $fieldMeta['data']['unique'] = $fieldDef['unique'];

        $formFields[] = $fieldMeta;
      }

      // Rescan this form's fields.
      $this->rescan_form($formId, $formFields);
    }

    // Continue with normal behavior of saving the field's details.
    return \ninja_forms_save_field_settings($formId, $data);
  }

  /**
   * Check for unique values when a Ninja Forms' form is submitted.
   *
   * @global \Ninja_Forms_Processing $ninja_forms_processing Ninja Forms Processing class.
   */
  function ninja_forms_pre_process() {
    global $ninja_forms_processing;

    // Get submitted values and their fields ids (as keys).
    $submittedFields = $ninja_forms_processing->get_all_submitted_fields();

    // Only proceed if there are indeed submitted values.
    if(!empty($submittedFields)) {
      // An array holding fields with non-unique values.
      $existingValues = array();

      // An array holding unique fields' meta data.
      $uniqueValues = array();

      // Cycle through the fields' values.
      foreach($submittedFields as $fieldId => $fieldValue) {
        // Only non-empty values are considered.
        if($fieldValue) {
          // Get meta data for this field.
          $fieldMeta = $ninja_forms_processing->get_field_settings($fieldId);

          // If field is a textbox and should be unique, validate it.
          if(FIELD == $fieldMeta['type'] && '1' == @$fieldMeta['data']['unique']) {
            // This is a unique field.
            $uniqueValues[$fieldId] = $fieldValue;

            // If field's value is not unique, record it.
            if(!$this->validate_field_value($fieldId, $fieldValue)) {
              $existingValues[] = $fieldId;
            }
          }
        }
      }

      // If there are unique fields, check the values.
      if(!empty($uniqueValues)) {
        if(empty($existingValues)) {
          // Get the logged-in user id.
          $userId = $ninja_forms_processing->get_user_ID();

          // Values are all unique, time to record them in our table.
          foreach($uniqueValues as $fieldId => &$fieldValue) {
            $this->record_unique_value($userId, $fieldId, $fieldValue);
          }
        } else {
          // Get Ninja Forms' settings.
          $ninja_plugin_settings = get_option('ninja_forms_settings');

          // Report a global error.
          $ninja_forms_processing->add_error('required-general', $ninja_plugin_settings['unique_div_label'], 'general');

          // Report for each individual field.
          foreach($existingValues as $fieldId) {
            $ninja_forms_processing->add_error('required-' . $fieldId, $ninja_plugin_settings['unique_field_label'], $fieldId);
          }
        }
      }
    }
  }

  /**
   * Validate the uniqueness of a textbox's value.
   *
   * @global \wpdb $wpdb WordPress Database Access Abstraction Object.
   * @param Integer $fieldId Field's unique id.
   * @param String $fieldValue Field's submitted value.
   * @return Boolean Returns TRUE if field's value is unique, FALSE otherwise.
   */
  private function validate_field_value($fieldId, &$fieldValue) {
    global $wpdb;

    // Get the unique hash value.
    $valueHash = $this->get_value_hash($fieldValue);

    // Construct the query.
    $query = array(
      'SELECT',
        'user_id',
      'FROM',
        $wpdb->prefix . TABLE,
      'WHERE',
        'field_id=' . $fieldId
    );

    // Add hash checks for the query.
    foreach($valueHash as $hashId => $hashValue) {
      $query[] = 'AND';
      $query[] = 'field_hash' . $hashId . '=' . $hashValue;
    }

    // Make the query a String.
    $query = implode(' ', $query);

    // Run the query and get the result.
    $result = $wpdb->get_row($query);

    // If there's no result, it means the field's value is unique.
    return empty($result);
  }

  /**
   * Store a hash of unique field's value.
   *
   * @global \wpdb $wpdb WordPress Database Access Abstraction Object.
   * @param Integer $userId Currently logged-in user id.
   * @param Integer $fieldId Unique field's id.
   * @param String $fieldValue Unique field's value.
   */
  private function record_unique_value($userId, $fieldId, &$fieldValue) {
    global $wpdb;

    // Normalize user's id.
    $userId = empty($userId) ? 0 : intval($userId);

    // Data to insert into table.
    $data = array(
      'user_id' => $userId,
      'field_id' => $fieldId
    );

    // Get hash for this value.
    $valueHash = $this->get_value_hash($fieldValue);

    // Add hash data.
    while(0 < ($hashId = count($valueHash))) {
      $data['field_hash' . $hashId] = array_pop($valueHash);
    }

    // Record field value's hash.
    $wpdb->replace($wpdb->prefix . TABLE, $data);
  }

  /**
   * Get a unique hash for the passed field's value.
   * The hash is returned as 4 integers (as an array).
   *
   * @param String $fieldValue The field's value
   * @return Array An array with 4 values
   */
  private function get_value_hash($fieldValue) {
    // Values must be lower-case.
    $fieldValue = @strtolower(trim($fieldValue));

    // Get a 40-byte hash representation of the value.
    $fieldValue = sha1($fieldValue, FALSE);

    // Divide the hash into 4 integers.
    $hash = array();
    for($loop = 0; 4 > $loop; $loop++) {
      $hash[1 + $loop] = hexdec(substr($fieldValue, 10 * $loop, 10));
    }

    return $hash;
  }

  /**
   * Hash all submissions for a particluar form that has unique fields.
   *
   * @global \wpdb $wpdb WordPress Database Access Abstraction Object.
   * @param Integer $formId Current form's id.
   * @param Array $formFields Definition of this form's fields.
   */
  function rescan_form($formId, $formFields) {
    global $wpdb;

    // Arrays holding unique and non-unique fields.
    $uniqueFields = array();
    $normalFields = array();

    // Go over the fields and check which one has a unique field.
    foreach($formFields as $fieldMeta) {
      // The field's id.
      $fieldId = @intval($fieldMeta['id']);

      // Continue field's id and type are valid.
      if(0 < $fieldId && FIELD == $fieldMeta['type']) {
        if('1' == @$fieldMeta['data']['unique']) {
          $uniqueFields[$fieldId] = TRUE;
        } else {
          $normalFields[] = $fieldId;
        }
      }
    }

    // Remove any previous hashes for normal fields.
    if(!empty($normalFields)) {
      // Construct the query.
      $query = implode(' ', array(
        'DELETE FROM',
          $wpdb->prefix . TABLE,
        'WHERE',
          'field_id IN',
            '(',
              implode(',', $normalFields),
            ')'
      ));

      // Send a query to clear the table.
      $wpdb->query($query);
    }

    // Add hashes for unique fields.
    if(!empty($uniqueFields)) {
      // Narrow down submissions by chunks.
      $args = array(
        'form_id' => $formId,
        'start' => 0,
        'limit' => CHUNK
      );

      // Loop through previous submissions gradually.
      while(!!($results = ninja_forms_get_subs($args))) {
        // Cycle through the results.
        foreach($results as &$row) {
          // If there are submitted data.
          if(!empty($row['data'])) {
            // Cycle through the fields.
            foreach($row['data'] as &$submission) {
              // Get the field's id.
              $fieldId = $submission['field_id'];

              // Is this a unique field?
              if(@$uniqueFields[$fieldId] && !empty($submission['user_value'])) {
                // Record this value in our table.
                $this->record_unique_value($row['user_id'], $fieldId, $submission['user_value']);
              }
            }
          }
        }

        // Advance to next chunk.
        $args['start'] += CHUNK;
        $args['limit'] = $args['start'] . ', ' . CHUNK;
      }
    }
  }

  /**
   * Rescan all Ninja Forms submissions and rehash unique values.
   *
   * @global \wpdb $wpdb WordPress Database Access Abstraction Object.
   */
  private function rescan_forms() {
    global $wpdb;

    // Zero-down the table.
    $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . TABLE);

    // Get details about all defined forms.
    $forms = ninja_forms_get_all_forms();

    // If there are forms.
    if(!empty($forms)) {
      // Cycle through the forms.
      foreach($forms as &$form) {
        // This is the form's id.
        $formId = @intval($form['id']);

        // Fetch the form's fields.
        $formFields = ninja_forms_get_fields_by_form_id($formId);

        // If there are fields for this form.
        if(!empty($formFields)) {
          $this->rescan_form($formId, $formFields);
        }
      }
    }
  }

  /**
   * Returns whether the Rescan link has been clicked.
   *
   * @return Boolean Returns TRUE if Rescan is requested, FALSE otherwise.
   */
  private function is_rescan_requested() {
    return isset($_GET['action']) && SLUG === $_GET['action'];
  }
}

// Kick-off the plugin.
Controller::kickoff();

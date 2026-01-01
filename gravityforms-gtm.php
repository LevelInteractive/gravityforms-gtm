<?php
/**
 * Plugin Name:       Gravity Forms - GTM Adapter
 * Plugin URI:        https://www.level.agency
 * Description:       A better GTM integration for Gravity Forms. Overrides the default confirmation behavior to allow for a redirect interstitial and a global submit event.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * License:           MIT
 * Author:            Derek Cavaliero
 * Author URI:        https://www.level.agency
 */

namespace Lvl\GravityForms\GTM;

use GFAPI;
 
if (! defined('WPINC'))
  die;

const VERSION = '1.0.0';

class Adapter
{
  public static string $_global_namespace = 'lvl';
  public static string $_plugin_namespace = 'gravityforms/gtm';

  public int $redirect_delay = 2000;
  public string $redirect_interstitial = "We are processing your submission. Please wait...";
  public string $redirect_spinner_color = '#000';

  private static $instance = null;
  
  public static function instance()
  {
    if (self::$instance === null)
      self::$instance = new self();

    return self::$instance;
  }

  public static function namespace(?string $append): string
  {
    $base = self::$_global_namespace . ':' . self::$_plugin_namespace;
    return $append ? $base . '/' . $append : $base;
  }

  public function __construct()
  {
    add_action('init', [$this, 'init']);
  }

  public function init()
  {
    add_action('wp_head', [$this, 'add_css_vars'], 1, 2);
    add_filter('gform_confirmation', [$this, 'confirmation_override'], 20, 4);
    add_filter('gform_form_args', [$this, 'force_ajax_submission_mode'], 10, 1);
    add_filter('gform_form_tag', [$this, 'modify_form_tag'], 20, 2);
    add_action('wp_enqueue_scripts', [$this, 'load_stylesheet'], 10, 2);
  }

  public function add_css_vars(): void
  {
    $vars = [
      '--gform-redirect-spinner-color' => apply_filters('lvl:gforms_gtm/redirect_spinner_color', $this->redirect_spinner_color),
    ];

    echo "<style>
    :root {" . implode(";", array_map(function($key, $value) {
      return "$key: $value";
    }, array_keys($vars), $vars)) . "}
    </style>";
  }

  public function load_stylesheet(): void
  {
    wp_enqueue_style(self::namespace('styles'), plugin_dir_url(__FILE__) . 'public/styles.css', [], '1.0.0'); 
  }

  public function force_ajax_submission_mode(array $form_args): array
  {
    $form_args['ajax'] = true;
    return $form_args;
  }

  public static function make_form_id(int $form_id): string
  {
    return 'gravity-forms:' . get_current_blog_id() . '-' . $form_id;
  }

  public static function make_provider_id(): string
  {
    return 'wordpress:' . $_SERVER['HTTP_HOST'];
  }

  public function modify_form_tag(string $form_tag, array $form): string
  {
    if (strpos($form_tag, 'data-form-name') !== false)
      return $form_tag;

    $form_tag = str_replace('data-formid', ' data-form-name="' . $form['title'] . '" data-formid', $form_tag);
    $form_tag = str_replace('data-formid', ' data-form-id="' . self::make_form_id($form['id']) . '" data-formid', $form_tag);

    return $form_tag;
  }

  public function get_redirect_interstitial(): string
  {
    $text = apply_filters('lvl:gforms_gtm/redirect_interstitial', $this->redirect_interstitial);

    $html = [
      '<div class="gform_interstitial_message">',
        '<div class="gform_interstitial_message_spinner"></div>',
        '<div class="gform_interstitial_message_text">' . $text . '</div>',
      '</div>',
    ];

    return implode("\n", $html);
  }

  public function confirmation_override(array|string $confirmation, array $form, array $entry, bool $ajax): array|string
  {
    $form_object = [
      'id' => self::make_form_id($form['id']),
      'name' => $form['title'],
      'platform' => self::make_provider_id(), // for backwards compatibility
      'provider' => self::make_provider_id(),
      'field_values' => self::get_named_fields_from_entry($form['id'], $entry),
    ];

    $global_submit_event = '
      <script>
      window.dataLayer = window.dataLayer || [];
      dataLayer.push({
        event: "lvl.form_submit",
        form: ' . json_encode($form_object) . ',
      });
      </script>
    ';

    $redirect_delay = apply_filters('lvl:gforms_gtm/redirect_delay', $this->redirect_delay);
    $redirect_interstitial = $this->get_redirect_interstitial();

    if (isset($confirmation['redirect'])) {

      $redirect = $confirmation['redirect'];
      $confirmation = $redirect_interstitial;
      $confirmation .= $global_submit_event;
      $confirmation .= '
        <script>
        setTimeout(function(){
          window.location.replace("' . $redirect . '");
        }, ' . $redirect_delay . ' );
        </script>
      ';

    } else {

      if (strpos($confirmation, 'gformRedirect') !== false) {

        preg_match('/document\.location\.href="(.*)";/', $confirmation, $matches);

        $confirmation = $global_submit_event;

        $confirmation .= '
          <script>
          setTimeout(function(){
            window.location.replace("' . $matches[1] . '");
          }, ' . $redirect_delay . ' );
          </script>
        ';

      } else {
        $confirmation .= $global_submit_event;
      }

    }
  
    return $confirmation;
  }

  public static function get_named_fields_from_entry(int $form_id, array $entry): array
  {
    $data = [
      'entry_id' => $entry['id'],
    ];

    $core_meta = [
      'payment_status',
      'payment_amount',
      'payment_method',
      'transaction_id',
      'transaction_type',
      'currency',
    ];

    foreach($entry as $key => $value) {
      if (in_array($key, $core_meta) && ! empty($value))
        $data[$key] = $value;

      // Custom meta with lvl: namespace.
      // e.g. lvl:custom_field
      if (str_starts_with($key, 'lvl:'))
        $data[explode(':', $key)[1]] = $value;
    }

    $fields = GFAPI::get_form($form_id)['fields'];

    foreach ($fields as $field) {

      if (! $field->allowsPrepopulate) 
        continue;

      if ($field->inputs) {
        foreach($field->inputs as $input) {

          // $field->type is the field type
          // $input['id'] is the field id
          // $input['name'] is the field name
          // $entry[$input['id']] is the field value

          if (empty($entry[$input['id']]))
              continue;

          if ($field->type == 'checkbox') {

            if (! isset($data[$field->inputName]))
              $data[$field->inputName] = [];

            $data[$field->inputName][] = $entry[$input['id']];

          } else {
            $key = in_array($field->type, ['address', 'name']) ? "{$field->type}.{$input['name']}" : $input['name'];
            $data[$key] = $entry[$input['id']];
          }
          
        }
      } else {
        if (empty($entry[$field->id]))
          continue;

        $value = $entry[$field->id];

        switch($field->type) {
          case 'multiselect':
            $value = json_decode($value);
            break;
        }

        $data[$field->inputName] = $value;
      }
    }

    $data = self::expand_nested_keys($data);

    return $data;
  }

  public static function expand_nested_keys(array $input): array
  {
    $output = [];
    
    foreach ($input as $key => $value) {

      if (strpos($key, '.') === false) {
        $output[$key] = $value;
        continue;
      }

      $keys = explode('.', $key);
      
      $current = &$output;
      
      foreach ($keys as $index => $key_part) {
        if ($index === count($keys) - 1) {
          $current[$key_part] = $value;
        } else {
          if (! isset($current[$key_part]) || ! is_array($current[$key_part])) {
            $current[$key_part] = [];
          }
          $current = &$current[$key_part];
        }
      }
      
      unset($current);
    }
    
    return $output;
  }

}

require_once __DIR__ . '/lib/Updater.php';
$updater = new Updater(__FILE__, VERSION);

register_activation_hook(__FILE__, [$updater, 'on_activate']);
register_deactivation_hook(__FILE__, [$updater, 'on_deactivate']);

Adapter::instance();
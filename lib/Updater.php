<?php

namespace Lvl\GravityForms\GTM;

if (! defined('WPINC'))
  die;

class Updater
{
  private string $file;
  private string $slug;
  private string $version;
  private string $type;
  
  private static string $base_uri = 'https://wordpress.level-cdn.com/api/packages';

  private ?array $remote_data = null;

  public function __construct(string $file, string $version, string $type = 'plugin') 
  {
    $this->file = $file;
    $this->slug = basename(dirname($this->file));
    $this->version = $version;
    $this->type = $type;

    add_action('admin_init', [$this, 'init']);
  }

  public function init(): void
  {
    if ($this->type === 'plugin') {
      add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
      add_filter('plugins_api', [$this, 'package_info'], 20, 3);
    } else {
      add_filter('pre_set_site_transient_update_themes', [$this, 'check_for_update']);
      add_filter('themes_api', [$this, 'package_info'], 20, 3);
    }

    add_filter('upgrader_pre_install', [$this, 'pre_install'], 10, 2);
  }

  private function get_endpoint(string $path = ''): string
  {
    return self::$base_uri . "/{$this->slug}" . $path;
  }

  public function check_for_update($transient) 
  {
    if (empty($transient->checked))
      return $transient;

    $identifier = $this->type === 'plugin' 
      ? "{$this->slug}/{$this->slug}.php" 
      : $this->slug;
        
    if (!isset($transient->checked[$identifier]))
      return $transient;

    $remote_data = $this->get_remote_data();
      
    if (!$remote_data)
      return $transient;

    $release = $remote_data['releases'][0] ?? null;

    if (!$release)
      return $transient;

    $remote_version = $release['version'] ?? false;

    if (!$remote_version || !version_compare($this->version, $remote_version, '<'))
      return $transient;

    if ($this->type === 'plugin') {
      $transient->response[$identifier] = (object) [
        'slug' => $this->slug,
        'plugin' => $identifier,
        'new_version' => $remote_version,
        'package' => $release['download_url'] ?? '',
        'url' => $release['html_url'] ?? '',
      ];
    } else {
      $transient->response[$identifier] = [
        'theme' => $this->slug,
        'new_version' => $remote_version,
        'package' => $release['download_url'] ?? '',
        'url' => $release['html_url'] ?? '',
      ];
    }

    return $transient;
  }

  public function pre_install($response, array $args): mixed
  {
    $is_plugin = isset($args['plugin']) && str_starts_with($args['plugin'], $this->slug . '/');
    $is_theme = isset($args['theme']) && $args['theme'] === $this->slug;

    if (!$is_plugin && !$is_theme)
      return $response;

    if (is_dir(dirname($this->file) . '/.git'))
      return new \WP_Error('git_present', "Update blocked: {$this->slug} contains a .git directory.");

    return $response;
  }

  public function package_info($result, $action, $args) 
  {
    $expected_action = $this->type === 'plugin' ? 'plugin_information' : 'theme_information';

    if ($action !== $expected_action)
      return $result;

    if (!isset($args->slug) || $args->slug !== $this->slug)
      return $result;

    $remote_data = $this->get_remote_data();

    if (!$remote_data)
      return $result;

    $package = $remote_data['package'] ?? [];
    $release = $remote_data['releases'][0] ?? [];

    return (object) [
      'name' => $package['name'] ?? $this->slug,
      'slug' => $this->slug,
      'version' => $release['version'] ?? $this->version,
      'author' => $release['author']['login'] ?? '',
      'author_profile' => $release['author']['url'] ?? '',
      'homepage' => $release['html_url'] ?? '',
      'download_link' => $release['download_url'] ?? '',
      'trunk' => $release['download_url'] ?? '',
      'last_updated' => $release['published_at'] ?? '',
      'sections' => [
        'description' => $package['name'] ?? '',
        'changelog' => $this->format_change_log($remote_data['releases'] ?? []),
      ],
    ];
  }

  public static function get_environment_versions(): array
  {
    global $wpdb;

    return [
      'php' => PHP_VERSION,
      'wordpress' => get_bloginfo('version'),
      'mysql' => $wpdb->db_version(),
    ];
  }

  public static function get_default_headers(): array 
  {
    $versions = self::get_environment_versions();

    return [
      'Accept' => 'application/json',
      'User-Agent' => 'Lvl/WordPress/Updater',
      'X-WordPress-Version' => $versions['wordpress'],
      'X-PHP-Version' => $versions['php'],
      'X-MySQL-Version' => $versions['mysql'],
      'X-WordPress-Hostname' => parse_url(home_url(), PHP_URL_HOST),
      'X-Server-Software' => $_SERVER['SERVER_SOFTWARE'] ?? '?',
    ];
  }

  private function get_remote_data(): array|false
  {
    if ($this->remote_data !== null)
      return $this->remote_data;

    $response = wp_remote_get($this->get_endpoint() . '?action=version_check', [
      'timeout' => 10,
      'headers' => self::get_default_headers(),
    ]);

    if (is_wp_error($response))
      return false;

    $status_code = wp_remote_retrieve_response_code($response);
    
    if ($status_code !== 200)
      return false;

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE)
      return false;

    $this->remote_data = $data;
    return $this->remote_data;
  }

  private function format_change_log(array $releases = []): string
  {
    foreach($releases as $release) {

      if (empty($release['notes']['html']))
        continue;

      $changelog[] = "<h3>{$release['tag_name']}</h3>" . $release['notes']['html'];
    }

    return implode('', $changelog);
  }

  public function send_analytic_event(string $action): void
  {
    wp_remote_post($this->get_endpoint("/analytics/{$action}"), [
      'timeout' => 5,
      'headers' => self::get_default_headers(),
      'body' => [
        'php_version' => PHP_VERSION,
      ],
    ]);
  }

  public function on_activate(): void
  {
    $this->send_analytic_event('activate');
  }

  public function on_deactivate(): void
  {
    $this->send_analytic_event('deactivate');
  }
}
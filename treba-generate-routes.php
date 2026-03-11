<?php
/**
 * Plugin Name: Route Article Generator
 * Plugin URI:  https://example.com/
 * Description: Генерує чернеткові статті для маршрутів через OpenRouter AI.
 * Version:     1.0.0
 * Author:      Your Name
 * Text Domain: treba-generate-routes
 * License:     GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define('TGR_VERSION', '1.0.0');
define('TGR_DIR', plugin_dir_path(__FILE__));
define('TGR_URL', plugin_dir_url(__FILE__));
define('TGR_BASENAME', plugin_basename(__FILE__));

// ---------------------------------------------------------------------------
// Autoload includes
// ---------------------------------------------------------------------------
foreach ([
  'class-settings',
  'class-csv-handler',
  'class-openrouter',
  'class-generator',
  'class-ajax-handler',
] as $file) {
  require_once TGR_DIR . 'includes/' . $file . '.php';
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action('plugins_loaded', function () {
  TGR_Settings::get_instance()->init();
  TGR_Ajax_Handler::get_instance()->init();
});

// ---------------------------------------------------------------------------
// Admin menu
// ---------------------------------------------------------------------------
add_action('admin_menu', function () {
  add_menu_page(
    __('Route Generator', 'treba-generate-routes'),
    __('Route Generator', 'treba-generate-routes'),
    'manage_options',
    'tgr-generate',
    [TGR_Settings::get_instance(), 'render_generate_page'],
    'dashicons-location-alt',
    26
  );

  add_submenu_page(
    'tgr-generate',
    __('Generate', 'treba-generate-routes'),
    __('Generate', 'treba-generate-routes'),
    'manage_options',
    'tgr-generate',
    [TGR_Settings::get_instance(), 'render_generate_page']
  );

  add_submenu_page(
    'tgr-generate',
    __('Settings', 'treba-generate-routes'),
    __('Settings', 'treba-generate-routes'),
    'manage_options',
    'tgr-settings',
    [TGR_Settings::get_instance(), 'render_settings_page']
  );
});

// ---------------------------------------------------------------------------
// Enqueue admin assets
// ---------------------------------------------------------------------------
add_action('admin_enqueue_scripts', function ($hook) {
  $allowed = ['toplevel_page_tgr-generate', 'route-generator_page_tgr-settings'];
  if (!in_array($hook, $allowed, true)) {
    return;
  }

  wp_enqueue_style(
    'tgr-admin',
    TGR_URL . 'assets/css/admin.css',
    [],
    TGR_VERSION
  );

  wp_enqueue_script(
    'tgr-admin',
    TGR_URL . 'assets/js/admin.js',
    ['jquery'],
    TGR_VERSION,
    true
  );

  wp_localize_script('tgr-admin', 'tgrData', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonceCsv' => wp_create_nonce('tgr_parse_csv'),
    'nonceGen' => wp_create_nonce('tgr_generate_route'),
    'i18n' => [
      'created' => __('Створено', 'treba-generate-routes'),
      'skipped' => __('Пропущено (вже існує)', 'treba-generate-routes'),
      'error' => __('Помилка', 'treba-generate-routes'),
      'done' => __('Готово!', 'treba-generate-routes'),
    ],
  ]);
});

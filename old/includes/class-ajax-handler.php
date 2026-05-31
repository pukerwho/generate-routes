<?php
defined('ABSPATH') || exit;

/**
 * Registers and handles all AJAX endpoints for the Routes plugin.
 * Both endpoints are admin-only (no nopriv registration).
 */
class TGR_Ajax_Handler
{

  private static ?TGR_Ajax_Handler $instance = null;

  public static function get_instance(): self
  {
    if (null === self::$instance) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private function __construct()
  {
  }

  public function init(): void
  {
    add_action('wp_ajax_tgr_parse_csv', [$this, 'handle_parse_csv']);
    add_action('wp_ajax_tgr_generate_route', [$this, 'handle_generate_route']);
    // Intentionally NO wp_ajax_nopriv_* registration — these are admin-only.
  }

  // -----------------------------------------------------------------------
  // AJAX: parse CSV
  // -----------------------------------------------------------------------

  public function handle_parse_csv(): void
  {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Недостатньо прав.', 'treba-generate-routes')], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'tgr_parse_csv')) {
      wp_send_json_error(['message' => __('Недійсний токен безпеки.', 'treba-generate-routes')], 403);
    }

    try {
      $handler = new TGR_CSV_Handler();
      $routes = $handler->parse_upload();

      if (empty($routes)) {
        wp_send_json_error(['message' => __('Список маршрутів порожній.', 'treba-generate-routes')]);
      }

      wp_send_json_success(['routes' => $routes]);

    } catch (RuntimeException $e) {
      wp_send_json_error(['message' => $e->getMessage()]);
    }
  }

  // -----------------------------------------------------------------------
  // AJAX: generate one route
  // -----------------------------------------------------------------------

  public function handle_generate_route(): void
  {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => __('Недостатньо прав.', 'treba-generate-routes')], 403);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'tgr_generate_route')) {
      wp_send_json_error(['message' => __('Недійсний токен безпеки.', 'treba-generate-routes')], 403);
    }

    // Receive route data as JSON string
    $raw_route = isset($_POST['route']) ? wp_unslash($_POST['route']) : '';
    if (empty($raw_route)) {
      wp_send_json_error(['message' => __('Дані маршруту не передані.', 'treba-generate-routes')]);
    }

    $route = json_decode($raw_route, true);
    if (!is_array($route)) {
      wp_send_json_error(['message' => __('Некоректний формат даних маршруту.', 'treba-generate-routes')]);
    }

    // Sanitize every field
    $sanitized = [];
    $text_fields = ['title', 'route_number', 'route_type', 'city', 'distance', 'interval', 'travel_time', 'carrier', 'price', 'model'];
    $textarea_fields = ['stops_forward', 'stops_backward'];

    foreach ($text_fields as $f) {
      $sanitized[$f] = isset($route[$f]) ? sanitize_text_field((string) $route[$f]) : '';
    }
    foreach ($textarea_fields as $f) {
      $sanitized[$f] = isset($route[$f]) ? sanitize_textarea_field((string) $route[$f]) : '';
    }

    $generator = new TGR_Generator();
    $result = $generator->process($sanitized);

    if ($result['status'] === 'error') {
      wp_send_json_error($result);
    }

    wp_send_json_success($result);
  }
}

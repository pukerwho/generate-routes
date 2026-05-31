<?php
defined('ABSPATH') || exit;

/**
 * OpenRouter API client.
 * All API calls go through this class — the key never touches the browser.
 */
class TGR_OpenRouter
{

  const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

  /** HTTP timeout in seconds for a single text generation request. */
  const TIMEOUT = 60;

  /** HTTP timeout in seconds for an image generation request (slower). */
  const IMAGE_TIMEOUT = 120;

  private string $api_key;
  private string $model;

  public function __construct(string $api_key, string $model)
  {
    $this->api_key = $api_key;
    $this->model = $model;
  }

  /**
   * Sends a prompt to OpenRouter and returns the generated text.
   *
   * @param string $prompt The full prompt to send.
   * @return string|WP_Error Generated text or WP_Error on failure.
   */
  public function generate(string $prompt)
  {
    if (empty($this->api_key)) {
      return new WP_Error('no_api_key', __('API ключ не налаштовано.', 'treba-generate-routes'));
    }

    $body = wp_json_encode([
      'model' => $this->model,
      'messages' => [
        [
          'role' => 'user',
          'content' => $prompt,
        ],
      ],
    ]);

    if ($body === false) {
      return new WP_Error('json_encode', __('Помилка підготовки запиту.', 'treba-generate-routes'));
    }

    $response = wp_remote_post(self::API_URL, [
      'timeout' => self::TIMEOUT,
      'headers' => [
        'Authorization' => 'Bearer ' . $this->api_key,
        'Content-Type' => 'application/json',
        'HTTP-Referer' => home_url(),
        'X-Title' => get_bloginfo('name'),
      ],
      'body' => $body,
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $data = json_decode($raw_body, true);

    if ($http_code !== 200) {
      $error_msg = isset($data['error']['message'])
        ? sanitize_text_field($data['error']['message'])
        : sprintf(__('HTTP помилка: %d', 'treba-generate-routes'), $http_code);
      return new WP_Error('api_error', $error_msg);
    }

    if (empty($data['choices'][0]['message']['content'])) {
      return new WP_Error('empty_response', __('Порожня відповідь від API.', 'treba-generate-routes'));
    }

    return (string) $data['choices'][0]['message']['content'];
  }

  /**
   * Generates an image and returns the decoded binary plus its MIME type.
   *
   * @param string $prompt The image prompt to send.
   * @return array{mime: string, bytes: string}|WP_Error
   */
  public function generate_image(string $prompt)
  {
    if (empty($this->api_key)) {
      return new WP_Error('no_api_key', __('API ключ не налаштовано.', 'treba-generate-routes'));
    }

    $body = wp_json_encode([
      'model' => $this->model,
      // Gemini image models return both an image and text.
      'modalities' => ['image', 'text'],
      'messages' => [
        [
          'role' => 'user',
          'content' => $prompt,
        ],
      ],
    ]);

    if ($body === false) {
      return new WP_Error('json_encode', __('Помилка підготовки запиту зображення.', 'treba-generate-routes'));
    }

    $response = wp_remote_post(self::API_URL, [
      'timeout' => self::IMAGE_TIMEOUT,
      'headers' => [
        'Authorization' => 'Bearer ' . $this->api_key,
        'Content-Type' => 'application/json',
        'HTTP-Referer' => home_url(),
        'X-Title' => get_bloginfo('name'),
      ],
      'body' => $body,
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ($http_code !== 200) {
      $error_msg = isset($data['error']['message'])
        ? sanitize_text_field($data['error']['message'])
        : sprintf(__('HTTP помилка: %d', 'treba-generate-routes'), $http_code);
      return new WP_Error('api_error', $error_msg);
    }

    // Image is delivered as a data URL in message.images[].image_url.url
    $url = $data['choices'][0]['message']['images'][0]['image_url']['url'] ?? '';
    if (!is_string($url) || $url === '') {
      return new WP_Error('no_image', __('API не повернув зображення.', 'treba-generate-routes'));
    }

    if (!preg_match('#^data:([a-z0-9.+-]+/[a-z0-9.+-]+);base64,(.+)$#is', $url, $m)) {
      return new WP_Error('bad_image', __('Некоректний формат зображення від API.', 'treba-generate-routes'));
    }

    $bytes = base64_decode($m[2], true);
    if ($bytes === false || $bytes === '') {
      return new WP_Error('bad_image', __('Не вдалося декодувати зображення.', 'treba-generate-routes'));
    }

    return ['mime' => strtolower($m[1]), 'bytes' => $bytes];
  }
}

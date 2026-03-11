<?php
defined('ABSPATH') || exit;

/**
 * Manages plugin settings: API key, model, prompt, post type.
 * Renders both admin pages (Settings and Generate).
 */
class TGR_Settings
{

  private static ?TGR_Settings $instance = null;

  // Option keys
  const OPT_API_KEY = 'tgr_api_key';
  const OPT_MODEL = 'tgr_model';
  const OPT_PROMPT = 'tgr_prompt';
  const OPT_POST_TYPE = 'tgr_post_type';

  // Transient key for cached model list
  const TRANSIENT_MODELS = 'tgr_models_cache';

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
    add_action('admin_post_tgr_save_settings', [$this, 'handle_save_settings']);
  }

  // -----------------------------------------------------------------------
  // Getters
  // -----------------------------------------------------------------------

  public function get_api_key(): string
  {
    $raw = get_option(self::OPT_API_KEY, '');
    return $raw ? $this->decrypt($raw) : '';
  }

  public function get_model(): string
  {
    return sanitize_text_field(get_option(self::OPT_MODEL, 'openai/gpt-4o-mini'));
  }

  public function get_prompt(): string
  {
    return get_option(self::OPT_PROMPT, '');
  }

  public function get_post_type(): string
  {
    $pt = get_option(self::OPT_POST_TYPE, 'post');
    $valid = get_post_types(['public' => true], 'names');
    return in_array($pt, $valid, true) ? $pt : 'post';
  }

  // -----------------------------------------------------------------------
  // Save handler
  // -----------------------------------------------------------------------

  public function handle_save_settings(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Недостатньо прав.', 'treba-generate-routes'), 403);
    }

    check_admin_referer('tgr_save_settings', 'tgr_settings_nonce');

    // API key: only update if a new non-empty value was provided
    $raw_key = isset($_POST['tgr_api_key']) ? trim(wp_unslash($_POST['tgr_api_key'])) : '';
    if ($raw_key !== '') {
      if (!preg_match('/^[A-Za-z0-9\-_:]{10,300}$/', $raw_key)) {
        wp_die(esc_html__('Некоректний формат API ключа.', 'treba-generate-routes'), 400);
      }
      update_option(self::OPT_API_KEY, $this->encrypt($raw_key), false);
    }

    // Model slug
    $model = isset($_POST['tgr_model']) ? sanitize_text_field(wp_unslash($_POST['tgr_model'])) : '';
    if ($model !== '') {
      update_option(self::OPT_MODEL, $model, false);
    }

    // Prompt
    $prompt = isset($_POST['tgr_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['tgr_prompt'])) : '';
    update_option(self::OPT_PROMPT, $prompt, false);

    // Post type
    $post_type = isset($_POST['tgr_post_type']) ? sanitize_key(wp_unslash($_POST['tgr_post_type'])) : 'post';
    $allowed_types = get_post_types(['public' => true], 'names');
    if (in_array($post_type, $allowed_types, true)) {
      update_option(self::OPT_POST_TYPE, $post_type, false);
    }

    delete_transient(self::TRANSIENT_MODELS);

    wp_safe_redirect(add_query_arg(['page' => 'tgr-settings', 'saved' => '1'], admin_url('admin.php')));
    exit;
  }

  // -----------------------------------------------------------------------
  // Model list (fetched from OpenRouter, cached 24 h)
  // -----------------------------------------------------------------------

  public function get_models(): array
  {
    $cached = get_transient(self::TRANSIENT_MODELS);
    if (is_array($cached) && !empty($cached)) {
      return $cached;
    }

    $api_key = $this->get_api_key();
    if (empty($api_key)) {
      return [];
    }

    $response = wp_remote_get('https://openrouter.ai/api/v1/models', [
      'timeout' => 15,
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type' => 'application/json',
      ],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
      return [];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['data']) || !is_array($body['data'])) {
      return [];
    }

    $models = [];
    foreach ($body['data'] as $m) {
      if (!empty($m['id']) && !empty($m['name'])) {
        $models[] = [
          'id' => sanitize_text_field($m['id']),
          'name' => sanitize_text_field($m['name']),
        ];
      }
    }

    usort($models, fn($a, $b) => strcmp($a['name'], $b['name']));
    set_transient(self::TRANSIENT_MODELS, $models, DAY_IN_SECONDS);

    return $models;
  }

  // -----------------------------------------------------------------------
  // Encryption helpers (key derived from WP salts)
  // -----------------------------------------------------------------------

  private function get_encryption_key(): string
  {
    return substr(hash('sha256', wp_salt('auth') . wp_salt('secure_auth')), 0, 32);
  }

  private function encrypt(string $value): string
  {
    $key = $this->get_encryption_key();
    $iv = random_bytes(16);
    $enc = openssl_encrypt($value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
  }

  private function decrypt(string $value): string
  {
    $key = $this->get_encryption_key();
    $data = base64_decode($value, true);
    if ($data === false || strlen($data) < 17) {
      return '';
    }
    $iv = substr($data, 0, 16);
    $enc = substr($data, 16);
    $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $dec !== false ? $dec : '';
  }

  // -----------------------------------------------------------------------
  // Render: Settings page
  // -----------------------------------------------------------------------

  public function render_settings_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Недостатньо прав.', 'treba-generate-routes'));
    }

    $saved = isset($_GET['saved']) && $_GET['saved'] === '1';
    $models = $this->get_models();
    $cur_model = $this->get_model();
    $cur_prompt = $this->get_prompt();
    $cur_pt = $this->get_post_type();
    $post_types = get_post_types(['public' => true], 'objects');
    $has_key = $this->get_api_key() !== '';
    ?>
    <div class="wrap tgr-wrap">
      <h1 class="tgr-page-title">
        <?php esc_html_e('Route Generator — Налаштування', 'treba-generate-routes'); ?>
      </h1>

      <?php if ($saved): ?>
        <div class="notice notice-success is-dismissible">
          <p>
            <?php esc_html_e('Налаштування збережено.', 'treba-generate-routes'); ?>
          </p>
        </div>
      <?php endif; ?>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tgr-form">
        <?php wp_nonce_field('tgr_save_settings', 'tgr_settings_nonce'); ?>
        <input type="hidden" name="action" value="tgr_save_settings">

        <div class="tgr-card">
          <h2>
            <?php esc_html_e('OpenRouter API', 'treba-generate-routes'); ?>
          </h2>

          <div class="tgr-field">
            <label for="tgr_api_key">
              <?php esc_html_e('API Ключ', 'treba-generate-routes'); ?>
            </label>
            <input type="password" id="tgr_api_key" name="tgr_api_key" class="regular-text" autocomplete="new-password"
              placeholder="<?php echo $has_key ? esc_attr__('••••• (вже збережено, введіть новий щоб оновити)', 'treba-generate-routes') : esc_attr__('sk-or-...', 'treba-generate-routes'); ?>">
            <p class="description">
              <?php esc_html_e('Ключ зберігається в зашифрованому вигляді. Залиште порожнім, щоб не змінювати.', 'treba-generate-routes'); ?>
            </p>
          </div>

          <div class="tgr-field">
            <label for="tgr_model">
              <?php esc_html_e('Модель AI (за замовчуванням)', 'treba-generate-routes'); ?>
            </label>
            <?php if (!$has_key): ?>
              <p class="description" style="color:#c00">
                <?php esc_html_e('Спочатку збережіть API ключ — тоді список моделей завантажиться автоматично.', 'treba-generate-routes'); ?>
              </p>
            <?php elseif (empty($models)): ?>
              <p class="description" style="color:#c00">
                <?php esc_html_e('Не вдалося завантажити список моделей. Перевірте API ключ.', 'treba-generate-routes'); ?>
              </p>
            <?php else: ?>
              <select id="tgr_model" name="tgr_model" class="regular-text">
                <?php foreach ($models as $m): ?>
                  <option value="<?php echo esc_attr($m['id']); ?>" <?php selected($cur_model, $m['id']); ?>>
                    <?php echo esc_html($m['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description">
                <?php esc_html_e('Можна перевизначити для кожного рядка через стовпець «model» у CSV.', 'treba-generate-routes'); ?>
              </p>
            <?php endif; ?>
          </div>
        </div>

        <div class="tgr-card">
          <h2>
            <?php esc_html_e('Промт', 'treba-generate-routes'); ?>
          </h2>

          <div class="tgr-field">
            <label for="tgr_prompt">
              <?php esc_html_e('Шаблон промту', 'treba-generate-routes'); ?>
            </label>
            <textarea id="tgr_prompt" name="tgr_prompt" rows="10" class="large-text"
              placeholder="<?php esc_attr_e('Напиши статтю про маршрут {route_number} ({route_type}) у місті {city}...', 'treba-generate-routes'); ?>"><?php echo esc_textarea($cur_prompt); ?></textarea>
            <p class="description">
              <?php esc_html_e('Доступні змінні:', 'treba-generate-routes'); ?>
              <code>{title}</code>, <code>{route_number}</code>, <code>{route_type}</code>,
              <code>{city}</code>, <code>{distance}</code>, <code>{interval}</code>,
              <code>{travel_time}</code>, <code>{carrier}</code>, <code>{price}</code>,
              <code>{stops_forward}</code>, <code>{stops_backward}</code>
            </p>
          </div>
        </div>

        <div class="tgr-card">
          <h2>
            <?php esc_html_e('Тип запису', 'treba-generate-routes'); ?>
          </h2>

          <div class="tgr-field">
            <label for="tgr_post_type">
              <?php esc_html_e('Публікувати як', 'treba-generate-routes'); ?>
            </label>
            <select id="tgr_post_type" name="tgr_post_type" class="regular-text">
              <?php foreach ($post_types as $pt): ?>
                <option value="<?php echo esc_attr($pt->name); ?>" <?php selected($cur_pt, $pt->name); ?>>
                  <?php echo esc_html($pt->labels->singular_name); ?> (
                  <?php echo esc_html($pt->name); ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <p>
          <?php submit_button(__('Зберегти налаштування', 'treba-generate-routes'), 'primary', 'submit', false); ?>
        </p>
      </form>
    </div>
    <?php
  }

  // -----------------------------------------------------------------------
  // Render: Generate page
  // -----------------------------------------------------------------------

  public function render_generate_page(): void
  {
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('Недостатньо прав.', 'treba-generate-routes'));
    }

    $has_key = $this->get_api_key() !== '';
    $has_prompt = trim($this->get_prompt()) !== '';
    $post_type = $this->get_post_type();
    $model = $this->get_model();
    ?>
    <div class="wrap tgr-wrap">
      <h1 class="tgr-page-title">
        <?php esc_html_e('Route Generator — Генерація', 'treba-generate-routes'); ?>
      </h1>

      <?php if (!$has_key || !$has_prompt): ?>
        <div class="notice notice-warning">
          <p>
            <?php esc_html_e('Будь ласка, спочатку заповніть', 'treba-generate-routes'); ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=tgr-settings')); ?>">
              <?php esc_html_e('налаштування плагіна', 'treba-generate-routes'); ?>
            </a>.
          </p>
        </div>
      <?php endif; ?>

      <div class="tgr-card">
        <h2>
          <?php esc_html_e('1. Завантажте CSV файл', 'treba-generate-routes'); ?>
        </h2>
        <p class="description">
          <?php esc_html_e('CSV повинен містити стовпці: title, route_number, route_type, city, distance, interval, travel_time, carrier, price, stops_forward, stops_backward. Стовпець model — опційний (перевизначає модель для рядка). Кодування UTF-8.', 'treba-generate-routes'); ?>
        </p>
        <div class="tgr-field">
          <input type="file" id="tgr-csv-file" accept=".csv" <?php disabled(!$has_key || !$has_prompt); ?>>
          <button id="tgr-parse-btn" class="button button-secondary" <?php disabled(!$has_key || !$has_prompt); ?>>
            <?php esc_html_e('Завантажити та розібрати CSV', 'treba-generate-routes'); ?>
          </button>
          <span id="tgr-csv-status"></span>
        </div>
      </div>

      <div class="tgr-card" id="tgr-routes-card" style="display:none">
        <h2>
          <?php esc_html_e('2. Маршрути для генерації', 'treba-generate-routes'); ?>
        </h2>
        <div id="tgr-routes-list" class="tgr-routes-list"></div>

        <div class="tgr-meta">
          <span>
            <?php esc_html_e('Модель (за замовч.):', 'treba-generate-routes'); ?> <strong>
              <?php echo esc_html($model); ?>
            </strong>
          </span>
          &nbsp;|&nbsp;
          <span>
            <?php esc_html_e('Тип запису:', 'treba-generate-routes'); ?> <strong>
              <?php echo esc_html($post_type); ?>
            </strong>
          </span>
        </div>

        <div class="tgr-actions">
          <button id="tgr-generate-btn" class="button button-primary">
            <?php esc_html_e('▶ Генерувати', 'treba-generate-routes'); ?>
          </button>
          <button id="tgr-stop-btn" class="button" style="display:none">
            <?php esc_html_e('■ Зупинити', 'treba-generate-routes'); ?>
          </button>
        </div>
      </div>

      <div class="tgr-card" id="tgr-progress-card" style="display:none">
        <h2>
          <?php esc_html_e('3. Прогрес', 'treba-generate-routes'); ?>
        </h2>
        <div class="tgr-progress-bar-wrap">
          <div class="tgr-progress-bar" id="tgr-progress-bar"></div>
        </div>
        <p id="tgr-progress-text"></p>
        <div id="tgr-log" class="tgr-log"></div>
      </div>
    </div>
    <?php
  }
}

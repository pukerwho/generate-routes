<?php
/*
Plugin Name: Treba Generate Routes
Description: Generate routes for your website via ChatGPT.
Version: 1.0.0
Author: Treba
*/

if (!defined('ABSPATH')) {
    exit();
}

if (!class_exists('Parsedown')) {
    require_once __DIR__ . '/includes/class-parsedown.php';
}

final class Treba_Routes_Ai_Content_Plugin
{
    private $allowed_users_option = 'trgr_allowed_users';
    private $api_key_options = [
        'openai' => 'trgr_api_key_openai',
        'openrouter' => 'trgr_api_key_openrouter',
    ];
    private $default_provider_option = 'trgr_default_provider';
    private $default_models_option = 'trgr_default_models';
    private $default_post_type_option = 'trgr_default_post_type';
    private $templates_option = 'trgr_templates';
    private $menu_slug = 'treba-routes-ai';
    private $notices = [];
    private $errors = [];
    private $reset_form = false;
    private $templates = [];
    private $markdown_parser;
    private $cached_api_keys = [];
    private $encryption_key = null;
    private $csv_session_ttl = HOUR_IN_SECONDS;
    private $csv_default_chunk = 3;
    private $csv_required_fields = [
        'title',
        'route_number',
        'route_type',
        'city',
        'distance',
        'interval',
        'travel_time',
        'carrier',
        'price',
        'stops_forward',
        'stops_backward',
    ];
    private $csv_optional_fields = ['template', 'model'];
    private $providers = [
        'openai' => 'OpenAI',
        'openrouter' => 'OpenRouter',
    ];
    private $models = [
        'openai' => [
            'gpt-4.1' => 'GPT-4.1 (детальні відповіді)',
            'gpt-4.1-mini' => 'GPT-4.1 mini (довші відповіді)',
            'gpt-4o-mini' => 'GPT-4o mini (швидко та дешево)',
            'gpt-4o' => 'GPT-4o (висока якість)',
            'gpt-4o-mini-realtime' =>
                'GPT-4o mini Realtime (потоки, експериментальна)',
            'gpt-4.1-preview' => 'GPT-4.1 Preview (альтернативна версія, beta)',
            'o1-mini' => 'o1-mini (експериментальна)',
            'o1-preview' => 'o1-preview (експериментальна)',
            'gpt-5-mini' => 'GPT-5 mini',
            'gpt-5-nano' => 'GPT-5 nano',
        ],
        'openrouter' => [
            'openrouter/auto' => 'OpenRouter Auto (розумний роутинг)',
            'openai/gpt-4o' => 'OpenRouter · OpenAI GPT-4o',
            'openai/gpt-4o-mini' => 'OpenRouter · OpenAI GPT-4o mini',
            'openai/gpt-4.1' => 'OpenRouter · OpenAI GPT-4.1',
            'openai/gpt-4.1-mini' => 'OpenRouter · OpenAI GPT-4.1 mini',
            'anthropic/claude-3.5-sonnet' => 'OpenRouter · Claude 3.5 Sonnet',
            'anthropic/claude-3.5-haiku' => 'OpenRouter · Claude 3.5 Haiku',
            'meta-llama/llama-3.1-8b-instruct' =>
                'OpenRouter · Llama 3.1 8B Instruct',
            'meta-llama/llama-3.1-70b-instruct' =>
                'OpenRouter · Llama 3.1 70B Instruct',
            'mistralai/mixtral-8x7b-instruct' =>
                'OpenRouter · Mixtral 8x7B Instruct',
            'mistralai/mixtral-8x22b-instruct' =>
                'OpenRouter · Mixtral 8x22B Instruct',
            'qwen/qwen-2-72b-instruct' => 'OpenRouter · Qwen2 72B Instruct',
            'qwen/qwen-2-7b-instruct' => 'OpenRouter · Qwen2 7B Instruct',
            'nvidia/nemotron-3-nano-30b-a3b:free' =>
                'OpenRouter · Nemotron 3 Nano 30B (free)',
            'mistralai/devstral-2512:free' =>
                'OpenRouter · DevStral 2512 (free)',
            'x-ai/grok-4-fast' => 'OpenRouter · Grok 4 Fast',
            'x-ai/grok-4.1-fast' => 'OpenRouter · Grok 4.1 Fast',
            'google/gemini-3-flash-preview' =>
                'OpenRouter · Gemini 3 Flash preview',
            'google/gemini-2.5-flash' => 'OpenRouter · Gemini 2.5 Flash',
            'google/gemini-2.0-flash-001' =>
                'OpenRouter · Gemini 2.0 Flash 001',
            'deepseek/deepseek-v3.2' => 'OpenRouter · DeepSeek V3.2',
            'deepseek/deepseek-chat-v3-0324' =>
                'OpenRouter · DeepSeek Chat V3 0324',
            'kwaipilot/kat-coder-pro:free' =>
                'OpenRouter · KAT Coder Pro (free)',
            'qwen/qwen3-235b-a22b-2507' =>
                'OpenRouter · Qwen3 235B A22B (2507)',
        ],
    ];

    public function __construct()
    {
        $this->load_templates();
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'handle_form_submissions']);
        add_action('wp_ajax_tgpt_csv_generate_batch', [
            $this,
            'ajax_generate_csv_batch',
        ]);
    }

    private function load_templates()
    {
        $stored = get_option($this->templates_option, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $normalized = [];

        foreach ($stored as $id => $template) {
            if (!is_array($template)) {
                continue;
            }

            $normalized_id = $this->sanitize_template_id($id);
            $label = isset($template['label'])
                ? sanitize_text_field((string) $template['label'])
                : '';
            $prompt = isset($template['prompt'])
                ? sanitize_textarea_field((string) $template['prompt'])
                : '';

            if (
                '' === $normalized_id ||
                '' === $label ||
                '' === trim($prompt)
            ) {
                continue;
            }

            $normalized[$normalized_id] = [
                'label' => $label,
                'prompt' => $prompt,
            ];
        }

        $this->templates = $normalized;
    }

    private function sanitize_template_id($id)
    {
        $id = strtolower((string) $id);
        $id = preg_replace('/[^a-z0-9_-]/', '', $id);

        return $id;
    }

    private function save_templates(array $templates)
    {
        $this->templates = $templates;
        update_option($this->templates_option, $templates);
    }

    private function get_default_template_key()
    {
        $keys = array_keys($this->templates);
        return $keys ? (string) $keys[0] : '';
    }

    public function register_admin_page()
    {
        if (!$this->is_user_allowed()) {
            return;
        }

        add_menu_page(
            __('Treba AI Routes', 'treba-generate-content'),
            __('Treba AI Routes', 'treba-generate-content'),
            'read',
            $this->menu_slug,
            [$this, 'render_admin_page'],
            'dashicons-edit',
            58
        );
    }

    public function handle_form_submissions()
    {
        if (empty($_POST['tgpt_action']) || !$this->is_user_allowed()) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['tgpt_action']));

        if ('save_settings' === $action) {
            $this->handle_settings_save();
        }

        if ('generate_post' === $action) {
            $this->handle_post_generation();
        }

        if ('upload_csv' === $action) {
            $this->handle_csv_upload();
        }

        if ('save_csv_mapping' === $action) {
            $this->handle_csv_mapping();
        }

        if ('clear_csv_session' === $action) {
            $this->clear_csv_session();
        }

        if ('save_template' === $action) {
            $this->handle_template_save();
        }

        if ('delete_template' === $action) {
            $this->handle_template_delete();
        }

        if ('export_templates' === $action) {
            $this->handle_templates_export();
        }

        if ('import_templates' === $action) {
            $this->handle_templates_import();
        }
    }

    public function render_admin_page()
    {
        if (!$this->is_user_allowed()) {
            wp_die(
                esc_html__(
                    'У вас немає доступу до цієї сторінки.',
                    'treba-generate-content'
                )
            );
        }

        $current_tab = isset($_GET['tab'])
            ? sanitize_key(wp_unslash($_GET['tab']))
            : 'generator';
        $can_manage_templates = $this->can_manage_templates();
        $can_manage_settings = current_user_can('manage_options');

        echo '<div class="wrap treba-routes-ai">';
        echo '<h1>' .
            esc_html__('Treba AI Writer', 'treba-generate-content') .
            '</h1>';
        $this->render_notices();

        echo '<h2 class="nav-tab-wrapper">';
        printf(
            '<a href="%s" class="nav-tab %s">%s</a>',
            esc_url(
                admin_url(
                    'admin.php?page=' . $this->menu_slug . '&tab=generator'
                )
            ),
            'generator' === $current_tab ? 'nav-tab-active' : '',
            esc_html__('Генератор контенту', 'treba-generate-content')
        );
        if ($can_manage_templates) {
            printf(
                '<a href="%s" class="nav-tab %s">%s</a>',
                esc_url(
                    admin_url(
                        'admin.php?page=' . $this->menu_slug . '&tab=templates'
                    )
                ),
                'templates' === $current_tab ? 'nav-tab-active' : '',
                esc_html__('Шаблони', 'treba-generate-content')
            );
        }
        if ($can_manage_settings) {
            printf(
                '<a href="%s" class="nav-tab %s">%s</a>',
                esc_url(
                    admin_url(
                        'admin.php?page=' . $this->menu_slug . '&tab=settings'
                    )
                ),
                'settings' === $current_tab ? 'nav-tab-active' : '',
                esc_html__('Налаштування доступу', 'treba-generate-content')
            );
        }
        echo '</h2>';

        if ('templates' === $current_tab && $can_manage_templates) {
            $this->render_templates_form();
        } elseif ('settings' === $current_tab && $can_manage_settings) {
            $this->render_settings_form();
        } else {
            $this->render_generator_form();
        }

        echo '</div>';
    }

    private function render_notices()
    {
        foreach ($this->errors as $error) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                wp_kses_post($error)
            );
        }

        foreach ($this->notices as $notice) {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                wp_kses_post($notice)
            );
        }
    }

    private function render_generator_form()
    {
        $providers = $this->providers;
        $selected_provider = $this->get_default_provider();
        $selected_provider = isset($providers[$selected_provider])
            ? $selected_provider
            : 'openai';
        $provider_label = $providers[$selected_provider] ?? $selected_provider;
        $api_key_available = $this->has_api_key($selected_provider);
        $default_template_key = $this->get_default_template_key();
        $templates = $this->templates;
        $post_types = $this->get_available_post_types();
        $post_type = $this->get_default_post_type();
        $post_type = isset($post_types[$post_type]) ? $post_type : 'post';
        $post_type_obj = $post_types[$post_type] ?? null;
        $categories_data = $this->get_category_terms_for_post_type($post_type);
        $post_type_label = $post_type;

        if ($post_type_obj) {
            $post_type_label =
                $post_type_obj->labels->singular_name ??
                ($post_type_obj->label ?? $post_type);
        }

        $selected_categories =
            isset($_POST['tgpt_categories']) && !$this->reset_form
                ? array_map(
                    'intval',
                    (array) wp_unslash($_POST['tgpt_categories'])
                )
                : [];
        $csv_session = $this->get_csv_session();
        $csv_headers = $csv_session['headers'] ?? [];
        $csv_total_rows = (int) ($csv_session['row_count'] ?? 0);
        $csv_mapping_complete = $this->is_csv_mapping_complete($csv_session);
        $csv_template_selected =
            $csv_session['template'] ?? $default_template_key;
        $csv_chunk = $this->get_csv_chunk_size($csv_session);

        if ('' === $default_template_key) {
            $templates_url = esc_url(
                admin_url(
                    'admin.php?page=' . $this->menu_slug . '&tab=templates'
                )
            );
            $message = sprintf(
                wp_kses(
                    __(
                        'Немає доступних шаблонів. Перейдіть на <a href="%s">вкладку «Шаблони»</a>, щоб створити або імпортувати промт.',
                        'treba-generate-content'
                    ),
                    [
                        'a' => [
                            'href' => [],
                        ],
                    ]
                ),
                $templates_url
            );
            echo '<div class="notice notice-error"><p>' .
                $message .
                '</p></div>';
            return;
        }
        ?>
		<form method="post">
			<?php wp_nonce_field('tgpt_generate_post'); ?>
			<input type="hidden" name="tgpt_action" value="generate_post">

			<table class="form-table" role="presentation">
				<tbody>
					<?php if (!$api_key_available): ?>
						<tr>
							<th scope="row"><?php echo esc_html(
           sprintf(
               __('API ключ (%s)', 'treba-generate-content'),
               $provider_label
           )
       ); ?></th>
							<td>
								<div class="notice notice-warning inline">
									<p>
										<?php if (current_user_can('manage_options')) {
              printf(
                  '%s <a href="%s">%s</a>',
                  esc_html__(
                      'Ключ для обраного провайдера ще не налаштований. Додайте його у вкладці «Налаштування».',
                      'treba-generate-content'
                  ),
                  esc_url(
                      admin_url(
                          'admin.php?page=' . $this->menu_slug . '&tab=settings'
                      )
                  ),
                  esc_html__('Відкрити налаштування', 'treba-generate-content')
              );
          } else {
              esc_html_e(
                  'Ключ для обраного провайдера ще не налаштований адміністратором. Зверніться до відповідальної особи.',
                  'treba-generate-content'
              );
          } ?>
									</p>
								</div>
							</td>
						</tr>
					<?php else: ?>
						<tr>
							<th scope="row"><?php echo esc_html(
           sprintf(
               __('API ключ (%s)', 'treba-generate-content'),
               $provider_label
           )
       ); ?></th>
							<td>
								<p class="description">
									<?php esc_html_e(
             'Ключ для провайдера збережено у вкладці «Налаштування» і буде використано автоматично.',
             'treba-generate-content'
         ); ?>
								</p>
							</td>
						</tr>
					<?php endif; ?>

					<tr>
						<th scope="row"><label for="tgpt_topic"><?php esc_html_e(
          'Назва статті / тема',
          'treba-generate-content'
      ); ?></label></th>
						<td><input id="tgpt_topic" class="regular-text" type="text" name="tgpt_topic" value="<?php echo esc_attr(
          $this->get_field_value('tgpt_topic')
      ); ?>" required></td>
					</tr>

                    <tr>
                        <th scope="row"><?php esc_html_e(
                            'Шаблон',
                            'treba-generate-content'
                        ); ?></th>
                        <td>
                            <select name="tgpt_template">
                                <?php foreach (
                                    $templates
                                    as $key => $template
                                ): ?>
                                    <option value="<?php echo esc_attr(
                                        $key
                                    ); ?>" <?php selected(
    $this->get_field_value('tgpt_template', $default_template_key),
    $key
); ?>>
                                        <?php echo esc_html(
                                            $template['label']
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e(
                            'Тип запису',
                            'treba-generate-content'
                        ); ?></th>
                        <td>
                            <strong>
                                <?php echo esc_html($post_type_label); ?>
                            </strong>
                            <input type="hidden" name="tgpt_post_type" value="<?php echo esc_attr(
                                $post_type
                            ); ?>">
                            <p class="description"><?php esc_html_e(
                                'Тип задається у вкладці «Налаштування доступу».',
                                'treba-generate-content'
                            ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e(
                            'Категорії',
                            'treba-generate-content'
                        ); ?></th>
                        <td>
                            <?php if (
                                $categories_data['taxonomy'] &&
                                !empty($categories_data['terms'])
                            ): ?>
                                <select name="tgpt_categories[]" multiple size="6" style="min-width:300px;">
                                    <?php foreach (
                                        $categories_data['terms']
                                        as $term
                                    ): ?>
                                        <option value="<?php echo esc_attr(
                                            $term->term_id
                                        ); ?>" <?php selected(
    in_array((int) $term->term_id, $selected_categories, true)
); ?>>
                                            <?php echo esc_html($term->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e(
                                    'Категорії для вибраного типу запису.',
                                    'treba-generate-content'
                                ); ?></p>
                            <?php elseif ($categories_data['taxonomy']): ?>
                                <p class="description"><?php esc_html_e(
                                    'Для цього типу запису ще немає категорій.',
                                    'treba-generate-content'
                                ); ?></p>
                            <?php else: ?>
                                <p class="description"><?php esc_html_e(
                                    'Для цього типу запису не знайдено таксономій з категоріями.',
                                    'treba-generate-content'
                                ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>

					<tr>
						<th scope="row"><label for="tgpt_route_number"><?php esc_html_e(
          'Номер маршруту',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_route_number" class="regular-text" type="text" name="tgpt_route_number" value="<?php echo esc_attr(
           $this->get_field_value('tgpt_route_number')
       ); ?>" required>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tgpt_route_type"><?php esc_html_e(
          'Тип маршруту',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_route_type" class="regular-text" type="text" name="tgpt_route_type" value="<?php echo esc_attr(
           $this->get_field_value('tgpt_route_type')
       ); ?>" placeholder="<?php esc_attr_e(
    'автобус, тролейбус, маршрутка тощо',
    'treba-generate-content'
); ?>" required>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tgpt_city"><?php esc_html_e(
          'Місто',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_city" class="regular-text" type="text" name="tgpt_city" value="<?php echo esc_attr(
           $this->get_field_value('tgpt_city')
       ); ?>" required>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tgpt_distance"><?php esc_html_e(
          'Довжина маршрута (км)',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_distance" class="regular-text" type="number" name="tgpt_distance" value="<?php echo esc_attr(
           $this->get_field_value('tgpt_distance')
       ); ?>" step="0.1" min="0" required>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tgpt_interval"><?php esc_html_e(
          'Інтервал',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_interval" class="regular-text" type="text" name="tgpt_interval" value="<?php echo esc_attr(
           $this->get_field_value('tgpt_interval')
       ); ?>" placeholder="<?php esc_attr_e(
    'наприклад: 8–12 хв у годину пік',
    'treba-generate-content'
); ?>" required>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tgpt_travel_time"><?php esc_html_e(
          'Час руху',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_travel_time" class="regular-text" type="text" name="tgpt_travel_time" value="<?php echo esc_attr(
           $this->get_field_value('tgpt_travel_time')
       ); ?>" placeholder="<?php esc_attr_e(
    'наприклад: 05:30–23:00',
    'treba-generate-content'
); ?>" required>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tgpt_carrier"><?php esc_html_e(
          'Перевізник',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_carrier" class="regular-text" type="text" name="tgpt_carrier" value="<?php echo esc_attr(
           $this->get_field_value('tgpt_carrier')
       ); ?>" placeholder="<?php esc_attr_e(
    'наприклад: КП «Міськелектротранс»',
    'treba-generate-content'
); ?>" required>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tgpt_price"><?php esc_html_e(
          'Ціна',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_price" class="regular-text" type="text" name="tgpt_price" value="<?php echo esc_attr(
           $this->get_field_value('tgpt_price')
       ); ?>" placeholder="<?php esc_attr_e(
    'наприклад: 15 грн',
    'treba-generate-content'
); ?>" required>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tgpt_stops_forward"><?php esc_html_e(
          'Зупинки туди',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<textarea id="tgpt_stops_forward" name="tgpt_stops_forward" rows="4" class="large-text" placeholder="<?php esc_attr_e(
           'Перерахуйте зупинки по порядку: Площа Шевченка, Центр, ...',
           'treba-generate-content'
       ); ?>" required><?php echo esc_textarea(
    $this->get_field_value('tgpt_stops_forward')
); ?></textarea>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="tgpt_stops_backward"><?php esc_html_e(
          'Зупинки назад',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<textarea id="tgpt_stops_backward" name="tgpt_stops_backward" rows="4" class="large-text" placeholder="<?php esc_attr_e(
           'Маршрут у зворотному напрямку',
           'treba-generate-content'
       ); ?>" required><?php echo esc_textarea(
    $this->get_field_value('tgpt_stops_backward')
); ?></textarea>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button(
       __('Згенерувати та створити запис', 'treba-generate-content')
   ); ?>
		</form>

        <?php $csv_field_labels = [
            'title' => __('Назва (title)', 'treba-generate-content'),
            'route_number' => __(
                'Номер маршруту (route_number)',
                'treba-generate-content'
            ),
            'route_type' => __(
                'Тип маршруту (route_type)',
                'treba-generate-content'
            ),
            'city' => __('Місто (city)', 'treba-generate-content'),
            'distance' => __('Довжина (distance)', 'treba-generate-content'),
            'interval' => __('Інтервал (interval)', 'treba-generate-content'),
            'travel_time' => __(
                'Час руху (travel_time)',
                'treba-generate-content'
            ),
            'carrier' => __('Перевізник (carrier)', 'treba-generate-content'),
            'price' => __('Ціна (price)', 'treba-generate-content'),
            'stops_forward' => __(
                'Зупинки туди (stops_forward)',
                'treba-generate-content'
            ),
            'stops_backward' => __(
                'Зупинки назад (stops_backward)',
                'treba-generate-content'
            ),
            'template' => __(
                'Шаблон (id шаблону, опційно)',
                'treba-generate-content'
            ),
            'model' => __(
                'Модель (ключ моделі, опційно)',
                'treba-generate-content'
            ),
        ]; ?>

        <div class="card">
            <h2><?php esc_html_e(
                'Імпорт CSV',
                'treba-generate-content'
            ); ?></h2>
            <p><?php esc_html_e(
                'Завантажте CSV (кома, UTF-8, перший рядок — заголовки), зробіть мапінг колонок і запустіть пакетну генерацію.',
                'treba-generate-content'
            ); ?></p>

            <form method="post" enctype="multipart/form-data" style="margin-bottom:16px;">
                <?php wp_nonce_field('tgpt_upload_csv'); ?>
                <input type="hidden" name="tgpt_action" value="upload_csv">
                <input type="file" name="tgpt_csv_file" accept=".csv,text/csv" required>
                <?php submit_button(
                    __('Завантажити CSV', 'treba-generate-content'),
                    'secondary',
                    'submit',
                    false
                ); ?>
            </form>

            <?php if (!empty($csv_session)): ?>
                <p>
                    <?php echo esc_html(
                        sprintf(
                            __(
                                'Файл: %1$s · Рядків: %2$d',
                                'treba-generate-content'
                            ),
                            $csv_session['file_name'] ?? '',
                            $csv_total_rows
                        )
                    ); ?>
                </p>

                <form method="post" style="margin-bottom:16px;">
                    <?php wp_nonce_field('tgpt_save_csv_mapping'); ?>
                    <input type="hidden" name="tgpt_action" value="save_csv_mapping">
                    <table class="form-table" role="presentation">
                        <tbody>
                            <?php foreach (
                                $csv_field_labels
                                as $field_key => $field_label
                            ): ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html(
                                        $field_label
                                    ); ?></th>
                                    <td>
                                        <select name="tgpt_csv_map[<?php echo esc_attr(
                                            $field_key
                                        ); ?>]" required>
                                            <option value=""><?php esc_html_e(
                                                '— Оберіть колонку —',
                                                'treba-generate-content'
                                            ); ?></option>
                                            <?php foreach (
                                                $csv_headers
                                                as $header
                                            ): ?>
                                                <option value="<?php echo esc_attr(
                                                    $header
                                                ); ?>" <?php selected(
    $csv_session['mapping'][$field_key] ?? '',
    $header
); ?>>
                                                    <?php echo esc_html(
                                                        $header
                                                    ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <th scope="row"><?php esc_html_e(
                                    'Шаблон (опційно, з CSV)',
                                    'treba-generate-content'
                                ); ?></th>
                                <td>
                                    <select name="tgpt_csv_map[template]">
                                        <option value=""><?php esc_html_e(
                                            '— Не використовувати —',
                                            'treba-generate-content'
                                        ); ?></option>
                                        <?php foreach (
                                            $csv_headers
                                            as $header
                                        ): ?>
                                            <option value="<?php echo esc_attr(
                                                $header
                                            ); ?>" <?php selected(
    $csv_session['mapping']['template'] ?? '',
    $header
); ?>>
                                                <?php echo esc_html($header); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e(
                                        'Вкажіть колонку з id шаблону (slug), якщо потрібно перевизначати шаблон по рядках.',
                                        'treba-generate-content'
                                    ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e(
                                    'Модель (опційно, з CSV)',
                                    'treba-generate-content'
                                ); ?></th>
                                <td>
                                    <select name="tgpt_csv_map[model]">
                                        <option value=""><?php esc_html_e(
                                            '— Не використовувати —',
                                            'treba-generate-content'
                                        ); ?></option>
                                        <?php foreach (
                                            $csv_headers
                                            as $header
                                        ): ?>
                                            <option value="<?php echo esc_attr(
                                                $header
                                            ); ?>" <?php selected(
    $csv_session['mapping']['model'] ?? '',
    $header
); ?>>
                                                <?php echo esc_html($header); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e(
                                        'Вкажіть колонку з точним ключем моделі (див. списки у налаштуваннях: OpenAI або OpenRouter).',
                                        'treba-generate-content'
                                    ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e(
                                    'Шаблон для імпорту',
                                    'treba-generate-content'
                                ); ?></th>
                                <td>
                                    <select name="tgpt_csv_template">
                                        <?php foreach (
                                            $templates
                                            as $key => $template
                                        ): ?>
                                            <option value="<?php echo esc_attr(
                                                $key
                                            ); ?>" <?php selected(
    $csv_template_selected,
    $key
); ?>>
                                                <?php echo esc_html(
                                                    $template['label']
                                                ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php esc_html_e(
                                        'Використовується для всіх рядків CSV.',
                                        'treba-generate-content'
                                    ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e(
                                    'Розмір пакета (рядків за один крок)',
                                    'treba-generate-content'
                                ); ?></th>
                                <td>
                                    <input type="number" name="tgpt_csv_chunk" value="<?php echo esc_attr(
                                        $csv_chunk
                                    ); ?>" min="1" max="10" step="1" style="width:80px;">
                                    <p class="description"><?php esc_html_e(
                                        'Рекомендуємо 3–5, щоб уникнути таймаутів.',
                                        'treba-generate-content'
                                    ); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button(
                        __('Зберегти мапінг', 'treba-generate-content')
                    ); ?>
                </form>

                <form method="post" style="margin-bottom:12px;">
                    <?php wp_nonce_field('tgpt_upload_csv'); ?>
                    <input type="hidden" name="tgpt_action" value="clear_csv_session">
                    <?php submit_button(
                        __('Очистити CSV', 'treba-generate-content'),
                        'delete',
                        'submit',
                        false
                    ); ?>
                </form>

                <?php if ($csv_mapping_complete && $csv_total_rows > 0): ?>
                    <button
                        id="tgpt-csv-start"
                        class="button button-primary"
                        data-total="<?php echo esc_attr($csv_total_rows); ?>"
                        data-chunk="<?php echo esc_attr($csv_chunk); ?>"
                        data-nonce="<?php echo esc_attr(
                            wp_create_nonce('tgpt_csv_batch')
                        ); ?>"
                        data-ajaxurl="<?php echo esc_url(
                            admin_url('admin-ajax.php')
                        ); ?>"
                    >
                        <?php esc_html_e(
                            'Почати пакетну генерацію',
                            'treba-generate-content'
                        ); ?>
                    </button>
                    <p class="description"><?php esc_html_e(
                        'Генерація виконується батчами, щоб уникнути таймаутів.',
                        'treba-generate-content'
                    ); ?></p>
                    <div id="tgpt-csv-progress" style="margin-top:8px;"></div>
                    <div id="tgpt-csv-summary" style="margin-top:8px;font-weight:600;"></div>
                    <div id="tgpt-csv-log" style="margin-top:8px;max-height:260px;overflow:auto;border:1px solid #ccd0d4;padding:8px;background:#fff;"></div>
                    <style>
                        #tgpt-csv-log .tgpt-log-line{margin:0 0 6px 0;padding:4px 6px;border-radius:3px;font-size:13px;line-height:1.4;}
                        #tgpt-csv-log .is-ok{background:#f3faf3;border:1px solid #b6e2b6;}
                        #tgpt-csv-log .is-error{background:#fff5f5;border:1px solid #f0b7b7;}
                        #tgpt-csv-progress small{color:#555;}
                    </style>
                    <script>
                        (() => {
                            const btn = document.getElementById('tgpt-csv-start');
                            if (!btn) return;

                            const progress = document.getElementById('tgpt-csv-progress');
                            const summary = document.getElementById('tgpt-csv-summary');
                            const logBox = document.getElementById('tgpt-csv-log');
                            const total = parseInt(btn.dataset.total || '0', 10);
                            const chunk = parseInt(btn.dataset.chunk || '3', 10);
                            const nonce = btn.dataset.nonce || '';
                            const ajaxUrl = btn.dataset.ajaxurl || (window.ajaxurl || '');

                            const logLines = [];
                            let currentOffset = 0;
                            let isRunning = false;
                            let okCount = 0;
                            let failCount = 0;

                            const render = (text) => {
                                if (progress) {
                                    progress.innerHTML = text;
                                }
                            };

                            const appendLog = (line, isOk) => {
                                if (logBox) {
                                    const div = document.createElement('div');
                                    div.className = 'tgpt-log-line ' + (isOk ? 'is-ok' : 'is-error');
                                    div.innerHTML = line;
                                    logBox.appendChild(div);
                                    logBox.scrollTop = logBox.scrollHeight;
                                    logLines.push(div);
                                    if (logLines.length > 200) {
                                        const first = logLines.shift();
                                        if (first && first.parentNode) {
                                            first.parentNode.removeChild(first);
                                        }
                                    }
                                }

                                if (summary) {
                                    summary.innerHTML =
                                        '<?php echo esc_js(
                                            __(
                                                'Успіхів:',
                                                'treba-generate-content'
                                            )
                                        ); ?> ' +
                                        okCount +
                                        ' · ' +
                                        '<?php echo esc_js(
                                            __(
                                                'Помилок:',
                                                'treba-generate-content'
                                            )
                                        ); ?> ' +
                                        failCount;
                                }

                                render(
                                    '<?php echo esc_js(
                                        __('Прогрес:', 'treba-generate-content')
                                    ); ?> ' +
                                    Math.min(currentOffset, total) +
                                    ' / ' +
                                    total +
                                    ' <small>(' +
                                    '<?php echo esc_js(
                                        __('пакет', 'treba-generate-content')
                                    ); ?> ' +
                                    chunk +
                                    ')</small>'
                                );
                            };

                            const runBatch = () => {
                                if (!ajaxUrl || !nonce) {
                                    render('<?php echo esc_js(
                                        __(
                                            'Немає AJAX конфігурації.',
                                            'treba-generate-content'
                                        )
                                    ); ?>');
                                    btn.disabled = false;
                                    return;
                                }

                                isRunning = true;
                                btn.disabled = true;
                                render(
                                    '<?php echo esc_js(
                                        __(
                                            'Обробка...',
                                            'treba-generate-content'
                                        )
                                    ); ?>'
                                );

                                const body = new URLSearchParams();
                                body.append('action', 'tgpt_csv_generate_batch');
                                body.append('nonce', nonce);
                                body.append('offset', String(currentOffset));
                                body.append('limit', String(chunk));

                                fetch(ajaxUrl, {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Content-Type':
                                            'application/x-www-form-urlencoded; charset=UTF-8',
                                    },
                                    body,
                                })
                                    .then((res) => res.json())
                                    .then((json) => {
                                        if (!json || !json.success) {
                                            const msg =
                                                (json && json.data && json.data.message) ||
                                                '<?php echo esc_js(
                                                    __(
                                                        'Помилка виконання запиту.',
                                                        'treba-generate-content'
                                                    )
                                                ); ?>';
                                            render(msg);
                                            btn.disabled = false;
                                            isRunning = false;
                                            return;
                                        }

                                        const data = json.data || {};
                                        const processed = Array.isArray(data.processed)
                                            ? data.processed
                                            : [];

                                        processed.forEach((row) => {
                                            if (row.success) {
                                                okCount += 1;
                                                appendLog(
                                                    '✔ ' +
                                                    '<?php echo esc_js(
                                                        __(
                                                            'Рядок',
                                                            'treba-generate-content'
                                                        )
                                                    ); ?> ' +
                                                    (row.row || '') +
                                                    ': ' +
                                                    (row.message || '<?php echo esc_js(
                                                        __(
                                                            'Готово',
                                                            'treba-generate-content'
                                                        )
                                                    ); ?>'),
                                                    true
                                                );
                                            } else {
                                                failCount += 1;
                                                appendLog(
                                                    '✖ ' +
                                                    '<?php echo esc_js(
                                                        __(
                                                            'Рядок',
                                                            'treba-generate-content'
                                                        )
                                                    ); ?> ' +
                                                    (row.row || '') +
                                                    ': ' +
                                                    (row.message ||
                                                        '<?php echo esc_js(
                                                            __(
                                                                'Помилка',
                                                                'treba-generate-content'
                                                            )
                                                        ); ?>'),
                                                    false
                                                );
                                            }
                                        });

                                        currentOffset = data.next_offset || currentOffset + processed.length;

                                        if (data.done) {
                                            render(
                                                '<?php echo esc_js(
                                                    __(
                                                        'Генерацію завершено.',
                                                        'treba-generate-content'
                                                    )
                                                ); ?>'
                                            );
                                            btn.disabled = false;
                                            isRunning = false;
                                            return;
                                        }

                                        runBatch();
                                    })
                                    .catch(() => {
                                        render('<?php echo esc_js(
                                            __(
                                                'Помилка мережі.',
                                                'treba-generate-content'
                                            )
                                        ); ?>');
                                        btn.disabled = false;
                                        isRunning = false;
                                    });
                            };

                            btn.addEventListener('click', (event) => {
                                event.preventDefault();
                                if (isRunning) {
                                    return;
                                }
                                logLines.length = 0;
                                if (logBox) {
                                    logBox.innerHTML = '';
                                }
                                okCount = 0;
                                failCount = 0;
                                if (summary) {
                                    summary.innerHTML = '';
                                }
                                currentOffset = 0;
                                runBatch();
                            });
                        })();
                    </script>
                <?php endif; ?>
            <?php endif; ?>
        </div>
		<?php
    }

    private function render_templates_form()
    {
        if (!$this->can_manage_templates()) {
            return;
        }

        $templates = $this->templates;
        $editing_template_id = '';
        $editing_template = [
            'label' => '',
            'prompt' => '',
        ];

        if (!empty($_GET['template'])) {
            $candidate = $this->sanitize_template_id(
                wp_unslash($_GET['template'])
            );

            if ($candidate && isset($templates[$candidate])) {
                $editing_template_id = $candidate;
                $editing_template = $templates[$candidate];
            }
        }

        $form_heading = $editing_template_id
            ? esc_html__('Редагувати шаблон', 'treba-generate-content')
            : esc_html__('Створити новий шаблон', 'treba-generate-content');
        ?>
		<div class="card">
			<h2><?php echo $form_heading; ?></h2>
			<p><?php esc_html_e(
       'Використовуйте змінні {topic}, {route_number}, {route_type}, {city}, {distance}, {interval}, {travel_time}, {carrier}, {price}, {stops_forward}, {stops_backward}. Дані підставляються автоматично з форми генерації.',
       'treba-generate-content'
   ); ?></p>
			<form method="post">
				<?php wp_nonce_field('tgpt_manage_templates'); ?>
				<input type="hidden" name="tgpt_action" value="save_template">
				<input type="hidden" name="tgpt_original_id" value="<?php echo esc_attr(
        $editing_template_id
    ); ?>">

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="tgpt_template_label"><?php esc_html_e(
           'Назва шаблону',
           'treba-generate-content'
       ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="tgpt_template_label" name="tgpt_template_label" value="<?php echo esc_attr(
            $editing_template['label']
        ); ?>" required>
								<p class="description"><?php esc_html_e(
            'Цю назву побачать користувачі у випадаючому списку генератора.',
            'treba-generate-content'
        ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="tgpt_template_id"><?php esc_html_e(
           'Системний ключ',
           'treba-generate-content'
       ); ?></label></th>
							<td>
                                <input type="text" class="regular-text" id="tgpt_template_id" name="tgpt_template_id" value="<?php echo esc_attr(
                                    $editing_template_id
                                ); ?>" placeholder="naprklad_template" required>
								<p class="description"><?php esc_html_e(
            'Лише латиниця, цифри, дефіси та підкреслення. Використовується у внутрішніх ідентифікаторах.',
            'treba-generate-content'
        ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="tgpt_template_prompt"><?php esc_html_e(
           'Промт для ChatGPT',
           'treba-generate-content'
       ); ?></label></th>
							<td>
								<textarea class="large-text" id="tgpt_template_prompt" name="tgpt_template_prompt" rows="14" required><?php echo esc_textarea(
            $editing_template['prompt']
        ); ?></textarea>
								<p class="description"><?php esc_html_e(
            'Опишіть структуру статті, побажання до тону, списків тощо.',
            'treba-generate-content'
        ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(
        $editing_template_id
            ? esc_html__('Оновити шаблон', 'treba-generate-content')
            : esc_html__('Створити шаблон', 'treba-generate-content')
    ); ?>
			</form>
		</div>

		<div class="card">
			<h2><?php esc_html_e(
       'Експорт та імпорт шаблонів',
       'treba-generate-content'
   ); ?></h2>
			<div class="tgpt-templates-import-export" style="display:flex;flex-wrap:wrap;gap:24px;">
				<div style="flex:1 1 280px;">
					<h3><?php esc_html_e('Експорт', 'treba-generate-content'); ?></h3>
					<p><?php esc_html_e(
         'Завантажте JSON-файл з усіма поточними шаблонами й використовуйте його як резервну копію.',
         'treba-generate-content'
     ); ?></p>
					<form method="post">
						<?php wp_nonce_field('tgpt_export_templates'); ?>
						<input type="hidden" name="tgpt_action" value="export_templates">
						<?php submit_button(
          esc_html__('Завантажити JSON', 'treba-generate-content'),
          'secondary',
          'submit',
          false
      ); ?>
					</form>
				</div>
				<div style="flex:1 1 280px;">
					<h3><?php esc_html_e('Імпорт', 'treba-generate-content'); ?></h3>
					<p><?php esc_html_e(
         'Імпортуйте файл, створений цією ж кнопкою експорту. Нові шаблони додадуться або повністю замінять поточні — на ваш вибір.',
         'treba-generate-content'
     ); ?></p>
					<form method="post" enctype="multipart/form-data">
						<?php wp_nonce_field('tgpt_import_templates'); ?>
						<input type="hidden" name="tgpt_action" value="import_templates">
						<input type="file" name="tgpt_templates_file" accept=".json,application/json" required>
						<p>
							<label>
								<input type="checkbox" name="tgpt_import_replace" value="1">
								<?php esc_html_e(
            'Очистити поточні шаблони перед імпортом',
            'treba-generate-content'
        ); ?>
							</label>
						</p>
						<?php submit_button(
          esc_html__('Імпортувати шаблони', 'treba-generate-content')
      ); ?>
					</form>
				</div>
			</div>
		</div>

		<h2><?php esc_html_e('Усі шаблони', 'treba-generate-content'); ?></h2>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e('Назва', 'treba-generate-content'); ?></th>
					<th><?php esc_html_e('Ключ', 'treba-generate-content'); ?></th>
					<th><?php esc_html_e('Дії', 'treba-generate-content'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if (empty($templates)): ?>
					<tr>
						<td colspan="3">
							<?php esc_html_e(
           'Поки що немає жодного шаблону. Створіть новий або імпортуйте існуючий JSON.',
           'treba-generate-content'
       ); ?>
						</td>
					</tr>
				<?php else: ?>
					<?php foreach ($templates as $template_id => $template_data): ?>
						<tr>
							<td><?php echo esc_html($template_data['label']); ?></td>
							<td><code><?php echo esc_html($template_id); ?></code></td>
							<td>
								<a class="button button-secondary" href="<?php echo esc_url(
            add_query_arg(
                [
                    'page' => $this->menu_slug,
                    'tab' => 'templates',
                    'template' => $template_id,
                ],
                admin_url('admin.php')
            )
        ); ?>"><?php esc_html_e('Редагувати', 'treba-generate-content'); ?></a>
								<form method="post" style="display:inline-block;margin-left:8px;">
									<?php wp_nonce_field('tgpt_manage_templates'); ?>
									<input type="hidden" name="tgpt_action" value="delete_template">
									<input type="hidden" name="tgpt_template_id" value="<?php echo esc_attr(
             $template_id
         ); ?>">
									<?php submit_button(
             esc_html__('Видалити', 'treba-generate-content'),
             'delete',
             'submit',
             false,
             [
                 'onclick' =>
                     "return confirm('" .
                     esc_js(
                         __('Видалити цей шаблон?', 'treba-generate-content')
                     ) .
                     "');",
             ]
         ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
    }

    private function render_settings_form()
    {
        $allowed_users = (array) get_option($this->allowed_users_option, []);
        $users = get_users([
            'orderby' => 'display_name',
            'order' => 'ASC',
            'fields' => ['ID', 'display_name', 'user_login'],
        ]);
        $default_provider = $this->get_default_provider();
        $post_types = $this->get_available_post_types();
        $default_post_type = $this->get_default_post_type();
        ?>
		<form method="post">
			<?php wp_nonce_field('tgpt_save_settings'); ?>
			<input type="hidden" name="tgpt_action" value="save_settings">

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="tgpt_api_key_openai"><?php esc_html_e(
          'OpenAI API ключ',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_api_key_openai" class="regular-text" type="password" name="tgpt_api_key_openai" value="" placeholder="sk-..." autocomplete="off">
							<?php if ($this->has_api_key('openai')): ?>
								<p class="description"><?php esc_html_e(
            'Ключ уже збережений. Залиште поле порожнім, щоб не змінювати.',
            'treba-generate-content'
        ); ?></p>
								<label>
									<input type="checkbox" name="tgpt_clear_api_key_openai" value="1">
									<?php esc_html_e('Видалити збережений ключ', 'treba-generate-content'); ?>
								</label>
							<?php else: ?>
								<p class="description"><?php esc_html_e(
            'Введіть ключ один раз, він буде збережений у зашифрованому вигляді.',
            'treba-generate-content'
        ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tgpt_api_key_openrouter"><?php esc_html_e(
          'OpenRouter API ключ',
          'treba-generate-content'
      ); ?></label></th>
						<td>
							<input id="tgpt_api_key_openrouter" class="regular-text" type="password" name="tgpt_api_key_openrouter" value="" placeholder="sk-or-v1-..." autocomplete="off">
							<?php if ($this->has_api_key('openrouter')): ?>
								<p class="description"><?php esc_html_e(
            'Ключ уже збережений. Залиште поле порожнім, щоб не змінювати.',
            'treba-generate-content'
        ); ?></p>
								<label>
									<input type="checkbox" name="tgpt_clear_api_key_openrouter" value="1">
									<?php esc_html_e('Видалити збережений ключ', 'treba-generate-content'); ?>
								</label>
							<?php else: ?>
								<p class="description"><?php esc_html_e(
            'Додайте ключ OpenRouter, щоб використовувати моделі через роутер.',
            'treba-generate-content'
        ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e(
          'Доступ до генератора',
          'treba-generate-content'
      ); ?></th>
						<td>
							<select name="tgpt_allowed_users[]" multiple size="6" style="min-width:300px;">
								<?php foreach ($users as $user): ?>
									<option value="<?php echo esc_attr($user->ID); ?>" <?php selected(
    in_array($user->ID, array_map('intval', $allowed_users), true)
); ?>>
										<?php echo esc_html(
              sprintf('%s (%s)', $user->display_name, $user->user_login)
          ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e(
           'Адміністратори мають доступ завжди, тут можна додати редакторів/копірайтерів.',
           'treba-generate-content'
       ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e(
          'Постачальник за замовчуванням',
          'treba-generate-content'
      ); ?></th>
						<td>
							<select name="tgpt_default_provider">
								<?php foreach ($this->providers as $provider_key => $provider_label): ?>
									<option value="<?php echo esc_attr($provider_key); ?>" <?php selected(
    $default_provider,
    $provider_key
); ?>>
										<?php echo esc_html($provider_label); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e(
           'Використовується за замовчуванням у формі генерації.',
           'treba-generate-content'
       ); ?></p>
						</td>
					</tr>

                    <tr>
                        <th scope="row"><?php esc_html_e(
                            'Тип запису за замовчуванням',
                            'treba-generate-content'
                        ); ?></th>
                        <td>
                            <select name="tgpt_default_post_type">
                                <?php foreach (
                                    $post_types
                                    as $type_key => $type_obj
                                ): ?>
                                    <option value="<?php echo esc_attr(
                                        $type_key
                                    ); ?>" <?php selected(
    $default_post_type,
    $type_key
); ?>>
                                        <?php echo esc_html(
                                            $type_obj->labels->singular_name ??
                                                ($type_obj->label ?? $type_key)
                                        ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e(
                                'Цей тип використовується у генераторі та для підбору категорій.',
                                'treba-generate-content'
                            ); ?></p>
                        </td>
                    </tr>

					<tr>
						<th scope="row"><?php esc_html_e(
          'Модель за замовчуванням · OpenAI',
          'treba-generate-content'
      ); ?></th>
						<td>
							<select name="tgpt_default_model[openai]">
								<?php foreach ($this->models['openai'] as $model_key => $model_label): ?>
									<option value="<?php echo esc_attr($model_key); ?>" <?php selected(
    $this->get_default_model('openai'),
    $model_key
); ?>>
										<?php echo esc_html($model_label); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e(
          'Модель за замовчуванням · OpenRouter',
          'treba-generate-content'
      ); ?></th>
						<td>
							<select name="tgpt_default_model[openrouter]">
								<?php foreach ($this->models['openrouter'] as $model_key => $model_label): ?>
									<option value="<?php echo esc_attr($model_key); ?>" <?php selected(
    $this->get_default_model('openrouter'),
    $model_key
); ?>>
										<?php echo esc_html($model_label); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button(__('Зберегти налаштування', 'treba-generate-content')); ?>
		</form>
		<?php
    }

    private function handle_settings_save()
    {
        if (!current_user_can('manage_options')) {
            $this->errors[] = esc_html__(
                'У вас немає прав для зміни налаштувань.',
                'treba-generate-content'
            );
            return;
        }

        check_admin_referer('tgpt_save_settings');

        $this->handle_single_api_key_save(
            'openai',
            $_POST['tgpt_api_key_openai'] ?? '',
            !empty($_POST['tgpt_clear_api_key_openai'])
        );
        $this->handle_single_api_key_save(
            'openrouter',
            $_POST['tgpt_api_key_openrouter'] ?? '',
            !empty($_POST['tgpt_clear_api_key_openrouter'])
        );

        $allowed_users = isset($_POST['tgpt_allowed_users'])
            ? array_map(
                'intval',
                (array) wp_unslash($_POST['tgpt_allowed_users'])
            )
            : [];
        update_option(
            $this->allowed_users_option,
            array_values(array_unique($allowed_users))
        );

        $default_provider = isset($_POST['tgpt_default_provider'])
            ? sanitize_key(wp_unslash($_POST['tgpt_default_provider']))
            : 'openai';
        $default_provider = isset($this->providers[$default_provider])
            ? $default_provider
            : 'openai';
        update_option($this->default_provider_option, $default_provider);

        $default_models_input = isset($_POST['tgpt_default_model'])
            ? (array) wp_unslash($_POST['tgpt_default_model'])
            : [];
        $default_models = $this->get_default_models();

        foreach ($this->models as $provider_key => $provider_models) {
            if (empty($default_models_input[$provider_key])) {
                continue;
            }

            $candidate = sanitize_text_field(
                $default_models_input[$provider_key]
            );

            if (isset($provider_models[$candidate])) {
                $default_models[$provider_key] = $candidate;
            }
        }

        update_option($this->default_models_option, $default_models);

        $post_types = $this->get_available_post_types();
        $default_post_type = isset($_POST['tgpt_default_post_type'])
            ? sanitize_key(wp_unslash($_POST['tgpt_default_post_type']))
            : 'post';
        if (!isset($post_types[$default_post_type])) {
            $default_post_type = 'post';
        }
        update_option($this->default_post_type_option, $default_post_type);

        $this->notices[] = esc_html__(
            'Налаштування збережено.',
            'treba-generate-content'
        );
    }

    private function handle_single_api_key_save(
        $provider,
        $raw_input,
        $should_clear
    ) {
        if (!isset($this->api_key_options[$provider])) {
            return;
        }

        $option_name = $this->api_key_options[$provider];
        $input =
            is_string($raw_input) || is_numeric($raw_input)
                ? trim(sanitize_text_field(wp_unslash($raw_input)))
                : '';

        if ($should_clear) {
            delete_option($option_name);
            unset($this->cached_api_keys[$provider]);
            $this->notices[] = sprintf(
                esc_html__('API-ключ %s видалено.', 'treba-generate-content'),
                esc_html($this->providers[$provider] ?? $provider)
            );
            return;
        }

        if ('' === $input) {
            return;
        }

        $encrypted_key = $this->encrypt_api_key($input);

        if ($encrypted_key) {
            update_option($option_name, $encrypted_key);
            $this->cached_api_keys[$provider] = $input;
            $this->notices[] = sprintf(
                esc_html__('API-ключ %s оновлено.', 'treba-generate-content'),
                esc_html($this->providers[$provider] ?? $provider)
            );
        } else {
            $this->errors[] = esc_html__(
                'Не вдалося зашифрувати API-ключ. Переконайтеся, що на сервері доступне OpenSSL.',
                'treba-generate-content'
            );
        }
    }

    private function handle_template_save()
    {
        if (!$this->can_manage_templates()) {
            return;
        }

        check_admin_referer('tgpt_manage_templates');

        $label = isset($_POST['tgpt_template_label'])
            ? sanitize_text_field(wp_unslash($_POST['tgpt_template_label']))
            : '';
        $template_id_input = isset($_POST['tgpt_template_id'])
            ? wp_unslash($_POST['tgpt_template_id'])
            : '';
        $template_id = $this->sanitize_template_id($template_id_input);
        $original_id = isset($_POST['tgpt_original_id'])
            ? $this->sanitize_template_id(
                wp_unslash($_POST['tgpt_original_id'])
            )
            : '';
        $prompt = isset($_POST['tgpt_template_prompt'])
            ? trim(
                sanitize_textarea_field(
                    wp_unslash($_POST['tgpt_template_prompt'])
                )
            )
            : '';

        if ('' === $label) {
            $this->errors[] = esc_html__(
                'Назва шаблону обов’язкова.',
                'treba-generate-content'
            );
            return;
        }

        if ('' === $template_id) {
            $template_id = $this->sanitize_template_id(sanitize_title($label));
        }

        if ('' === $template_id) {
            $this->errors[] = esc_html__(
                'Задайте коректний системний ключ (латиниця, цифри, - або _).',
                'treba-generate-content'
            );
            return;
        }

        if ('' === $prompt) {
            $this->errors[] = esc_html__(
                'Промт для шаблону не може бути порожнім.',
                'treba-generate-content'
            );
            return;
        }

        $templates = $this->templates;

        if (
            $original_id &&
            $original_id !== $template_id &&
            isset($templates[$template_id])
        ) {
            $this->errors[] = esc_html__(
                'Шаблон з таким ключем уже існує.',
                'treba-generate-content'
            );
            return;
        }

        if (!$original_id && isset($templates[$template_id])) {
            $this->errors[] = esc_html__(
                'Шаблон з таким ключем уже існує.',
                'treba-generate-content'
            );
            return;
        }

        if ($original_id && isset($templates[$original_id])) {
            unset($templates[$original_id]);
        }

        $templates[$template_id] = [
            'label' => $label,
            'prompt' => $prompt,
        ];

        $this->save_templates($templates);
        $this->notices[] = esc_html__(
            'Шаблон збережено.',
            'treba-generate-content'
        );
    }

    private function handle_template_delete()
    {
        if (!$this->can_manage_templates()) {
            return;
        }

        check_admin_referer('tgpt_manage_templates');

        $template_id = isset($_POST['tgpt_template_id'])
            ? $this->sanitize_template_id(
                wp_unslash($_POST['tgpt_template_id'])
            )
            : '';

        if ('' === $template_id || !isset($this->templates[$template_id])) {
            $this->errors[] = esc_html__(
                'Шаблон не знайдено.',
                'treba-generate-content'
            );
            return;
        }

        $templates = $this->templates;
        unset($templates[$template_id]);
        $this->save_templates($templates);

        $this->notices[] = esc_html__(
            'Шаблон видалено.',
            'treba-generate-content'
        );
    }

    private function handle_templates_export()
    {
        if (!$this->can_manage_templates()) {
            return;
        }

        check_admin_referer('tgpt_export_templates');

        $export_data = [];

        foreach ($this->templates as $id => $template) {
            $export_data[] = [
                'id' => $id,
                'label' => $template['label'],
                'prompt' => $template['prompt'],
            ];
        }

        $json = wp_json_encode(
            $export_data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (false === $json) {
            $this->errors[] = esc_html__(
                'Не вдалося сформувати файл експорту. Спробуйте ще раз.',
                'treba-generate-content'
            );
            return;
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header(
            'Content-Disposition: attachment; filename="treba-templates-' .
                gmdate('Y-m-d') .
                '.json"'
        );

        echo $json;
        exit();
    }

    private function handle_templates_import()
    {
        if (!$this->can_manage_templates()) {
            return;
        }

        check_admin_referer('tgpt_import_templates');

        if (
            empty($_FILES['tgpt_templates_file']) ||
            !isset($_FILES['tgpt_templates_file']['tmp_name'])
        ) {
            $this->errors[] = esc_html__(
                'Файл із шаблонами не завантажено.',
                'treba-generate-content'
            );
            return;
        }

        $file = $_FILES['tgpt_templates_file'];

        if (
            (int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            $this->errors[] = esc_html__(
                'Помилка завантаження файлу. Спробуйте ще раз.',
                'treba-generate-content'
            );
            return;
        }

        $raw = file_get_contents($file['tmp_name']);

        if (false === $raw) {
            $this->errors[] = esc_html__(
                'Не вдалося прочитати файл із шаблонами.',
                'treba-generate-content'
            );
            return;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            $this->errors[] = esc_html__(
                'Файл має хибний формат. Очікується JSON-масив.',
                'treba-generate-content'
            );
            return;
        }

        $imported = [];

        foreach ($decoded as $maybe_id => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $raw_id = $entry['id'] ?? (is_string($maybe_id) ? $maybe_id : '');
            $template_id = $this->sanitize_template_id($raw_id);
            $label = isset($entry['label'])
                ? sanitize_text_field($entry['label'])
                : '';
            $prompt = isset($entry['prompt'])
                ? sanitize_textarea_field($entry['prompt'])
                : '';

            if ('' === $template_id || '' === $label || '' === trim($prompt)) {
                continue;
            }

            $imported[$template_id] = [
                'label' => $label,
                'prompt' => $prompt,
            ];
        }

        if (empty($imported)) {
            $this->errors[] = esc_html__(
                'У файлі не знайдено жодного валідного шаблону.',
                'treba-generate-content'
            );
            return;
        }

        $replace_existing = !empty($_POST['tgpt_import_replace']);
        $templates = $replace_existing ? [] : $this->templates;

        foreach ($imported as $id => $template) {
            $templates[$id] = $template;
        }

        $this->save_templates($templates);

        $this->notices[] = sprintf(
            esc_html__(
                'Імпорт завершено: додано/оновлено %d шаблон(ів).',
                'treba-generate-content'
            ),
            count($imported)
        );
    }

    private function handle_csv_upload()
    {
        if (!$this->is_user_allowed()) {
            return;
        }

        check_admin_referer('tgpt_upload_csv');

        if (
            empty($_FILES['tgpt_csv_file']) ||
            !is_array($_FILES['tgpt_csv_file'])
        ) {
            $this->errors[] = esc_html__(
                'Не завантажено файл CSV.',
                'treba-generate-content'
            );
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $file = $_FILES['tgpt_csv_file'];
        $allowed_mimes = [
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'application/csv' => 'text/csv',
            'text/comma-separated-values' => 'text/csv',
            'application/vnd.ms-excel' => 'text/csv',
        ];

        $uploaded = wp_handle_upload($file, [
            'test_form' => false,
            'mimes' => $allowed_mimes,
        ]);

        if (isset($uploaded['error'])) {
            $this->errors[] = esc_html($uploaded['error']);
            return;
        }

        $file_path = $uploaded['file'] ?? '';

        if ('' === $file_path) {
            $this->errors[] = esc_html__(
                'Не вдалося зберегти файл.',
                'treba-generate-content'
            );
            return;
        }

        $parse = $this->read_csv_headers_and_count($file_path, ',');
        $headers = $parse['headers'];
        $rows = (int) $parse['rows'];

        if (empty($headers)) {
            @unlink($file_path);
            $this->errors[] = esc_html__(
                'CSV не містить заголовків або має порожній перший рядок.',
                'treba-generate-content'
            );
            return;
        }

        $this->remove_csv_file($this->get_csv_session());

        $session = [
            'file_path' => $file_path,
            'file_name' => basename($file_path),
            'headers' => $headers,
            'row_count' => max(0, $rows),
            'delimiter' => ',',
            'uploaded_at' => time(),
            'mapping' => [],
            'template' => $this->get_default_template_key(),
            'chunk' => $this->csv_default_chunk,
        ];

        $this->save_csv_session($session);

        $this->notices[] = sprintf(
            esc_html__(
                'CSV завантажено: %d рядків (без заголовка). Оберіть мапінг полів нижче.',
                'treba-generate-content'
            ),
            max(0, $rows)
        );
    }

    private function handle_csv_mapping()
    {
        if (!$this->is_user_allowed()) {
            return;
        }

        check_admin_referer('tgpt_save_csv_mapping');

        $session = $this->get_csv_session();

        if (empty($session)) {
            $this->errors[] = esc_html__(
                'Спершу завантажте CSV-файл.',
                'treba-generate-content'
            );
            return;
        }

        $mapping_input = isset($_POST['tgpt_csv_map'])
            ? (array) $_POST['tgpt_csv_map']
            : [];
        $mapping = [];

        foreach ($this->csv_required_fields as $field_key) {
            $mapping[$field_key] = isset($mapping_input[$field_key])
                ? sanitize_text_field(wp_unslash($mapping_input[$field_key]))
                : '';
        }

        foreach ($mapping as $field => $column) {
            if ('' === $column) {
                $this->errors[] = esc_html__(
                    'Заповніть мапінг для всіх обовʼязкових полів.',
                    'treba-generate-content'
                );
                return;
            }
        }

        foreach ($this->csv_optional_fields as $optional_key) {
            $mapping[$optional_key] = isset($mapping_input[$optional_key])
                ? sanitize_text_field(wp_unslash($mapping_input[$optional_key]))
                : '';
        }

        $template = isset($_POST['tgpt_csv_template'])
            ? sanitize_key(wp_unslash($_POST['tgpt_csv_template']))
            : $this->get_default_template_key();

        if (!isset($this->templates[$template])) {
            $template = $this->get_default_template_key();
        }

        $chunk = isset($_POST['tgpt_csv_chunk'])
            ? (int) $_POST['tgpt_csv_chunk']
            : $this->csv_default_chunk;
        $chunk = max(1, min(10, $chunk));

        $session['mapping'] = $mapping;
        $session['template'] = $template;
        $session['chunk'] = $chunk;

        $this->save_csv_session($session);

        $this->notices[] = esc_html__(
            'Мапінг збережено. Можна запускати пакетну генерацію.',
            'treba-generate-content'
        );
    }

    private function handle_post_generation()
    {
        check_admin_referer('tgpt_generate_post');

        $payload = [
            'title' => $_POST['tgpt_topic'] ?? '',
            'template' =>
                $_POST['tgpt_template'] ?? $this->get_default_template_key(),
            'post_type' =>
                $_POST['tgpt_post_type'] ?? $this->get_default_post_type(),
            'route_number' => $_POST['tgpt_route_number'] ?? '',
            'route_type' => $_POST['tgpt_route_type'] ?? '',
            'city' => $_POST['tgpt_city'] ?? '',
            'distance' => $_POST['tgpt_distance'] ?? '',
            'interval' => $_POST['tgpt_interval'] ?? '',
            'travel_time' => $_POST['tgpt_travel_time'] ?? '',
            'carrier' => $_POST['tgpt_carrier'] ?? '',
            'price' => $_POST['tgpt_price'] ?? '',
            'stops_forward' => $_POST['tgpt_stops_forward'] ?? '',
            'stops_backward' => $_POST['tgpt_stops_backward'] ?? '',
            'selected_categories' => isset($_POST['tgpt_categories'])
                ? (array) wp_unslash($_POST['tgpt_categories'])
                : [],
        ];

        $result = $this->process_generation($payload, true);

        if (!$result['success']) {
            return;
        }

        $this->reset_form = true;
    }

    private function process_generation(array $input, $add_notices = true)
    {
        $providers = $this->providers;
        $provider = $this->get_default_provider();
        $provider = isset($providers[$provider]) ? $provider : 'openai';
        $errors = [];
        $model_override = isset($input['model'])
            ? sanitize_text_field(wp_unslash($input['model']))
            : '';
        $api_key = '';

        if ('' !== $model_override) {
            $detected_provider = $this->find_provider_for_model(
                $model_override
            );

            if ($detected_provider && isset($providers[$detected_provider])) {
                $provider = $detected_provider;
            } else {
                $errors[] = esc_html__(
                    'Модель із CSV не знайдена у переліку доступних.',
                    'treba-generate-content'
                );
            }
        }

        $api_key = $this->get_saved_api_key($provider);

        if (empty($api_key)) {
            $errors[] = sprintf(
                esc_html__(
                    'API ключ для %s не налаштований. Додайте його у вкладці «Налаштування».',
                    'treba-generate-content'
                ),
                esc_html($providers[$provider] ?? ucfirst($provider))
            );

            if ($add_notices) {
                $this->errors = array_merge($this->errors, $errors);
            }

            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        $post_types = $this->get_available_post_types();
        $post_type = isset($input['post_type'])
            ? sanitize_key(wp_unslash($input['post_type']))
            : $this->get_default_post_type();
        $post_type = isset($post_types[$post_type])
            ? $post_type
            : $this->get_default_post_type();
        $taxonomy_for_categories = $this->get_primary_taxonomy_for_post_type(
            $post_type
        );
        $title = isset($input['title'])
            ? sanitize_text_field(wp_unslash($input['title']))
            : '';
        $template = isset($input['template'])
            ? sanitize_key(wp_unslash($input['template']))
            : $this->get_default_template_key();
        $template = isset($this->templates[$template])
            ? $template
            : $this->get_default_template_key();
        $route_number = isset($input['route_number'])
            ? sanitize_text_field(wp_unslash($input['route_number']))
            : '';
        $route_type = isset($input['route_type'])
            ? sanitize_text_field(wp_unslash($input['route_type']))
            : '';
        $city = isset($input['city'])
            ? sanitize_text_field(wp_unslash($input['city']))
            : '';
        $distance = isset($input['distance'])
            ? floatval(wp_unslash($input['distance']))
            : 0;
        $interval = isset($input['interval'])
            ? sanitize_text_field(wp_unslash($input['interval']))
            : '';
        $travel_time = isset($input['travel_time'])
            ? sanitize_text_field(wp_unslash($input['travel_time']))
            : '';
        $carrier = isset($input['carrier'])
            ? sanitize_text_field(wp_unslash($input['carrier']))
            : '';
        $price = isset($input['price'])
            ? sanitize_text_field(wp_unslash($input['price']))
            : '';
        $selected_categories = isset($input['selected_categories'])
            ? array_map('intval', (array) $input['selected_categories'])
            : [];
        $stops_forward = $this->prepare_list_from_textarea(
            $input['stops_forward'] ?? ''
        );
        $stops_backward = $this->prepare_list_from_textarea(
            $input['stops_backward'] ?? ''
        );
        $available_models = $this->get_models_for_provider($provider);
        $model = $this->get_default_model($provider);
        if ('' !== $model_override) {
            if (isset($available_models[$model_override])) {
                $model = $model_override;
            } else {
                $errors[] = sprintf(
                    esc_html__(
                        'Модель %s недоступна для обраного провайдера.',
                        'treba-generate-content'
                    ),
                    esc_html($model_override)
                );
            }
        }
        $valid_categories = [];

        if ($taxonomy_for_categories && !empty($selected_categories)) {
            $valid_terms = get_terms([
                'taxonomy' => $taxonomy_for_categories,
                'hide_empty' => false,
                'include' => $selected_categories,
                'fields' => 'ids',
            ]);

            if (!is_wp_error($valid_terms)) {
                $valid_categories = array_map('intval', $valid_terms);
            }
        }

        if (empty($title)) {
            $errors[] = esc_html__(
                'Назва статті обовʼязкова.',
                'treba-generate-content'
            );
        }

        if (
            '' === $route_number ||
            '' === $route_type ||
            '' === $city ||
            $distance <= 0 ||
            '' === $interval ||
            '' === $travel_time ||
            '' === $carrier ||
            '' === $price ||
            empty($stops_forward) ||
            empty($stops_backward)
        ) {
            $errors[] = esc_html__(
                'Заповніть усі поля форми, включно із зупинками та довжиною маршруту.',
                'treba-generate-content'
            );
        }

        if ('' === $template || !isset($this->templates[$template])) {
            $errors[] = esc_html__(
                'Немає доступних шаблонів. Додайте їх на вкладці «Шаблони».',
                'treba-generate-content'
            );
        }

        if (!isset($available_models[$model])) {
            $model = (string) array_key_first($available_models);
        }

        if (!empty($errors)) {
            if ($add_notices) {
                $this->errors = array_merge($this->errors, $errors);
            }

            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        $prompt = $this->build_prompt($template, [
            'title' => $title,
            'route_number' => $route_number,
            'route_type' => $route_type,
            'city' => $city,
            'distance' => $distance,
            'interval' => $interval,
            'travel_time' => $travel_time,
            'carrier' => $carrier,
            'price' => $price,
            'stops_forward' => $stops_forward,
            'stops_backward' => $stops_backward,
        ]);

        $ai_result = $this->request_ai_content(
            $provider,
            $api_key,
            $model,
            $prompt
        );

        if (empty($ai_result) || empty($ai_result['content'])) {
            return [
                'success' => false,
                'errors' => [
                    esc_html__(
                        'Не вдалося отримати відповідь від моделі.',
                        'treba-generate-content'
                    ),
                ],
            ];
        }

        $content = $this->convert_markdown_to_html($ai_result['content']);

        if ('' === trim($content)) {
            $error = esc_html__(
                'Не вдалося перетворити контент у HTML.',
                'treba-generate-content'
            );

            if ($add_notices) {
                $this->errors[] = $error;
            }

            return [
                'success' => false,
                'errors' => [$error],
            ];
        }

        $post_args = [
            'post_title' => $title,
            'post_content' => wp_kses_post($content),
            'post_status' => 'draft',
            'post_author' => get_current_user_id(),
            'post_type' => $post_type,
        ];

        $post_id = wp_insert_post($post_args, true);

        if (is_wp_error($post_id)) {
            $error = esc_html__(
                'Не вдалося створити запис. Спробуйте пізніше.',
                'treba-generate-content'
            );

            if ($add_notices) {
                $this->errors[] = $error;
            }

            return [
                'success' => false,
                'errors' => [$error],
            ];
        }

        update_post_meta($post_id, '_treba_ai_template', $template);
        update_post_meta($post_id, '_treba_ai_model', $model);
        update_post_meta($post_id, '_treba_ai_provider', $provider);
        update_post_meta($post_id, '_treba_ai_route_number', $route_number);
        update_post_meta($post_id, '_treba_ai_route_type', $route_type);
        update_post_meta($post_id, '_treba_ai_city', $city);
        update_post_meta($post_id, '_treba_ai_distance', $distance);
        update_post_meta($post_id, '_treba_ai_interval', $interval);
        update_post_meta($post_id, '_treba_ai_travel_time', $travel_time);
        update_post_meta($post_id, '_treba_ai_carrier', $carrier);
        update_post_meta($post_id, '_treba_ai_price', $price);
        update_post_meta($post_id, '_treba_ai_stops_forward', $stops_forward);
        update_post_meta($post_id, '_treba_ai_stops_backward', $stops_backward);

        if ($taxonomy_for_categories && !empty($valid_categories)) {
            wp_set_post_terms(
                $post_id,
                $valid_categories,
                $taxonomy_for_categories,
                false
            );
        }

        $used_model =
            isset($ai_result['model']) && $ai_result['model']
                ? $ai_result['model']
                : $model;
        $usage = $ai_result['usage'] ?? [];
        $tokens_text = '';

        if (!empty($usage)) {
            $prompt_tokens = isset($usage['prompt_tokens'])
                ? (int) $usage['prompt_tokens']
                : 0;
            $completion_tokens = isset($usage['completion_tokens'])
                ? (int) $usage['completion_tokens']
                : 0;
            $total_tokens = isset($usage['total_tokens'])
                ? (int) $usage['total_tokens']
                : $prompt_tokens + $completion_tokens;
            $tokens_text = sprintf(
                __(
                    ' · Токени: prompt %d + completion %d = %d',
                    'treba-generate-content'
                ),
                $prompt_tokens,
                $completion_tokens,
                $total_tokens
            );
        }

        $message = sprintf(
            '%s <a href="%s" target="_blank">%s</a> · <a href="%s">%s</a> · %s%s',
            esc_html__('Статтю створено.', 'treba-generate-content'),
            esc_url(get_permalink($post_id)),
            esc_html__('Подивитись', 'treba-generate-content'),
            esc_url(get_edit_post_link($post_id)),
            esc_html__('Редагувати', 'treba-generate-content'),
            esc_html__('Модель:', 'treba-generate-content') .
                ' ' .
                esc_html($used_model),
            $tokens_text
        );

        if ($add_notices) {
            $this->notices[] = $message;
        }

        return [
            'success' => true,
            'post_id' => $post_id,
            'message' => $message,
            'model' => $used_model,
            'tokens_text' => $tokens_text,
        ];
    }

    public function ajax_generate_csv_batch()
    {
        if (!$this->is_user_allowed()) {
            wp_send_json_error(
                [
                    'message' => esc_html__(
                        'Немає доступу до генерації.',
                        'treba-generate-content'
                    ),
                ],
                403
            );
        }

        check_ajax_referer('tgpt_csv_batch', 'nonce');

        $session = $this->get_csv_session();

        if (empty($session) || empty($session['file_path'])) {
            wp_send_json_error(
                [
                    'message' => esc_html__(
                        'Сесія CSV не знайдена. Завантажте файл ще раз.',
                        'treba-generate-content'
                    ),
                ],
                400
            );
        }

        if (!$this->is_csv_mapping_complete($session)) {
            wp_send_json_error(
                [
                    'message' => esc_html__(
                        'Мапінг не завершено. Збережіть мапінг полів.',
                        'treba-generate-content'
                    ),
                ],
                400
            );
        }

        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $offset = max(0, $offset);
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 0;
        $limit = $limit > 0 ? $limit : $this->get_csv_chunk_size($session);

        $headers = $session['headers'] ?? [];
        $rows = $this->read_csv_rows_chunk(
            $session['file_path'],
            $headers,
            $offset,
            $limit,
            $session['delimiter'] ?? ','
        );

        if (empty($rows)) {
            wp_send_json_success([
                'processed' => [],
                'next_offset' => $offset,
                'done' => true,
                'total' => (int) ($session['row_count'] ?? 0),
            ]);
        }

        $mapping = $session['mapping'];
        $template = $session['template'] ?? $this->get_default_template_key();
        $results = [];

        foreach ($rows as $index => $row) {
            $row_template = $template;

            if (
                !empty($mapping['template']) &&
                !empty($row[$mapping['template']])
            ) {
                $candidate = sanitize_key($row[$mapping['template']]);

                if (isset($this->templates[$candidate])) {
                    $row_template = $candidate;
                }
            }

            $row_model = '';

            if (!empty($mapping['model']) && !empty($row[$mapping['model']])) {
                $row_model = sanitize_text_field($row[$mapping['model']]);
            }

            $payload = [
                'title' => $row[$mapping['title']] ?? '',
                'template' => $row_template,
                'post_type' => $this->get_default_post_type(),
                'route_number' => $row[$mapping['route_number']] ?? '',
                'route_type' => $row[$mapping['route_type']] ?? '',
                'city' => $row[$mapping['city']] ?? '',
                'distance' => $row[$mapping['distance']] ?? '',
                'interval' => $row[$mapping['interval']] ?? '',
                'travel_time' => $row[$mapping['travel_time']] ?? '',
                'carrier' => $row[$mapping['carrier']] ?? '',
                'price' => $row[$mapping['price']] ?? '',
                'stops_forward' => $row[$mapping['stops_forward']] ?? '',
                'stops_backward' => $row[$mapping['stops_backward']] ?? '',
                'model' => $row_model,
            ];

            $result = $this->process_generation($payload, false);
            $row_number = $offset + $index + 1;

            if ($result['success']) {
                $results[] = [
                    'row' => $row_number,
                    'success' => true,
                    'post_id' => $result['post_id'],
                    'message' => $result['message'],
                ];
            } else {
                $message = '';

                if (!empty($result['errors'])) {
                    $message = implode(
                        '; ',
                        array_map('wp_kses_post', $result['errors'])
                    );
                }

                $results[] = [
                    'row' => $row_number,
                    'success' => false,
                    'message' =>
                        $message ?:
                        esc_html__(
                            'Не вдалося згенерувати запис.',
                            'treba-generate-content'
                        ),
                ];
            }
        }

        $processed_count = count($rows);
        $next_offset = $offset + $processed_count;
        $total_rows = (int) ($session['row_count'] ?? 0);
        $done = $next_offset >= $total_rows;

        // продовжуємо тримати сесію актуальною на час обробки
        $this->save_csv_session($session);

        if ($done) {
            $this->remove_csv_file($session);
            delete_transient($this->get_csv_session_key());
        }

        wp_send_json_success([
            'processed' => $results,
            'next_offset' => $next_offset,
            'done' => $done,
            'total' => $total_rows,
        ]);
    }

    private function build_prompt($template_key, array $data)
    {
        $template = $this->templates[$template_key]['prompt'];
        $distance = isset($data['distance']) ? (float) $data['distance'] : 0;
        $distance_text =
            $distance > 0
                ? $this->normalize_distance($distance) . ' км'
                : __('невідомо', 'treba-generate-content');
        $stops_forward = $this->format_stops_list($data['stops_forward'] ?? []);
        $stops_backward = $this->format_stops_list(
            $data['stops_backward'] ?? []
        );

        $replacements = [
            '{topic}' => $data['title'] ?? '',
            '{route_number}' => $data['route_number'] ?? '',
            '{route_type}' => $data['route_type'] ?? '',
            '{city}' => $data['city'] ?? '',
            '{distance}' => $distance_text,
            '{interval}' => $data['interval'] ?? '',
            '{travel_time}' => $data['travel_time'] ?? '',
            '{carrier}' => $data['carrier'] ?? '',
            '{price}' => $data['price'] ?? '',
            '{stops_forward}' => $stops_forward,
            '{stops_backward}' => $stops_backward,
        ];

        $filled_template = strtr($template, $replacements);

        $route_summary = array_filter(
            array_map('trim', [
                'Назва: ' . ($data['title'] ?? ''),
                'Номер маршруту: ' . ($data['route_number'] ?? ''),
                'Тип: ' . ($data['route_type'] ?? ''),
                'Місто: ' . ($data['city'] ?? ''),
                'Довжина: ' . $distance_text,
                'Інтервал: ' . ($data['interval'] ?? ''),
                'Час руху: ' . ($data['travel_time'] ?? ''),
                'Перевізник: ' . ($data['carrier'] ?? ''),
                'Ціна: ' . ($data['price'] ?? ''),
                'Зупинки туди: ' . $stops_forward,
                'Зупинки назад: ' . $stops_backward,
            ])
        );

        $prompt_parts = [
            'Ти транспортний копірайтер і аналітик. Пиши структуровано, фактологічно та без вигадок.',
            $filled_template,
            "Використай ці дані маршруту:\n" . implode("\n", $route_summary),
            'Пиши українською мовою, додавай підзаголовки H2/H3 та списки, пояснюй зручно для пасажирів.',
        ];

        return implode("\n\n", array_filter(array_map('trim', $prompt_parts)));
    }

    private function request_ai_content($provider, $api_key, $model, $prompt)
    {
        $provider_key = isset($this->providers[$provider])
            ? $provider
            : 'openai';
        $provider_label = $this->providers[$provider_key] ?? ucfirst($provider);
        $endpoint =
            'openrouter' === $provider_key
                ? 'https://openrouter.ai/api/v1/chat/completions'
                : 'https://api.openai.com/v1/chat/completions';

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ];

        if ('openrouter' === $provider_key) {
            $headers['HTTP-Referer'] = home_url('/');
            $headers['X-Title'] = get_bloginfo('name');
        }

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body' => wp_json_encode([
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' =>
                            'You are a helpful assistant that writes well-structured long-form SEO articles.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.65,
            ]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            $this->errors[] = sprintf(
                '%s %s',
                esc_html__('Помилка запиту до API:', 'treba-generate-content'),
                esc_html(
                    $provider_label . ': ' . $response->get_error_message()
                )
            );
            return '';
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (200 !== $code) {
            $message =
                $body['error']['message'] ??
                esc_html__('Невідома помилка API.', 'treba-generate-content');
            $this->errors[] = sprintf(
                '%s %s',
                esc_html__('API повернув помилку:', 'treba-generate-content'),
                esc_html($provider_label . ': ' . $message)
            );
            return '';
        }

        $content = $body['choices'][0]['message']['content'] ?? '';

        if (empty($content)) {
            $this->errors[] = sprintf(
                '%s %s',
                esc_html__(
                    'API не повернув контент:',
                    'treba-generate-content'
                ),
                esc_html($provider_label)
            );
            return '';
        }

        $usage =
            isset($body['usage']) && is_array($body['usage'])
                ? $body['usage']
                : [];
        $response_model = isset($body['model'])
            ? (string) $body['model']
            : $model;

        return [
            'content' => trim($content),
            'usage' => $usage,
            'model' => $response_model,
        ];
    }

    private function prepare_list_from_textarea($raw)
    {
        $raw = is_scalar($raw) ? wp_unslash($raw) : '';
        $list = preg_split('/[\r\n,]+/', (string) $raw);
        $list = array_filter(array_map('trim', $list));
        return array_values($list);
    }

    private function format_stops_list($stops)
    {
        if (!is_array($stops)) {
            return '';
        }

        $stops = array_values(array_filter(array_map('trim', $stops)));

        return $stops
            ? implode(' → ', $stops)
            : __('немає даних', 'treba-generate-content');
    }

    private function normalize_distance($distance)
    {
        $distance = (float) $distance;

        if ($distance <= 0) {
            return '0';
        }

        if ((int) $distance === $distance) {
            return (string) (int) $distance;
        }

        return rtrim(rtrim(number_format($distance, 1, '.', ''), '0'), '.');
    }

    private function get_field_value($key, $default = '')
    {
        if ($this->reset_form) {
            return $default;
        }

        if (isset($_POST[$key])) {
            return wp_unslash($_POST[$key]);
        }

        return $default;
    }

    private function get_default_models()
    {
        $stored = get_option($this->default_models_option, []);
        return is_array($stored) ? $stored : [];
    }

    private function get_models_for_provider($provider)
    {
        return $this->models[$provider] ?? [];
    }

    private function get_available_post_types()
    {
        $types = get_post_types(
            [
                'public' => true,
                'show_ui' => true,
            ],
            'objects'
        );
        $disallow = ['attachment', 'revision', 'nav_menu_item'];

        foreach ($disallow as $blocked) {
            unset($types[$blocked]);
        }

        if (!isset($types['post'])) {
            $post_obj = get_post_type_object('post');

            if ($post_obj) {
                $types['post'] = $post_obj;
            }
        }

        return $types;
    }

    private function get_primary_taxonomy_for_post_type($post_type)
    {
        $taxonomies = get_object_taxonomies($post_type, 'objects');

        if (isset($taxonomies['category'])) {
            return 'category';
        }

        foreach ($taxonomies as $slug => $taxonomy) {
            $taxonomy_obj = is_object($taxonomy) ? $taxonomy : null;

            if (
                $taxonomy_obj &&
                !empty($taxonomy_obj->hierarchical) &&
                !empty($taxonomy_obj->show_ui)
            ) {
                return (string) $slug;
            }
        }

        return '';
    }

    private function get_category_terms_for_post_type($post_type)
    {
        $taxonomy = $this->get_primary_taxonomy_for_post_type($post_type);

        if ('' === $taxonomy) {
            return [
                'taxonomy' => '',
                'terms' => [],
            ];
        }

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return [
                'taxonomy' => $taxonomy,
                'terms' => [],
            ];
        }

        return [
            'taxonomy' => $taxonomy,
            'terms' => $terms,
        ];
    }

    private function get_csv_session_key()
    {
        $user_id = get_current_user_id();
        return 'tgpt_csv_session_' . (int) $user_id;
    }

    private function get_csv_session()
    {
        $session = get_transient($this->get_csv_session_key());
        return is_array($session) ? $session : [];
    }

    private function save_csv_session(array $data)
    {
        set_transient(
            $this->get_csv_session_key(),
            $data,
            $this->csv_session_ttl
        );
    }

    private function clear_csv_session()
    {
        if (!$this->is_user_allowed()) {
            return;
        }

        check_admin_referer('tgpt_upload_csv');

        $this->remove_csv_file($this->get_csv_session());
        delete_transient($this->get_csv_session_key());
        $this->notices[] = esc_html__(
            'Файл імпорту очищено.',
            'treba-generate-content'
        );
    }

    private function remove_csv_file($session)
    {
        if (
            isset($session['file_path']) &&
            $session['file_path'] &&
            is_readable($session['file_path'])
        ) {
            @unlink($session['file_path']);
        }
    }

    private function read_csv_headers_and_count($file_path, $delimiter = ',')
    {
        if (!is_readable($file_path)) {
            return [
                'headers' => [],
                'rows' => 0,
            ];
        }

        $headers = [];
        $rows = 0;
        $handle = fopen($file_path, 'r');

        if (false === $handle) {
            return [
                'headers' => [],
                'rows' => 0,
            ];
        }

        $headers = fgetcsv($handle, 0, $delimiter);
        $headers = is_array($headers) ? array_map('trim', $headers) : [];

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (
                $data === [null] ||
                (count($data) === 1 && '' === trim((string) $data[0]))
            ) {
                continue;
            }
            $rows++;
        }

        fclose($handle);

        return [
            'headers' => array_values(array_filter($headers)),
            'rows' => $rows,
        ];
    }

    private function read_csv_rows_chunk(
        $file_path,
        $headers,
        $offset,
        $length,
        $delimiter = ','
    ) {
        $rows = [];

        if (
            !is_readable($file_path) ||
            empty($headers) ||
            $offset < 0 ||
            $length <= 0
        ) {
            return $rows;
        }

        $handle = fopen($file_path, 'r');

        if (false === $handle) {
            return $rows;
        }

        // skip header
        fgetcsv($handle, 0, $delimiter);

        $current_index = 0;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (
                $data === [null] ||
                (count($data) === 1 && '' === trim((string) $data[0]))
            ) {
                $current_index++;
                continue;
            }

            if ($current_index < $offset) {
                $current_index++;
                continue;
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = $data[$index] ?? '';
            }

            $rows[] = $row;
            $current_index++;

            if (count($rows) >= $length) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function is_csv_mapping_complete(array $session)
    {
        if (empty($session['mapping']) || !is_array($session['mapping'])) {
            return false;
        }

        foreach ($this->csv_required_fields as $field_key) {
            if (
                empty($session['mapping'][$field_key]) ||
                !is_string($session['mapping'][$field_key])
            ) {
                return false;
            }
        }

        return true;
    }

    private function find_provider_for_model($model_key)
    {
        foreach ($this->models as $provider_key => $provider_models) {
            if (isset($provider_models[$model_key])) {
                return (string) $provider_key;
            }
        }

        return '';
    }

    private function get_csv_chunk_size(array $session)
    {
        $chunk =
            isset($session['chunk']) && (int) $session['chunk'] > 0
                ? (int) $session['chunk']
                : $this->csv_default_chunk;
        return max(1, min(10, $chunk));
    }

    private function get_default_model($provider)
    {
        $provider = isset($this->providers[$provider])
            ? $provider
            : $this->get_default_provider();
        $models = $this->get_models_for_provider($provider);
        $fallback = (string) array_key_first($models);
        $stored = $this->get_default_models();
        $candidate = $stored[$provider] ?? $fallback;

        return isset($models[$candidate]) ? $candidate : $fallback;
    }

    private function get_default_provider()
    {
        $stored = get_option($this->default_provider_option, 'openai');
        return isset($this->providers[$stored]) ? $stored : 'openai';
    }

    private function get_default_post_type()
    {
        $stored = get_option($this->default_post_type_option, 'post');
        $types = $this->get_available_post_types();

        return isset($types[$stored]) ? $stored : 'post';
    }

    private function convert_markdown_to_html($markdown)
    {
        $markdown = (string) $markdown;

        if ('' === trim($markdown)) {
            return '';
        }

        $parser = $this->get_markdown_parser();

        if ($parser) {
            return (string) $parser->text($markdown);
        }

        return wpautop($markdown);
    }

    private function get_markdown_parser()
    {
        if (null === $this->markdown_parser && class_exists('Parsedown')) {
            $this->markdown_parser = new Parsedown();

            if (method_exists($this->markdown_parser, 'setSafeMode')) {
                $this->markdown_parser->setSafeMode(true);
            }

            if (method_exists($this->markdown_parser, 'setBreaksEnabled')) {
                $this->markdown_parser->setBreaksEnabled(true);
            }
        }

        return $this->markdown_parser;
    }

    private function get_saved_api_key($provider)
    {
        if (!isset($this->api_key_options[$provider])) {
            $provider = $this->get_default_provider();
        }

        if (isset($this->cached_api_keys[$provider])) {
            return $this->cached_api_keys[$provider];
        }

        $option_name = $this->api_key_options[$provider];
        $stored = get_option($option_name, '');

        if ('' === $stored) {
            $this->cached_api_keys[$provider] = '';
            return '';
        }

        $decrypted = $this->decrypt_api_key($stored);
        $this->cached_api_keys[$provider] = is_string($decrypted)
            ? $decrypted
            : '';

        return $this->cached_api_keys[$provider];
    }

    private function has_api_key($provider)
    {
        return '' !== $this->get_saved_api_key($provider);
    }

    private function encrypt_api_key($api_key)
    {
        $api_key = trim($api_key);

        if ('' === $api_key || !function_exists('openssl_encrypt')) {
            return '';
        }

        $encryption_key = $this->get_encryption_key();
        $iv = $this->generate_iv();
        $cipher = openssl_encrypt(
            $api_key,
            'aes-256-cbc',
            $encryption_key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if (false === $cipher) {
            return '';
        }

        return wp_json_encode([
            'iv' => base64_encode($iv),
            'value' => base64_encode($cipher),
        ]);
    }

    private function decrypt_api_key($value)
    {
        if ('' === $value) {
            return '';
        }

        if (!function_exists('openssl_decrypt')) {
            return $value;
        }

        $decoded = json_decode($value, true);

        if (
            !is_array($decoded) ||
            empty($decoded['iv']) ||
            empty($decoded['value'])
        ) {
            return $value;
        }

        $iv = base64_decode($decoded['iv'], true);
        $cipher = base64_decode($decoded['value'], true);

        if (!is_string($iv) || !is_string($cipher) || 16 !== strlen($iv)) {
            return '';
        }

        $encryption_key = $this->get_encryption_key();
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-cbc',
            $encryption_key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return is_string($plain) ? $plain : '';
    }

    private function get_encryption_key()
    {
        if (null !== $this->encryption_key) {
            return $this->encryption_key;
        }

        $source = '';

        foreach (
            ['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY']
            as $constant
        ) {
            if (defined($constant)) {
                $source .= constant($constant);
            }
        }

        if ('' === $source) {
            $source = wp_salt('auth');
        }

        $this->encryption_key = hash('sha256', $source, true);

        return $this->encryption_key;
    }

    private function generate_iv()
    {
        if (function_exists('random_bytes')) {
            return random_bytes(16);
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            return openssl_random_pseudo_bytes(16);
        }

        return substr(
            hash('sha256', wp_generate_password(64, true, true), true),
            0,
            16
        );
    }

    private function can_manage_templates()
    {
        return $this->is_user_allowed();
    }

    private function is_user_allowed()
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $allowed_ids = array_map(
            'intval',
            (array) get_option($this->allowed_users_option, [])
        );
        return in_array(get_current_user_id(), $allowed_ids, true);
    }
}

new Treba_Routes_Ai_Content_Plugin();

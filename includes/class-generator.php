<?php
defined('ABSPATH') || exit;

/**
 * Generates one draft post for a given route row.
 * Checks for duplicates, calls OpenRouter, creates the post.
 */
class TGR_Generator
{

  private TGR_Settings $settings;

  public function __construct()
  {
    $this->settings = TGR_Settings::get_instance();
  }

  /**
   * Processes a single route row.
   *
   * @param array $route Sanitized route row from CSV.
   * @return array{status: string, message: string, post_id?: int}
   */
  public function process(array $route): array
  {
    $post_type = $this->settings->get_post_type();
    $prompt = $this->settings->get_prompt();

    $title = sanitize_text_field($route['title'] ?? '');
    if (empty($title)) {
      return [
        'status' => 'error',
        'message' => __('Порожня назва маршруту (title).', 'treba-generate-routes'),
      ];
    }

    if (empty($prompt)) {
      return [
        'status' => 'error',
        'message' => __('Промт не налаштовано.', 'treba-generate-routes'),
      ];
    }

    // Check for duplicate by title
    if ($this->post_exists($title, $post_type)) {
      return [
        'status' => 'skipped',
        'message' => sprintf(__('"%s" вже існує — пропущено.', 'treba-generate-routes'), $title),
      ];
    }

    // Substitute all placeholders
    $full_prompt = $this->build_prompt($prompt, $route);

    // Determine model: per-row override OR settings model
    $model = !empty($route['model'])
      ? sanitize_text_field($route['model'])
      : $this->settings->get_model();

    $api = new TGR_OpenRouter($this->settings->get_api_key(), $model);

    $content = $api->generate($full_prompt);

    if (is_wp_error($content)) {
      return [
        'status' => 'error',
        'message' => sprintf(
          __('"%1$s": %2$s', 'treba-generate-routes'),
          $title,
          $content->get_error_message()
        ),
      ];
    }

    // Convert Markdown → Gutenberg HTML
    $html = $this->markdown_to_html($content);

    $post_id = wp_insert_post([
      'post_title' => $title,
      'post_content' => wp_kses_post($html),
      'post_status' => 'draft',
      'post_type' => $post_type,
      'post_author' => get_current_user_id(),
    ], true);

    if (is_wp_error($post_id)) {
      return [
        'status' => 'error',
        'message' => sprintf(
          __('Помилка створення запису "%1$s": %2$s', 'treba-generate-routes'),
          $title,
          $post_id->get_error_message()
        ),
      ];
    }

    return [
      'status' => 'created',
      'message' => sprintf(
        __('"%1$s" — чернетку створено (<a href="%2$s" target="_blank">редагувати</a>)', 'treba-generate-routes'),
        esc_html($title),
        esc_url(get_edit_post_link($post_id))
      ),
      'post_id' => $post_id,
    ];
  }

  /**
   * Replaces all placeholders in the prompt with route data.
   */
  private function build_prompt(string $prompt, array $route): string
  {
    $placeholders = [
      '{title}',
      '{route_number}',
      '{route_type}',
      '{city}',
      '{distance}',
      '{interval}',
      '{travel_time}',
      '{carrier}',
      '{price}',
      '{stops_forward}',
      '{stops_backward}',
    ];

    $values = [];
    foreach ($placeholders as $ph) {
      $key = trim($ph, '{}');
      $values[] = $route[$key] ?? '';
    }

    return str_replace($placeholders, $values, $prompt);
  }

  /**
   * Checks if a post with the exact same title already exists.
   */
  private function post_exists(string $title, string $post_type): bool
  {
    $query = new WP_Query([
      'post_type' => $post_type,
      'post_status' => 'any',
      'title' => $title,
      'posts_per_page' => 1,
      'no_found_rows' => true,
      'update_post_meta_cache' => false,
      'update_post_term_cache' => false,
      'fields' => 'ids',
    ]);

    return $query->have_posts();
  }

  /**
   * Converts Markdown to Gutenberg block-format HTML.
   */
  private function markdown_to_html(string $markdown): string
  {
    $text = str_replace(["\r\n", "\r"], "\n", $markdown);
    $blocks = preg_split('/\n{2,}/', trim($text));
    $output = [];

    foreach ($blocks as $block) {
      $block = trim($block);
      if ($block === '') {
        continue;
      }

      // Horizontal rule
      if (preg_match('/^([-*_]){3,}$/', $block)) {
        $output[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
        continue;
      }

      // Headings
      if (preg_match('/^(#{1,6})\s+(.+)$/', $block, $m)) {
        $level = strlen($m[1]);
        $heading = $this->inline_markdown(trim($m[2]));
        $output[] = "<!-- wp:heading {\"level\":{$level}} -->\n<h{$level} class=\"wp-block-heading\">{$heading}</h{$level}>\n<!-- /wp:heading -->";
        continue;
      }

      // Unordered list
      if (preg_match('/^[\-\*\+]\s/', $block)) {
        $items = $this->parse_list_items($block);
        $output[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">{$items}</ul>\n<!-- /wp:list -->";
        continue;
      }

      // Ordered list
      if (preg_match('/^\d+\.\s/', $block)) {
        $items = $this->parse_list_items($block);
        $output[] = "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">{$items}</ol>\n<!-- /wp:list -->";
        continue;
      }

      // Blockquote
      if (preg_match('/^>\s?/', $block)) {
        $inner = preg_replace('/^>\s?/m', '', $block);
        $inner = $this->inline_markdown($inner);
        $output[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>{$inner}</p></blockquote>\n<!-- /wp:quote -->";
        continue;
      }

      // Markdown table: block contains | and a separator row like |---|---|---|
      if (strpos($block, '|') !== false && preg_match('/^\|?[ \t]*:?-+:?[ \t]*\|/m', $block)) {
        $table_html = $this->parse_table($block);
        if ($table_html !== '') {
          $output[] = $table_html;
          continue;
        }
      }

      // Paragraph (default)
      $lines = explode("\n", $block);
      $lines = array_map(fn($l) => $this->inline_markdown(trim($l)), $lines);
      $para = implode('<br>', $lines);
      $output[] = "<!-- wp:paragraph -->\n<p>{$para}</p>\n<!-- /wp:paragraph -->";
    }

    return implode("\n\n", $output);
  }

  private function parse_list_items(string $block): string
  {
    $lines = explode("\n", $block);
    $items = '';
    foreach ($lines as $line) {
      $line = trim($line);
      $line = preg_replace('/^([\-\*\+]|\d+\.)\s+/', '', $line);
      $items .= '<li>' . $this->inline_markdown($line) . '</li>';
    }
    return $items;
  }

  /**
   * Converts a Markdown pipe table into a Gutenberg wp:table block.
   * Handles optional leading/trailing pipes, and :---/---: alignment markers.
   */
  private function parse_table(string $block): string
  {
    $lines = array_filter(
      array_map('trim', explode("\n", $block)),
      fn($l) => $l !== ''
    );
    $lines = array_values($lines);

    if (count($lines) < 2) {
      return '';
    }

    // Helper: split a pipe-row into cells, stripping leading/trailing pipes
    $split = function (string $line): array {
      $line = trim($line, '| ');
      return array_map('trim', explode('|', $line));
    };

    // Row 0 = header, Row 1 = separator (skip), Row 2+ = body
    $header_cells = $split($lines[0]);

    // Build <thead>
    $th_html = '';
    foreach ($header_cells as $cell) {
      $th_html .= '<th>' . $this->inline_markdown($cell) . '</th>';
    }
    $thead = "<thead><tr>{$th_html}</tr></thead>";

    // Build <tbody> (skip index 1 which is the separator row)
    $tbody_rows = '';
    for ($i = 2; $i < count($lines); $i++) {
      $cells = $split($lines[$i]);
      $td_html = '';
      foreach ($cells as $cell) {
        $td_html .= '<td>' . $this->inline_markdown($cell) . '</td>';
      }
      $tbody_rows .= "<tr>{$td_html}</tr>";
    }
    $tbody = $tbody_rows !== '' ? "<tbody>{$tbody_rows}</tbody>" : '';

    $table = "<figure class=\"wp-block-table\"><table><thead>{$thead}</thead>{$tbody}</table></figure>";
    return "<!-- wp:table -->\n{$table}\n<!-- /wp:table -->";
  }

  private function inline_markdown(string $text): string
  {
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    $text = preg_replace('/[\*_]{3}(.+?)[\*_]{3}/', '<strong><em>$1</em></strong>', $text);
    $text = preg_replace('/[\*_]{2}(.+?)[\*_]{2}/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<!\*)[\*_](?![\*_])(.+?)(?<!\*)([\*_])(?![\*_])/', '<em>$1</em>', $text);
    $text = preg_replace('/~~(.+?)~~/', '<del>$1</del>', $text);
    $text = preg_replace_callback(
      '/\[([^\]]+)\]\(([^)]+)\)/',
      function ($m) {
        return '<a href="' . esc_url($m[2]) . '">' . esc_html($m[1]) . '</a>';
      },
      $text
    );
    return $text;
  }
}

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
   * Uses a line-by-line state machine so elements separated by a single
   * newline (common in AI output) are parsed correctly.
   */
  private function markdown_to_html(string $markdown): string
  {
    $text = str_replace(["\r\n", "\r"], "\n", $markdown);
    $lines = explode("\n", $text);
    $output = [];

    $buf_type = '';
    $buf = [];

    $flush = function () use (&$buf_type, &$buf, &$output) {
      if (empty($buf) || $buf_type === '') {
        $buf_type = '';
        $buf = [];
        return;
      }
      switch ($buf_type) {
        case 'heading':
          if (preg_match('/^(#{1,6})\s+(.+)$/', $buf[0], $m)) {
            $level = strlen($m[1]);
            $h = $this->inline_markdown(trim($m[2]));
            $output[] = "<!-- wp:heading {\"level\":{$level}} -->\n<h{$level} class=\"wp-block-heading\">{$h}</h{$level}>\n<!-- /wp:heading -->";
          }
          break;
        case 'hr':
          $output[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
          break;
        case 'ul':
          $items = $this->parse_list_items(implode("\n", $buf));
          $output[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">{$items}</ul>\n<!-- /wp:list -->";
          break;
        case 'ol':
          $items = $this->parse_list_items(implode("\n", $buf));
          $output[] = "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">{$items}</ol>\n<!-- /wp:list -->";
          break;
        case 'quote':
          $inner = preg_replace('/^>\s?/m', '', implode("\n", $buf));
          $output[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>" . $this->inline_markdown($inner) . "</p></blockquote>\n<!-- /wp:quote -->";
          break;
        case 'table':
          $block_str = implode("\n", $buf);
          if (preg_match('/^\|?[\s]*:?-+:?[\s]*\|/m', $block_str)) {
            $tbl = $this->parse_table($block_str);
            if ($tbl !== '') {
              $output[] = $tbl;
              break;
            }
          }
          // Fallback to paragraph
          $output[] = "<!-- wp:paragraph -->\n<p>" . implode('<br>', array_map(fn($l) => $this->inline_markdown(trim($l)), $buf)) . "</p>\n<!-- /wp:paragraph -->";
          break;
        case 'para':
          $pl = array_filter(array_map(fn($l) => $this->inline_markdown(trim($l)), $buf), fn($l) => $l !== '');
          if (!empty($pl)) {
            $output[] = "<!-- wp:paragraph -->\n<p>" . implode('<br>', $pl) . "</p>\n<!-- /wp:paragraph -->";
          }
          break;
      }
      $buf_type = '';
      $buf = [];
    };

    foreach ($lines as $line) {
      $t = rtrim($line);

      if (trim($t) === '') {
        $flush();
        continue;
      }

      // Heading (always single-line)
      if (preg_match('/^(#{1,6})\s/', $t)) {
        $flush();
        $buf_type = 'heading';
        $buf = [$t];
        $flush();
        continue;
      }

      // Horizontal rule
      if (preg_match('/^([-*_]){3,}$/', trim($t))) {
        $flush();
        $buf_type = 'hr';
        $buf = [$t];
        $flush();
        continue;
      }

      // Unordered list item
      if (preg_match('/^[\-\*\+]\s/', $t)) {
        if ($buf_type !== 'ul') {
          $flush();
          $buf_type = 'ul';
        }
        $buf[] = $t;
        continue;
      }

      // Ordered list item
      if (preg_match('/^\d+\.\s/', $t)) {
        if ($buf_type !== 'ol') {
          $flush();
          $buf_type = 'ol';
        }
        $buf[] = $t;
        continue;
      }

      // Blockquote
      if (strpos($t, '>') === 0) {
        if ($buf_type !== 'quote') {
          $flush();
          $buf_type = 'quote';
        }
        $buf[] = $t;
        continue;
      }

      // Table (line contains |)
      if (strpos($t, '|') !== false) {
        if ($buf_type !== 'table') {
          $flush();
          $buf_type = 'table';
        }
        $buf[] = $t;
        continue;
      }

      // Paragraph
      if ($buf_type !== 'para') {
        $flush();
        $buf_type = 'para';
      }
      $buf[] = $t;
    }

    $flush();
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

    $table = "<figure class=\"wp-block-table\"><table>{$thead}{$tbody}</table></figure>";
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

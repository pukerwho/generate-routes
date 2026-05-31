<?php
defined('ABSPATH') || exit;

/**
 * Handles CSV upload and parsing for routes.
 * Returns a sanitized array of route row arrays.
 */
class TGR_CSV_Handler
{

  /** Maximum allowed file size in bytes (2 MB). */
  const MAX_FILE_SIZE = 2097152;

  /**
   * Required columns in the CSV (case-insensitive match).
   */
  const REQUIRED_COLUMNS = [
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

  /**
   * Optional columns.
   */
  const OPTIONAL_COLUMNS = ['model'];

  /**
   * Validates the uploaded file, parses it, and returns an array of route rows.
   *
   * @throws RuntimeException On any validation or parsing failure.
   * @return array[] Each element is an associative array of route fields.
   */
  public function parse_upload(): array
  {
    if (!current_user_can('manage_options')) {
      throw new RuntimeException(__('Недостатньо прав.', 'treba-generate-routes'));
    }

    if (empty($_FILES['tgr_csv']) || !is_array($_FILES['tgr_csv'])) {
      throw new RuntimeException(__('Файл не отримано.', 'treba-generate-routes'));
    }

    $file = $_FILES['tgr_csv'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException($this->upload_error_message($file['error']));
    }

    if ($file['size'] > self::MAX_FILE_SIZE) {
      throw new RuntimeException(
        sprintf(
          __('Файл надто великий. Максимум %d МБ.', 'treba-generate-routes'),
          self::MAX_FILE_SIZE / 1048576
        )
      );
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
      throw new RuntimeException(__('Дозволено лише .csv файли.', 'treba-generate-routes'));
    }

    $tmp_path = $file['tmp_name'];
    if (!is_uploaded_file($tmp_path)) {
      throw new RuntimeException(__('Некоректний шлях до файлу.', 'treba-generate-routes'));
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp_path);
    $allowed = ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'];
    if (!in_array($mime, $allowed, true)) {
      throw new RuntimeException(
        sprintf(__('Недопустимий тип файлу: %s', 'treba-generate-routes'), esc_html($mime))
      );
    }

    return $this->parse_csv($tmp_path);
  }

  /**
   * Opens the CSV, maps all columns, and returns sanitized route rows.
   */
  private function parse_csv(string $path): array
  {
    $handle = fopen($path, 'rb');
    if (!$handle) {
      throw new RuntimeException(__('Не вдалося відкрити файл.', 'treba-generate-routes'));
    }

    // Strip UTF-8 BOM if present
    $bom = fread($handle, 3);
    $has_bom = ($bom === "\xEF\xBB\xBF");
    if (!$has_bom) {
      rewind($handle);
    }

    // Auto-detect delimiter
    $first_line = fgets($handle);
    rewind($handle);
    if ($has_bom) {
      fread($handle, 3);
    }
    $delimiter = $this->detect_delimiter($first_line);

    // Read header row
    $header = fgetcsv($handle, 8192, $delimiter);
    if (!$header) {
      fclose($handle);
      throw new RuntimeException(__('CSV файл порожній або пошкоджений.', 'treba-generate-routes'));
    }

    // Normalise header names
    $header = array_map(fn($h) => mb_strtolower(trim($h)), $header);

    // Locate required columns
    $col_map = [];
    foreach (array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS) as $col) {
      $idx = array_search($col, $header, true);
      if ($idx !== false) {
        $col_map[$col] = $idx;
      }
    }

    // Ensure all required columns exist
    $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($col_map));
    if (!empty($missing)) {
      fclose($handle);
      throw new RuntimeException(
        sprintf(
          __("У файлі відсутні обов'язкові стовпці: %s", 'treba-generate-routes'),
          implode(', ', $missing)
        )
      );
    }

    $routes = [];
    while (($row = fgetcsv($handle, 8192, $delimiter)) !== false) {
      $route = [];
      foreach ($col_map as $field => $idx) {
        $raw = isset($row[$idx]) ? trim($row[$idx]) : '';
        // stops fields may contain commas — keep as-is
        $route[$field] = in_array($field, ['stops_forward', 'stops_backward'], true)
          ? sanitize_textarea_field($raw)
          : sanitize_text_field($raw);
      }

      // Skip completely empty rows
      if (empty(array_filter($route))) {
        continue;
      }

      // Skip rows with no title
      if (empty($route['title'])) {
        continue;
      }

      $routes[] = $route;
    }

    fclose($handle);

    if (empty($routes)) {
      throw new RuntimeException(__('CSV не містить жодного рядка з даними.', 'treba-generate-routes'));
    }

    return $routes;
  }

  private function detect_delimiter(string $line): string
  {
    $counts = [];
    foreach ([',', ';', "\t"] as $d) {
      $counts[$d] = substr_count($line, $d);
    }
    arsort($counts);
    $top = array_key_first($counts);
    return ($counts[$top] > 0) ? $top : ',';
  }

  private function upload_error_message(int $code): string
  {
    $messages = [
      UPLOAD_ERR_INI_SIZE => __('Файл перевищує дозволений розмір (php.ini).', 'treba-generate-routes'),
      UPLOAD_ERR_FORM_SIZE => __('Файл перевищує дозволений розмір (форма).', 'treba-generate-routes'),
      UPLOAD_ERR_PARTIAL => __('Файл завантажено лише частково.', 'treba-generate-routes'),
      UPLOAD_ERR_NO_FILE => __('Файл не вибрано.', 'treba-generate-routes'),
      UPLOAD_ERR_NO_TMP_DIR => __('Відсутня тимчасова директорія.', 'treba-generate-routes'),
      UPLOAD_ERR_CANT_WRITE => __('Помилка запису на диск.', 'treba-generate-routes'),
      UPLOAD_ERR_EXTENSION => __('Завантаження зупинено розширенням PHP.', 'treba-generate-routes'),
    ];
    return $messages[$code] ?? __('Невідома помилка завантаження.', 'treba-generate-routes');
  }
}

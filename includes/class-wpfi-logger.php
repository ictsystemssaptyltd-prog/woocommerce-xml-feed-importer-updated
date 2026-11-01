<?php
if (!defined('ABSPATH')) exit;

final class WPFI_Logger {
    const OPTION = 'wpfi_import_logs';
    const LIMIT = 500;

    public function write($feed, $level, $message, array $context = []) {
        $level = sanitize_key($level);
        $message = is_scalar($message) ? trim((string) $message) : wp_json_encode($message);
        if ($message === '') return;
        $logs = get_option(self::OPTION, []);
        if (!is_array($logs)) $logs = [];
        $entry = ['time' => current_time('mysql'), 'feed' => isset($feed['name']) ? sanitize_text_field($feed['name']) : 'Unknown', 'slug' => isset($feed['slug']) ? sanitize_text_field($feed['slug']) : 'unknown', 'level' => $level, 'message' => $message, 'context' => $context];
        array_unshift($logs, $entry);
        $logs = array_slice($logs, 0, self::LIMIT);
        update_option(self::OPTION, $logs, false);
        $log_line = '[WPFI][' . $level . '] ' . $message;
        if (!empty($context)) $log_line .= ' | ' . wp_json_encode($context);
        error_log($log_line);
        if (function_exists('wc_get_logger')) {
            try { $logger = wc_get_logger(); $logger->log($level, $message, ['source' => 'wpfi-' . ($feed['slug'] ?? 'importer'), 'context' => $context]); }
            catch (Throwable $e) { error_log('[WPFI] WooCommerce logger failed: ' . $e->getMessage()); }
        }
    }

    public function all($limit = 200) {
        $logs = get_option(self::OPTION, []);
        if (!is_array($logs)) return [];
        return array_slice($logs, 0, absint($limit));
    }

    public function clear() { delete_option(self::OPTION); }
}

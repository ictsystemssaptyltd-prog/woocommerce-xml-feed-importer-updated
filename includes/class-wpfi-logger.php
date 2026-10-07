<?php
if (!defined('ABSPATH')) exit;

final class WPFI_Logger {
    const OPTION = 'wpfi_import_logs';
    const LIMIT = 500;

    /**
     * Write a log entry
     */
    public function write($feed, $level, $message, array $context = []) {
        // Validate inputs
        $level = sanitize_key($level);
        $message = wp_strip_all_tags($message);
        
        if (empty($message)) {
            return;
        }

        // Get existing logs
        $logs = get_option(self::OPTION, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        // Create log entry
        $entry = [
            'time' => current_time('mysql'),
            'feed' => isset($feed['name']) ? sanitize_text_field($feed['name']) : 'Unknown',
            'slug' => isset($feed['slug']) ? sanitize_text_field($feed['slug']) : 'unknown',
            'level' => $level,
            'message' => $message,
            'context' => array_map('sanitize_text_field', $context)
        ];

        // Add to top of logs
        array_unshift($logs, $entry);

        // Keep only latest logs
        $logs = array_slice($logs, 0, self::LIMIT);

        // Save logs
        update_option(self::OPTION, $logs, false);

        // Also log to WooCommerce logger if available
        if (function_exists('wc_get_logger')) {
            try {
                $logger = wc_get_logger();
                $logger->log(
                    $level,
                    $message,
                    [
                        'source' => 'wpfi-' . ($feed['slug'] ?? 'importer')
                    ]
                );
            } catch (Exception $e) {
                // Silently fail if WooCommerce logger is not available
            }
        }
    }

    /**
     * Get all logs
     */
    public function all($limit = 200) {
        $logs = get_option(self::OPTION, []);
        if (!is_array($logs)) {
            return [];
        }
        return array_slice($logs, 0, absint($limit));
    }

    /**
     * Clear all logs
     */
    public function clear() {
        delete_option(self::OPTION);
    }
}

<?php
if (!defined('ABSPATH')) exit;

final class WPFI_Plugin {
    private static $instance;

    public static function instance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        // Ensure option exists for storing feeds
        if (!get_option(WPFI_Feed_Repository::OPTION)) {
            update_option(WPFI_Feed_Repository::OPTION, [], false);
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public static function deactivate() {
        // Clear all scheduled imports
        if (class_exists('WPFI_Scheduler')) {
            wp_clear_scheduled_hook(WPFI_Scheduler::HOOK);
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public function boot() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo '<strong>WooCommerce XML Feed Importer:</strong> This plugin requires WooCommerce to be activated.';
                echo '</p></div>';
            });
            return false;
        }

        // Initialize components
        try {
            // Create repository instance
            $repository = new WPFI_Feed_Repository();
            
            // Create logger instance
            $logger = new WPFI_Logger();
            
            // Create importer instance
            $importer = new WPFI_Importer($logger);
            
            // Create scheduler instance
            $scheduler = new WPFI_Scheduler($repository, $importer);
            
            // Sync scheduler with stored feeds
            add_action('init', function() use ($scheduler) {
                // Run scheduler sync on each WordPress init
                if (wp_doing_cron()) {
                    $scheduler->sync();
                }
            }, 20);
            
            // Initialize admin interface
            new WPFI_Admin($repository, $scheduler, $importer, $logger);
            
            return true;
        } catch (Exception $e) {
            // Log initialization errors
            error_log('WPFI Error: ' . $e->getMessage());
            
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo '<strong>WooCommerce XML Feed Importer Error:</strong> ' . esc_html($e->getMessage());
                echo '</p></div>';
            });
            return false;
        }
    }
}

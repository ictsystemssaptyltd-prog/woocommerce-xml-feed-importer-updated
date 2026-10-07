<?php
if (!defined('ABSPATH')) exit;

final class WPFI_Scheduler {
    const HOOK = 'wpfi_import_feed';
    
    private $repo;
    private $importer;

    public function __construct($r, $i) {
        $this->repo = $r;
        $this->importer = $i;
        
        // Register custom cron schedules
        add_filter('cron_schedules', [$this, 'schedules']);
        
        // Register cron job handler
        add_action(self::HOOK, [$this, 'run'], 10, 1);
    }

    /**
     * Register custom cron schedules
     */
    public function schedules($schedules) {
        $schedules['wpfi_15m'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 minutes', 'wpfi')
        ];
        
        $schedules['wpfi_30m'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 minutes', 'wpfi')
        ];
        
        $schedules['wpfi_6h'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 hours', 'wpfi')
        ];
        
        return $schedules;
    }

    /**
     * Clear all scheduled imports
     */
    public function clear() {
        $feeds = $this->repo->all();
        if (is_array($feeds)) {
            foreach ($feeds as $feed) {
                $id = $feed['id'] ?? '';
                if (!empty($id)) {
                    wp_clear_scheduled_hook(self::HOOK, [$id]);
                }
            }
        }
    }

    /**
     * Synchronize all scheduled imports
     */
    public function sync() {
        // Clear existing schedules
        $this->clear();

        // Get available schedules
        $schedules = wp_get_schedules();
        if (!is_array($schedules)) {
            return;
        }

        // Schedule each enabled feed
        $feeds = $this->repo->all();
        if (is_array($feeds)) {
            foreach ($feeds as $feed) {
                $id = $feed['id'] ?? '';
                $enabled = $feed['enabled'] ?? 0;
                $url = $feed['url'] ?? '';
                $frequency = $feed['frequency'] ?? 'daily';

                // Only schedule if feed is enabled and has required fields
                if (!empty($id) && !empty($enabled) && !empty($url) && isset($schedules[$frequency])) {
                    // Schedule with slight delay to avoid thundering herd
                    wp_schedule_event(
                        time() + 2 * MINUTE_IN_SECONDS,
                        $frequency,
                        self::HOOK,
                        [$id]
                    );
                }
            }
        }
    }

    /**
     * Run scheduled import for a feed
     */
    public function run($id) {
        if (empty($id)) {
            return false;
        }

        // Get feed
        $feed = $this->repo->get($id);
        if (!$feed) {
            return false;
        }

        // Only import if enabled
        if (empty($feed['enabled'])) {
            return false;
        }

        // Run import
        return $this->importer->import($feed);
    }
}

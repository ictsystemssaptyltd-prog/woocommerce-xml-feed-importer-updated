<?php
if (!defined('ABSPATH')) exit;
 
final class WPFI_Scheduler {
    const HOOK = 'wpfi_import_feed';
    
    private $repo;
    private $importer;

    public function __construct($r, $i) {
        $this->repo = $r;
        $this->importer = $i;
        
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action(self::HOOK, [$this, 'run'], 10, 1);
    }

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

    public function sync() {
        $this->clear();
        $schedules = wp_get_schedules();
        if (!is_array($schedules)) {
            return;
        }

        $feeds = $this->repo->all();
        if (is_array($feeds)) {
            foreach ($feeds as $feed) {
                $id = $feed['id'] ?? '';
                $enabled = $feed['enabled'] ?? 0;
                $url = $feed['url'] ?? '';
                $frequency = $feed['frequency'] ?? 'daily';

                if (!empty($id) && !empty($enabled) && !empty($url) && isset($schedules[$frequency])) {
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

    public function run($id) {
        if (empty($id)) {
            return false;
        }

        try {
            $feed = $this->repo->get($id);
            if (!$feed) {
                return false;
            }

            if (empty($feed['enabled'])) {
                return false;
            }

            return $this->importer->import($feed);
        } catch (Throwable $e) {
            $feed = $this->repo->get($id);
            if ($feed && method_exists($this->importer, 'log_exception')) {
                $this->importer->log_exception($feed, $e);
            }

            error_log('[WPFI] Background import failed for feed ' . $id . ': ' . $e->getMessage());
            error_log($e->getTraceAsString());
            return false;
        }
    }
}

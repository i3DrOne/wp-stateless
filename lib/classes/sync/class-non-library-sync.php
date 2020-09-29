<?php

namespace wpCloud\StatelessMedia\Sync;

use wpCloud\StatelessMedia\Singleton;

class NonLibrarySync extends BackgroundSync {

  // Make it singleton
  use Singleton;

  /**
   * Cron Healthcheck interval
   */
  public $cron_interval = 5;

  /**
   * Unique action
   */
  protected $action = 'wps_bg_non_library_sync';

  /**
   * Items holder
   */
  private $items = [];

  /**
   * Extended construct
   */
  public function __construct() {
    $this->items = array_filter(array_unique(apply_filters('sm:sync::nonMediaFiles', [])));
    parent::__construct();
  }

  /**
   * Get sync name
   * 
   * @return string
   */
  public function get_name() {
    return __('Compatibility and Custom Folders', ud_get_stateless_media()->domain);
  }

  /**
   * Sync helper window
   * 
   * @return HelperWindow
   */
  public function get_helper_window() {
    return new HelperWindow(
      __('What are Compatibility and Custom Folders?', ud_get_stateless_media()->domain),
      __('All kind of files that were created by themes and plugins in custom folders out of standard Media Library, and that WP-Statless has a Compatibility Support for. Limit and Sorting is not supported.', ud_get_stateless_media()->domain),
    );
  }

  /**
   * Start the process
   * 
   * @return bool
   */
  public function start() {
    try {
      if ($this->is_process_running()) return false;

      // Make sure there is no orphaned data and state
      delete_site_option($this->get_stopped_option_key());
      $this->clear_process_meta();

      $chunks = array_chunk($this->items, $this->get_max_batch_size());
      if (!empty($chunks)) {
        foreach ($chunks as $chunk) {
          foreach ($chunk as $item) {
            $this->push_to_queue($item);
          }

          $this->save();
        }
      }

      $this->dispatch();

      $this->save_process_meta([
        'starttime' => current_time('timestamp')
      ]);
      $this->log('Started');
      return true;
    } catch (\Throwable $e) {
      $this->log(sprintf(__('Could not start the process due to the error: %s'), $e->getMessage()));
      $this->stop();
      return false;
    }
  }

  /**
   * 
   */
  protected function task($item) {
    try {
      @error_reporting(0);
      timer_start();

      if (ud_get_stateless_media()->is_connected_to_gs() !== true) {
        throw new FatalException(__('Not connected to GCS', ud_get_stateless_media()->domain));
      }

      $upload_dir = wp_upload_dir();
      $file_path = trim($item, '/');
      $fullsizepath = $upload_dir['basedir'] . '/' . $file_path;

      do_action('sm:sync::syncFile', $file_path, $fullsizepath, true);

      $this->log(sprintf(__('%1$s (ID %2$s) was successfully synchronised in %3$s seconds.', ud_get_stateless_media()->domain), esc_html(get_the_title($file_path)), $file_path, timer_stop()));

      parent::task($item);
      return false;
    } catch (FatalException $e) {
      $this->log("Stopped due to error - {$e->getMessage()}");
      $this->stop();
      return false;
    } catch (UnprocessableException $e) {
      $this->log($e->getMessage());
      return false;
    } catch (\Throwable $e) {
      $this->log("Stopped due to error - {$e->getMessage()}");
      $this->stop();
      return false;
    }
  }

  /**
   * Thing to do in complete
   */
  protected function complete() {
    parent::complete();

    // @todo do something when complete
    $this->log("Complete");
  }

  /**
   * Notice if process seemed to be stuck
   * 
   * @return string|false
   */
  public function get_process_notice() {
    $last = intval($this->get_process_meta('last_at'));
    if (!$last) {
      $last = intval($this->get_process_meta('starttime'));
      if (!$last) return false;
    }

    $processed = intval($this->get_process_meta('processed'));
    if ($processed > 0) return false;

    if (!property_exists($this, 'cron_interval')) return false;

    $waiting = current_time('timestamp') - $last;
    if ($waiting < MINUTE_IN_SECONDS * $this->cron_interval) return false;

    return sprintf(__('This process takes longer than it should. Please, make sure loopback connections and WP Cron are enabled and working, or try restarting the process.', ud_get_stateless_media()->domain));
  }

  /**
   * Get count of items
   * 
   * @return int
   */
  public function get_total_items() {
    return count($this->items);
  }

  public function extend_queue() {
    // Not needed for this kind of sync
  }
}

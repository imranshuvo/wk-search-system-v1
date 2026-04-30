<?php
/** @var array $analytics */
use WKSearchSystem\Plugin;
use WKSearchSystem\ApiClient;

$client = new ApiClient();
$start = isset($_GET['wk_start']) ? sanitize_text_field($_GET['wk_start']) : '';
$end = isset($_GET['wk_end']) ? sanitize_text_field($_GET['wk_end']) : '';

try {
  $top = $client->fetchTopQueries($start ?: null, $end ?: null);
  $zero = $client->fetchZeroResults($start ?: null, $end ?: null);
  $perf = $client->fetchPerformance($start ?: null, $end ?: null);
} catch (\Exception $e) {
  $top = $zero = $perf = ['rows'=>[]];
}
?>
<div class="wrap">
  <h1><?php echo esc_html__('Search Analytics', 'woo-fast-search'); ?></h1>
  <form method="get">
    <input type="hidden" name="page" value="wk-search-analytics" />
    <label>Start <input type="date" name="wk_start" value="<?php echo esc_attr($start); ?>" /></label>
    <label>End <input type="date" name="wk_end" value="<?php echo esc_attr($end); ?>" /></label>
    <button class="button button-primary" type="submit">Filter</button>
  </form>

  <h2>Top Queries</h2>
  <table class="widefat fixed striped">
    <thead><tr><th>Query</th><th>Count</th><th>Last Searched</th></tr></thead>
    <tbody>
    <?php foreach (($top['rows'] ?? []) as $r): ?>
      <tr><td><?php echo esc_html($r['query'] ?? ''); ?></td><td><?php echo intval($r['count'] ?? 0); ?></td><td><?php echo esc_html($r['last_searched'] ?? ''); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Zero-Result Queries</h2>
  <table class="widefat fixed striped">
    <thead><tr><th>Query</th><th>Count</th><th>Last Searched</th></tr></thead>
    <tbody>
    <?php foreach (($zero['rows'] ?? []) as $r): ?>
      <tr><td><?php echo esc_html($r['query'] ?? ''); ?></td><td><?php echo intval($r['count'] ?? 0); ?></td><td><?php echo esc_html($r['last_searched'] ?? ''); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <h2>Performance (ms sum per hour)</h2>
  <table class="widefat fixed striped">
    <thead><tr><th>Date</th><th>Hour</th><th>Sum(ms)</th></tr></thead>
    <tbody>
    <?php foreach (($perf['rows'] ?? []) as $r): ?>
      <tr><td><?php echo esc_html($r['date'] ?? ''); ?></td><td><?php echo intval($r['hour'] ?? 0); ?></td><td><?php echo intval($r['count'] ?? 0); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>




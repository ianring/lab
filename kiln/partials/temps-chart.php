<?php
declare(strict_types=1);
/**
 * The temperature chart. Rendered by firing.php on load and by api.php after a
 * reading changes, so the drawing lives in exactly one place.
 *
 * Expects $temps (array) and $chartCone (string).
 */
$temps     = isset($temps) && is_array($temps) ? $temps : [];
$chartCone = isset($chartCone) ? (string)$chartCone : '';
$chart     = temp_chart_svg($temps, ['cone' => $chartCone]);
?>
<?php if ($chart !== ''): ?>
    <figure class="chart-figure"><?= $chart ?></figure>
<?php endif; ?>

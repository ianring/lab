<?php
declare(strict_types=1);
/**
 * The whole timeline — picture then written steps — in one card, the way the
 * planner shows it. Expects $plan from plan_firing() or plan_manual_firing().
 * Include it, do not call it directly.
 *
 * The firing page places the two halves separately, so they live in
 * timeline-bars.php and timeline-steps.php.
 */
?>
<section class="card timeline-card">
    <h2>Timeline</h2>
    <?php require __DIR__ . '/timeline-bars.php'; ?>
    <?php require __DIR__ . '/timeline-steps.php'; ?>
</section>

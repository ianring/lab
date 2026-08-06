<?php
declare(strict_types=1);
/**
 * The written half of the timeline: every point of interest in order, with the
 * gap between each one. Expects $plan. Include it, do not call it directly.
 */
$kindLabels = [
    'action'    => 'Do',
    'presence'  => 'Be there',
    'milestone' => 'Kiln',
    'finish'    => 'Finish',
    'cooling'   => 'Cooling',
    'deadline'  => 'Deadline',
];
?>
<ol class="markers">
    <?php $lastDay = ''; ?>
    <?php $previous = null; ?>
    <?php foreach ($plan['markers'] as $marker): ?>
        <?php if ($previous !== null): ?>
            <?php $elapsed = minutes_between($previous, $marker['at']); ?>
            <?php if ($elapsed > 0): ?>
                <li class="marker-gap"><span><?= e(fmt_minutes($elapsed)) ?></span></li>
            <?php endif; ?>
        <?php endif; ?>
        <?php $day = $marker['at']->format('Y-m-d'); ?>
        <?php if ($day !== $lastDay): ?>
            <li class="marker-day"><?= e(fmt_day($marker['at'])) ?></li>
            <?php $lastDay = $day; ?>
        <?php endif; ?>
        <li class="marker marker--<?= e($marker['kind']) ?>">
            <time><?= e(ltrim($marker['at']->format('g:i a'), '0')) ?></time>
            <div>
                <span class="marker-kind"><?= e($kindLabels[$marker['kind']] ?? $marker['kind']) ?></span>
                <strong><?= e($marker['label']) ?></strong>
                <?php if (!empty($marker['detail'])): ?>
                    <p><?= e($marker['detail']) ?></p>
                <?php endif; ?>
            </div>
        </li>
        <?php $previous = $marker['at']; ?>
    <?php endforeach; ?>
</ol>

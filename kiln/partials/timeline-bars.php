<?php
declare(strict_types=1);
/**
 * The picture half of the timeline: the segment bar, the three visit bands and
 * the legend under them. Expects $plan from plan_firing() or
 * plan_manual_firing(). Include it, do not call it directly.
 */
$windowStart = $plan['window_start'];
$windowEnd   = $plan['window_end'];
$totalMinutes = max(1, minutes_between($windowStart, $windowEnd));

$pct = static function (DateTimeImmutable $a, DateTimeImmutable $b) use ($windowStart, $totalMinutes) {
    $left  = max(0, minutes_between($windowStart, $a)) / $totalMinutes * 100;
    $width = max(0, minutes_between($a, $b)) / $totalMinutes * 100;
    return [round($left, 3), round(min($width, 100 - $left), 3)];
};
?>
<div class="tl">
    <div class="tl-track">
        <?php foreach ($plan['segments'] as $segment): ?>
            <?php [$left, $width] = $pct($segment['start'], $segment['end']); ?>
            <div class="tl-seg tl-seg--<?= e($segment['key']) ?>"
                 style="left:<?= $left ?>%;width:<?= $width ?>%"
                 title="<?= e($segment['label'] . ' — ' . fmt_minutes($segment['minutes'])) ?>">
                <span><?= e($segment['label']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php $bands = $plan['visit_bands'] ?? $plan['presence']; ?>
    <div class="tl-track tl-track--presence">
        <?php foreach ($bands as $band): ?>
            <?php
            if ($band['end'] <= $band['start']) {
                continue;
            }
            [$left, $width] = $pct($band['start'], $band['end']);
            ?>
            <div class="tl-band"
                 style="left:<?= $left ?>%;width:<?= $width ?>%"
                 title="<?= e(($band['number'] ?? '') !== '' ? 'Visit ' . $band['number'] . ' — ' : '')
                     . $band['label'] . ': ' . fmt_clock($band['start']) . ' to ' . fmt_clock($band['end']) ?>">
                <span><?= e($band['number'] ?? '') ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php /* Over 48 hours the bands are slivers, so the names go here where
             there is room for them, with the times spelled out. */ ?>
    <ul class="tl-visits">
        <?php foreach ($bands as $band): ?>
            <?php if ($band['end'] <= $band['start']) { continue; } ?>
            <li>
                <span class="tl-visit-number"><?= e($band['number'] ?? '') ?></span>
                <span class="tl-visit-name"><?= e($band['label']) ?></span>
                <span class="tl-visit-time"><?= e(fmt_clock($band['start'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tl-axis">
        <span><?= e(fmt_clock($windowStart)) ?></span>
        <span><?= e(fmt_clock($windowEnd)) ?></span>
    </div>
</div>

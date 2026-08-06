<?php
declare(strict_types=1);
/**
 * The logged temperature rows. Rendered both by firing.php on page load and by
 * api.php after a change, so the row markup lives in exactly one place.
 *
 * Expects $temps (array) and $firingId (int).
 */
$temps    = isset($temps) && is_array($temps) ? $temps : [];
$firingId = isset($firingId) ? (int)$firingId : 0;
?>
<?php foreach ($temps as $i => $temp): ?>
    <?php $at = parse_dt($temp['at'] ?? ''); ?>
    <li>
        <form method="post" action="firing.php" class="temp-row">
            <input type="hidden" name="action" value="temp_delete">
            <input type="hidden" name="id" value="<?= e($firingId) ?>">
            <input type="hidden" name="index" value="<?= e($i) ?>">

            <span class="temp-view temp-when">
                <span class="temp-date"><?= e($at ? $at->format('D j M') : '') ?></span>
                <span class="temp-time"><?= $at ? e(ltrim($at->format('g:i a'), '0')) : '&mdash;' ?></span>
            </span>
            <input class="temp-edit temp-when" type="datetime-local" name="temp_at"
                   value="<?= e(dt_input($at)) ?>" disabled>

            <span class="temp-view temp-c"><strong><?= e($temp['c'] ?? '') ?></strong>&deg;C</span>
            <input class="temp-edit temp-c" type="number" inputmode="decimal" step="1"
                   name="temp_c" value="<?= e($temp['c'] ?? '') ?>" disabled>

            <button type="submit" class="btn btn--tiny temp-action" aria-label="Delete this reading">
                <span class="glyph-view">&times;</span>
                <span class="glyph-edit">&check;</span>
            </button>
        </form>
    </li>
<?php endforeach; ?>

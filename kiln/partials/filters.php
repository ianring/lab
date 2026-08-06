<?php
declare(strict_types=1);
/**
 * The person and kiln filters, shared by the list and the calendar so both
 * offer the same choices and carry them across when you switch views.
 *
 * Expects:
 *   $filterAction  the page to submit to
 *   $personFilter  a user id, or 0 for everyone
 *   $kilnFilter    a kiln id, or '' for all
 *   $filterKeep    optional query parameters to carry through (the month)
 */
?>
<form method="get" action="<?= e($filterAction) ?>" class="filters">
    <?php foreach (($filterKeep ?? []) as $name => $value): ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
    <?php endforeach; ?>

    <select name="person" onchange="this.form.submit()">
        <?php foreach (person_filter_options() as $option): ?>
            <option value="<?= e($option['id']) ?>" <?= $option['id'] === $personFilter ? 'selected' : '' ?>>
                <?= e($option['label']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <select name="kiln" onchange="this.form.submit()">
        <option value="">All kilns</option>
        <?php foreach (kiln_list() as $kid => $k): ?>
            <option value="<?= e($kid) ?>" <?= (string)$kid === $kilnFilter ? 'selected' : '' ?>><?= e($k['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <noscript><button type="submit" class="btn">Filter</button></noscript>
</form>

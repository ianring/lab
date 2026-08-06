<?php
declare(strict_types=1);
/**
 * "Fired by": a signed-in user, or a typed name for anybody else.
 *
 * The select carries the user id; -1 means "somebody who has not signed in"
 * and reveals the text box. Without JavaScript both are simply visible.
 *
 * Expects $firedBy (int) and $firedByName (string).
 */
$firedBy     = isset($firedBy) ? (int)$firedBy : 0;
$firedByName = isset($firedByName) ? trim((string)$firedByName) : '';
$isGuest     = $firedBy === 0 && $firedByName !== '';
$known       = false;
?>
<div class="field firer" data-firer>
    <label for="fired_by">Fired by</label>
    <select name="fired_by" id="fired_by" data-firer-select>
        <option value="0" <?= (!$isGuest && $firedBy === 0) ? 'selected' : '' ?>>Unassigned</option>
        <?php foreach (users_all() as $user): ?>
            <?php $known = $known || (int)$user['id'] === $firedBy; ?>
            <option value="<?= e($user['id']) ?>" <?= (int)$user['id'] === $firedBy ? 'selected' : '' ?>>
                <?= e($user['name']) ?>
            </option>
        <?php endforeach; ?>
        <?php if ($firedBy > 0 && !$known): ?>
            <option value="<?= e($firedBy) ?>" selected><?= e(user_name($firedBy)) ?></option>
        <?php endif; ?>
        <option value="-1" <?= $isGuest ? 'selected' : '' ?>>Someone else&hellip;</option>
    </select>

    <input type="text" name="fired_by_name" id="fired_by_name" data-firer-name
           class="firer-name" placeholder="Their name"
           value="<?= e($firedByName) ?>" <?= $isGuest ? '' : 'hidden' ?>>
</div>

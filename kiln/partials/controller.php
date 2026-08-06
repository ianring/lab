<?php
declare(strict_types=1);
/**
 * The controller panel and its keypress walkthrough.
 *
 * Lives with visit 1 of a firing — the buttons only matter when you are
 * standing at the kiln. Expects $steps and $keypad.
 */
?>
<div class="controller" id="controller">
    <div class="controller-brand">
        <svg class="controller-sun" viewBox="0 0 40 24" aria-hidden="true" focusable="false">
            <g fill="none" stroke="#f0c33c" stroke-width="2" stroke-linecap="round">
                <path d="M8 22a12 12 0 0 1 24 0"/>
                <path d="M20 2v3M31 6l-2 2M9 6l2 2M2 18h3M35 18h3"/>
            </g>
        </svg>
        <span><?= e($keypad['brand']) ?></span>
    </div>

    <div class="controller-head">
        <span class="key key--start" data-key="<?= e($keypad['start']['id']) ?>"><?= nl2br(e($keypad['start']['label'])) ?></span>
        <div class="controller-display" id="led"><?= led_svg($steps[0]['display'] ?? 'IdLE', ['digits' => 4, 'id' => 'panel']) ?></div>
        <div class="controller-units"><span>&deg;F</span><span>&deg;C <em>when lit</em></span></div>
    </div>
    <div class="controller-legend">Hours. Minutes when lit</div>

    <div class="controller-body">
        <div class="controller-col">
            <?php foreach ($keypad['left'] as $block): ?>
                <div class="key-block">
                    <div class="key-heading"><?= e($block['heading']) ?></div>
                    <div class="key-group<?= !empty($block['stack']) ? ' key-group--stack' : '' ?>">
                        <?php foreach ($block['keys'] as $key): ?>
                            <span class="key key--small" data-key="<?= e($key['id']) ?>"><?= nl2br(e($key['label'])) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="keypad">
            <?php foreach ($keypad['rows'] as $row): ?>
                <div class="keypad-row">
                    <?php foreach ($row as $key): ?>
                        <span class="key<?= !empty($key['span']) ? ' key--span2' : '' ?>" data-key="<?= e($key['id']) ?>"><?= nl2br(e($key['label'])) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="controller-col">
            <?php foreach ($keypad['right'] as $block): ?>
                <div class="key-block">
                    <div class="key-heading key-heading--right"><?= e($block['heading']) ?></div>
                    <div class="key-group key-group--stack">
                        <?php foreach ($block['keys'] as $key): ?>
                            <span class="key key--speed" data-key="<?= e($key['id']) ?>"><?= nl2br(e($key['label'])) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="keypad-row keypad-row--bottom">
        <?php foreach ($keypad['bottom'] as $key): ?>
            <span class="key key--small" data-key="<?= e($key['id']) ?>"><?= nl2br(e($key['label'])) ?></span>
        <?php endforeach; ?>
    </div>

    <div class="controller-model"><?= e($keypad['model']) ?></div>
</div>

<div class="walk">
    <div class="walk-head">
        <span id="step-count">Step 1 of <?= count($steps) ?></span>
        <div class="walk-buttons">
            <button type="button" class="btn btn--ghost" id="step-prev">Back</button>
            <button type="button" class="btn" id="step-next">Next</button>
        </div>
    </div>
    <h3 id="step-title"><?= e($steps[0]['title'] ?? '') ?></h3>
    <p id="step-detail"><?= e($steps[0]['detail'] ?? '') ?></p>
    <ul class="step-checks" id="step-checks" hidden></ul>
</div>

<ol class="walk-all">
    <?php foreach ($steps as $step): ?>
        <li<?= !empty($step['optional']) ? ' class="optional"' : '' ?>>
            <strong><?= e($step['title']) ?></strong>
            <span class="walk-display"><?= e($step['display']) ?></span>
            <p><?= e($step['detail']) ?></p>
            <?php if (!empty($step['checks'])): ?>
                <ul class="walk-subchecks">
                    <?php foreach ($step['checks'] as $check): ?>
                        <li><?= e($check) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ol>

<script type="application/json" id="steps-data"><?= json_encode($steps, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<?php
declare(strict_types=1);

/**
 * Segment test page. Everything here is rendered by PHP with no JavaScript
 * involved, so if the diagonals light up on this page but not on the planner,
 * the problem is the browser's cached copy of assets/app.js — hard-reload it.
 */

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/led.php';
require __DIR__ . '/lib/auth.php';

require_login();

$states = [
    'WAIT', 'IdLE', 'CONE', 'HOLd', 'S-GL', 'F-GL', 'S-bS', 'F-bS',
    'PRHT', 'CPL', 'rEV', 'On', '3.20', '0.15', '69', '998', '1223',
];

$segments = array_keys(led_segments());

$pageTitle = 'Display test';
$active    = '';
require __DIR__ . '/partials/header.php';
?>

<section class="card">
    <h2>Display test</h2>
    <p class="hint">Drawn entirely by <code>lib/led.php</code> &mdash; no JavaScript touches this page.
        If the diagonals are lit here but dark on the planner, your browser is running a cached
        <code>assets/app.js</code>; hard-reload it.</p>
</section>

<section class="card">
    <h3 class="phase">All segments lit</h3>
    <div class="led-demo"><?= led_svg('', ['digits' => 1, 'label' => 'all segments']) ?></div>
    <p class="hint">The full starburst below, for comparison &mdash; every one of the
        <?= e(count($segments) - 1) ?> segments plus the decimal point.</p>
    <div class="led-demo"><?= led_svg_all_on() ?></div>
</section>

<section class="card">
    <h3 class="phase">Known states</h3>
    <div class="led-grid">
        <?php foreach ($states as $state): ?>
            <figure>
                <?= led_svg($state, ['digits' => 4]) ?>
                <figcaption><?= e($state) ?><?= isset(led_states()[$state]) ? ' <span class="tag">state</span>' : '' ?></figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
</section>

<section class="card">
    <h3 class="phase">The whole font</h3>
    <div class="led-grid led-grid--tight">
        <?php foreach (array_keys(led_font()) as $char): ?>
            <?php if (trim((string)$char) === '') { continue; } ?>
            <figure>
                <?= led_svg((string)$char, ['digits' => 1, 'glow' => 0.7]) ?>
                <figcaption><?= e($char) ?></figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
    <p class="hint">Correct any glyph in <code>led_font()</code> in <code>lib/led.php</code> &mdash; segments are
        listed by name (<code>a b c d e f g1 g2 h j k l m n</code>). For a readout the font cannot express,
        add the whole string to <code>led_states()</code> instead.</p>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>

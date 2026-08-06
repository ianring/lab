<?php
declare(strict_types=1);

require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/data.php';
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/auth.php';

require_login();

const USERS_PER_PAGE = 25;

/** Sortable columns, and the SQL each one orders by. */
$columns = [
    'name'    => ['label' => 'Name',    'sql' => 'u.name',          'dir' => 'asc'],
    'firings' => ['label' => 'Firings', 'sql' => 'firings',         'dir' => 'desc'],
    'last'    => ['label' => 'Last fired', 'sql' => 'last_firing',  'dir' => 'desc'],
];

$sort = (string)q('sort', 'name');
if (!isset($columns[$sort])) {
    $sort = 'name';
}
$dir = strtolower((string)q('dir', $columns[$sort]['dir'])) === 'asc' ? 'ASC' : 'DESC';

$page  = max(1, (int)q('page', 1));
$total = (int)(db_value('SELECT COUNT(*) FROM users') ?? 0);
$pages = max(1, (int)ceil($total / USERS_PER_PAGE));
$page  = min($page, $pages);
$offset = ($page - 1) * USERS_PER_PAGE;

// One query: users with their firing counts alongside.
$rows = db_rows(
    'SELECT u.id, u.name, u.email, u.picture,
            COUNT(f.id) AS firings,
            MAX(COALESCE(f.actual_start, f.created_at)) AS last_firing
       FROM users u
       LEFT JOIN firings f ON f.fired_by = u.id
      GROUP BY u.id, u.name, u.email, u.picture
      ORDER BY ' . $columns[$sort]['sql'] . ' ' . $dir . ', u.name ASC
      LIMIT ' . USERS_PER_PAGE . ' OFFSET ' . $offset
);

// Names in the log that belong to nobody with an account.
$guests = db_rows(
    "SELECT fired_by_name AS name, COUNT(*) AS firings,
            MAX(COALESCE(actual_start, created_at)) AS last_firing
       FROM firings
      WHERE fired_by = 0 AND fired_by_name <> ''
      GROUP BY fired_by_name
      ORDER BY firings DESC, name ASC"
);

$me = current_user_id();

/** A column heading that flips its own sort direction. */
$heading = static function (string $key) use ($columns, $sort, $dir, $page) {
    $next = ($key === $sort && $dir === 'ASC') ? 'desc' : 'asc';
    if ($key !== $sort) {
        $next = $columns[$key]['dir'];
    }
    $mark = $key === $sort ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '';
    return '<a href="users.php?sort=' . e($key) . '&dir=' . e($next) . '">'
        . e($columns[$key]['label']) . $mark . '</a>';
};

$pageTitle = 'Users';
$active    = 'users';
require __DIR__ . '/partials/header.php';
?>

<section class="card">
    <div class="record-head">
        <h2>Users</h2>
        <span class="muted"><?= e($total) ?> signed in</span>
    </div>

    <div class="table-scroll">
        <table class="steps-table users-table">
            <thead>
                <tr>
                    <th><?= $heading('name') ?></th>
                    <th class="right"><?= $heading('firings') ?></th>
                    <th><?= $heading('last') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <span class="user-cell">
                            <?php if (!empty($row['picture'])): ?>
                                <img class="avatar avatar--tiny" src="<?= e($row['picture']) ?>" alt="" referrerpolicy="no-referrer">
                            <?php endif; ?>
                            <?php if ((int)$row['firings'] > 0): ?>
                                <a href="firings.php?person=<?= e($row['id']) ?>"><?= e($row['name']) ?></a>
                            <?php else: ?>
                                <?= e($row['name']) ?>
                            <?php endif; ?>
                            <?= (int)$row['id'] === $me ? '<span class="muted">you</span>' : '' ?>
                        </span>
                    </td>
                    <td class="right"><?= e((int)$row['firings']) ?></td>
                    <td class="muted"><?= !empty($row['last_firing']) ? e(fmt_day(parse_dt($row['last_firing']))) : '&mdash;' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="3" class="muted">Nobody yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="pager">
            <?php if ($page > 1): ?>
                <a class="btn btn--tiny" href="users.php?sort=<?= e($sort) ?>&dir=<?= e(strtolower($dir)) ?>&page=<?= e($page - 1) ?>">&larr; Back</a>
            <?php endif; ?>
            <span class="muted">Page <?= e($page) ?> of <?= e($pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn--tiny" href="users.php?sort=<?= e($sort) ?>&dir=<?= e(strtolower($dir)) ?>&page=<?= e($page + 1) ?>">Next &rarr;</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

<?php if ($guests): ?>
<section class="card">
    <div class="record-head">
        <h2>Named in the log</h2>
        <span class="muted"><?= e(count($guests)) ?></span>
    </div>
    <p class="hint">People recorded on a firing by name, with no account here &mdash; mostly imported logs.</p>
    <div class="table-scroll">
        <table class="steps-table">
            <thead><tr><th>Name</th><th class="right">Firings</th><th>Last fired</th></tr></thead>
            <tbody>
            <?php foreach ($guests as $guest): ?>
                <tr>
                    <td><?= e($guest['name']) ?></td>
                    <td class="right"><?= e((int)$guest['firings']) ?></td>
                    <td class="muted"><?= !empty($guest['last_firing']) ? e(fmt_day(parse_dt($guest['last_firing']))) : '&mdash;' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

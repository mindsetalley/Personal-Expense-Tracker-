<?php
session_start();
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/csrf.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
$user_id = $_SESSION['user_id'];
$errors  = [];

// A month is valid only if it looks like 2026-11
function valid_month(?string $m): bool {
    return $m !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1;
}
function shift_month(string $m, string $by): string {
    return (new DateTime($m . '-01'))->modify($by)->format('Y-m');
}

// Which month are we looking at? (GET when viewing, POST when saving)
$requested = $_POST['month'] ?? $_GET['month'] ?? null;
$month = valid_month($requested) ? $requested : date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'copy') {
        // Copy last month's limits, skipping categories that already have one
        $prev = shift_month($month, '-1 month');
        $stmt = $pdo->prepare(
            "INSERT INTO budgets (user_id, category_id, month_year, limit_amount)
             SELECT b.user_id, b.category_id, ?, b.limit_amount
             FROM budgets b
             JOIN categories c ON c.id = b.category_id AND c.user_id = b.user_id
             WHERE b.user_id = ? AND b.month_year = ?
             ON DUPLICATE KEY UPDATE limit_amount = budgets.limit_amount"
        );
        $stmt->execute([$month, $user_id, $prev]);
        header('Location: index.php?month=' . $month . '&copied=' . $stmt->rowCount());
        exit;
    }

    // Save or update one budget
    $category_id = $_POST['category_id'] ?? '';
    $limit       = $_POST['limit_amount'] ?? '';

    if (!is_numeric($limit) || $limit <= 0) {
        $errors[] = 'Limit must be a number greater than 0.';
    }

    $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ? AND user_id = ?");
    $stmt->execute([$category_id, $user_id]);
    if (!$stmt->fetch()) {
        $errors[] = 'Invalid category.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare(
            "INSERT INTO budgets (user_id, category_id, month_year, limit_amount)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE limit_amount = VALUES(limit_amount)"
        );
        $stmt->execute([$user_id, $category_id, $month, $limit]);
        header('Location: index.php?month=' . $month . '&saved=1');
        exit;
    }
}

// Each category with its limit (if any) and spending for the chosen month
$stmt = $pdo->prepare(
    "SELECT c.id, c.name, b.limit_amount, COALESCE(SUM(t.amount), 0) AS spent
     FROM categories c
     LEFT JOIN budgets b
            ON b.category_id = c.id AND b.month_year = ?
     LEFT JOIN transactions t
            ON t.category_id = c.id AND t.type = 'expense'
           AND DATE_FORMAT(t.transaction_date, '%Y-%m') = ?
     WHERE c.user_id = ?
     GROUP BY c.id, c.name, b.limit_amount
     ORDER BY c.name"
);
$stmt->execute([$month, $month, $user_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$prevMonth  = shift_month($month, '-1 month');
$nextMonth  = shift_month($month, '+1 month');
$monthLabel = (new DateTime($month . '-01'))->format('F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Budgets</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/../includes/navbar.php'; ?>
<div class="container" style="max-width: 720px;">

    <h3 class="mb-3">Budgets</h3>

    <!-- Month picker -->
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <a href="?month=<?= $prevMonth ?>" class="btn btn-outline-secondary btn-sm">&laquo; Previous</a>
        <strong class="px-2"><?= htmlspecialchars($monthLabel) ?></strong>
        <a href="?month=<?= $nextMonth ?>" class="btn btn-outline-secondary btn-sm">Next &raquo;</a>

        <form method="GET" class="d-flex gap-2 ms-auto">
            <input type="month" name="month" value="<?= htmlspecialchars($month) ?>"
                   class="form-control form-control-sm" required>
            <button class="btn btn-outline-primary btn-sm">Go</button>
        </form>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success py-2">Budget saved.</div>
    <?php endif; ?>
    <?php if (isset($_GET['copied'])): ?>
        <?php $n = (int) $_GET['copied']; ?>
        <div class="alert alert-<?= $n > 0 ? 'success' : 'info' ?> py-2">
            <?= $n > 0
                ? "Copied $n budget" . ($n === 1 ? '' : 's') . " from " . htmlspecialchars((new DateTime($prevMonth . '-01'))->format('F Y')) . "."
                : 'Nothing to copy. Last month has no budgets, or this month already has them all.' ?>
        </div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
    <?php endforeach; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="POST" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                <div class="col-sm-5">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-select" required>
                        <?php foreach ($rows as $r): ?>
                            <option value="<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Monthly limit (GH₵)</label>
                    <input type="number" name="limit_amount" step="0.01" min="0.01" class="form-control" required>
                </div>
                <div class="col-sm-3">
                    <button class="btn btn-primary w-100">Set budget</button>
                </div>
            </form>
        </div>
    </div>

    <form method="POST" class="mb-4"
          onsubmit="return confirm('Copy budgets from <?= htmlspecialchars((new DateTime($prevMonth . '-01'))->format('F Y')) ?>? Existing budgets for this month will not be changed.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="copy">
        <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
        <button class="btn btn-outline-secondary btn-sm">
            Copy budgets from <?= htmlspecialchars((new DateTime($prevMonth . '-01'))->format('F Y')) ?>
        </button>
    </form>

    <?php foreach ($rows as $r):
        $spent = (float) $r['spent'];
        $limit = $r['limit_amount'] !== null ? (float) $r['limit_amount'] : null;
        $pct   = ($limit && $limit > 0) ? ($spent / $limit) * 100 : 0;
        $color = $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'success');
    ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <strong><?= htmlspecialchars($r['name']) ?></strong>
                    <span>
                        GH₵<?= number_format($spent, 2) ?>
                        <?php if ($limit !== null): ?>
                            / GH₵<?= number_format($limit, 2) ?>
                        <?php else: ?>
                            <span class="text-muted">(no budget set)</span>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if ($limit !== null): ?>
                    <div class="progress mt-2" style="height: 10px;">
                        <div class="progress-bar bg-<?= $color ?>"
                             style="width: <?= min($pct, 100) ?>%"></div>
                    </div>
                    <?php if ($pct >= 100): ?>
                        <small class="text-danger">Over budget by GH₵<?= number_format($spent - $limit, 2) ?></small>
                    <?php elseif ($pct >= 80): ?>
                        <small class="text-warning">Warning: <?= round($pct) ?>% of your budget used</small>
                    <?php else: ?>
                        <small class="text-muted">GH₵<?= number_format($limit - $spent, 2) ?> left</small>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

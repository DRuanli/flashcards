<?php
require_once '../config.php';
// decks/list.php - List all decks

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

// Check for flash messages
$flash_message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Get all decks for the user
$conn = connectDB();
$stmt = $conn->prepare("
    SELECT d.*, 
           COUNT(c.card_id) as card_count,
           (SELECT COUNT(*) FROM progress p 
            JOIN cards c2 ON p.card_id = c2.card_id 
            WHERE c2.deck_id = d.deck_id AND p.user_id = ? AND p.next_review <= CURDATE()) as due_count
    FROM decks d
    LEFT JOIN cards c ON d.deck_id = c.deck_id
    WHERE d.user_id = ?
    GROUP BY d.deck_id
    ORDER BY d.created_at DESC
");
$stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$decks = [];
while ($row = $result->fetch_assoc()) {
    $decks[] = $row;
}

$stmt->close();
$conn->close();

// Include header
include_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-layer-group me-2"></i>My Decks</h1>
    <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-primary">
        <i class="fas fa-plus me-2"></i>Create New Deck
    </a>
</div>

<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success mb-4">
        <i class="fas fa-check-circle me-2"></i><?php echo $flash_message; ?>
    </div>
<?php endif; ?>

<?php if (empty($decks)): ?>
    <div class="row">
        <div class="col-md-8 mx-auto text-center my-5">
            <div class="empty-state p-5">
                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20'/%3E%3C/svg%3E" 
                     alt="No decks" class="mb-4" style="width: 120px; height: 120px; opacity: 0.6;">
                <h3 class="mb-3">No Decks Found</h3>
                <p class="text-muted mb-4">You haven't created any flashcard decks yet. Start by creating your first deck!</p>
                <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus me-2"></i>Create Your First Deck
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="row">
        <?php foreach ($decks as $deck): ?>
            <div class="col-md-4 mb-4">
                <div class="card h-100 deck-card">
                    <div class="deck-pattern"></div>
                    <?php if ($deck['due_count'] > 0): ?>
                        <span class="badge bg-danger due-badge">
                            <i class="fas fa-exclamation-circle me-1"></i><?php echo $deck['due_count']; ?> due
                        </span>
                    <?php endif; ?>
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($deck['deck_name']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            <?php 
                                echo !empty($deck['description']) 
                                    ? htmlspecialchars($deck['description']) 
                                    : '<em class="text-muted">No description</em>';
                            ?>
                        </p>
                        <div class="d-flex justify-content-between mt-3">
                            <span class="badge bg-primary rounded-pill">
                                <i class="fas fa-clone me-1"></i><?php echo $deck['card_count']; ?> cards
                            </span>
                            <span class="badge bg-secondary rounded-pill">
                                <i class="fas fa-calendar-alt me-1"></i>Created <?php echo date('M d', strtotime($deck['created_at'])); ?>
                            </span>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-th-list me-1"></i>Manage Cards
                        </a>
                        <div>
                            <?php if ($deck['card_count'] > 0): ?>
                                <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-success">
                                    <i class="fas fa-graduation-cap me-1"></i>Study
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo SITE_URL; ?>/decks/edit.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-edit me-1"></i>Edit
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>
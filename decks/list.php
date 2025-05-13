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
include_once '../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>My Decks</h1>
    <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-primary">Create New Deck</a>
</div>

<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success mb-4">
        <?php echo $flash_message; ?>
    </div>
<?php endif; ?>

<?php if (empty($decks)): ?>
    <div class="alert alert-info">
        <p>You haven't created any decks yet. Click the button above to create your first deck!</p>
    </div>
<?php else: ?>
    <div class="row">
        <?php foreach ($decks as $deck): ?>
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0"><?php echo htmlspecialchars($deck['deck_name']); ?></h5>
                    </div>
                    <div class="card-body">
                        <p class="card-text">
                            <?php 
                                echo !empty($deck['description']) 
                                    ? htmlspecialchars($deck['description']) 
                                    : '<em>No description</em>';
                            ?>
                        </p>
                        <div class="d-flex justify-content-between">
                            <span class="badge bg-primary"><?php echo $deck['card_count']; ?> cards</span>
                            <?php if ($deck['due_count'] > 0): ?>
                                <span class="badge bg-danger"><?php echo $deck['due_count']; ?> due</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <div>
                            <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-outline-primary">Manage Cards</a>
                        </div>
                        <div>
                            <?php if ($deck['card_count'] > 0): ?>
                                <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-success">Study</a>
                            <?php endif; ?>
                            <a href="<?php echo SITE_URL; ?>/decks/edit.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include_once '../includes/footer.php'; ?>

<?php
require_once '../config.php';
// cards/list.php - List all cards in a deck

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

$errors = [];
$deck_id = isset($_GET['deck_id']) ? (int)$_GET['deck_id'] : 0;

// Check for flash messages
$flash_message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Verify deck exists and belongs to the user
$conn = connectDB();
$stmt = $conn->prepare("SELECT deck_name FROM decks WHERE deck_id = ? AND user_id = ?");
$stmt->bind_param("ii", $deck_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Deck not found or doesn't belong to user
    $_SESSION['flash_message'] = "Invalid deck selected.";
    redirect(SITE_URL . "/decks/list.php");
}

$deck = $result->fetch_assoc();

// Get all cards for the deck
$stmt = $conn->prepare("
    SELECT c.*, 
           p.next_review, 
           p.repetitions, 
           p.ease_factor,
           CASE 
               WHEN p.next_review <= CURDATE() THEN 'Due' 
               WHEN p.next_review IS NULL THEN 'New'
               ELSE 'Later' 
           END as status
    FROM cards c
    LEFT JOIN progress p ON c.card_id = p.card_id AND p.user_id = ?
    WHERE c.deck_id = ?
    ORDER BY c.created_at DESC
");
$stmt->bind_param("ii", $_SESSION['user_id'], $deck_id);
$stmt->execute();
$result = $stmt->get_result();

$cards = [];
while ($row = $result->fetch_assoc()) {
    $cards[] = $row;
}

$stmt->close();
$conn->close();

// Include header
include_once '../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>/decks/list.php">My Decks</a></li>
        <li class="breadcrumb-item active"><?php echo htmlspecialchars($deck['deck_name']); ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Cards in "<?php echo htmlspecialchars($deck['deck_name']); ?>"</h1>
    <div>
        <?php if (!empty($cards)): ?>
            <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-success me-2">Study Deck</a>
        <?php endif; ?>
        <a href="<?php echo SITE_URL; ?>/cards/create.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-primary">Add Card</a>
    </div>
</div>

<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success mb-4">
        <?php echo $flash_message; ?>
    </div>
<?php endif; ?>

<?php if (empty($cards)): ?>
    <div class="alert alert-info">
        <p>This deck doesn't have any cards yet. Click the button above to add your first card!</p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover">
            <thead>
                <tr>
                    <th>Question</th>
                    <th>Answer</th>
                    <th>Status</th>
                    <th>Next Review</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cards as $card): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($card['question']); ?></td>
                        <td><?php echo htmlspecialchars($card['answer']); ?></td>
                        <td>
                            <?php 
                                $status_class = '';
                                switch($card['status']) {
                                    case 'Due':
                                        $status_class = 'badge bg-danger';
                                        break;
                                    case 'New':
                                        $status_class = 'badge bg-primary';
                                        break;
                                    case 'Later':
                                        $status_class = 'badge bg-secondary';
                                        break;
                                }
                                echo "<span class=\"{$status_class}\">{$card['status']}</span>";
                            ?>
                        </td>
                        <td>
                            <?php 
                                echo $card['next_review'] 
                                    ? date('Y-m-d', strtotime($card['next_review'])) 
                                    : 'Not scheduled';
                            ?>
                        </td>
                        <td>
                            <a href="<?php echo SITE_URL; ?>/cards/edit.php?card_id=<?php echo $card['card_id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                            <a href="<?php echo SITE_URL; ?>/cards/delete.php?card_id=<?php echo $card['card_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this card?')">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php include_once '../includes/footer.php'; ?>

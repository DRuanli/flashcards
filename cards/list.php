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
include_once dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>/decks/list.php"><i class="fas fa-layer-group me-1"></i>My Decks</a></li>
        <li class="breadcrumb-item active"><?php echo htmlspecialchars($deck['deck_name']); ?></li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-book me-2"></i><?php echo htmlspecialchars($deck['deck_name']); ?></h1>
    <div>
        <?php if (!empty($cards)): ?>
            <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-success me-2">
                <i class="fas fa-graduation-cap me-2"></i>Study Deck
            </a>
        <?php endif; ?>
        <a href="<?php echo SITE_URL; ?>/cards/create.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-primary">
            <i class="fas fa-plus me-2"></i>Add Card
        </a>
    </div>
</div>

<?php if (!empty($flash_message)): ?>
    <div class="alert alert-success mb-4">
        <i class="fas fa-check-circle me-2"></i><?php echo $flash_message; ?>
    </div>
<?php endif; ?>

<?php if (empty($cards)): ?>
    <div class="row">
        <div class="col-md-8 mx-auto text-center my-5">
            <div class="empty-state p-5">
                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='3' width='18' height='18' rx='2' ry='2'/%3E%3Cline x1='3' y1='9' x2='21' y2='9'/%3E%3Cline x1='9' y1='21' x2='9' y2='9'/%3E%3C/svg%3E" 
                     alt="No cards" class="mb-4" style="width: 120px; height: 120px; opacity: 0.6;">
                <h3 class="mb-3">No Cards Found</h3>
                <p class="text-muted mb-4">This deck doesn't have any cards yet. Start by adding your first flashcard!</p>
                <a href="<?php echo SITE_URL; ?>/cards/create.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus me-2"></i>Add Your First Card
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width: 35%">Question</th>
                            <th style="width: 35%">Answer</th>
                            <th style="width: 10%">Status</th>
                            <th style="width: 10%">Next Review</th>
                            <th style="width: 10%">Actions</th>
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
                                        echo "<span class=\"{$status_class}\">";
                                        
                                        switch($card['status']) {
                                            case 'Due':
                                                echo "<i class='fas fa-exclamation-circle me-1'></i>";
                                                break;
                                            case 'New':
                                                echo "<i class='fas fa-star me-1'></i>";
                                                break;
                                            case 'Later':
                                                echo "<i class='fas fa-clock me-1'></i>";
                                                break;
                                        }
                                        
                                        echo "{$card['status']}</span>";
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                        if ($card['next_review']) {
                                            echo "<span title='" . date('Y-m-d', strtotime($card['next_review'])) . "'>";
                                            
                                            // Calculate days until review
                                            $today = new DateTime();
                                            $reviewDate = new DateTime($card['next_review']);
                                            $diff = $today->diff($reviewDate);
                                            
                                            if ($diff->invert) {
                                                echo "<i class='fas fa-exclamation-circle text-danger me-1'></i> Due now";
                                            } else if ($diff->days == 0) {
                                                echo "<i class='fas fa-calendar-day text-primary me-1'></i> Today";
                                            } else if ($diff->days == 1) {
                                                echo "<i class='fas fa-calendar-day text-primary me-1'></i> Tomorrow";
                                            } else {
                                                echo "<i class='fas fa-calendar-day text-secondary me-1'></i> In " . $diff->days . " days";
                                            }
                                            
                                            echo "</span>";
                                        } else {
                                            echo "<span class='text-muted'><i class='fas fa-calendar-alt me-1'></i> Not scheduled</span>";
                                        }
                                    ?>
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="<?php echo SITE_URL; ?>/cards/edit.php?card_id=<?php echo $card['card_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="<?php echo SITE_URL; ?>/cards/delete.php?card_id=<?php echo $card['card_id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this card?')">
                                            <i class="fas fa-trash-alt"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>
<?php
require_once 'config.php';

// index.php - Dashboard (main page)

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

// Get user statistics
$conn = connectDB();

// Get total decks
$stmt = $conn->prepare("SELECT COUNT(*) as total_decks FROM decks WHERE user_id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$total_decks = $result->fetch_assoc()['total_decks'];

// Get total cards
$stmt = $conn->prepare("
    SELECT COUNT(*) as total_cards 
    FROM cards c
    JOIN decks d ON c.deck_id = d.deck_id
    WHERE d.user_id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$total_cards = $result->fetch_assoc()['total_cards'];

// Get cards due for review
$today = date('Y-m-d');
$stmt = $conn->prepare("
    SELECT COUNT(*) as due_cards 
    FROM progress p
    JOIN cards c ON p.card_id = c.card_id
    JOIN decks d ON c.deck_id = d.deck_id
    WHERE p.user_id = ? AND (p.next_review <= ? OR p.next_review IS NULL)
");
$stmt->bind_param("is", $_SESSION['user_id'], $today);
$stmt->execute();
$result = $stmt->get_result();
$due_cards = $result->fetch_assoc()['due_cards'];

// Get recent study activity (last 7 days)
$stmt = $conn->prepare("
    SELECT DATE_FORMAT(date_studied, '%Y-%m-%d') as study_date, 
           SUM(cards_studied) as cards_studied,
           SUM(correct_answers) as correct_answers
    FROM statistics
    WHERE user_id = ? AND date_studied >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY date_studied
    ORDER BY date_studied
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$activity = [];
while ($row = $result->fetch_assoc()) {
    $activity[] = $row;
}

// Get recent decks
$stmt = $conn->prepare("
    SELECT d.deck_id, d.deck_name, COUNT(c.card_id) as card_count
    FROM decks d
    LEFT JOIN cards c ON d.deck_id = c.deck_id
    WHERE d.user_id = ?
    GROUP BY d.deck_id
    ORDER BY d.created_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$recent_decks = [];
while ($row = $result->fetch_assoc()) {
    $recent_decks[] = $row;
}

$conn->close();

// Include header
include_once 'includes/header.php';
?>

<h1 class="mb-4">Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h1>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <h5 class="card-title">Total Decks</h5>
                <p class="card-text display-4"><?php echo $total_decks; ?></p>
                <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-primary">View All Decks</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <h5 class="card-title">Total Flashcards</h5>
                <p class="card-text display-4"><?php echo $total_cards; ?></p>
                <a href="<?php echo SITE_URL; ?>/cards/list.php" class="btn btn-primary">View All Cards</a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-center h-100">
            <div class="card-body">
                <h5 class="card-title">Cards Due Today</h5>
                <p class="card-text display-4"><?php echo $due_cards; ?></p>
                <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-success">Start Studying</a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5>Study Activity (Last 7 Days)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($activity)): ?>
                    <p class="text-center">No study activity in the last 7 days</p>
                <?php else: ?>
                    <canvas id="activityChart" width="400" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Recent Decks</h5>
                <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-sm btn-primary">+ New Deck</a>
            </div>
            <div class="card-body">
                <?php if (empty($recent_decks)): ?>
                    <p class="text-center">No decks created yet</p>
                    <div class="text-center">
                        <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-primary">Create Your First Deck</a>
                    </div>
                <?php else: ?>
                    <ul class="list-group">
                        <?php foreach ($recent_decks as $deck): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>">
                                    <?php echo htmlspecialchars($deck['deck_name']); ?>
                                </a>
                                <span class="badge bg-primary rounded-pill"><?php echo $deck['card_count']; ?> cards</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript for charts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js"></script>
<script>
<?php if (!empty($activity)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('activityChart').getContext('2d');
    
    // Prepare data for chart
    const labels = [
        <?php 
            foreach ($activity as $day) {
                echo "'" . date('M d', strtotime($day['study_date'])) . "',";
            }
        ?>
    ];
    
    const studiedData = [
        <?php 
            foreach ($activity as $day) {
                echo $day['cards_studied'] . ",";
            }
        ?>
    ];
    
    const correctData = [
        <?php 
            foreach ($activity as $day) {
                echo $day['correct_answers'] . ",";
            }
        ?>
    ];
    
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Cards Studied',
                    data: studiedData,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Correct Answers',
                    data: correctData,
                    backgroundColor: 'rgba(75, 192, 192, 0.5)',
                    borderColor: 'rgba(75, 192, 192, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }
            },
            responsive: true,
            maintainAspectRatio: false
        }
    });
});
<?php endif; ?>
</script>

<?php include_once 'includes/footer.php'; ?>

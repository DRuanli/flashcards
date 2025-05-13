<?php
require_once '../config.php';
// study/index.php - Study flashcards

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

$deck_id = isset($_GET['deck_id']) ? (int)$_GET['deck_id'] : 0;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'due';  // 'due', 'all', or 'cram'
$session_size = isset($_GET['limit']) ? (int)$_GET['limit'] : 20; // Default 20, but customizable
$card_type = isset($_GET['type']) ? $_GET['type'] : 'all'; // 'all', 'failed', 'new', 'learning', 'mastered'

$conn = connectDB();

// If deck_id is provided, verify it belongs to the user
if ($deck_id > 0) {
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
}

// Get user streak information
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$stmt = $conn->prepare("
    SELECT date_studied FROM statistics 
    WHERE user_id = ? 
    AND date_studied >= ? 
    GROUP BY date_studied 
    ORDER BY date_studied DESC
");
$stmt->bind_param("is", $_SESSION['user_id'], $yesterday);
$stmt->execute();
$result = $stmt->get_result();

$study_dates = [];
while ($row = $result->fetch_assoc()) {
    $study_dates[] = $row['date_studied'];
}

$studied_today = in_array($today, $study_dates);
$studied_yesterday = in_array($yesterday, $study_dates);
$maintaining_streak = $studied_today || !$studied_yesterday;

// Get cards for study session
$sql = "
    SELECT c.card_id, c.question, c.answer, c.deck_id, d.deck_name,
           p.next_review, p.repetitions, p.ease_factor,
           CASE 
               WHEN p.repetitions = 0 THEN 'new'
               WHEN p.repetitions <= 2 THEN 'learning'
               ELSE 'mastered'
           END as card_status
    FROM cards c
    JOIN decks d ON c.deck_id = d.deck_id
    LEFT JOIN progress p ON c.card_id = p.card_id AND p.user_id = ?
    WHERE d.user_id = ?
";

// Add deck filter if specified
if ($deck_id > 0) {
    $sql .= " AND c.deck_id = ?";
}

// Add due filter if mode is 'due'
if ($mode === 'due') {
    $sql .= " AND (p.next_review <= ? OR p.next_review IS NULL)";
}

// Add card type filter
if ($card_type === 'failed') {
    $sql .= " AND p.repetitions = 0 AND p.next_review IS NOT NULL";
} elseif ($card_type === 'new') {
    $sql .= " AND (p.next_review IS NULL OR p.repetitions = 0)";
} elseif ($card_type === 'learning') {
    $sql .= " AND p.repetitions BETWEEN 1 AND 2";
} elseif ($card_type === 'mastered') {
    $sql .= " AND p.repetitions > 2";
}

// If cram mode, prioritize recent and difficult cards
if ($mode === 'cram') {
    $sql .= " ORDER BY p.repetitions ASC, RAND() LIMIT ?";
} else {
    $sql .= " ORDER BY RAND() LIMIT ?";
}

// Prepare the statement with the appropriate binding
if ($deck_id > 0 && $mode === 'due') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiisi", $_SESSION['user_id'], $_SESSION['user_id'], $deck_id, $today, $session_size);
} elseif ($deck_id > 0) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiii", $_SESSION['user_id'], $_SESSION['user_id'], $deck_id, $session_size);
} elseif ($mode === 'due') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iisi", $_SESSION['user_id'], $_SESSION['user_id'], $today, $session_size);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $_SESSION['user_id'], $_SESSION['user_id'], $session_size);
}

$stmt->execute();
$result = $stmt->get_result();

$cards = [];
while ($row = $result->fetch_assoc()) {
    $cards[] = $row;
}

// Get user's study stats for today
$stmt = $conn->prepare("
    SELECT SUM(cards_studied) as today_studied, SUM(correct_answers) as today_correct
    FROM statistics 
    WHERE user_id = ? AND date_studied = CURDATE()
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$today_stats = $result->fetch_assoc();
$today_studied = $today_stats['today_studied'] ?? 0;

// Get user's daily goal (default to 20)
$daily_goal = 20; // This could come from user preferences in a real implementation

$conn->close();

// Include header
include_once '../includes/header.php';
?>

<div class="mb-4">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><i class="fas fa-home me-1"></i>Dashboard</a></li>
            <?php if ($deck_id > 0): ?>
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>/decks/list.php"><i class="fas fa-layer-group me-1"></i>My Decks</a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($deck['deck_name']); ?></li>
            <?php else: ?>
                <li class="breadcrumb-item active">Study Session</li>
            <?php endif; ?>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0">
            <?php 
                if ($deck_id > 0) {
                    echo "<i class='fas fa-graduation-cap me-2'></i>Studying: " . htmlspecialchars($deck['deck_name']);
                } else {
                    echo "<i class='fas fa-graduation-cap me-2'></i>Study Session";
                }
            ?>
        </h1>
        
        <div class="session-controls">
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#studyOptionsModal">
                <i class="fas fa-sliders-h me-2"></i>Options
            </button>
        </div>
    </div>
    
    <?php if (!$studied_today && count($cards) > 0): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-fire-alt me-2"></i>
        <?php if ($studied_yesterday): ?>
            Keep your study streak going! You've studied for <?php echo count($study_dates); ?> consecutive days.
        <?php else: ?>
            Start a new study streak today! Consistent daily practice is key to effective learning.
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($today_studied > 0 && $today_studied < $daily_goal && count($cards) > 0): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <i class="fas fa-chart-line me-2"></i>
        You've studied <?php echo $today_studied; ?> cards today. Study <?php echo $daily_goal - $today_studied; ?> more to reach your daily goal!
        <div class="progress mt-2" style="height: 10px;">
            <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo min(100, ($today_studied / $daily_goal) * 100); ?>%"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <?php if (empty($cards)): ?>
        <div class="row">
            <div class="col-md-8 mx-auto text-center my-5">
                <div class="card study-complete-card">
                    <div class="card-body p-5">
                        <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%238AA367' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M22 11.08V12a10 10 0 1 1-5.93-9.14'/%3E%3Cpolyline points='22 4 12 14.01 9 11.01'/%3E%3C/svg%3E" 
                            alt="All done" class="mb-4" style="width: 120px; height: 120px; opacity: 0.7;">
                        <h3 class="mb-3">All Caught Up!</h3>
                        <p class="text-muted mb-4">
                            <?php if ($mode === 'due'): ?>
                                You've completed all due cards for today. Great job!
                            <?php else: ?>
                                There are no cards available to study right now.
                            <?php endif; ?>
                        </p>
                        <div class="d-flex justify-content-center mt-4 flex-wrap">
                            <?php if ($mode === 'due' && $deck_id > 0): ?>
                                <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck_id; ?>&mode=all" class="btn btn-primary m-1">
                                    <i class="fas fa-sync-alt me-2"></i>Study All Cards in This Deck
                                </a>
                            <?php elseif ($mode === 'due'): ?>
                                <a href="<?php echo SITE_URL; ?>/study/index.php?mode=all" class="btn btn-primary m-1">
                                    <i class="fas fa-sync-alt me-2"></i>Study All Cards
                                </a>
                            <?php endif; ?>
                            
                            <a href="<?php echo SITE_URL; ?>/study/index.php?mode=cram&limit=50" class="btn btn-warning m-1">
                                <i class="fas fa-bolt me-2"></i>Cram Mode
                            </a>
                            
                            <?php if ($deck_id > 0): ?>
                                <a href="<?php echo SITE_URL; ?>/cards/create.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-outline-primary m-1">
                                    <i class="fas fa-plus me-2"></i>Add More Cards
                                </a>
                            <?php else: ?>
                                <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-outline-primary m-1">
                                    <i class="fas fa-plus me-2"></i>Create a New Deck
                                </a>
                            <?php endif; ?>
                            
                            <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-outline-secondary m-1">
                                <i class="fas fa-arrow-left me-2"></i>Back to Decks
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div id="study-container" data-cards='<?php echo htmlspecialchars(json_encode($cards)); ?>' data-mode='<?php echo htmlspecialchars($mode); ?>'>
            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <!-- Study progress information -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <span class="study-counter">Card <span id="current-count">0</span> of <span id="total-count"><?php echo count($cards); ?></span></span>
                                </div>
                                <div>
                                    <span class="study-timer"><i class="far fa-clock me-2"></i><span id="study-time">00:00</span></span>
                                </div>
                            </div>
                            <div class="progress">
                                <div id="progress-bar" class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            
                            <!-- Card type indicator -->
                            <div class="text-center mt-2">
                                <span id="card-status-badge" class="badge bg-secondary d-none">New</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Flashcard -->
                    <div class="card-container mb-4">
                        <div class="flashcard" id="current-card">
                            <div class="flashcard-front" id="card-front">
                                <p class="text-center fs-4 mb-0">Click "Start Studying" to begin your session</p>
                            </div>
                            <div class="flashcard-back" id="card-back">
                                <p class="text-center fs-4 mb-0">Answer will appear here</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <button id="hint-button" class="btn btn-sm btn-outline-info d-none">
                                <i class="fas fa-lightbulb me-2"></i>Show Hint
                            </button>
                        </div>
                        <p class="text-center text-muted mb-0">
                            <small>
                                <i class="fas fa-hand-point-up me-1"></i> Click the card to flip it
                                <span class="ms-2 d-none d-md-inline">or press <kbd>Space</kbd></span>
                            </small>
                        </p>
                        <div>
                            <button id="mark-review-button" class="btn btn-sm btn-outline-warning d-none">
                                <i class="fas fa-flag me-2"></i>Mark for Review
                            </button>
                        </div>
                    </div>
                    
                    <!-- Self-assessment prediction (before flipping card) -->
                    <div id="self-assessment" class="mb-4 text-center d-none">
                        <p class="mb-3">How confident are you about knowing this answer?</p>
                        <div class="d-flex justify-content-center">
                            <button class="btn btn-outline-danger mx-1" data-confidence="low">
                                <i class="far fa-frown me-1"></i> Not confident
                            </button>
                            <button class="btn btn-outline-warning mx-1" data-confidence="medium">
                                <i class="far fa-meh me-1"></i> Somewhat confident
                            </button>
                            <button class="btn btn-outline-success mx-1" data-confidence="high">
                                <i class="far fa-smile me-1"></i> Very confident
                            </button>
                        </div>
                    </div>
                    
                    <!-- Study controls (after flipping) -->
                    <div id="card-controls" class="d-none">
                        <p class="text-center mb-4 study-question">How well did you know this?</p>
                        <div class="row g-2">
                            <div class="col-6 col-md-3">
                                <button class="btn btn-rating w-100 py-3" data-rating="1">
                                    <i class="far fa-frown mb-2 d-block" style="font-size: 1.5rem;"></i>
                                    <span>Failed</span>
                                    <span class="shortcut-hint d-none d-md-inline">(1)</span>
                                </button>
                            </div>
                            <div class="col-6 col-md-3">
                                <button class="btn btn-rating w-100 py-3" data-rating="2">
                                    <i class="far fa-meh mb-2 d-block" style="font-size: 1.5rem;"></i>
                                    <span>Hard</span>
                                    <span class="shortcut-hint d-none d-md-inline">(2)</span>
                                </button>
                            </div>
                            <div class="col-6 col-md-3">
                                <button class="btn btn-rating w-100 py-3" data-rating="3">
                                    <i class="far fa-smile mb-2 d-block" style="font-size: 1.5rem;"></i>
                                    <span>Good</span>
                                    <span class="shortcut-hint d-none d-md-inline">(3)</span>
                                </button>
                            </div>
                            <div class="col-6 col-md-3">
                                <button class="btn btn-rating w-100 py-3" data-rating="4">
                                    <i class="far fa-grin-stars mb-2 d-block" style="font-size: 1.5rem;"></i>
                                    <span>Easy</span>
                                    <span class="shortcut-hint d-none d-md-inline">(4)</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Answer typing mode (optional) -->
                    <div id="typing-mode" class="d-none mb-4">
                        <p class="text-center mb-3">Type your answer:</p>
                        <div class="input-group mb-3">
                            <input type="text" id="typed-answer" class="form-control" placeholder="Enter your answer...">
                            <button id="check-answer-btn" class="btn btn-primary">
                                <i class="fas fa-check me-1"></i>Check
                            </button>
                        </div>
                        <div id="typing-result" class="alert d-none"></div>
                    </div>
                    
                    <!-- Start button -->
                    <div id="start-controls" class="text-center mb-5">
                        <button id="start-button" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-play me-2"></i>Start Studying
                        </button>
                    </div>
                    
                    <!-- Completion message (hidden initially) -->
                    <div id="completion-message" class="d-none">
                        <div class="card">
                            <div class="card-body text-center p-5">
                                <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%238AA367' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M22 11.08V12a10 10 0 1 1-5.93-9.14'/%3E%3Cpolyline points='22 4 12 14.01 9 11.01'/%3E%3C/svg%3E"
                                    alt="Session complete" class="mb-4" style="width: 100px; height: 100px; opacity: 0.7;">
                                <h3 class="mb-3">Study Session Complete!</h3>
                                <p class="mb-4" id="completion-message-text">Great job! You've completed this study session.</p>
                                
                                <!-- Achievement notification (if any) -->
                                <div id="achievement-alert" class="alert alert-success mb-4 d-none">
                                    <i class="fas fa-award me-2"></i><span id="achievement-text"></span>
                                </div>
                                
                                <div class="d-flex justify-content-center flex-wrap">
                                    <?php if ($deck_id > 0): ?>
                                        <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-outline-primary m-1">
                                            <i class="fas fa-layer-group me-2"></i>Back to Decks
                                        </a>
                                        <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-success m-1">
                                            <i class="fas fa-sync-alt me-2"></i>Study More
                                        </a>
                                    <?php else: ?>
                                        <a href="<?php echo SITE_URL; ?>" class="btn btn-outline-primary m-1">
                                            <i class="fas fa-home me-2"></i>Dashboard
                                        </a>
                                        <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-success m-1">
                                            <i class="fas fa-sync-alt me-2"></i>Study More
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Cards marked for review -->
                    <div id="review-cards-section" class="mt-4 d-none">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-flag me-2"></i>Cards Marked for Review</h5>
                            </div>
                            <div class="card-body p-0">
                                <ul id="review-cards-list" class="list-group list-group-flush">
                                    <!-- Review cards will be added here -->
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Study statistics -->
                    <div id="study-stats" class="mt-4 d-none">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Session Statistics</h5>
                                <button class="btn btn-sm btn-outline-primary" id="expand-stats-btn">
                                    <i class="fas fa-chart-line me-1"></i>Detailed Stats
                                </button>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-6 col-md-3 mb-3">
                                        <div class="stat-box p-3">
                                            <i class="fas fa-clone mb-2" style="font-size: 1.5rem; color: var(--indigo);"></i>
                                            <p class="mb-1 text-muted">Cards Studied</p>
                                            <h4 id="stat-total" class="mb-0">0</h4>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-3">
                                        <div class="stat-box p-3">
                                            <i class="fas fa-check-circle mb-2" style="font-size: 1.5rem; color: var(--matcha);"></i>
                                            <p class="mb-1 text-muted">Correct</p>
                                            <h4 id="stat-correct" class="mb-0">0</h4>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-3">
                                        <div class="stat-box p-3">
                                            <i class="fas fa-percentage mb-2" style="font-size: 1.5rem; color: var(--asagi);"></i>
                                            <p class="mb-1 text-muted">Accuracy</p>
                                            <h4 id="stat-accuracy" class="mb-0">0%</h4>
                                        </div>
                                    </div>
                                    <div class="col-6 col-md-3 mb-3">
                                        <div class="stat-box p-3">
                                            <i class="fas fa-clock mb-2" style="font-size: 1.5rem; color: var(--sakura);"></i>
                                            <p class="mb-1 text-muted">Avg. Time</p>
                                            <h4 id="stat-time" class="mb-0">0s</h4>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Expanded stats (hidden initially) -->
                                <div id="expanded-stats" class="mt-4 d-none">
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <h6>Performance by Card Type</h6>
                                            <canvas id="card-types-chart" height="200"></canvas>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <h6>Response Time Distribution</h6>
                                            <canvas id="time-distribution-chart" height="200"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Study Options Modal -->
<div class="modal fade" id="studyOptionsModal" tabindex="-1" aria-labelledby="studyOptionsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="studyOptionsModalLabel"><i class="fas fa-sliders-h me-2"></i>Study Options</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="study-options-form" action="<?php echo $_SERVER['PHP_SELF']; ?>" method="GET">
                    <?php if ($deck_id > 0): ?>
                        <input type="hidden" name="deck_id" value="<?php echo $deck_id; ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Study Mode</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="mode" id="mode-due" value="due" <?php echo $mode === 'due' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="mode-due">
                                <i class="fas fa-calendar-day me-1"></i>Due Cards
                            </label>
                            
                            <input type="radio" class="btn-check" name="mode" id="mode-all" value="all" <?php echo $mode === 'all' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="mode-all">
                                <i class="fas fa-th-list me-1"></i>All Cards
                            </label>
                            
                            <input type="radio" class="btn-check" name="mode" id="mode-cram" value="cram" <?php echo $mode === 'cram' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="mode-cram">
                                <i class="fas fa-bolt me-1"></i>Cram Mode
                            </label>
                        </div>
                        <small class="form-text text-muted">
                            Cram mode ignores spaced repetition for intensive study sessions
                        </small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="limit" class="form-label">Cards per Session</label>
                        <select class="form-select" id="limit" name="limit">
                            <option value="10" <?php echo $session_size === 10 ? 'selected' : ''; ?>>10 cards</option>
                            <option value="20" <?php echo $session_size === 20 ? 'selected' : ''; ?>>20 cards</option>
                            <option value="50" <?php echo $session_size === 50 ? 'selected' : ''; ?>>50 cards</option>
                            <option value="100" <?php echo $session_size === 100 ? 'selected' : ''; ?>>100 cards</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="type" class="form-label">Card Types</label>
                        <select class="form-select" id="type" name="type">
                            <option value="all" <?php echo $card_type === 'all' ? 'selected' : ''; ?>>All cards</option>
                            <option value="new" <?php echo $card_type === 'new' ? 'selected' : ''; ?>>New cards</option>
                            <option value="learning" <?php echo $card_type === 'learning' ? 'selected' : ''; ?>>Learning cards</option>
                            <option value="mastered" <?php echo $card_type === 'mastered' ? 'selected' : ''; ?>>Mastered cards</option>
                            <option value="failed" <?php echo $card_type === 'failed' ? 'selected' : ''; ?>>Failed cards</option>
                        </select>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label d-block">Study Features</label>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="enable-hints" checked>
                            <label class="form-check-label" for="enable-hints">Enable Hints</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="enable-self-assessment">
                            <label class="form-check-label" for="enable-self-assessment">Self-Assessment Mode</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="enable-typing-mode">
                            <label class="form-check-label" for="enable-typing-mode">Typing Mode</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enable-focus-mode">
                            <label class="form-check-label" for="enable-focus-mode">Focus Mode</label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="study-options-form" class="btn btn-primary">Apply & Restart</button>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced CSS for Study Interface -->
<style>
    /* Flashcard enhancements */
    .flashcard {
        transition: transform 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275), 
                    box-shadow 0.3s ease;
        cursor: pointer;
    }
    
    .flashcard:hover {
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
    }
    
    /* Improved rating buttons */
    .btn-rating {
        transition: all 0.3s ease;
        border-radius: 12px;
        border: 1px solid #e0e0e0;
        background-color: white;
        position: relative;
        overflow: hidden;
    }
    
    .btn-rating:hover {
        transform: translateY(-3px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }
    
    .btn-rating[data-rating="1"]:hover {
        background-color: rgba(232, 48, 21, 0.1);
        border-color: #E83015;
    }
    
    .btn-rating[data-rating="2"]:hover {
        background-color: rgba(211, 166, 37, 0.1);
        border-color: #D3A625;
    }
    
    .btn-rating[data-rating="3"]:hover {
        background-color: rgba(125, 185, 222, 0.1);
        border-color: #7DB9DE;
    }
    
    .btn-rating[data-rating="4"]:hover {
        background-color: rgba(138, 163, 103, 0.1);
        border-color: #8AA367;
    }
    
    .shortcut-hint {
        position: absolute;
        bottom: 2px;
        right: 5px;
        font-size: 0.7rem;
        opacity: 0.7;
    }
    
    /* Card status badges */
    #card-status-badge.new {
        background-color: var(--indigo);
    }
    
    #card-status-badge.learning {
        background-color: var(--asagi);
    }
    
    #card-status-badge.mastered {
        background-color: var(--matcha);
    }
    
    /* Statistics styling */
    .stat-box {
        background-color: rgba(245, 245, 245, 0.5);
        border-radius: 8px;
        transition: all 0.3s ease;
    }
    
    .stat-box:hover {
        background-color: rgba(245, 245, 245, 0.8);
        transform: translateY(-3px);
    }
    
    /* Focus mode styling */
    body.focus-mode .navbar,
    body.focus-mode .footer,
    body.focus-mode .breadcrumb,
    body.focus-mode h1:not(.focus-visible) {
        display: none !important;
    }
    
    body.focus-mode .flashcard {
        height: 400px;
    }
    
    body.focus-mode {
        background-color: var(--kinari);
    }
    
    /* Keyboard shortcut style */
    kbd {
        background-color: #f8f9fa;
        border: 1px solid #d3d3d3;
        border-radius: 3px;
        box-shadow: 0 1px 1px rgba(0, 0, 0, .2);
        color: #333;
        display: inline-block;
        font-size: 0.85em;
        font-weight: 600;
        line-height: 1;
        padding: 2px 5px;
    }
    
    /* Achievement animation */
    @keyframes achievement-glow {
        0% { box-shadow: 0 0 5px rgba(255, 215, 0, 0.5); }
        50% { box-shadow: 0 0 20px rgba(255, 215, 0, 0.8); }
        100% { box-shadow: 0 0 5px rgba(255, 215, 0, 0.5); }
    }
    
    #achievement-alert {
        animation: achievement-glow 2s infinite;
        border: 1px solid gold;
    }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Only run if we have cards to study
    const studyContainer = document.getElementById('study-container');
    if (studyContainer) {
        // Get cards data from data attribute
        const cardsData = JSON.parse(studyContainer.dataset.cards);
        const studyMode = studyContainer.dataset.mode;
        let currentCardIndex = 0;
        let totalCards = cardsData.length;
        let cardsStudied = 0;
        let correctAnswers = 0;
        let startTime = 0;
        let totalStudyTime = 0;
        let cardTimes = [];
        let sessionStartTime = 0;
        let timerInterval = null;
        let markedForReview = [];
        
        // Track performance by card status
        let cardTypeStats = {
            'new': { total: 0, correct: 0 },
            'learning': { total: 0, correct: 0 },
            'mastered': { total: 0, correct: 0 }
        };
        
        // Study option flags
        let enableHints = true;
        let enableSelfAssessment = false;
        let enableTypingMode = false;
        let enableFocusMode = false;
        
        // Get DOM elements safely with existence checks
        const cardFront = document.getElementById('card-front');
        const cardBack = document.getElementById('card-back');
        const currentCard = document.getElementById('current-card');
        const progressBar = document.getElementById('progress-bar');
        const cardControls = document.getElementById('card-controls');
        const startControls = document.getElementById('start-controls');
        const startButton = document.getElementById('start-button');
        const completionMessage = document.getElementById('completion-message');
        const studyStats = document.getElementById('study-stats');
        const currentCount = document.getElementById('current-count');
        const totalCount = document.getElementById('total-count');
        const studyTime = document.getElementById('study-time');
        const cardStatusBadge = document.getElementById('card-status-badge');
        const selfAssessment = document.getElementById('self-assessment');
        const typingMode = document.getElementById('typing-mode');
        const typedAnswer = document.getElementById('typed-answer');
        const checkAnswerBtn = document.getElementById('check-answer-btn');
        const typingResult = document.getElementById('typing-result');
        const hintButton = document.getElementById('hint-button');
        const markReviewButton = document.getElementById('mark-review-button');
        const reviewCardsSection = document.getElementById('review-cards-section');
        const reviewCardsList = document.getElementById('review-cards-list');
        const expandStatsBtn = document.getElementById('expand-stats-btn');
        const expandedStats = document.getElementById('expanded-stats');
        
        // Stats elements
        const statTotal = document.getElementById('stat-total');
        const statCorrect = document.getElementById('stat-correct');
        const statAccuracy = document.getElementById('stat-accuracy');
        const statTime = document.getElementById('stat-time');
        
        // Safe event binding function
        function bindEvent(element, eventType, handler) {
            if (element) {
                element.addEventListener(eventType, handler);
            }
        }
        
        // Study option toggles
        const hintsToggle = document.getElementById('enable-hints');
        if (hintsToggle) {
            bindEvent(hintsToggle, 'change', function() {
                enableHints = this.checked;
                updateHintButton();
            });
        }
        
        const selfAssessmentToggle = document.getElementById('enable-self-assessment');
        if (selfAssessmentToggle) {
            bindEvent(selfAssessmentToggle, 'change', function() {
                enableSelfAssessment = this.checked;
                // Toggle visibility of self assessment controls
                if (selfAssessment) {
                    if (enableSelfAssessment) {
                        selfAssessment.classList.remove('d-none');
                    } else {
                        selfAssessment.classList.add('d-none');
                    }
                }
            });
        }
        
        const typingModeToggle = document.getElementById('enable-typing-mode');
        if (typingModeToggle) {
            bindEvent(typingModeToggle, 'change', function() {
                enableTypingMode = this.checked;
                // Toggle visibility of typing mode controls
                if (typingMode) {
                    if (enableTypingMode) {
                        typingMode.classList.remove('d-none');
                    } else {
                        typingMode.classList.add('d-none');
                    }
                }
            });
        }
        
        const focusModeToggle = document.getElementById('enable-focus-mode');
        if (focusModeToggle) {
            bindEvent(focusModeToggle, 'change', function() {
                enableFocusMode = this.checked;
                // Toggle focus mode
                if (enableFocusMode) {
                    document.body.classList.add('focus-mode');
                } else {
                    document.body.classList.remove('focus-mode');
                }
            });
        }
        
        // Start the study session
        if (startButton) {
            bindEvent(startButton, 'click', function() {
                if (startControls) {
                    startControls.classList.add('d-none');
                }
                
                // Initialize study options based on toggles
                const hintsEl = document.getElementById('enable-hints');
                const selfAssessmentEl = document.getElementById('enable-self-assessment');
                const typingModeEl = document.getElementById('enable-typing-mode');
                const focusModeEl = document.getElementById('enable-focus-mode');
                
                enableHints = hintsEl ? hintsEl.checked : true;
                enableSelfAssessment = selfAssessmentEl ? selfAssessmentEl.checked : false;
                enableTypingMode = typingModeEl ? typingModeEl.checked : false;
                enableFocusMode = focusModeEl ? focusModeEl.checked : false;
                
                if (enableFocusMode) {
                    document.body.classList.add('focus-mode');
                }
                
                if (enableHints && hintButton) {
                    hintButton.classList.remove('d-none');
                }
                
                if (markReviewButton) {
                    markReviewButton.classList.remove('d-none');
                }
                
                showNextCard();
                updateStats();
                
                // Start session timer
                sessionStartTime = Date.now();
                timerInterval = setInterval(updateTimer, 1000);
            });
        }
        
        // Flip card when clicked
        if (currentCard) {
            bindEvent(currentCard, 'click', function() {
                if (!currentCard.classList.contains('flipped') && !currentCard.classList.contains('d-none')) {
                    flipCard();
                }
            });
        }
        
        // Handle hint button
        if (hintButton) {
            bindEvent(hintButton, 'click', function() {
                if (!cardsData || currentCardIndex >= cardsData.length) {
                    return;
                }
                
                // Generate hint by showing the first letter and then dashes for remaining letters
                const answer = cardsData[currentCardIndex].answer;
                const words = answer.split(' ');
                let hint = '';
                
                words.forEach(word => {
                    if (word.length > 0) {
                        hint += word[0];
                        for (let i = 1; i < word.length; i++) {
                            hint += '–';
                        }
                        hint += ' ';
                    }
                });
                
                // Show hint in a small tooltip near the button
                this.setAttribute('data-bs-toggle', 'tooltip');
                this.setAttribute('data-bs-placement', 'top');
                this.setAttribute('title', hint);
                
                // Initialize tooltip
                try {
                    new bootstrap.Tooltip(this).show();
                    
                    // Hide tooltip after a few seconds
                    setTimeout(() => {
                        const tooltipInstance = bootstrap.Tooltip.getInstance(this);
                        if (tooltipInstance) {
                            tooltipInstance.hide();
                        }
                    }, 3000);
                } catch (error) {
                    console.error("Error showing tooltip:", error);
                }
            });
        }
        
        // Handle mark for review button
        if (markReviewButton) {
            bindEvent(markReviewButton, 'click', function() {
                // Add current card to review list if not already there
                if (!markedForReview.includes(currentCardIndex)) {
                    markedForReview.push(currentCardIndex);
                    
                    // Show feedback
                    this.classList.replace('btn-outline-warning', 'btn-warning');
                    this.innerHTML = '<i class="fas fa-check me-1"></i>Marked';
                    
                    // Disable button to prevent multiple clicks
                    this.disabled = true;
                }
            });
        }
        
        // Handle typing mode check answer button
        if (checkAnswerBtn && typedAnswer) {
            bindEvent(checkAnswerBtn, 'click', function() {
                if (!cardsData || currentCardIndex >= cardsData.length) {
                    return;
                }
                
                const userAnswer = typedAnswer.value.trim().toLowerCase();
                const correctAnswer = cardsData[currentCardIndex].answer.toLowerCase();
                
                // Simple check - could be enhanced with fuzzy matching
                const isCorrect = userAnswer === correctAnswer;
                
                // Show result
                if (typingResult) {
                    typingResult.classList.remove('d-none', 'alert-success', 'alert-danger');
                    if (isCorrect) {
                        typingResult.classList.add('alert-success');
                        typingResult.innerHTML = '<i class="fas fa-check-circle me-2"></i>Correct!';
                    } else {
                        typingResult.classList.add('alert-danger');
                        typingResult.innerHTML = `<i class="fas fa-times-circle me-2"></i>Incorrect. The correct answer is: <strong>${cardsData[currentCardIndex].answer}</strong>`;
                    }
                }
                
                // Flip card to show answer
                setTimeout(() => {
                    flipCard();
                }, 1000);
            });
        }
        
        // Handle expand stats button
        if (expandStatsBtn && expandedStats) {
            bindEvent(expandStatsBtn, 'click', function() {
                if (expandedStats.classList.contains('d-none')) {
                    expandedStats.classList.remove('d-none');
                    this.innerHTML = '<i class="fas fa-compress-alt me-1"></i>Hide Details';
                    generateDetailedStats();
                } else {
                    expandedStats.classList.add('d-none');
                    this.innerHTML = '<i class="fas fa-chart-line me-1"></i>Detailed Stats';
                }
            });
        }
        
        // Handle self-assessment buttons
        if (selfAssessment) {
            const confidenceButtons = selfAssessment.querySelectorAll('button');
            confidenceButtons.forEach(button => {
                bindEvent(button, 'click', function() {
                    const confidence = this.dataset.confidence;
                    // Hide self assessment
                    selfAssessment.classList.add('d-none');
                    // Flip card to show answer
                    flipCard();
                    // Record self-assessment (could be used for analysis)
                    console.log('Self-assessed confidence:', confidence);
                });
            });
        }
        
        // Handle answer rating buttons
        if (cardControls) {
            const ratingButtons = cardControls.querySelectorAll('button');
            ratingButtons.forEach(button => {
                bindEvent(button, 'click', function() {
                    const rating = parseInt(this.dataset.rating);
                    
                    // Validate rating
                    if (isNaN(rating) || rating < 1 || rating > 4) {
                        console.error("Invalid rating value", rating);
                        return;
                    }
                    
                    // Update statistics
                    cardsStudied++;
                    
                    // Track performance by card status
                    if (cardsData && currentCardIndex < cardsData.length) {
                        const cardStatus = cardsData[currentCardIndex].card_status;
                        if (cardTypeStats[cardStatus]) {
                            cardTypeStats[cardStatus].total++;
                            
                            if (rating >= 3) {
                                correctAnswers++;
                                cardTypeStats[cardStatus].correct++;
                            }
                        }
                        
                        // Record time taken
                        const endTime = Date.now();
                        const timeSpent = (endTime - startTime) / 1000;
                        cardTimes.push(timeSpent);
                        totalStudyTime += timeSpent;
                        
                        // Submit answer to server (include cram mode flag)
                        submitAnswer(cardsData[currentCardIndex].card_id, rating, studyMode === 'cram');
                        
                        // Show next card or end session
                        currentCardIndex++;
                        updateProgressBar();
                        
                        if (currentCardIndex < totalCards) {
                            showNextCard();
                        } else {
                            handleSessionCompletion();
                        }
                        
                        updateStats();
                    } else {
                        console.error("Card data not available or index out of range");
                    }
                });
            });
        }
        
        // Add keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            // Space bar to flip card
            if (event.code === 'Space' && currentCard && !currentCard.classList.contains('d-none') && !currentCard.classList.contains('flipped')) {
                flipCard();
                event.preventDefault();
            }
            
            // 1-4 keys for rating (when controls are visible)
            if (cardControls && !cardControls.classList.contains('d-none')) {
                if (event.key >= '1' && event.key <= '4') {
                    const rating = parseInt(event.key);
                    // Trigger click on the corresponding button
                    const ratingButton = cardControls.querySelector(`button[data-rating="${rating}"]`);
                    if (ratingButton) {
                        ratingButton.click();
                        event.preventDefault();
                    }
                }
            }
            
            // 'H' key for hint
            if (event.key === 'h' && hintButton && !hintButton.classList.contains('d-none')) {
                hintButton.click();
                event.preventDefault();
            }
            
            // 'M' key for marking review
            if (event.key === 'm' && markReviewButton && !markReviewButton.classList.contains('d-none')) {
                markReviewButton.click();
                event.preventDefault();
            }
        });
        
        // Function to flip the card
        function flipCard() {
            if (!currentCard) return;
            
            currentCard.classList.add('flipped');
            
            // Show appropriate controls based on study mode
            if (enableTypingMode && typingMode) {
                typingMode.classList.add('d-none');
            }
            
            if (cardControls) {
                cardControls.classList.remove('d-none');
            }
        }
        
        // Function to show the next card
        function showNextCard() {
            if (!currentCard || !cardFront || !cardBack || !cardsData || currentCardIndex >= cardsData.length) {
                console.error("Cannot show next card: missing elements or invalid card index");
                return;
            }
            
            // Reset card state
            currentCard.classList.remove('flipped');
            if (cardControls) {
                cardControls.classList.add('d-none');
            }
            
            if (enableTypingMode && typingMode) {
                typingMode.classList.remove('d-none');
                if (typedAnswer) typedAnswer.value = '';
                if (typingResult) typingResult.classList.add('d-none');
            }
            
            // Reset hint and mark review buttons
            if (hintButton) {
                try {
                    const hintTooltip = bootstrap.Tooltip.getInstance(hintButton);
                    if (hintTooltip) {
                        hintTooltip.dispose();
                    }
                } catch (error) {
                    console.error("Error disposing tooltip:", error);
                }
            }
            
            if (markReviewButton) {
                markReviewButton.classList.replace('btn-warning', 'btn-outline-warning');
                markReviewButton.innerHTML = '<i class="fas fa-flag me-2"></i>Mark for Review';
                markReviewButton.disabled = false;
            }
            
            // Get current card
            const card = cardsData[currentCardIndex];
            
            // Update card content with HTML formatting
            cardFront.innerHTML = `
                <div>
                    <p class="text-center fs-4">${formatCardText(card.question)}</p>
                    <p class="text-muted text-center mt-3"><small>Deck: ${card.deck_name}</small></p>
                </div>
            `;
            
            cardBack.innerHTML = `
                <div>
                    <p class="text-center fs-4">${formatCardText(card.answer)}</p>
                </div>
            `;
            
            // Show card status badge
            if (cardStatusBadge) {
                cardStatusBadge.textContent = card.card_status.charAt(0).toUpperCase() + card.card_status.slice(1);
                cardStatusBadge.className = `badge ${card.card_status} d-inline-block`;
                cardStatusBadge.classList.remove('d-none');
            }
            
            // Show self assessment if enabled
            if (enableSelfAssessment && selfAssessment) {
                selfAssessment.classList.remove('d-none');
            }
            
            // Update counter
            if (currentCount) {
                currentCount.textContent = currentCardIndex + 1;
            }
            
            // Start timer
            startTime = Date.now();
            
            // Update hint button visibility
            updateHintButton();
        }
        
        // Format card text with basic HTML
        function formatCardText(text) {
            if (!text) return "";
            
            // Convert line breaks to <br> tags
            text = text.replace(/\n/g, '<br>');
            
            // Bold text between *asterisks*
            text = text.replace(/\*(.*?)\*/g, '<strong>$1</strong>');
            
            // Italic text between _underscores_
            text = text.replace(/_(.*?)_/g, '<em>$1</em>');
            
            return text;
        }
        
        // Update hint button visibility
        function updateHintButton() {
            if (!hintButton) return;
            
            if (enableHints) {
                hintButton.classList.remove('d-none');
            } else {
                hintButton.classList.add('d-none');
            }
        }
        
        // Function to update progress bar
        function updateProgressBar() {
            if (!progressBar) return;
            
            const progress = Math.round((currentCardIndex / totalCards) * 100);
            progressBar.style.width = `${progress}%`;
            progressBar.setAttribute('aria-valuenow', progress);
        }
        
        // Function to update timer
        function updateTimer() {
            if (!studyTime) return;
            
            const elapsedTime = Math.floor((Date.now() - sessionStartTime) / 1000);
            const minutes = Math.floor(elapsedTime / 60);
            const seconds = elapsedTime % 60;
            studyTime.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        
        // Function to handle session completion
        function handleSessionCompletion() {
            clearInterval(timerInterval);
            
            if (currentCard) currentCard.classList.add('d-none');
            if (cardControls) cardControls.classList.add('d-none');
            
            if (enableTypingMode && typingMode) {
                typingMode.classList.add('d-none');
            }
            
            if (enableSelfAssessment && selfAssessment) {
                selfAssessment.classList.add('d-none');
            }
            
            if (hintButton) hintButton.classList.add('d-none');
            if (markReviewButton) markReviewButton.classList.add('d-none');
            
            // Remove focus mode if enabled
            if (enableFocusMode) {
                document.body.classList.remove('focus-mode');
            }
            
            const completionMessageText = document.getElementById('completion-message-text');
            const achievementAlert = document.getElementById('achievement-alert');
            const achievementText = document.getElementById('achievement-text');
            
            // Customize completion message based on performance
            const accuracy = cardsStudied > 0 ? Math.round((correctAnswers / cardsStudied) * 100) : 0;
            
            if (completionMessageText) {
                if (accuracy >= 90) {
                    completionMessageText.textContent = "Excellent work! Your recall accuracy is outstanding.";
                } else if (accuracy >= 70) {
                    completionMessageText.textContent = "Great job! You're making solid progress with these cards.";
                } else if (accuracy >= 50) {
                    completionMessageText.textContent = "Good effort! Keep practicing to improve your recall.";
                } else {
                    completionMessageText.textContent = "Don't worry about the difficult cards. Each review makes them easier to remember!";
                }
            }
            
            // Check for achievements
            if (achievementAlert && achievementText) {
                if (accuracy >= 80 && cardsStudied >= 10) {
                    achievementAlert.classList.remove('d-none');
                    achievementText.textContent = "Achievement Unlocked: Master Memorizer - 80%+ accuracy on 10+ cards!";
                } else if (cardsStudied >= 20) {
                    achievementAlert.classList.remove('d-none');
                    achievementText.textContent = "Achievement Unlocked: Study Marathon - Completed 20+ cards in one session!";
                }
            }
            
            if (completionMessage) completionMessage.classList.remove('d-none');
            if (studyStats) studyStats.classList.remove('d-none');
            
            // Show cards marked for review if any
            if (reviewCardsSection && reviewCardsList && markedForReview.length > 0) {
                reviewCardsSection.classList.remove('d-none');
                
                // Clear existing list
                reviewCardsList.innerHTML = '';
                
                // Add each marked card
                markedForReview.forEach(index => {
                    if (index < cardsData.length) {
                        const card = cardsData[index];
                        const listItem = document.createElement('li');
                        listItem.className = 'list-group-item d-flex justify-content-between align-items-center';
                        
                        const content = document.createElement('div');
                        content.innerHTML = `
                            <div class="fw-bold">${formatCardText(card.question)}</div>
                            <small>${card.deck_name}</small>
                        `;
                        
                        const viewButton = document.createElement('button');
                        viewButton.className = 'btn btn-sm btn-outline-primary ms-2';
                        viewButton.innerHTML = '<i class="fas fa-eye"></i>';
                        viewButton.setAttribute('data-bs-toggle', 'tooltip');
                        viewButton.setAttribute('data-bs-placement', 'top');
                        viewButton.setAttribute('title', card.answer);
                        
                        listItem.appendChild(content);
                        listItem.appendChild(viewButton);
                        reviewCardsList.appendChild(listItem);
                        
                        // Initialize tooltip
                        try {
                            new bootstrap.Tooltip(viewButton);
                        } catch (error) {
                            console.error("Error initializing tooltip:", error);
                        }
                    }
                });
            }
        }
        
        // Function to update statistics
        function updateStats() {
            if (statTotal) statTotal.innerText = cardsStudied;
            if (statCorrect) statCorrect.innerText = correctAnswers;
            
            if (statAccuracy) {
                const accuracy = cardsStudied > 0 ? Math.round((correctAnswers / cardsStudied) * 100) : 0;
                statAccuracy.innerText = `${accuracy}%`;
            }
            
            if (statTime) {
                const avgTime = cardsStudied > 0 ? Math.round(totalStudyTime / cardsStudied) : 0;
                statTime.innerText = `${avgTime}s`;
            }
        }
        
        // Function to generate detailed statistics charts
        function generateDetailedStats() {
            const ctxCardTypes = document.getElementById('card-types-chart');
            const ctxTime = document.getElementById('time-distribution-chart');
            
            if (!ctxCardTypes || !ctxTime) {
                console.error("Chart canvas elements not found");
                return;
            }
            
            try {
                // Create chart for card types
                new Chart(ctxCardTypes.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: ['New', 'Learning', 'Mastered'],
                        datasets: [{
                            label: 'Correct',
                            data: [
                                cardTypeStats.new.correct,
                                cardTypeStats.learning.correct,
                                cardTypeStats.mastered.correct
                            ],
                            backgroundColor: 'rgba(138, 163, 103, 0.7)',
                            borderColor: 'rgba(138, 163, 103, 1)',
                            borderWidth: 1
                        }, {
                            label: 'Incorrect',
                            data: [
                                cardTypeStats.new.total - cardTypeStats.new.correct,
                                cardTypeStats.learning.total - cardTypeStats.learning.correct,
                                cardTypeStats.mastered.total - cardTypeStats.mastered.correct
                            ],
                            backgroundColor: 'rgba(232, 48, 21, 0.7)',
                            borderColor: 'rgba(232, 48, 21, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Cards'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Card Type'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                position: 'top'
                            }
                        }
                    }
                });
                
                // Group times into ranges (0-5s, 5-10s, 10-20s, etc.)
                const timeRanges = [
                    '0-5s', '5-10s', '10-20s', '20-30s', '30s+'
                ];
                
                const timeDistribution = [0, 0, 0, 0, 0];
                
                cardTimes.forEach(time => {
                    if (time <= 5) timeDistribution[0]++;
                    else if (time <= 10) timeDistribution[1]++;
                    else if (time <= 20) timeDistribution[2]++;
                    else if (time <= 30) timeDistribution[3]++;
                    else timeDistribution[4]++;
                });
                
                // Create time distribution chart
                new Chart(ctxTime.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: timeRanges,
                        datasets: [{
                            label: 'Cards',
                            data: timeDistribution,
                            backgroundColor: 'rgba(62, 74, 137, 0.7)',
                            borderColor: 'rgba(62, 74, 137, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Cards'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Response Time'
                                }
                            }
                        }
                    }
                });
            } catch (error) {
                console.error("Error generating charts:", error);
            }
        }
        
        // Function to submit answer to server with error handling
        function submitAnswer(cardId, rating, cramMode = false) {
            if (!cardId || isNaN(cardId) || cardId <= 0) {
                console.error("Invalid card ID for submission", cardId);
                return;
            }
            
            // Create AJAX request
            const xhr = new XMLHttpRequest();
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    console.log("Response status:", xhr.status);
                    console.log("Response text:", xhr.responseText);
                    
                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            console.log("Stats update success:", response.success);
                        } catch (e) {
                            console.error("Error parsing response:", e);
                        }
                    } else {
                        console.error("Error submitting answer. Status:", xhr.status);
                    }
                }
            };
            
            // Open connection and set headers
            xhr.open('POST', '<?php echo SITE_URL; ?>/study/submit_answer.php', true);
            xhr.setHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            // Prepare data
            const data = `card_id=${cardId}&rating=${rating}&cram_mode=${cramMode ? 1 : 0}`;
            console.log("Submitting answer:", data);
            
            // Send request
            xhr.send(data);
        }
    }
});
</script>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>
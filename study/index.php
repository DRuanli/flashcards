<?php
require_once '../config.php';
// study/index.php - Study flashcards

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

$deck_id = isset($_GET['deck_id']) ? (int)$_GET['deck_id'] : 0;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'due';  // 'due' or 'all'

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

// Get cards for study session
$today = date('Y-m-d');
$sql = "
    SELECT c.card_id, c.question, c.answer, c.deck_id, d.deck_name,
           p.next_review, p.repetitions, p.ease_factor
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

$sql .= " ORDER BY RAND() LIMIT 20";  // Get random cards, limited to 20 at a time

if ($deck_id > 0 && $mode === 'due') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiis", $_SESSION['user_id'], $_SESSION['user_id'], $deck_id, $today);
} elseif ($deck_id > 0) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $_SESSION['user_id'], $_SESSION['user_id'], $deck_id);
} elseif ($mode === 'due') {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iis", $_SESSION['user_id'], $_SESSION['user_id'], $today);
} else {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $_SESSION['user_id'], $_SESSION['user_id']);
}

$stmt->execute();
$result = $stmt->get_result();

$cards = [];
while ($row = $result->fetch_assoc()) {
    $cards[] = $row;
}

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

    <h1 class="mb-4 text-center">
        <?php 
            if ($deck_id > 0) {
                echo "<i class='fas fa-graduation-cap me-2'></i>Studying: " . htmlspecialchars($deck['deck_name']);
            } else {
                echo "<i class='fas fa-graduation-cap me-2'></i>Study Session";
            }
        ?>
    </h1>
    
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
        <div id="study-container" data-cards='<?php echo htmlspecialchars(json_encode($cards)); ?>'>
            <div class="row">
                <div class="col-md-8 mx-auto">
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
                    <p class="text-center text-muted mb-4"><small><i class="fas fa-hand-point-up me-1"></i> Click the card to flip it</small></p>
                    
                    <!-- Study controls -->
                    <div id="card-controls" class="d-none">
                        <p class="text-center mb-4 study-question">How well did you know this?</p>
                        <div class="row g-2">
                            <div class="col-md-3 col-6">
                                <button class="btn w-100 py-3" data-rating="1">
                                    <i class="far fa-frown mb-2 d-block" style="font-size: 1.5rem;"></i>
                                    <span>Failed</span>
                                </button>
                            </div>
                            <div class="col-md-3 col-6">
                                <button class="btn w-100 py-3" data-rating="2">
                                    <i class="far fa-meh mb-2 d-block" style="font-size: 1.5rem;"></i>
                                    <span>Hard</span>
                                </button>
                            </div>
                            <div class="col-md-3 col-6">
                                <button class="btn w-100 py-3" data-rating="3">
                                    <i class="far fa-smile mb-2 d-block" style="font-size: 1.5rem;"></i>
                                    <span>Good</span>
                                </button>
                            </div>
                            <div class="col-md-3 col-6">
                                <button class="btn w-100 py-3" data-rating="4">
                                    <i class="far fa-grin-stars mb-2 d-block" style="font-size: 1.5rem;"></i>
                                    <span>Easy</span>
                                </button>
                            </div>
                        </div>
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
                                <p class="mb-4">Great job! You've completed this study session.</p>
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
                    
                    <!-- Study statistics -->
                    <div id="study-stats" class="mt-4 d-none">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Session Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-3 col-6 mb-3">
                                        <div class="stat-box p-3">
                                            <i class="fas fa-clone mb-2" style="font-size: 1.5rem; color: var(--indigo);"></i>
                                            <p class="mb-1 text-muted">Cards Studied</p>
                                            <h4 id="stat-total" class="mb-0">0</h4>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6 mb-3">
                                        <div class="stat-box p-3">
                                            <i class="fas fa-check-circle mb-2" style="font-size: 1.5rem; color: var(--matcha);"></i>
                                            <p class="mb-1 text-muted">Correct</p>
                                            <h4 id="stat-correct" class="mb-0">0</h4>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6 mb-3">
                                        <div class="stat-box p-3">
                                            <i class="fas fa-percentage mb-2" style="font-size: 1.5rem; color: var(--asagi);"></i>
                                            <p class="mb-1 text-muted">Accuracy</p>
                                            <h4 id="stat-accuracy" class="mb-0">0%</h4>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-6 mb-3">
                                        <div class="stat-box p-3">
                                            <i class="fas fa-clock mb-2" style="font-size: 1.5rem; color: var(--sakura);"></i>
                                            <p class="mb-1 text-muted">Avg. Time</p>
                                            <h4 id="stat-time" class="mb-0">0s</h4>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Only run if we have cards to study
    if (document.getElementById('study-container')) {
        // Get cards data from data attribute
        const cardsData = JSON.parse(document.getElementById('study-container').dataset.cards);
        let currentCardIndex = 0;
        let totalCards = cardsData.length;
        let cardsStudied = 0;
        let correctAnswers = 0;
        let startTime = 0;
        let totalStudyTime = 0;
        let sessionStartTime = 0;
        let timerInterval = null;
        
        // Get DOM elements
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
        
        // Stats elements
        const statTotal = document.getElementById('stat-total');
        const statCorrect = document.getElementById('stat-correct');
        const statAccuracy = document.getElementById('stat-accuracy');
        const statTime = document.getElementById('stat-time');
        
        // Start the study session
        startButton.addEventListener('click', function() {
            startControls.classList.add('d-none');
            showNextCard();
            updateStats();
            
            // Start session timer
            sessionStartTime = Date.now();
            timerInterval = setInterval(updateTimer, 1000);
        });
        
        // Flip card when clicked
        currentCard.addEventListener('click', function() {
            if (!currentCard.classList.contains('flipped') && cardControls.classList.contains('d-none')) {
                currentCard.classList.add('flipped');
                cardControls.classList.remove('d-none');
            }
        });
        
        // Handle answer rating buttons
        cardControls.querySelectorAll('button').forEach(button => {
            button.addEventListener('click', function() {
                const rating = parseInt(this.dataset.rating);
                
                // Update statistics
                cardsStudied++;
                if (rating >= 3) {
                    correctAnswers++;
                }
                
                // Record time taken
                const endTime = Date.now();
                totalStudyTime += (endTime - startTime) / 1000;
                
                // Submit answer to server
                submitAnswer(cardsData[currentCardIndex].card_id, rating);
                
                // Show next card or end session
                currentCardIndex++;
                updateProgressBar();
                
                if (currentCardIndex < totalCards) {
                    showNextCard();
                } else {
                    endStudySession();
                }
                
                updateStats();
            });
        });
        
        // Function to show the next card
        function showNextCard() {
            // Reset card state
            currentCard.classList.remove('flipped');
            cardControls.classList.add('d-none');
            
            // Get current card
            const card = cardsData[currentCardIndex];
            
            // Update card content
            cardFront.innerHTML = `
                <div>
                    <p class="text-center fs-4">${card.question}</p>
                    <p class="text-muted text-center mt-3"><small>Deck: ${card.deck_name}</small></p>
                </div>
            `;
            
            cardBack.innerHTML = `
                <div>
                    <p class="text-center fs-4">${card.answer}</p>
                </div>
            `;
            
            // Update counter
            currentCount.textContent = currentCardIndex + 1;
            
            // Start timer
            startTime = Date.now();
        }
        
        // Function to update progress bar
        function updateProgressBar() {
            const progress = Math.round((currentCardIndex / totalCards) * 100);
            progressBar.style.width = `${progress}%`;
            progressBar.setAttribute('aria-valuenow', progress);
        }
        
        // Function to update timer
        function updateTimer() {
            const elapsedTime = Math.floor((Date.now() - sessionStartTime) / 1000);
            const minutes = Math.floor(elapsedTime / 60);
            const seconds = elapsedTime % 60;
            studyTime.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        
        // Function to end study session
        function endStudySession() {
            clearInterval(timerInterval);
            currentCard.classList.add('d-none');
            cardControls.classList.add('d-none');
            completionMessage.classList.remove('d-none');
            studyStats.classList.remove('d-none');
        }
        
        // Function to update statistics
        function updateStats() {
            statTotal.innerText = cardsStudied;
            statCorrect.innerText = correctAnswers;
            
            const accuracy = cardsStudied > 0 ? Math.round((correctAnswers / cardsStudied) * 100) : 0;
            statAccuracy.innerText = `${accuracy}%`;
            
            const avgTime = cardsStudied > 0 ? Math.round(totalStudyTime / cardsStudied) : 0;
            statTime.innerText = `${avgTime}s`;
            
            studyStats.classList.remove('d-none');
        }
        
        // Function to submit answer to server
        function submitAnswer(cardId, rating) {
            // Create AJAX request
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo SITE_URL; ?>/study/submit_answer.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            // Prepare data
            const data = `card_id=${cardId}&rating=${rating}`;
            
            // Send request
            xhr.send(data);
            
            // We're not waiting for the response as it's not critical for the UI
        }
    }
});
</script>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>
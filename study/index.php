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
    <h1 class="mb-3">
        <?php 
            if ($deck_id > 0) {
                echo "Studying: " . htmlspecialchars($deck['deck_name']);
            } else {
                echo "Study Session";
            }
        ?>
    </h1>
    
    <?php if (empty($cards)): ?>
        <div class="alert alert-info">
            <p>There are no cards to study right now!</p>
            <?php if ($mode === 'due'): ?>
                <p>
                    You've completed all due cards. 
                    <?php if ($deck_id > 0): ?>
                        <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck_id; ?>&mode=all">Study all cards in this deck</a>
                    <?php else: ?>
                        <a href="<?php echo SITE_URL; ?>/study/index.php?mode=all">Study all cards</a>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p>
                    <?php if ($deck_id > 0): ?>
                        <a href="<?php echo SITE_URL; ?>/cards/create.php?deck_id=<?php echo $deck_id; ?>">Add some cards to this deck</a>
                    <?php else: ?>
                        <a href="<?php echo SITE_URL; ?>/decks/create.php">Create a deck</a> and add some cards
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div id="study-container" data-cards='<?php echo htmlspecialchars(json_encode($cards)); ?>'>
            <div class="row">
                <div class="col-md-8 mx-auto">
                    <!-- Study progress bar -->
                    <div class="progress mb-3">
                        <div id="progress-bar" class="progress-bar" role="progressbar" style="width: 0%;">0%</div>
                    </div>
                    
                    <!-- Flashcard -->
                    <div class="card-container mb-4">
                        <div class="flashcard" id="current-card">
                            <div class="flashcard-front" id="card-front">
                                <p class="text-center fs-4">Click to start studying</p>
                            </div>
                            <div class="flashcard-back" id="card-back">
                                <p class="text-center fs-4">Answer will appear here</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Study controls -->
                    <div id="card-controls" class="d-none">
                        <p class="text-center mb-4">How well did you know this?</p>
                        <div class="d-flex justify-content-between">
                            <button class="btn btn-danger" data-rating="1">Failed</button>
                            <button class="btn btn-warning" data-rating="2">Hard</button>
                            <button class="btn btn-info" data-rating="3">Good</button>
                            <button class="btn btn-success" data-rating="4">Easy</button>
                        </div>
                    </div>
                    
                    <!-- Start button -->
                    <div id="start-controls" class="text-center">
                        <button id="start-button" class="btn btn-primary">Start Studying</button>
                    </div>
                    
                    <!-- Completion message (hidden initially) -->
                    <div id="completion-message" class="text-center d-none">
                        <h3 class="mb-3">Study session complete!</h3>
                        <p class="mb-4">Great job! You've completed this study session.</p>
                        <div class="d-flex justify-content-center">
                            <?php if ($deck_id > 0): ?>
                                <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-primary me-2">Back to Decks</a>
                                <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-success">Study More</a>
                            <?php else: ?>
                                <a href="<?php echo SITE_URL; ?>" class="btn btn-primary me-2">Dashboard</a>
                                <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-success">Study More</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Study statistics -->
                    <div id="study-stats" class="mt-4 d-none">
                        <div class="card">
                            <div class="card-header">
                                <h5>Session Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-3">
                                        <p class="mb-1">Cards Studied</p>
                                        <h4 id="stat-total">0</h4>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1">Correct</p>
                                        <h4 id="stat-correct">0</h4>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1">Accuracy</p>
                                        <h4 id="stat-accuracy">0%</h4>
                                    </div>
                                    <div class="col-md-3">
                                        <p class="mb-1">Avg. Time</p>
                                        <h4 id="stat-time">0s</h4>
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
            
            // Start timer
            startTime = Date.now();
        }
        
        // Function to update progress bar
        function updateProgressBar() {
            const progress = Math.round((currentCardIndex / totalCards) * 100);
            progressBar.style.width = `${progress}%`;
            progressBar.innerText = `${progress}%`;
        }
        
        // Function to end study session
        function endStudySession() {
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
            xhr.open('POST', `${window.location.origin}/flashcards/study/submit_answer.php`, true);
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

<?php include_once '../includes/footer.php'; ?>

<?php
require_once '../config.php';
// study/submit_answer.php - Process study answers and update progress

// Ensure request is POST and user is logged in
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isLoggedIn()) {
    http_response_code(403);
    exit('Forbidden');
}

// Get POST data
$card_id = isset($_POST['card_id']) ? (int)$_POST['card_id'] : 0;
$rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
$cram_mode = isset($_POST['cram_mode']) && $_POST['cram_mode'] == 1;

// Validate data
if ($card_id <= 0 || $rating < 1 || $rating > 4) {
    http_response_code(400);
    exit('Invalid data');
}

// Get current date
$today = date('Y-m-d');

// Connect to database
$conn = connectDB();

// Get card details to ensure it belongs to a deck the user has access to
$stmt = $conn->prepare("
    SELECT c.deck_id
    FROM cards c
    JOIN decks d ON c.deck_id = d.deck_id
    WHERE c.card_id = ? AND d.user_id = ?
");
$stmt->bind_param("ii", $card_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Card not found or doesn't belong to user
    http_response_code(403);
    $conn->close();
    exit('Forbidden');
}

$card = $result->fetch_assoc();
$deck_id = $card['deck_id'];

// Get existing progress or create new one
$stmt = $conn->prepare("
    SELECT * 
    FROM progress 
    WHERE user_id = ? AND card_id = ?
");
$stmt->bind_param("ii", $_SESSION['user_id'], $card_id);
$stmt->execute();
$result = $stmt->get_result();

// Default values for new cards
$ease_factor = 2.5;
$interval = 0;
$repetitions = 0;

if ($result->num_rows > 0) {
    // Update existing progress
    $progress = $result->fetch_assoc();
    $ease_factor = $progress['ease_factor'];
    $interval = $progress['interval'];
    $repetitions = $progress['repetitions'];
}

// Calculate new values using the SuperMemo SM-2 algorithm
// (modified for cram mode if enabled)
if ($cram_mode) {
    // For cram mode, use shorter intervals regardless of performance
    // This ensures cards will be reviewed again soon even if rated "Easy"
    if ($rating < 3) {
        // If rating is less than 3 (failed or hard), reset repetitions
        $repetitions = 0;
        $interval = 1; // Review tomorrow
    } else {
        // Increment repetitions but keep interval short
        $repetitions++;
        $interval = max(1, min(3, $rating)); // 1-3 days based on rating
    }
    
    // Don't modify ease factor in cram mode
} else {
    // Standard SM-2 algorithm implementation
    if ($rating < 3) {
        // If rating is less than 3 (failed or hard), reset repetitions
        $repetitions = 0;
        $interval = 1;
    } else {
        // Increment repetitions
        $repetitions++;
        
        // Calculate new interval
        if ($repetitions === 1) {
            $interval = 1;
        } elseif ($repetitions === 2) {
            $interval = 6;
        } else {
            $interval = round($interval * $ease_factor);
        }
    }

    // Adjust ease factor based on rating (between 1.3 and 2.5)
    $ease_factor = max(1.3, $ease_factor + (0.1 - (5 - $rating) * (0.08 + (5 - $rating) * 0.02)));
}

// Calculate next review date
$next_review = date('Y-m-d', strtotime("+{$interval} days"));

// Update progress
$stmt = $conn->prepare("
    INSERT INTO progress (user_id, card_id, ease_factor, interval, repetitions, next_review, last_reviewed)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE 
        ease_factor = VALUES(ease_factor),
        interval = VALUES(interval),
        repetitions = VALUES(repetitions),
        next_review = VALUES(next_review),
        last_reviewed = VALUES(last_reviewed)
");
$stmt->bind_param("iidiis", $_SESSION['user_id'], $card_id, $ease_factor, $interval, $repetitions, $next_review);
$stmt->execute();

// Update statistics
$correct = ($rating >= 3) ? 1 : 0;

$stmt = $conn->prepare("
    INSERT INTO statistics (user_id, deck_id, date_studied, cards_studied, correct_answers)
    VALUES (?, ?, CURDATE(), 1, ?)
    ON DUPLICATE KEY UPDATE 
        cards_studied = cards_studied + 1,
        correct_answers = correct_answers + VALUES(correct_answers)
");
$stmt->bind_param("iii", $_SESSION['user_id'], $deck_id, $correct);
$stmt->execute();

// Update user streak
// Check if this is the first card studied today
if ($correct) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM user_streaks 
        WHERE user_id = ? AND streak_date = CURDATE()
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $streak_exists = $result->fetch_assoc()['count'] > 0;
    
    if (!$streak_exists) {
        // Get yesterday's streak
        $stmt = $conn->prepare("
            SELECT current_streak
            FROM user_streaks 
            WHERE user_id = ? AND streak_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $yesterday_streak = 0;
        if ($result->num_rows > 0) {
            $yesterday_streak = $result->fetch_assoc()['current_streak'];
        }
        
        // Calculate new streak
        $new_streak = $yesterday_streak + 1;
        
        // Insert today's streak
        $stmt = $conn->prepare("
            INSERT INTO user_streaks (user_id, streak_date, current_streak)
            VALUES (?, CURDATE(), ?)
        ");
        $stmt->bind_param("ii", $_SESSION['user_id'], $new_streak);
        $stmt->execute();
    }
}

// Close connection
$conn->close();

// Send success response
http_response_code(200);
echo json_encode(['success' => true]);
?>
<?php
require_once '../config.php';

// cards/create.php - Create a new flashcard

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

$errors = [];
$deck_id = isset($_GET['deck_id']) ? (int)$_GET['deck_id'] : 0;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize form data
    $question = trim(htmlspecialchars($_POST['question']));
    $answer = trim(htmlspecialchars($_POST['answer']));
    $create_another = isset($_POST['create_another']);
    
    // Validate form data
    if (empty($question)) {
        $errors[] = "Question is required";
    }
    
    if (empty($answer)) {
        $errors[] = "Answer is required";
    }
    
    // If no errors, proceed with creating card
    if (empty($errors)) {
        // Insert new card
        $stmt = $conn->prepare("INSERT INTO cards (deck_id, question, answer) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $deck_id, $question, $answer);
        
        if ($stmt->execute()) {
            // Card created successfully
            $card_id = $conn->insert_id;
            
            // Initialize progress record
            $stmt = $conn->prepare("INSERT INTO progress (user_id, card_id, next_review) VALUES (?, ?, CURDATE())");
            $stmt->bind_param("ii", $_SESSION['user_id'], $card_id);
            $stmt->execute();
            
            if ($create_another) {
                // Redirect to create another card
                $_SESSION['flash_message'] = "Card created successfully! Create another.";
                redirect(SITE_URL . "/cards/create.php?deck_id=" . $deck_id);
            } else {
                // Redirect to card list
                $_SESSION['flash_message'] = "Card created successfully!";
                redirect(SITE_URL . "/cards/list.php?deck_id=" . $deck_id);
            }
        } else {
            $errors[] = "Failed to create card: " . $conn->error;
        }
    }
}

$conn->close();

// Include header
include_once dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>/decks/list.php"><i class="fas fa-layer-group me-1"></i>My Decks</a></li>
        <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck_id; ?>"><?php echo htmlspecialchars($deck['deck_name']); ?></a></li>
        <li class="breadcrumb-item active">Add Card</li>
    </ol>
</nav>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="mb-0"><i class="fas fa-plus me-2"></i>Add New Card</h2>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($_SESSION['flash_message'])): ?>
                    <div class="alert alert-success mb-4">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php 
                            echo $_SESSION['flash_message']; 
                            unset($_SESSION['flash_message']);
                        ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form action="<?php echo $_SERVER['PHP_SELF'] . '?deck_id=' . $deck_id; ?>" method="POST">
                    <div class="mb-3">
                        <label for="question" class="form-label">Question / Front Side</label>
                        <textarea class="form-control" id="question" name="question" rows="3" placeholder="Enter the question or prompt for the front of the card" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="answer" class="form-label">Answer / Back Side</label>
                        <textarea class="form-control" id="answer" name="answer" rows="3" placeholder="Enter the answer for the back of the card" required></textarea>
                    </div>
                    <div class="mb-4 form-check">
                        <input type="checkbox" class="form-check-input" id="create_another" name="create_another">
                        <label class="form-check-label" for="create_another">Create another card after this one</label>
                    </div>
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create Card
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Card Preview -->
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-eye me-2"></i>Card Preview</h3>
            </div>
            <div class="card-body p-4">
                <div class="card-container">
                    <div class="flashcard">
                        <div class="flashcard-front" id="preview-front">
                            <p class="text-center fs-4">Question preview will appear here</p>
                        </div>
                        <div class="flashcard-back" id="preview-back">
                            <p class="text-center fs-4">Answer preview will appear here</p>
                        </div>
                    </div>
                </div>
                <p class="text-center text-muted mt-3"><small><i class="fas fa-hand-point-up me-1"></i>Click the card to flip it</small></p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const questionInput = document.getElementById('question');
    const answerInput = document.getElementById('answer');
    const previewFront = document.getElementById('preview-front');
    const previewBack = document.getElementById('preview-back');
    
    // Update preview when inputs change
    questionInput.addEventListener('input', function() {
        previewFront.innerHTML = this.value ? `<p class="fs-4">${this.value}</p>` : `<p class="text-center fs-4">Question preview will appear here</p>`;
    });
    
    answerInput.addEventListener('input', function() {
        previewBack.innerHTML = this.value ? `<p class="fs-4">${this.value}</p>` : `<p class="text-center fs-4">Answer preview will appear here</p>`;
    });
});
</script>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>
<?php
require_once '../config.php';

// cards/edit.php - Edit an existing flashcard

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

$errors = [];
$card_id = isset($_GET['card_id']) ? (int)$_GET['card_id'] : 0;

// Verify card exists and belongs to the user
$conn = connectDB();
$stmt = $conn->prepare("
    SELECT c.*, d.deck_name, d.deck_id 
    FROM cards c
    JOIN decks d ON c.deck_id = d.deck_id
    WHERE c.card_id = ? AND d.user_id = ?
");
$stmt->bind_param("ii", $card_id, $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Card not found or doesn't belong to user
    $_SESSION['flash_message'] = "Invalid card selected.";
    redirect(SITE_URL . "/decks/list.php");
}

$card = $result->fetch_assoc();
$deck_id = $card['deck_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize form data
    $question = trim(htmlspecialchars($_POST['question']));
    $answer = trim(htmlspecialchars($_POST['answer']));
    
    // Validate form data
    if (empty($question)) {
        $errors[] = "Question is required";
    }
    
    if (empty($answer)) {
        $errors[] = "Answer is required";
    }
    
    // If no errors, proceed with updating card
    if (empty($errors)) {
        // Update card
        $stmt = $conn->prepare("UPDATE cards SET question = ?, answer = ? WHERE card_id = ?");
        $stmt->bind_param("ssi", $question, $answer, $card_id);
        
        if ($stmt->execute()) {
            // Card updated successfully
            $_SESSION['flash_message'] = "Card updated successfully!";
            redirect(SITE_URL . "/cards/list.php?deck_id=" . $deck_id);
        } else {
            $errors[] = "Failed to update card: " . $conn->error;
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
        <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck_id; ?>"><?php echo htmlspecialchars($card['deck_name']); ?></a></li>
        <li class="breadcrumb-item active">Edit Card</li>
    </ol>
</nav>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="mb-0"><i class="fas fa-edit me-2"></i>Edit Card</h2>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form action="<?php echo $_SERVER['PHP_SELF'] . '?card_id=' . $card_id; ?>" method="POST">
                    <div class="mb-3">
                        <label for="question" class="form-label">Question / Front Side</label>
                        <textarea class="form-control" id="question" name="question" rows="3" required><?php echo htmlspecialchars($card['question']); ?></textarea>
                    </div>
                    <div class="mb-4">
                        <label for="answer" class="form-label">Answer / Back Side</label>
                        <textarea class="form-control" id="answer" name="answer" rows="3" required><?php echo htmlspecialchars($card['answer']); ?></textarea>
                    </div>
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck_id; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Card
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
                            <p class="fs-4"><?php echo htmlspecialchars($card['question']); ?></p>
                        </div>
                        <div class="flashcard-back" id="preview-back">
                            <p class="fs-4"><?php echo htmlspecialchars($card['answer']); ?></p>
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
<?php
require_once '../config.php';
// decks/edit.php - Edit an existing deck

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

$errors = [];
$deck_id = isset($_GET['deck_id']) ? (int)$_GET['deck_id'] : 0;

// Verify deck exists and belongs to the user
$conn = connectDB();
$stmt = $conn->prepare("SELECT * FROM decks WHERE deck_id = ? AND user_id = ?");
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
    $deck_name = trim(htmlspecialchars($_POST['deck_name']));
    $description = trim(htmlspecialchars($_POST['description']));
    
    // Validate form data
    if (empty($deck_name)) {
        $errors[] = "Deck name is required";
    }
    
    // If no errors, proceed with updating deck
    if (empty($errors)) {
        // Update deck
        $stmt = $conn->prepare("UPDATE decks SET deck_name = ?, description = ? WHERE deck_id = ?");
        $stmt->bind_param("ssi", $deck_name, $description, $deck_id);
        
        if ($stmt->execute()) {
            // Deck updated successfully
            $_SESSION['flash_message'] = "Deck updated successfully!";
            redirect(SITE_URL . "/decks/list.php");
        } else {
            $errors[] = "Failed to update deck: " . $conn->error;
        }
    }
}

$conn->close();

// Include header
include_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h2>Edit Deck</h2>
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo $error; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form action="<?php echo $_SERVER['PHP_SELF'] . '?deck_id=' . $deck_id; ?>" method="POST">
                    <div class="mb-3">
                        <label for="deck_name" class="form-label">Deck Name</label>
                        <input type="text" class="form-control" id="deck_name" name="deck_name" value="<?php echo htmlspecialchars($deck['deck_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($deck['description']); ?></textarea>
                    </div>
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Update Deck</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>
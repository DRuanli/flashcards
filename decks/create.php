<?php
require_once '../config.php';
// decks/create.php - Create a new deck

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize form data
    $deck_name = trim(htmlspecialchars($_POST['deck_name']));
    $description = trim(htmlspecialchars($_POST['description']));
    
    // Validate form data
    if (empty($deck_name)) {
        $errors[] = "Deck name is required";
    }
    
    // If no errors, proceed with creating deck
    if (empty($errors)) {
        $conn = connectDB();
        
        // Insert new deck
        $stmt = $conn->prepare("INSERT INTO decks (user_id, deck_name, description) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $_SESSION['user_id'], $deck_name, $description);
        
        if ($stmt->execute()) {
            // Deck created successfully, redirect to deck list
            $deck_id = $conn->insert_id;
            $_SESSION['flash_message'] = "Deck created successfully!";
            redirect(SITE_URL . "/cards/create.php?deck_id=" . $deck_id);
        } else {
            $errors[] = "Failed to create deck: " . $conn->error;
        }
        
        $stmt->close();
        $conn->close();
    }
}

// Include header
include_once dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>/decks/list.php"><i class="fas fa-layer-group me-1"></i>My Decks</a></li>
        <li class="breadcrumb-item active">Create Deck</li>
    </ol>
</nav>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h2 class="mb-0"><i class="fas fa-plus me-2"></i>Create New Deck</h2>
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
                
                <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
                    <div class="mb-3">
                        <label for="deck_name" class="form-label">Deck Name</label>
                        <input type="text" class="form-control" id="deck_name" name="deck_name" placeholder="Enter a name for your deck" required>
                    </div>
                    <div class="mb-4">
                        <label for="description" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="What is this deck about?"></textarea>
                    </div>
                    <div class="d-flex justify-content-between">
                        <a href="<?php echo SITE_URL; ?>/decks/list.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Create Deck
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>
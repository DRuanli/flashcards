<?php
require_once '../config.php';

// cards/delete.php - Delete a flashcard

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

$card_id = isset($_GET['card_id']) ? (int)$_GET['card_id'] : 0;

// Verify card exists and belongs to the user
$conn = connectDB();
$stmt = $conn->prepare("
    SELECT c.deck_id, d.user_id 
    FROM cards c
    JOIN decks d ON c.deck_id = d.deck_id
    WHERE c.card_id = ?
");
$stmt->bind_param("i", $card_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Card not found
    $_SESSION['flash_message'] = "Invalid card selected.";
    redirect(SITE_URL . "/decks/list.php");
}

$card = $result->fetch_assoc();

// Check if card belongs to user
if ($card['user_id'] != $_SESSION['user_id']) {
    // Card doesn't belong to user
    $_SESSION['flash_message'] = "You don't have permission to delete this card.";
    redirect(SITE_URL . "/decks/list.php");
}

$deck_id = $card['deck_id'];

// Delete card
$stmt = $conn->prepare("DELETE FROM cards WHERE card_id = ?");
$stmt->bind_param("i", $card_id);

if ($stmt->execute()) {
    // Also delete associated progress records
    $stmt = $conn->prepare("DELETE FROM progress WHERE card_id = ?");
    $stmt->bind_param("i", $card_id);
    $stmt->execute();
    
    // Card deleted successfully
    $_SESSION['flash_message'] = "Card deleted successfully!";
} else {
    // Error deleting card
    $_SESSION['flash_message'] = "Error deleting card: " . $conn->error;
}

$conn->close();

// Redirect back to card list
redirect(SITE_URL . "/cards/list.php?deck_id=" . $deck_id);
?>
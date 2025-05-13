<?php
require_once '../config.php';
// decks/list.php - List all decks with enhanced features

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

// Check for flash messages
$flash_message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// Process tag operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $conn = connectDB();
    
    if ($_POST['action'] === 'add_tag' && isset($_POST['deck_id']) && isset($_POST['tag_name'])) {
        $deck_id = (int)$_POST['deck_id'];
        $tag_name = trim(htmlspecialchars($_POST['tag_name']));
        
        if (!empty($tag_name)) {
            // First check if tag exists
            $stmt = $conn->prepare("SELECT tag_id FROM tags WHERE tag_name = ?");
            $stmt->bind_param("s", $tag_name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Tag exists, get its ID
                $tag_id = $result->fetch_assoc()['tag_id'];
            } else {
                // Create new tag
                $stmt = $conn->prepare("INSERT INTO tags (tag_name) VALUES (?)");
                $stmt->bind_param("s", $tag_name);
                $stmt->execute();
                $tag_id = $conn->insert_id;
            }
            
            // Associate tag with deck
            $stmt = $conn->prepare("INSERT IGNORE INTO deck_tags (deck_id, tag_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $deck_id, $tag_id);
            $stmt->execute();
        }
    } elseif ($_POST['action'] === 'remove_tag' && isset($_POST['deck_id']) && isset($_POST['tag_id'])) {
        $deck_id = (int)$_POST['deck_id'];
        $tag_id = (int)$_POST['tag_id'];
        
        // Remove tag from deck
        $stmt = $conn->prepare("DELETE FROM deck_tags WHERE deck_id = ? AND tag_id = ?");
        $stmt->bind_param("ii", $deck_id, $tag_id);
        $stmt->execute();
    } elseif ($_POST['action'] === 'batch_operation' && isset($_POST['deck_ids']) && isset($_POST['operation'])) {
        $deck_ids = $_POST['deck_ids'];
        $operation = $_POST['operation'];
        
        if ($operation === 'delete') {
            // Delete selected decks
            foreach ($deck_ids as $deck_id) {
                $deck_id = (int)$deck_id;
                
                // Verify ownership
                $stmt = $conn->prepare("SELECT user_id FROM decks WHERE deck_id = ?");
                $stmt->bind_param("i", $deck_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0 && $result->fetch_assoc()['user_id'] == $_SESSION['user_id']) {
                    // Delete deck
                    $stmt = $conn->prepare("DELETE FROM decks WHERE deck_id = ?");
                    $stmt->bind_param("i", $deck_id);
                    $stmt->execute();
                }
            }
            
            $_SESSION['flash_message'] = "Selected decks deleted successfully.";
        } elseif ($operation === 'merge' && isset($_POST['target_deck_id'])) {
            $target_deck_id = (int)$_POST['target_deck_id'];
            
            // Verify target deck ownership
            $stmt = $conn->prepare("SELECT user_id FROM decks WHERE deck_id = ?");
            $stmt->bind_param("i", $target_deck_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0 && $result->fetch_assoc()['user_id'] == $_SESSION['user_id']) {
                // Move cards from selected decks to target deck
                foreach ($deck_ids as $deck_id) {
                    if ($deck_id != $target_deck_id) {
                        $deck_id = (int)$deck_id;
                        
                        // Verify ownership
                        $stmt = $conn->prepare("SELECT user_id FROM decks WHERE deck_id = ?");
                        $stmt->bind_param("i", $deck_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows > 0 && $result->fetch_assoc()['user_id'] == $_SESSION['user_id']) {
                            // Move cards
                            $stmt = $conn->prepare("UPDATE cards SET deck_id = ? WHERE deck_id = ?");
                            $stmt->bind_param("ii", $target_deck_id, $deck_id);
                            $stmt->execute();
                            
                            // Delete source deck
                            $stmt = $conn->prepare("DELETE FROM decks WHERE deck_id = ?");
                            $stmt->bind_param("i", $deck_id);
                            $stmt->execute();
                        }
                    }
                }
                
                $_SESSION['flash_message'] = "Decks merged successfully.";
            }
        }
        
        redirect(SITE_URL . "/decks/list.php");
    }
    
    $conn->close();
}

// Get filter parameters
$filter_tag = isset($_GET['tag']) ? (int)$_GET['tag'] : 0;
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'recent';
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$view_mode = isset($_GET['view']) ? $_GET['view'] : 'grid';

// Get all decks for the user with additional stats
$conn = connectDB();
$params = [$_SESSION['user_id'], $_SESSION['user_id']];
$param_types = "ii";

$sql = "
    SELECT d.*, 
           COUNT(c.card_id) as card_count,
           (SELECT COUNT(*) FROM progress p 
            JOIN cards c2 ON p.card_id = c2.card_id 
            WHERE c2.deck_id = d.deck_id AND p.user_id = ? AND p.next_review <= CURDATE()) as due_count,
           (SELECT MAX(date_studied) FROM statistics WHERE user_id = ? AND deck_id = d.deck_id) as last_studied,
           (SELECT ROUND(AVG(ease_factor), 2) FROM progress p JOIN cards c3 ON p.card_id = c3.card_id WHERE c3.deck_id = d.deck_id AND p.user_id = d.user_id) as avg_ease
    FROM decks d
    LEFT JOIN cards c ON d.deck_id = c.deck_id
    WHERE d.user_id = ?
";

$params[] = $_SESSION['user_id'];
$param_types .= "i";

// Add search filter if provided
if (!empty($search_query)) {
    $sql .= " AND (d.deck_name LIKE ? OR d.description LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ss";
}

// Add tag filter if provided
if ($filter_tag > 0) {
    $sql .= " AND d.deck_id IN (SELECT deck_id FROM deck_tags WHERE tag_id = ?)";
    $params[] = $filter_tag;
    $param_types .= "i";
}

$sql .= " GROUP BY d.deck_id";

// Add sorting
switch ($sort_by) {
    case 'name':
        $sql .= " ORDER BY d.deck_name ASC";
        break;
    case 'cards':
        $sql .= " ORDER BY card_count DESC";
        break;
    case 'due':
        $sql .= " ORDER BY due_count DESC";
        break;
    case 'studied':
        $sql .= " ORDER BY last_studied DESC";
        break;
    case 'created':
    default:
        $sql .= " ORDER BY d.created_at DESC";
        break;
}

$stmt = $conn->prepare($sql);
$stmt->bind_param($param_types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$decks = [];
while ($row = $result->fetch_assoc()) {
    // Get tags for each deck
    $deck_id = $row['deck_id'];
    $tag_stmt = $conn->prepare("
        SELECT t.tag_id, t.tag_name, t.tag_color 
        FROM tags t
        JOIN deck_tags dt ON t.tag_id = dt.tag_id
        WHERE dt.deck_id = ?
    ");
    $tag_stmt->bind_param("i", $deck_id);
    $tag_stmt->execute();
    $tag_result = $tag_stmt->get_result();
    
    $tags = [];
    while ($tag = $tag_result->fetch_assoc()) {
        $tags[] = $tag;
    }
    
    $row['tags'] = $tags;
    $decks[] = $row;
}

// Get all available tags for filters
$stmt = $conn->prepare("
    SELECT t.tag_id, t.tag_name, t.tag_color, COUNT(dt.deck_id) as deck_count 
    FROM tags t
    JOIN deck_tags dt ON t.tag_id = dt.tag_id
    JOIN decks d ON dt.deck_id = d.deck_id
    WHERE d.user_id = ?
    GROUP BY t.tag_id
    ORDER BY deck_count DESC
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$tags = [];
while ($row = $result->fetch_assoc()) {
    $tags[] = $row;
}

$conn->close();

// Include header
include_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="deck-management-container">
    <!-- Dashboard Header with Analytics Summary -->
    <div class="dashboard-header mb-4">
        <div class="card bg-gradient-primary text-white">
            <div class="card-body p-4">
                <div class="row align-items-center">
                    <div class="col-lg-8">
                        <h1 class="mb-3"><i class="fas fa-layer-group me-2"></i>My Decks</h1>
                        <div class="row deck-stats">
                            <div class="col-md-3 col-6 mb-3 mb-md-0">
                                <div class="stat-item">
                                    <h3 class="mb-1"><?php echo count($decks); ?></h3>
                                    <p class="mb-0 text-white-50">Total Decks</p>
                                </div>
                            </div>
                            <div class="col-md-3 col-6 mb-3 mb-md-0">
                                <div class="stat-item">
                                    <h3 class="mb-1"><?php echo array_sum(array_column($decks, 'card_count')); ?></h3>
                                    <p class="mb-0 text-white-50">Total Cards</p>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-item">
                                    <h3 class="mb-1"><?php echo array_sum(array_column($decks, 'due_count')); ?></h3>
                                    <p class="mb-0 text-white-50">Cards Due Today</p>
                                </div>
                            </div>
                            <div class="col-md-3 col-6">
                                <div class="stat-item">
                                    <h3 class="mb-1"><?php echo count($tags); ?></h3>
                                    <p class="mb-0 text-white-50">Tags</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 text-center text-lg-end mt-4 mt-lg-0">
                        <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-light btn-lg px-4">
                            <i class="fas fa-plus me-2"></i>Create New Deck
                        </a>
                        <div class="mt-3">
                            <a href="<?php echo SITE_URL; ?>/decks/import.php" class="btn btn-outline-light">
                                <i class="fas fa-file-import me-2"></i>Import Decks
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($flash_message)): ?>
        <div class="alert alert-success mb-4">
            <i class="fas fa-check-circle me-2"></i><?php echo $flash_message; ?>
        </div>
    <?php endif; ?>

    <!-- Deck Filters and Controls -->
    <div class="deck-controls card mb-4">
        <div class="card-body p-3">
            <div class="row align-items-center">
                <!-- Search Box -->
                <div class="col-lg-4 col-md-6 mb-3 mb-lg-0">
                    <form action="<?php echo $_SERVER['PHP_SELF']; ?>" method="GET" class="search-form">
                        <div class="input-group">
                            <input type="text" class="form-control" placeholder="Search decks..." name="q" value="<?php echo htmlspecialchars($search_query); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                        <?php if ($filter_tag): ?>
                            <input type="hidden" name="tag" value="<?php echo $filter_tag; ?>">
                        <?php endif; ?>
                        <?php if ($sort_by): ?>
                            <input type="hidden" name="sort" value="<?php echo $sort_by; ?>">
                        <?php endif; ?>
                        <?php if ($view_mode): ?>
                            <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
                        <?php endif; ?>
                    </form>
                </div>
                
                <!-- Sort and Filter Dropdowns -->
                <div class="col-lg-5 col-md-6 mb-3 mb-lg-0">
                    <div class="d-flex flex-wrap">
                        <!-- Sort Dropdown -->
                        <div class="dropdown me-2 mb-2 mb-md-0">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-sort me-1"></i>
                                <?php
                                    $sort_labels = [
                                        'recent' => 'Most Recent',
                                        'name' => 'Name',
                                        'cards' => 'Card Count',
                                        'due' => 'Due Cards',
                                        'studied' => 'Last Studied'
                                    ];
                                    echo $sort_labels[$sort_by] ?? 'Sort By';
                                ?>
                            </button>
                            <ul class="dropdown-menu" aria-labelledby="sortDropdown">
                                <li><a class="dropdown-item <?php echo $sort_by === 'recent' ? 'active' : ''; ?>" href="?sort=recent<?php echo $filter_tag ? '&tag='.$filter_tag : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?><?php echo $view_mode ? '&view='.$view_mode : ''; ?>">Most Recent</a></li>
                                <li><a class="dropdown-item <?php echo $sort_by === 'name' ? 'active' : ''; ?>" href="?sort=name<?php echo $filter_tag ? '&tag='.$filter_tag : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?><?php echo $view_mode ? '&view='.$view_mode : ''; ?>">Name</a></li>
                                <li><a class="dropdown-item <?php echo $sort_by === 'cards' ? 'active' : ''; ?>" href="?sort=cards<?php echo $filter_tag ? '&tag='.$filter_tag : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?><?php echo $view_mode ? '&view='.$view_mode : ''; ?>">Card Count</a></li>
                                <li><a class="dropdown-item <?php echo $sort_by === 'due' ? 'active' : ''; ?>" href="?sort=due<?php echo $filter_tag ? '&tag='.$filter_tag : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?><?php echo $view_mode ? '&view='.$view_mode : ''; ?>">Due Cards</a></li>
                                <li><a class="dropdown-item <?php echo $sort_by === 'studied' ? 'active' : ''; ?>" href="?sort=studied<?php echo $filter_tag ? '&tag='.$filter_tag : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?><?php echo $view_mode ? '&view='.$view_mode : ''; ?>">Last Studied</a></li>
                            </ul>
                        </div>
                        
                        <!-- Tag Filter Dropdown -->
                        <div class="dropdown me-2 mb-2 mb-md-0">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="tagDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-tag me-1"></i>
                                <?php
                                    if ($filter_tag) {
                                        foreach ($tags as $tag) {
                                            if ($tag['tag_id'] == $filter_tag) {
                                                echo 'Tag: ' . htmlspecialchars($tag['tag_name']);
                                                break;
                                            }
                                        }
                                    } else {
                                        echo 'All Tags';
                                    }
                                ?>
                            </button>
                            <ul class="dropdown-menu" aria-labelledby="tagDropdown">
                                <li><a class="dropdown-item <?php echo $filter_tag === 0 ? 'active' : ''; ?>" href="?<?php echo $sort_by ? 'sort='.$sort_by : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?><?php echo $view_mode ? '&view='.$view_mode : ''; ?>">All Tags</a></li>
                                
                                <?php if (!empty($tags)): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <?php foreach ($tags as $tag): ?>
                                        <li>
                                            <a class="dropdown-item <?php echo $filter_tag === $tag['tag_id'] ? 'active' : ''; ?>" href="?tag=<?php echo $tag['tag_id']; ?><?php echo $sort_by ? '&sort='.$sort_by : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?><?php echo $view_mode ? '&view='.$view_mode : ''; ?>">
                                                <span class="tag-color-indicator" style="background-color: <?php echo $tag['tag_color'] ?? '#3E4A89'; ?>"></span>
                                                <?php echo htmlspecialchars($tag['tag_name']); ?>
                                                <span class="badge bg-secondary ms-1"><?php echo $tag['deck_count']; ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>
                        
                        <!-- View Mode Toggle -->
                        <div class="btn-group" role="group">
                            <a href="?view=grid<?php echo $sort_by ? '&sort='.$sort_by : ''; ?><?php echo $filter_tag ? '&tag='.$filter_tag : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?>" class="btn btn-outline-secondary <?php echo $view_mode === 'grid' ? 'active' : ''; ?>">
                                <i class="fas fa-th-large"></i>
                            </a>
                            <a href="?view=list<?php echo $sort_by ? '&sort='.$sort_by : ''; ?><?php echo $filter_tag ? '&tag='.$filter_tag : ''; ?><?php echo $search_query ? '&q='.urlencode($search_query) : ''; ?>" class="btn btn-outline-secondary <?php echo $view_mode === 'list' ? 'active' : ''; ?>">
                                <i class="fas fa-list"></i>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Batch Actions -->
                <div class="col-lg-3 d-flex justify-content-lg-end">
                    <div class="dropdown batch-actions-dropdown">
                        <button class="btn btn-outline-primary dropdown-toggle" type="button" id="batchActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false" disabled>
                            <i class="fas fa-tasks me-1"></i>Batch Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="batchActionsDropdown">
                            <li><a class="dropdown-item batch-action" data-action="merge" href="#"><i class="fas fa-object-group me-2"></i>Merge Selected</a></li>
                            <li><a class="dropdown-item batch-action" data-action="export" href="#"><i class="fas fa-file-export me-2"></i>Export Selected</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item batch-action text-danger" data-action="delete" href="#"><i class="fas fa-trash-alt me-2"></i>Delete Selected</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Batch Operations Form (hidden) -->
    <form id="batchOperationForm" action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST" class="d-none">
        <input type="hidden" name="action" value="batch_operation">
        <input type="hidden" name="operation" id="batchOperation">
        <input type="hidden" name="target_deck_id" id="targetDeckId">
        <div id="selectedDeckInputs"></div>
    </form>

    <!-- Merge Decks Modal -->
    <div class="modal fade" id="mergeDeckModal" tabindex="-1" aria-labelledby="mergeDeckModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="mergeDeckModalLabel">Merge Decks</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Select the target deck to merge into:</p>
                    <select class="form-select" id="mergeTargetDeck">
                        <option value="">Select a deck...</option>
                        <?php foreach ($decks as $deck): ?>
                            <option value="<?php echo $deck['deck_id']; ?>"><?php echo htmlspecialchars($deck['deck_name']); ?> (<?php echo $deck['card_count']; ?> cards)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle me-2"></i>This action cannot be undone. All cards from the selected decks will be moved to the target deck, and the source decks will be deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmMergeBtn" disabled>Merge Decks</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteConfirmModalLabel">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the selected decks? This action cannot be undone.</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>All cards in these decks will also be deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Deck Tag Management Modal -->
    <div class="modal fade" id="manageDeckTagsModal" tabindex="-1" aria-labelledby="manageDeckTagsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="manageDeckTagsModalLabel">Manage Tags</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="currentDeckTags" class="mb-3">
                        <!-- Current tags will be displayed here -->
                    </div>
                    
                    <form id="addTagForm" action="<?php echo $_SERVER['PHP_SELF']; ?>" method="POST">
                        <input type="hidden" name="action" value="add_tag">
                        <input type="hidden" name="deck_id" id="tagDeckId" value="">
                        
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" name="tag_name" id="newTagInput" placeholder="Add a new tag...">
                            <button class="btn btn-primary" type="submit">Add</button>
                        </div>
                    </form>
                    
                    <div class="mt-3">
                        <h6>Suggested Tags</h6>
                        <div id="suggestedTags" class="d-flex flex-wrap gap-2">
                            <!-- Suggested tags will be displayed here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($decks)): ?>
        <div class="row">
            <div class="col-md-8 mx-auto text-center my-5">
                <div class="empty-state p-5">
                    <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20'/%3E%3C/svg%3E" 
                         alt="No decks" class="mb-4" style="width: 120px; height: 120px; opacity: 0.6;">
                    <h3 class="mb-3">No Decks Found</h3>
                    <p class="text-muted mb-4">You haven't created any flashcard decks yet. Start by creating your first deck!</p>
                    <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-plus me-2"></i>Create Your First Deck
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Decks List -->
        <form id="deckSelectionForm">
            <?php if ($view_mode === 'list'): ?>
                <!-- List View -->
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover deck-table mb-0">
                            <thead>
                                <tr>
                                    <th style="width: 40px">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="selectAllDecks">
                                        </div>
                                    </th>
                                    <th>Deck</th>
                                    <th>Cards</th>
                                    <th>Due</th>
                                    <th>Progress</th>
                                    <th>Last Studied</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($decks as $deck): ?>
                                    <tr>
                                        <td>
                                            <div class="form-check">
                                                <input class="form-check-input deck-checkbox" type="checkbox" data-deck-id="<?php echo $deck['deck_id']; ?>">
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="me-3">
                                                    <div class="deck-icon">
                                                        <i class="fas fa-book"></i>
                                                    </div>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0">
                                                        <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="text-decoration-none">
                                                            <?php echo htmlspecialchars($deck['deck_name']); ?>
                                                        </a>
                                                    </h6>
                                                    <?php if (!empty($deck['tags'])): ?>
                                                        <div class="deck-tags mt-1">
                                                            <?php foreach ($deck['tags'] as $tag): ?>
                                                                <span class="badge" style="background-color: <?php echo $tag['tag_color'] ?? '#3E4A89'; ?>">
                                                                    <?php echo htmlspecialchars($tag['tag_name']); ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo $deck['card_count']; ?></td>
                                        <td>
                                            <?php if ($deck['due_count'] > 0): ?>
                                                <span class="badge bg-danger">
                                                    <?php echo $deck['due_count']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                                // Calculate progress (percentage of cards not due)
                                                $progress = 0;
                                                if ($deck['card_count'] > 0) {
                                                    $progress = 100 - (($deck['due_count'] / $deck['card_count']) * 100);
                                                }
                                            ?>
                                            <div class="progress" style="height: 8px; width: 100px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo round($progress); ?>%"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($deck['last_studied']): ?>
                                                <?php 
                                                    $last_date = new DateTime($deck['last_studied']);
                                                    $now = new DateTime();
                                                    $diff = $last_date->diff($now);
                                                    
                                                    if ($diff->days == 0) {
                                                        echo "Today";
                                                    } elseif ($diff->days == 1) {
                                                        echo "Yesterday";
                                                    } else {
                                                        echo $diff->days . " days ago";
                                                    }
                                                ?>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group">
                                                <?php if ($deck['card_count'] > 0): ?>
                                                    <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-success" data-bs-toggle="tooltip" title="Study Deck">
                                                        <i class="fas fa-graduation-cap"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="View Cards">
                                                    <i class="fas fa-th-list"></i>
                                                </a>
                                                <a href="<?php echo SITE_URL; ?>/decks/edit.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-outline-secondary" data-bs-toggle="tooltip" title="Edit Deck">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-sm btn-outline-info tag-manage-btn" data-deck-id="<?php echo $deck['deck_id']; ?>" data-bs-toggle="tooltip" title="Manage Tags">
                                                    <i class="fas fa-tags"></i>
                                                </button>
                                                <a href="<?php echo SITE_URL; ?>/decks/export.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-outline-primary" data-bs-toggle="tooltip" title="Export Deck">
                                                    <i class="fas fa-file-export"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <!-- Grid View -->
                <div class="row">
                    <?php foreach ($decks as $deck): ?>
                        <div class="col-md-4 col-sm-6 mb-4">
                            <div class="card h-100 deck-card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <div class="form-check">
                                        <input class="form-check-input deck-checkbox" type="checkbox" data-deck-id="<?php echo $deck['deck_id']; ?>">
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-link text-muted" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="fas fa-ellipsis-v"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>"><i class="fas fa-th-list me-2"></i>View Cards</a></li>
                                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/decks/edit.php?deck_id=<?php echo $deck['deck_id']; ?>"><i class="fas fa-edit me-2"></i>Edit Deck</a></li>
                                            <li><a class="dropdown-item tag-manage-btn" href="#" data-deck-id="<?php echo $deck['deck_id']; ?>"><i class="fas fa-tags me-2"></i>Manage Tags</a></li>
                                            <li><a class="dropdown-item" href="<?php echo SITE_URL; ?>/decks/export.php?deck_id=<?php echo $deck['deck_id']; ?>"><i class="fas fa-file-export me-2"></i>Export Deck</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item text-danger" href="<?php echo SITE_URL; ?>/decks/delete.php?deck_id=<?php echo $deck['deck_id']; ?>" onclick="return confirm('Are you sure you want to delete this deck?')"><i class="fas fa-trash-alt me-2"></i>Delete</a></li>
                                        </ul>
                                    </div>
                                </div>
                                <div class="deck-pattern"></div>
                                <?php if ($deck['due_count'] > 0): ?>
                                    <span class="badge bg-danger due-badge">
                                        <i class="fas fa-exclamation-circle me-1"></i><?php echo $deck['due_count']; ?> due
                                    </span>
                                <?php endif; ?>
                                <div class="card-body">
                                    <h5 class="card-title mb-2">
                                        <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="text-decoration-none">
                                            <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($deck['deck_name']); ?>
                                        </a>
                                    </h5>
                                    
                                    <?php if (!empty($deck['tags'])): ?>
                                        <div class="deck-tags mb-2">
                                            <?php foreach ($deck['tags'] as $tag): ?>
                                                <span class="badge" style="background-color: <?php echo $tag['tag_color'] ?? '#3E4A89'; ?>">
                                                    <?php echo htmlspecialchars($tag['tag_name']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <p class="card-text">
                                        <?php 
                                            echo !empty($deck['description']) 
                                                ? htmlspecialchars(substr($deck['description'], 0, 100)) . (strlen($deck['description']) > 100 ? '...' : '')
                                                : '<em class="text-muted">No description</em>';
                                        ?>
                                    </p>
                                    
                                    <!-- Progress Bar -->
                                    <?php
                                        // Calculate progress (percentage of cards not due)
                                        $progress = 0;
                                        if ($deck['card_count'] > 0) {
                                            $progress = 100 - (($deck['due_count'] / $deck['card_count']) * 100);
                                        }
                                    ?>
                                    <div class="progress mb-3" style="height: 8px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo round($progress); ?>%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mt-3">
                                        <span class="badge bg-primary rounded-pill">
                                            <i class="fas fa-clone me-1"></i><?php echo $deck['card_count']; ?> cards
                                        </span>
                                        
                                        <?php if ($deck['last_studied']): ?>
                                            <span class="badge bg-info rounded-pill" data-bs-toggle="tooltip" title="Last studied on <?php echo date('M d, Y', strtotime($deck['last_studied'])); ?>">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php 
                                                    $last_date = new DateTime($deck['last_studied']);
                                                    $now = new DateTime();
                                                    $diff = $last_date->diff($now);
                                                    
                                                    if ($diff->days == 0) {
                                                        echo "Today";
                                                    } elseif ($diff->days == 1) {
                                                        echo "Yesterday";
                                                    } else {
                                                        echo $diff->days . "d ago";
                                                    }
                                                ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary rounded-pill">
                                                <i class="fas fa-clock me-1"></i>Never studied
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-footer d-flex justify-content-between">
                                    <a href="<?php echo SITE_URL; ?>/cards/create.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-plus me-1"></i>Add Cards
                                    </a>
                                    <?php if ($deck['card_count'] > 0): ?>
                                        <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-success">
                                            <i class="fas fa-graduation-cap me-1"></i>Study
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-success" disabled>
                                            <i class="fas fa-graduation-cap me-1"></i>Study
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<!-- Enhanced styles for deck management -->
<style>
    /* Dashboard header styling */
    .dashboard-header .card {
        border-radius: 15px;
        overflow: hidden;
    }
    
    .deck-stats .stat-item {
        padding: 0 10px;
        border-right: 1px solid rgba(255, 255, 255, 0.2);
    }
    
    .deck-stats .col-md-3:last-child .stat-item {
        border-right: none;
    }
    
    .deck-stats h3 {
        font-size: 1.8rem;
        font-weight: 600;
    }
    
    /* Deck controls */
    .deck-controls {
        border-radius: 10px;
        background-color: #f8f9fa;
        border: none;
    }
    
    .search-form {
        max-width: 100%;
    }
    
    /* Tag styling */
    .tag-color-indicator {
        display: inline-block;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-right: 5px;
    }
    
    .deck-tags .badge {
        margin-right: 5px;
        font-weight: 400;
        padding: 5px 8px;
        border-radius: 4px;
    }
    
    /* List view styles */
    .deck-table th {
        background-color: #f8f9fa;
        font-weight: 500;
    }
    
    .deck-icon {
        width: 40px;
        height: 40px;
        background-color: var(--indigo);
        color: white;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }
    
    /* Grid view styles */
    .deck-card {
        transition: all 0.3s ease;
        border: none;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    
    .deck-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
    }
    
    .deck-card .card-header {
        background-color: rgba(245, 245, 245, 0.5);
        border-bottom: none;
        padding: 0.5rem 1rem;
    }
    
    .deck-card .card-footer {
        background-color: rgba(245, 245, 245, 0.5);
        border-top: none;
    }
    
    .due-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 1;
    }
    
    /* Animations for added elements */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .deck-card, .alert {
        animation: fadeIn 0.3s ease forwards;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Handle deck selection
    const selectAllCheckbox = document.getElementById('selectAllDecks');
    const deckCheckboxes = document.querySelectorAll('.deck-checkbox');
    const batchActionsBtn = document.querySelector('.batch-actions-dropdown button');
    
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            deckCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateBatchActionsState();
        });
    }
    
    deckCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateBatchActionsState();
        });
    });
    
    function updateBatchActionsState() {
        const selectedCount = document.querySelectorAll('.deck-checkbox:checked').length;
        
        if (selectedCount > 0) {
            batchActionsBtn.removeAttribute('disabled');
            batchActionsBtn.textContent = `${selectedCount} Selected`;
        } else {
            batchActionsBtn.setAttribute('disabled', true);
            batchActionsBtn.innerHTML = '<i class="fas fa-tasks me-1"></i>Batch Actions';
        }
    }
    
    // Handle batch actions
    const batchActions = document.querySelectorAll('.batch-action');
    batchActions.forEach(action => {
        action.addEventListener('click', function(e) {
            e.preventDefault();
            
            const actionType = this.dataset.action;
            const selectedDeckIds = [];
            
            deckCheckboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    selectedDeckIds.push(checkbox.dataset.deckId);
                }
            });
            
            if (selectedDeckIds.length === 0) {
                return;
            }
            
            // Clear previous inputs
            document.getElementById('selectedDeckInputs').innerHTML = '';
            
            // Add selected deck IDs to form
            selectedDeckIds.forEach(deckId => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'deck_ids[]';
                input.value = deckId;
                document.getElementById('selectedDeckInputs').appendChild(input);
            });
            
            document.getElementById('batchOperation').value = actionType;
            
            // Handle different action types
            if (actionType === 'delete') {
                // Show delete confirmation modal
                new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
                
                // Handle confirm delete button
                document.getElementById('confirmDeleteBtn').onclick = function() {
                    document.getElementById('batchOperationForm').submit();
                };
            } else if (actionType === 'merge') {
                // Show merge decks modal
                const mergeModal = new bootstrap.Modal(document.getElementById('mergeDeckModal'));
                mergeModal.show();
                
                // Handle target deck selection
                const mergeTargetSelect = document.getElementById('mergeTargetDeck');
                const confirmMergeBtn = document.getElementById('confirmMergeBtn');
                
                // Reset select options
                mergeTargetSelect.innerHTML = '<option value="">Select a deck...</option>';
                
                // Add all decks as options, but exclude selected decks
                <?php foreach ($decks as $deck): ?>
                const deckId<?php echo $deck['deck_id']; ?> = '<?php echo $deck['deck_id']; ?>';
                if (!selectedDeckIds.includes(deckId<?php echo $deck['deck_id']; ?>)) {
                    const option = document.createElement('option');
                    option.value = deckId<?php echo $deck['deck_id']; ?>;
                    option.textContent = '<?php echo htmlspecialchars($deck['deck_name']); ?> (<?php echo $deck['card_count']; ?> cards)';
                    mergeTargetSelect.appendChild(option);
                }
                <?php endforeach; ?>
                
                // Enable/disable confirm button based on selection
                mergeTargetSelect.addEventListener('change', function() {
                    confirmMergeBtn.disabled = !this.value;
                });
                
                // Handle confirm merge button
                confirmMergeBtn.onclick = function() {
                    document.getElementById('targetDeckId').value = mergeTargetSelect.value;
                    document.getElementById('batchOperationForm').submit();
                };
            } else if (actionType === 'export') {
                // Redirect to export page with selected deck IDs
                window.location.href = '<?php echo SITE_URL; ?>/decks/export.php?deck_ids=' + selectedDeckIds.join(',');
            }
        });
    });
    
    // Tag management
    const tagManageBtns = document.querySelectorAll('.tag-manage-btn');
    tagManageBtns.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            
            const deckId = this.dataset.deckId;
            document.getElementById('tagDeckId').value = deckId;
            
            // Get current deck tags
            let currentTags = [];
            <?php foreach ($decks as $deck): ?>
            if (deckId === '<?php echo $deck['deck_id']; ?>') {
                currentTags = [
                    <?php foreach ($deck['tags'] as $tag): ?>
                    {
                        id: <?php echo $tag['tag_id']; ?>,
                        name: '<?php echo htmlspecialchars($tag['tag_name']); ?>',
                        color: '<?php echo $tag['tag_color'] ?? '#3E4A89'; ?>'
                    },
                    <?php endforeach; ?>
                ];
            }
            <?php endforeach; ?>
            
            // Display current tags
            const currentTagsContainer = document.getElementById('currentDeckTags');
            currentTagsContainer.innerHTML = '';
            
            if (currentTags.length > 0) {
                currentTags.forEach(tag => {
                    const tagElement = document.createElement('div');
                    tagElement.className = 'badge me-2 mb-2 p-2';
                    tagElement.style.backgroundColor = tag.color;
                    
                    tagElement.innerHTML = `
                        ${tag.name}
                        <button type="button" class="btn-close btn-close-white ms-2" style="font-size: 0.5rem;" data-tag-id="${tag.id}"></button>
                    `;
                    
                    currentTagsContainer.appendChild(tagElement);
                    
                    // Handle tag removal
                    tagElement.querySelector('.btn-close').addEventListener('click', function() {
                        const tagId = this.dataset.tagId;
                        
                        // Create and submit form
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '<?php echo $_SERVER['PHP_SELF']; ?>';
                        
                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'action';
                        actionInput.value = 'remove_tag';
                        
                        const deckIdInput = document.createElement('input');
                        deckIdInput.type = 'hidden';
                        deckIdInput.name = 'deck_id';
                        deckIdInput.value = deckId;
                        
                        const tagIdInput = document.createElement('input');
                        tagIdInput.type = 'hidden';
                        tagIdInput.name = 'tag_id';
                        tagIdInput.value = tagId;
                        
                        form.appendChild(actionInput);
                        form.appendChild(deckIdInput);
                        form.appendChild(tagIdInput);
                        
                        document.body.appendChild(form);
                        form.submit();
                    });
                });
            } else {
                currentTagsContainer.innerHTML = '<p class="text-muted">No tags assigned to this deck yet.</p>';
            }
            
            // Display suggested tags
            const suggestedTagsContainer = document.getElementById('suggestedTags');
            suggestedTagsContainer.innerHTML = '';
            
            // Get all available tags that are not already assigned to this deck
            const availableTags = [
                <?php foreach ($tags as $tag): ?>
                {
                    id: <?php echo $tag['tag_id']; ?>,
                    name: '<?php echo htmlspecialchars($tag['tag_name']); ?>',
                    color: '<?php echo $tag['tag_color'] ?? '#3E4A89'; ?>'
                },
                <?php endforeach; ?>
            ].filter(tag => !currentTags.some(currentTag => currentTag.id === tag.id));
            
            if (availableTags.length > 0) {
                availableTags.forEach(tag => {
                    const tagElement = document.createElement('button');
                    tagElement.type = 'button';
                    tagElement.className = 'badge bg-secondary border-0';
                    tagElement.textContent = tag.name;
                    tagElement.style.backgroundColor = tag.color;
                    
                    tagElement.addEventListener('click', function() {
                        document.getElementById('newTagInput').value = tag.name;
                        document.getElementById('addTagForm').submit();
                    });
                    
                    suggestedTagsContainer.appendChild(tagElement);
                });
            } else {
                suggestedTagsContainer.innerHTML = '<p class="text-muted">No suggested tags available.</p>';
            }
            
            // Show the modal
            new bootstrap.Modal(document.getElementById('manageDeckTagsModal')).show();
        });
    });
});
</script>

<?php include_once dirname(__DIR__) . '/includes/footer.php'; ?>
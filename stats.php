<?php
require_once 'config.php';

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

// Get statistics
$conn = connectDB();

// Get overall statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT deck_id) as total_decks,
        SUM(cards_studied) as total_cards_studied,
        SUM(correct_answers) as total_correct,
        ROUND(SUM(correct_answers) / SUM(cards_studied) * 100) as overall_accuracy
    FROM statistics
    WHERE user_id = ?
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$overall_stats = $result->fetch_assoc();

// Get study streaks (consecutive days)
$stmt = $conn->prepare("
    SELECT date_studied
    FROM statistics
    WHERE user_id = ?
    GROUP BY date_studied
    ORDER BY date_studied DESC
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$study_dates = [];
while ($row = $result->fetch_assoc()) {
    $study_dates[] = $row['date_studied'];
}

// Calculate current streak
$current_streak = 0;
$yesterday = date('Y-m-d', strtotime('-1 day'));

// Check if user studied today
$today = date('Y-m-d');
$studied_today = in_array($today, $study_dates);

// Start from yesterday or today
$start_date = $studied_today ? $today : $yesterday;

// Calculate streak
for ($i = 0; $i < count($study_dates); $i++) {
    $date_to_check = date('Y-m-d', strtotime("-{$i} days"));
    if (in_array($date_to_check, $study_dates)) {
        $current_streak++;
    } else {
        break;
    }
}

// Get study history by day for the last 30 days
$stmt = $conn->prepare("
    SELECT 
        date_studied,
        SUM(cards_studied) as cards_studied,
        SUM(correct_answers) as correct_answers,
        ROUND(SUM(correct_answers) / SUM(cards_studied) * 100) as accuracy
    FROM statistics
    WHERE user_id = ? AND date_studied >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY date_studied
    ORDER BY date_studied
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$daily_stats = [];
while ($row = $result->fetch_assoc()) {
    $daily_stats[] = $row;
}

// Get deck statistics
$stmt = $conn->prepare("
    SELECT 
        d.deck_id,
        d.deck_name,
        COUNT(c.card_id) as total_cards,
        SUM(CASE WHEN p.next_review <= CURDATE() THEN 1 ELSE 0 END) as due_cards,
        COALESCE(s.cards_studied, 0) as cards_studied,
        COALESCE(s.correct_answers, 0) as correct_answers,
        COALESCE(ROUND(s.correct_answers / s.cards_studied * 100), 0) as accuracy
    FROM decks d
    LEFT JOIN cards c ON d.deck_id = c.deck_id
    LEFT JOIN progress p ON c.card_id = p.card_id AND p.user_id = ?
    LEFT JOIN (
        SELECT 
            deck_id,
            SUM(cards_studied) as cards_studied,
            SUM(correct_answers) as correct_answers
        FROM statistics
        WHERE user_id = ?
        GROUP BY deck_id
    ) s ON d.deck_id = s.deck_id
    WHERE d.user_id = ?
    GROUP BY d.deck_id
    ORDER BY cards_studied DESC
");
$stmt->bind_param("iii", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$deck_stats = [];
while ($row = $result->fetch_assoc()) {
    $deck_stats[] = $row;
}

$conn->close();

// Include header
include_once 'includes/header.php';
?>

<h1 class="mb-4">Your Progress</h1>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <h5 class="card-title">Study Streak</h5>
                <p class="card-text display-4"><?php echo $current_streak; ?></p>
                <p class="text-muted">consecutive days</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <h5 class="card-title">Cards Studied</h5>
                <p class="card-text display-4"><?php echo $overall_stats['total_cards_studied'] ?? 0; ?></p>
                <p class="text-muted">total reviews</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <h5 class="card-title">Correct Answers</h5>
                <p class="card-text display-4"><?php echo $overall_stats['total_correct'] ?? 0; ?></p>
                <p class="text-muted">cards remembered</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center h-100">
            <div class="card-body">
                <h5 class="card-title">Accuracy</h5>
                <p class="card-text display-4"><?php echo $overall_stats['overall_accuracy'] ?? 0; ?>%</p>
                <p class="text-muted">overall performance</p>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5>Study Activity (Last 30 Days)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($daily_stats)): ?>
                    <p class="text-center">No study activity in the last 30 days</p>
                <?php else: ?>
                    <canvas id="dailyActivityChart" width="400" height="200"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5>Study Calendar</h5>
            </div>
            <div class="card-body">
                <div id="study-calendar"></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5>Deck Performance</h5>
    </div>
    <div class="card-body">
        <?php if (empty($deck_stats)): ?>
            <p class="text-center">No deck statistics available</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Deck</th>
                            <th>Cards</th>
                            <th>Due</th>
                            <th>Studied</th>
                            <th>Correct</th>
                            <th>Accuracy</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deck_stats as $deck): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($deck['deck_name']); ?></td>
                                <td><?php echo $deck['total_cards']; ?></td>
                                <td>
                                    <?php if ($deck['due_cards'] > 0): ?>
                                        <span class="badge bg-danger"><?php echo $deck['due_cards']; ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $deck['cards_studied']; ?></td>
                                <td><?php echo $deck['correct_answers']; ?></td>
                                <td>
                                    <div class="progress">
                                        <div 
                                            class="progress-bar 
                                                <?php 
                                                    if ($deck['accuracy'] >= 80) echo 'bg-success';
                                                    elseif ($deck['accuracy'] >= 60) echo 'bg-info';
                                                    elseif ($deck['accuracy'] >= 40) echo 'bg-warning';
                                                    else echo 'bg-danger';
                                                ?>"
                                            role="progressbar" 
                                            style="width: <?php echo $deck['accuracy']; ?>%;"
                                        >
                                            <?php echo $deck['accuracy']; ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-success">Study</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- JavaScript for charts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js"></script>
<script>
<?php if (!empty($daily_stats)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('dailyActivityChart').getContext('2d');
    
    // Prepare data for chart
    const labels = [
        <?php 
            foreach ($daily_stats as $day) {
                echo "'" . date('M d', strtotime($day['date_studied'])) . "',";
            }
        ?>
    ];
    
    const studiedData = [
        <?php 
            foreach ($daily_stats as $day) {
                echo $day['cards_studied'] . ",";
            }
        ?>
    ];
    
    const accuracyData = [
        <?php 
            foreach ($daily_stats as $day) {
                echo $day['accuracy'] . ",";
            }
        ?>
    ];
    
    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Cards Studied',
                    data: studiedData,
                    backgroundColor: 'rgba(54, 162, 235, 0.5)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Accuracy (%)',
                    data: accuracyData,
                    type: 'line',
                    fill: false,
                    backgroundColor: 'rgba(255, 99, 132, 0.5)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 2,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Cards Studied'
                    },
                    ticks: {
                        precision: 0
                    }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'Accuracy (%)'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            },
            responsive: true,
            maintainAspectRatio: false
        }
    });
    
    // Simple calendar to show study days
    const studyDates = [
        <?php 
            foreach ($study_dates as $date) {
                echo "'" . $date . "',";
            }
        ?>
    ];
    
    renderCalendar(studyDates);
});

// Function to render a simple calendar
function renderCalendar(studyDates) {
    const calendarEl = document.getElementById('study-calendar');
    const currentDate = new Date();
    const currentMonth = currentDate.getMonth();
    const currentYear = currentDate.getFullYear();
    
    // Create month and year header
    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    const header = document.createElement('h6');
    header.className = 'text-center mb-3';
    header.textContent = `${monthNames[currentMonth]} ${currentYear}`;
    calendarEl.appendChild(header);
    
    // Create day labels
    const dayLabels = document.createElement('div');
    dayLabels.className = 'row text-center mb-2';
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    
    days.forEach(day => {
        const dayEl = document.createElement('div');
        dayEl.className = 'col';
        dayEl.textContent = day;
        dayLabels.appendChild(dayEl);
    });
    
    calendarEl.appendChild(dayLabels);
    
    // Get first day of month and number of days
    const firstDay = new Date(currentYear, currentMonth, 1).getDay();
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    
    // Create calendar days
    let dayCount = 1;
    let calendarHtml = '';
    
    // Create 6 rows (max possible for a month)
    for (let i = 0; i < 6; i++) {
        calendarHtml += '<div class="row mb-2">';
        
        // Create 7 columns (days of week)
        for (let j = 0; j < 7; j++) {
            // Add empty cells for days before start of month
            if (i === 0 && j < firstDay) {
                calendarHtml += '<div class="col"></div>';
            } 
            // Add days of month
            else if (dayCount <= daysInMonth) {
                const date = `${currentYear}-${(currentMonth + 1).toString().padStart(2, '0')}-${dayCount.toString().padStart(2, '0')}`;
                const isStudyDay = studyDates.includes(date);
                const isToday = dayCount === currentDate.getDate();
                
                let cellClass = 'col text-center';
                let dayHtml = '';
                
                if (isStudyDay) {
                    dayHtml = `<span class="badge rounded-pill bg-success">${dayCount}</span>`;
                } else if (isToday) {
                    dayHtml = `<span class="badge rounded-pill bg-primary">${dayCount}</span>`;
                } else {
                    dayHtml = dayCount;
                }
                
                calendarHtml += `<div class="${cellClass}">${dayHtml}</div>`;
                dayCount++;
            } 
            // Add empty cells for days after end of month
            else {
                calendarHtml += '<div class="col"></div>';
            }
        }
        
        calendarHtml += '</div>';
        
        // Stop if we've reached the end of the month
        if (dayCount > daysInMonth) {
            break;
        }
    }
    
    calendarEl.innerHTML += calendarHtml;
}
<?php endif; ?>
</script>

<?php include_once 'includes/footer.php'; ?>
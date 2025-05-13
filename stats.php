<?php
require_once 'config.php';

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect(SITE_URL . "/auth/login.php");
}

// Get statistics from database
$conn = connectDB();

// Get overall statistics with a single optimized query
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT d.deck_id) as total_decks,
        (SELECT COUNT(*) FROM cards c JOIN decks d2 ON c.deck_id = d2.deck_id WHERE d2.user_id = ?) as total_cards,
        COALESCE(SUM(s.cards_studied), 0) as total_cards_studied,
        COALESCE(SUM(s.correct_answers), 0) as total_correct,
        CASE WHEN SUM(s.cards_studied) > 0 
            THEN ROUND(SUM(s.correct_answers) / SUM(s.cards_studied) * 100) 
            ELSE 0 
        END as overall_accuracy
    FROM decks d
    LEFT JOIN statistics s ON d.deck_id = s.deck_id AND s.user_id = ?
    WHERE d.user_id = ?
");
$stmt->bind_param("iii", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$overall_stats = $result->fetch_assoc();

// Get study dates for streak calculation
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
$study_counts = []; // For storing cards studied per day
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
    $study_counts[$row['date_studied']] = $row['cards_studied']; // Store cards studied per day
}

// Get deck statistics with a single optimized query
$stmt = $conn->prepare("
    SELECT 
        d.deck_id,
        d.deck_name,
        COUNT(c.card_id) as total_cards,
        SUM(CASE WHEN p.next_review <= CURDATE() THEN 1 ELSE 0 END) as due_cards,
        COALESCE(s.cards_studied, 0) as cards_studied,
        COALESCE(s.correct_answers, 0) as correct_answers,
        CASE WHEN s.cards_studied > 0 
            THEN ROUND(s.correct_answers / s.cards_studied * 100) 
            ELSE 0 
        END as accuracy
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
    ORDER BY cards_studied DESC, d.created_at DESC
");
$stmt->bind_param("iii", $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$deck_stats = [];
while ($row = $result->fetch_assoc()) {
    $deck_stats[] = $row;
}

// Get top 5 days with most cards studied
$stmt = $conn->prepare("
    SELECT 
        date_studied,
        SUM(cards_studied) as total_studied,
        SUM(correct_answers) as total_correct,
        ROUND(SUM(correct_answers) / SUM(cards_studied) * 100) as day_accuracy
    FROM statistics
    WHERE user_id = ?
    GROUP BY date_studied
    ORDER BY total_studied DESC
    LIMIT 5
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$top_study_days = [];
while ($row = $result->fetch_assoc()) {
    $top_study_days[] = $row;
}

// Get study time distribution
$stmt = $conn->prepare("
    SELECT 
        CASE
            WHEN HOUR(last_reviewed) BETWEEN 5 AND 11 THEN 'morning'
            WHEN HOUR(last_reviewed) BETWEEN 12 AND 17 THEN 'afternoon'
            ELSE 'evening'
        END as time_of_day,
        COUNT(*) as review_count
    FROM progress
    WHERE user_id = ? AND last_reviewed IS NOT NULL
    GROUP BY time_of_day
");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();

$total_reviews = 0;
$study_times = [
    'morning' => 0,
    'afternoon' => 0,
    'evening' => 0
];

while ($row = $result->fetch_assoc()) {
    $study_times[$row['time_of_day']] = $row['review_count'];
    $total_reviews += $row['review_count'];
}

// Convert to percentages
if ($total_reviews > 0) {
    foreach ($study_times as $time => $count) {
        $study_times[$time] = round(($count / $total_reviews) * 100);
    }
}

$conn->close();

// Include header
include_once 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-chart-line me-2"></i>Your Progress</h1>
    <a href="<?php echo SITE_URL; ?>" class="btn btn-outline-primary">
        <i class="fas fa-home me-2"></i>Dashboard
    </a>
</div>

<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-4">
        <div class="card stat-card h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-fire stat-icon"></i>
                <h5 class="card-title text-muted mb-2">Study Streak</h5>
                <p class="card-text display-4 mb-1"><?php echo $current_streak; ?></p>
                <p class="text-muted"><small>consecutive days</small></p>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-4">
        <div class="card stat-card h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-clone stat-icon"></i>
                <h5 class="card-title text-muted mb-2">Cards Studied</h5>
                <p class="card-text display-4 mb-1"><?php echo $overall_stats['total_cards_studied'] ?? 0; ?></p>
                <p class="text-muted"><small>total reviews</small></p>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-4">
        <div class="card stat-card h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-check-circle stat-icon"></i>
                <h5 class="card-title text-muted mb-2">Correct Answers</h5>
                <p class="card-text display-4 mb-1"><?php echo $overall_stats['total_correct'] ?? 0; ?></p>
                <p class="text-muted"><small>cards remembered</small></p>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-4">
        <div class="card stat-card h-100">
            <div class="card-body text-center p-4">
                <i class="fas fa-percentage stat-icon"></i>
                <h5 class="card-title text-muted mb-2">Accuracy</h5>
                <p class="card-text display-4 mb-1"><?php echo $overall_stats['overall_accuracy'] ?? 0; ?>%</p>
                <p class="text-muted"><small>overall performance</small></p>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-8 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-chart-area me-2"></i>Study Activity (Last 30 Days)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($daily_stats)): ?>
                    <div class="text-center py-5">
                        <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cline x1='4' y1='21' x2='4' y2='14'/%3E%3Cline x1='8' y1='21' x2='8' y2='12'/%3E%3Cline x1='12' y1='21' x2='12' y2='8'/%3E%3Cline x1='16' y1='21' x2='16' y2='16'/%3E%3Cline x1='20' y1='21' x2='20' y2='10'/%3E%3C/svg%3E" 
                             alt="No activity" style="width: 80px; height: 80px; opacity: 0.5;" class="mb-3">
                        <p class="text-muted">No study activity in the last 30 days</p>
                        <a href="<?php echo SITE_URL; ?>/study/index.php" class="btn btn-primary">Start Studying Now</a>
                    </div>
                <?php else: ?>
                    <canvas id="dailyActivityChart" height="250"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Study Calendar</h5>
            </div>
            <div class="card-body">
                <div id="study-calendar"></div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-8 mb-4">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-book me-2"></i>Deck Performance</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($deck_stats)): ?>
                    <div class="text-center py-5">
                        <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='200' height='200' viewBox='0 0 24 24' fill='none' stroke='%233E4A89' stroke-width='1' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20'/%3E%3C/svg%3E" 
                            alt="No decks" style="width: 80px; height: 80px; opacity: 0.5;" class="mb-3">
                        <p class="text-muted">No deck statistics available</p>
                        <a href="<?php echo SITE_URL; ?>/decks/create.php" class="btn btn-primary">Create Your First Deck</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
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
                                        <td>
                                            <a href="<?php echo SITE_URL; ?>/cards/list.php?deck_id=<?php echo $deck['deck_id']; ?>" class="text-decoration-none">
                                                <i class="fas fa-book me-2"></i><?php echo htmlspecialchars($deck['deck_name']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo $deck['total_cards']; ?></td>
                                        <td>
                                            <?php if ($deck['due_cards'] > 0): ?>
                                                <span class="badge bg-danger">
                                                    <i class="fas fa-exclamation-circle me-1"></i><?php echo $deck['due_cards']; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $deck['cards_studied']; ?></td>
                                        <td><?php echo $deck['correct_answers']; ?></td>
                                        <td>
                                            <div class="progress" style="height: 8px; width: 100px;">
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
                                                    aria-valuenow="<?php echo $deck['accuracy']; ?>"
                                                    aria-valuemin="0"
                                                    aria-valuemax="100"
                                                ></div>
                                            </div>
                                            <small><?php echo $deck['accuracy']; ?>%</small>
                                        </td>
                                        <td>
                                            <a href="<?php echo SITE_URL; ?>/study/index.php?deck_id=<?php echo $deck['deck_id']; ?>" class="btn btn-sm btn-success">
                                                <i class="fas fa-graduation-cap"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4 mb-4">
        <div class="row">
            <!-- Study Time Distribution -->
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Study Time Distribution</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($total_reviews > 0): ?>
                            <div class="study-time-container">
                                <canvas id="studyTimeChart" height="220"></canvas>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <p class="text-muted">No study time data available yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Best Study Days -->
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-trophy me-2"></i>Best Study Days</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($top_study_days)): ?>
                            <div class="text-center py-4">
                                <p class="text-muted">No study data available yet</p>
                            </div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($top_study_days as $index => $day): ?>
                                    <li class="list-group-item px-3 py-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <div class="d-flex align-items-center">
                                                    <span class="trophy-badge me-2"><?php echo $index + 1; ?></span>
                                                    <div>
                                                        <strong><?php echo date('F j, Y', strtotime($day['date_studied'])); ?></strong>
                                                        <div class="text-muted small">
                                                            <?php echo $day['total_correct']; ?> correct out of <?php echo $day['total_studied']; ?> cards
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-<?php 
                                                    if ($day['day_accuracy'] >= 80) echo 'success';
                                                    elseif ($day['day_accuracy'] >= 60) echo 'info';
                                                    elseif ($day['day_accuracy'] >= 40) echo 'warning';
                                                    else echo 'danger';
                                                ?> p-2">
                                                    <?php echo $day['day_accuracy']; ?>%
                                                </span>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CSS Styles -->
<style>
    .stat-card {
        position: relative;
        overflow: hidden;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        border: none;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
    }
    
    .stat-icon {
        position: absolute;
        top: 15px;
        right: 15px;
        font-size: 1.8rem;
        opacity: 0.15;
        color: var(--indigo);
    }
    
    #study-calendar {
        min-height: 250px;
    }
    
    .calendar-day {
        display: inline-flex;
        justify-content: center;
        align-items: center;
        width: 2rem;
        height: 2rem;
        border-radius: 50%;
        font-size: 0.9rem;
        margin: 0 auto;
        transition: all 0.2s ease;
    }
    
    .calendar-day.today {
        background-color: var(--indigo);
        color: white;
    }
    
    .calendar-day.studied-1:not(.today) {
        background-color: #d6e9c6;
        color: var(--kuro);
    }
    
    .calendar-day.studied-2:not(.today) {
        background-color: #A4C86A;
        color: var(--kuro);
    }
    
    .calendar-day.studied-3:not(.today) {
        background-color: #8AA367;
        color: white;
    }
    
    .calendar-day.studied-4:not(.today) {
        background-color: #537A32;
        color: white;
    }
    
    .calendar-day.today.studied {
        background: linear-gradient(135deg, var(--indigo) 50%, var(--matcha) 50%);
        color: white;
    }
    
    .trophy-badge {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background-color: #FFD700;
        color: #333;
        font-weight: 600;
        font-size: 0.9rem;
    }
    
    .trophy-badge:nth-child(1) {
        background-color: #FFD700; /* Gold */
    }
    
    .trophy-badge:nth-child(2) {
        background-color: #C0C0C0; /* Silver */
    }
    
    .trophy-badge:nth-child(3) {
        background-color: #CD7F32; /* Bronze */
    }
</style>

<!-- JavaScript for charts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.7.0/chart.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    });
    
    // Render calendar
    renderCalendar();
    
    <?php if (!empty($daily_stats)): ?>
    // Render activity chart
    renderActivityChart();
    <?php endif; ?>
    
    <?php if ($total_reviews > 0): ?>
    // Render study time chart
    renderStudyTimeChart();
    <?php endif; ?>
});

function renderCalendar() {
    const calendarEl = document.getElementById('study-calendar');
    if (!calendarEl) return;
    
    const currentDate = new Date();
    const currentMonth = currentDate.getMonth();
    const currentYear = currentDate.getFullYear();
    
    // Get study dates from PHP
    const studyDates = [
        <?php 
            foreach ($study_dates as $date) {
                echo "'$date',";
            }
        ?>
    ];
    
    // Get study counts for intensity
    const studyCounts = {
        <?php 
            foreach ($study_counts as $date => $count) {
                echo "'$date': $count,";
            }
        ?>
    };
    
    // Function to calculate intensity level (1-4) based on count
    function calculateIntensity(count) {
        if (count <= 5) return 1;
        if (count <= 15) return 2;
        if (count <= 30) return 3;
        return 4;
    }
    
    // Create month and year header
    const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    const header = document.createElement('h6');
    header.className = 'text-center mb-3';
    header.textContent = `${monthNames[currentMonth]} ${currentYear}`;
    calendarEl.appendChild(header);
    
    // Create day labels
    const dayLabels = document.createElement('div');
    dayLabels.className = 'row text-center mb-2';
    const days = ['日', '月', '火', '水', '木', '金', '土'];
    
    days.forEach(day => {
        const dayEl = document.createElement('div');
        dayEl.className = 'col px-1';
        dayEl.textContent = day;
        dayEl.style.fontSize = '0.8rem';
        dayEl.style.fontWeight = '500';
        dayLabels.appendChild(dayEl);
    });
    
    calendarEl.appendChild(dayLabels);
    
    // Get first day of month and number of days
    const firstDay = new Date(currentYear, currentMonth, 1).getDay();
    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    
    // Create calendar days
    let dayCount = 1;
    let calendarHtml = '';
    
    // Create rows for the month
    for (let i = 0; i < 6; i++) {
        calendarHtml += '<div class="row mb-2">';
        
        // Create 7 columns (days of week)
        for (let j = 0; j < 7; j++) {
            // Add empty cells for days before start of month
            if (i === 0 && j < firstDay) {
                calendarHtml += '<div class="col px-1"></div>';
            } 
            // Add days of month
            else if (dayCount <= daysInMonth) {
                const date = `${currentYear}-${(currentMonth + 1).toString().padStart(2, '0')}-${dayCount.toString().padStart(2, '0')}`;
                const isStudyDay = studyDates.includes(date);
                const isToday = dayCount === currentDate.getDate() && currentMonth === currentDate.getMonth() && currentYear === currentDate.getFullYear();
                
                // Calculate intensity level (1-4) based on actual study count
                const studyIntensity = isStudyDay ? calculateIntensity(studyCounts[date] || 0) : 0;
                
                let dayHtml;
                let intensityClass = studyIntensity > 0 ? ` studied-${studyIntensity}` : '';
                
                if (isStudyDay && isToday) {
                    dayHtml = `<div class="calendar-day today studied${intensityClass}">${dayCount}</div>`;
                } else if (isStudyDay) {
                    dayHtml = `<div class="calendar-day studied${intensityClass}">${dayCount}</div>`;
                } else if (isToday) {
                    dayHtml = `<div class="calendar-day today">${dayCount}</div>`;
                } else {
                    dayHtml = `<div class="calendar-day">${dayCount}</div>`;
                }
                
                const studyCountText = studyCounts[date] ? ` - ${studyCounts[date]} cards studied` : '';
                calendarHtml += `<div class="col px-1 text-center" title="${date}${studyCountText}" data-bs-toggle="tooltip">${dayHtml}</div>`;
                dayCount++;
            } 
            // Add empty cells for days after end of month
            else {
                calendarHtml += '<div class="col px-1"></div>';
            }
        }
        
        calendarHtml += '</div>';
        
        // Stop if we've reached the end of the month
        if (dayCount > daysInMonth) {
            break;
        }
    }
    
    const calendarDays = document.createElement('div');
    calendarDays.innerHTML = calendarHtml;
    calendarEl.appendChild(calendarDays);
}

<?php if (!empty($daily_stats)): ?>
function renderActivityChart() {
    const ctx = document.getElementById('dailyActivityChart').getContext('2d');
    if (!ctx) return;
    
    // Create gradient for line chart
    const blueGradient = ctx.createLinearGradient(0, 0, 0, 300);
    blueGradient.addColorStop(0, 'rgba(62, 74, 137, 0.8)');
    blueGradient.addColorStop(1, 'rgba(62, 74, 137, 0.2)');
    
    const greenGradient = ctx.createLinearGradient(0, 0, 0, 300);
    greenGradient.addColorStop(0, 'rgba(138, 163, 103, 0.8)');
    greenGradient.addColorStop(1, 'rgba(138, 163, 103, 0.2)');
    
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
    
    const correctData = [
        <?php 
            foreach ($daily_stats as $day) {
                echo $day['correct_answers'] . ",";
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
                    backgroundColor: blueGradient,
                    borderColor: 'rgba(62, 74, 137, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y',
                    barPercentage: 0.6,
                    categoryPercentage: 0.7
                },
                {
                    label: 'Correct Answers',
                    data: correctData,
                    backgroundColor: greenGradient,
                    borderColor: 'rgba(138, 163, 103, 1)',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y',
                    barPercentage: 0.6,
                    categoryPercentage: 0.7
                },
                {
                    label: 'Accuracy (%)',
                    data: accuracyData,
                    type: 'line',
                    fill: false,
                    backgroundColor: 'rgba(255, 183, 197, 0.7)',
                    borderColor: 'rgba(255, 183, 197, 1)',
                    borderWidth: 2,
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(255, 183, 197, 1)',
                    pointBorderColor: '#fff',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 12
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.9)',
                    titleColor: '#333',
                    bodyColor: '#666',
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 6,
                    titleFont: {
                        family: "'Noto Sans JP', sans-serif",
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        family: "'Noto Sans JP', sans-serif",
                        size: 13
                    },
                    boxWidth: 10,
                    usePointStyle: true
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(200, 200, 200, 0.1)',
                        drawBorder: false
                    },
                    title: {
                        display: true,
                        text: 'Cards',
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 12
                        }
                    },
                    ticks: {
                        precision: 0,
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 11
                        }
                    }
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    max: 100,
                    grid: {
                        drawOnChartArea: false
                    },
                    title: {
                        display: true,
                        text: 'Accuracy (%)',
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 12
                        }
                    },
                    ticks: {
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 11
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 11
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>

<?php if ($total_reviews > 0): ?>
function renderStudyTimeChart() {
    const ctx = document.getElementById('studyTimeChart').getContext('2d');
    if (!ctx) return;
    
    const timeData = {
        labels: ['Morning', 'Afternoon', 'Evening'],
        datasets: [
            {
                data: [
                    <?php echo $study_times['morning']; ?>,
                    <?php echo $study_times['afternoon']; ?>,
                    <?php echo $study_times['evening']; ?>
                ],
                backgroundColor: [
                    'rgba(125, 185, 222, 0.8)',  // Morning - light blue
                    'rgba(62, 74, 137, 0.8)',    // Afternoon - indigo
                    'rgba(83, 122, 50, 0.8)'     // Evening - dark green
                ],
                borderColor: [
                    'rgba(125, 185, 222, 1)',
                    'rgba(62, 74, 137, 1)',
                    'rgba(83, 122, 50, 1)'
                ],
                borderWidth: 1
            }
        ]
    };
    
    const timeChart = new Chart(ctx, {
        type: 'doughnut',
        data: timeData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            family: "'Noto Sans JP', sans-serif",
                            size: 12
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(255, 255, 255, 0.9)',
                    titleColor: '#333',
                    bodyColor: '#666',
                    borderColor: '#e0e0e0',
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 6,
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ' + context.raw + '%';
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>
</script>

<?php include_once 'includes/footer.php'; ?>
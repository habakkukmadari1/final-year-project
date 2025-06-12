<?php
// history_overview.php (Enhanced)
require_once 'db_config.php';
require_once 'auth.php';

check_login('head_teacher');

// Fetch dropdown data
$academic_years = $conn->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);

// --- Handle AJAX for analytics ---
if (isset($_GET['action']) && $_GET['action'] == 'get_history_analytics') {
    header('Content-Type: application/json');
    $year_id = filter_input(INPUT_GET, 'year_id', FILTER_VALIDATE_INT);
    if (!$year_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid year ID.']);
        exit;
    }
    
    $analytics = ['total_students' => 0, 'graduated' => 0, 'dropped_out' => 0, 'teachers' => 0];

    // Get student stats for that year
    $stmt_stud = $conn->prepare("SELECT status, COUNT(DISTINCT student_id) as count FROM student_enrollments WHERE academic_year_id = ? GROUP BY status");
    $stmt_stud->bind_param("i", $year_id);
    $stmt_stud->execute();
    $res_stud = $stmt_stud->get_result();
    while ($row = $res_stud->fetch_assoc()) {
        $analytics['total_students'] += $row['count'];
        if ($row['status'] == 'graduated') $analytics['graduated'] = $row['count'];
        if ($row['status'] == 'dropped_out') $analytics['dropped_out'] = $row['count'];
    }
    $stmt_stud->close();
    
    // Get teacher count for that year
    $stmt_teach = $conn->prepare("SELECT COUNT(DISTINCT teacher_id) as count FROM teacher_class_subject_papers WHERE academic_year_id = ?");
    $stmt_teach->bind_param("i", $year_id);
    $stmt_teach->execute();
    $res_teach = $stmt_teach->get_result();
    $analytics['teachers'] = $res_teach->fetch_assoc()['count'] ?? 0;
    $stmt_teach->close();

    echo json_encode(['success' => true, 'data' => $analytics]);
    exit;
}


// --- Handle Form Search ---
$search_term = trim($_GET['q'] ?? '');
$search_results = [];
$is_searching = !empty($search_term);

if ($is_searching) {
    $sql = "SELECT s.id, s.full_name, s.admission_number, s.sex, s.enrollment_status, c.class_name as last_known_class
            FROM students s
            LEFT JOIN classes c ON s.current_class_id = c.id
            WHERE (s.full_name LIKE ? OR s.admission_number = ?)
            GROUP BY s.id ORDER BY s.full_name";
    
    $like_term = "%{$search_term}%";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $like_term, $search_term);
    $stmt->execute();
    $search_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School History & Archives - Head Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="style/styles.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="wrapper">
    <?php include 'nav&sidebar.php'; ?>

    <main class="p-3 p-md-4">
        <h2 class="h3 mb-4"><i class="fas fa-archive me-2 text-primary"></i>School History & Archives</h2>
        
        <ul class="nav nav-tabs mb-4" id="historyTabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" id="search-tab" data-bs-toggle="tab" data-bs-target="#search-pane">Student Search</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="analytics-history-tab" data-bs-toggle="tab" data-bs-target="#analytics-history-pane">Historical Analytics</button></li>
        </ul>

        <div class="tab-content" id="historyTabsContent">
            <div class="tab-pane fade show active" id="search-pane" role="tabpanel">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <form method="GET" action="history_overview.php">
                            <div class="input-group input-group-lg"><input type="text" name="q" class="form-control" placeholder="Search by Student Name or Admission Number..." value="<?php echo htmlspecialchars($search_term); ?>" required><button class="btn btn-primary" type="submit"><i class="fas fa-search me-2"></i>Search</button></div>
                            <small class="form-text text-muted">Find current and former students to view their complete academic history.</small>
                        </form>
                    </div>
                </div>

                <?php if ($is_searching): ?>
                <div class="card shadow-sm mt-4">
                    <div class="card-header"><h5 class="mb-0">Search Results</h5></div>
                    <div class="card-body">
                        <?php if(!empty($search_results)): ?>
                        <p class="text-muted">Found <?php echo count($search_results); ?> result(s) for "<strong><?php echo htmlspecialchars($search_term); ?></strong>".</p>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead><tr><th>Adm. No</th><th>Full Name</th><th>Sex</th><th>Last Known Class</th><th>Status</th><th>Action</th></tr></thead>
                                <tbody>
                                    <?php foreach($search_results as $student): ?>
                                    <tr><td><?php echo htmlspecialchars($student['admission_number']); ?></td><td><?php echo htmlspecialchars($student['full_name']); ?></td><td><?php echo htmlspecialchars($student['sex']); ?></td><td><?php echo htmlspecialchars($student['last_known_class'] ?? 'N/A'); ?></td><td><span class="badge bg-secondary"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ',$student['enrollment_status']))); ?></span></td><td><a href="student_details.php?id=<?php echo $student['id']; ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye me-1"></i>View Profile</a></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-warning">No records found for "<strong><?php echo htmlspecialchars($search_term); ?></strong>".</div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="analytics-history-pane" role="tabpanel">
                <div class="row align-items-center mb-4">
                    <div class="col-md-4">
                        <label for="historyYearSelect" class="form-label">Select Year to Analyze:</label>
                        <select id="historyYearSelect" class="form-select">
                            <option value="">Choose a year...</option>
                            <?php foreach($academic_years as $year): ?><option value="<?php echo $year['id']; ?>"><?php echo $year['year_name']; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div id="historyAnalyticsDashboard" class="d-none">
                    <div class="row mb-4"><div class="col-md-3"><div class="card text-center shadow-sm"><div class="card-body"><h5>Total Students</h5><h3 id="histStatTotal">...</h3></div></div></div><div class="col-md-3"><div class="card text-center shadow-sm"><div class="card-body"><h5>Graduated</h5><h3 id="histStatGraduated">...</h3></div></div></div><div class="col-md-3"><div class="card text-center shadow-sm"><div class="card-body"><h5>Dropped Out</h5><h3 id="histStatDropped">...</h3></div></div></div><div class="col-md-3"><div class="card text-center shadow-sm"><div class="card-body"><h5>Teachers</h5><h3 id="histStatTeachers">...</h3></div></div></div></div>
                </div>
                <div id="historyAnalyticsPlaceholder"><p class="text-muted text-center p-5">Please select a year to view its historical snapshot.</p></div>
                <div class="mt-4"><div style="height: 350px;"><canvas id="htHistoryChart"></canvas></div></div>
            </div>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="javascript/script.js"></script>
<script>
    let htHistoryChart = null;

    $(document).ready(function() {
        $('#historyYearSelect').on('change', function() {
            const yearId = $(this).val();
            if (yearId) {
                loadHistoryAnalytics(yearId);
            } else {
                $('#historyAnalyticsDashboard').addClass('d-none');
                $('#historyAnalyticsPlaceholder').removeClass('d-none');
            }
        });

        // This is a sample chart for the main dashboard view, not tied to a selection.
        const ctx = document.getElementById('htHistoryChart');
        if (ctx) {
            htHistoryChart = new Chart(ctx, { type: 'line', options: { responsive: true, maintainAspectRatio: false } });
            // You would populate this with overall historical data via another AJAX call on page load.
        }
    });

    function loadHistoryAnalytics(yearId) {
        $('#historyAnalyticsDashboard').addClass('d-none');
        $('#historyAnalyticsPlaceholder').html('<p class="text-muted text-center p-5"><span class="spinner-border spinner-border-sm"></span> Loading...</p>').removeClass('d-none');
        $.ajax({
            url: 'history_overview.php', type: 'GET',
            data: { action: 'get_history_analytics', year_id: yearId }, dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    $('#histStatTotal').text(data.total_students);
                    $('#histStatGraduated').text(data.graduated);
                    $('#histStatDropped').text(data.dropped_out);
                    $('#histStatTeachers').text(data.teachers);
                    $('#historyAnalyticsDashboard').removeClass('d-none');
                    $('#historyAnalyticsPlaceholder').addClass('d-none');
                } else {
                    $('#historyAnalyticsPlaceholder').html(`<p class="text-danger text-center p-5">Error: ${response.message}</p>`);
                }
            },
            error: function() { $('#historyAnalyticsPlaceholder').html('<p class="text-danger text-center p-5">Failed to load analytics data.</p>'); }
        });
    }
</script>
</body>
</html>
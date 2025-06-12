<?php
// Dos/academic_year_management.php
require_once '../db_config.php';
require_once '../auth.php';

check_login('director_of_studies');
$loggedInUserId = $_SESSION['user_id'];

// --- AJAX ACTION HANDLING (ENHANCED) ---
$action = $_REQUEST['action'] ?? null;
if ($action && $conn) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'An unknown error occurred.'];

    // Use a transaction for any write operations
    if (in_array($action, ['add_academic_year', 'edit_academic_year', 'request_set_current_year', 'cancel_request'])) {
        $conn->begin_transaction();
    }

    try {
        switch ($action) {
            case 'add_academic_year':
                $year_name = trim($_POST['year_name'] ?? '');
                $start_date = $_POST['start_date'] ?? null;
                $end_date = $_POST['end_date'] ?? null;

                if (empty($year_name) || !ctype_digit($year_name) || strlen($year_name) != 4) {
                    throw new Exception('Year must be a 4-digit number (e.g., 2024).');
                }
                if (empty($start_date) || empty($end_date)) {
                    throw new Exception('Start Date and End Date are required.');
                }
                
                $stmt_check = $conn->prepare("SELECT id FROM academic_years WHERE year_name = ?");
                $stmt_check->bind_param("s", $year_name);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    throw new Exception('An academic year with this name already exists.');
                }
                $stmt_check->close();

                $stmt_year = $conn->prepare("INSERT INTO academic_years (year_name, start_date, end_date) VALUES (?, ?, ?)");
                $stmt_year->bind_param("sss", $year_name, $start_date, $end_date);
                if (!$stmt_year->execute()) {
                    throw new Exception('Failed to add academic year: ' . $stmt_year->error);
                }
                $year_id = $stmt_year->insert_id;
                $stmt_year->close();

                $stmt_term = $conn->prepare("INSERT INTO terms (academic_year_id, term_name) VALUES (?, ?)");
                $terms = ["Term 1", "Term 2", "Term 3"];
                foreach ($terms as $term_name) {
                    $stmt_term->bind_param("is", $year_id, $term_name);
                    if (!$stmt_term->execute()) {
                        throw new Exception('Failed to add term: ' . $term_name);
                    }
                }
                $stmt_term->close();

                $response = ['success' => true, 'message' => "Academic Year {$year_name} and its 3 terms created successfully."];
                break;

            case 'get_year_details_for_edit':
                $year_id = filter_input(INPUT_GET, 'year_id', FILTER_VALIDATE_INT);
                if (!$year_id) throw new Exception('Invalid Year ID.');

                $year_details = null;
                $terms_details = [];

                $stmt_y = $conn->prepare("SELECT * FROM academic_years WHERE id = ?");
                $stmt_y->bind_param("i", $year_id);
                $stmt_y->execute();
                $year_details = $stmt_y->get_result()->fetch_assoc();
                $stmt_y->close();

                $stmt_t = $conn->prepare("SELECT * FROM terms WHERE academic_year_id = ? ORDER BY term_name ASC");
                $stmt_t->bind_param("i", $year_id);
                $stmt_t->execute();
                $terms_result = $stmt_t->get_result();
                while($row = $terms_result->fetch_assoc()) {
                    $terms_details[] = $row;
                }
                $stmt_t->close();

                // Check for missing terms
                $existing_names = array_map('strtolower', array_column($terms_details, 'term_name'));
                $default_terms = ["Term 1", "Term 2", "Term 3"];
                $missing_terms = [];
                foreach ($default_terms as $t) {
                    if (!in_array(strtolower($t), $existing_names)) $missing_terms[] = $t;
                }

                if (!$year_details) throw new Exception('Academic Year not found.');
                $response = [
                    'success' => true,
                    'year' => $year_details,
                    'terms' => $terms_details,
                    'missing_terms' => $missing_terms
                ];
                break;

            case 'edit_academic_year':
                $year_id = filter_input(INPUT_POST, 'edit_year_id', FILTER_VALIDATE_INT);
                $year_name = trim($_POST['edit_year_name'] ?? '');
                $start_date = $_POST['edit_start_date'] ?? null;
                $end_date = $_POST['edit_end_date'] ?? null;
                $terms = $_POST['terms'] ?? [];

                if (!$year_id || empty($year_name) || empty($start_date) || empty($end_date)) {
                    throw new Exception('Invalid data submitted for year update.');
                }
                
                $stmt_update_year = $conn->prepare("UPDATE academic_years SET year_name=?, start_date=?, end_date=? WHERE id=?");
                $stmt_update_year->bind_param("sssi", $year_name, $start_date, $end_date, $year_id);
                if (!$stmt_update_year->execute()) throw new Exception('Failed to update year details.');
                $stmt_update_year->close();

                $stmt_update_term = $conn->prepare("UPDATE terms SET term_name=?, start_date=?, end_date=? WHERE id=?");
                foreach($terms as $term_id => $term_data) {
                    $term_name = trim($term_data['name']);
                    $term_start = !empty($term_data['start']) ? $term_data['start'] : null;
                    $term_end = !empty($term_data['end']) ? $term_data['end'] : null;
                    if(empty($term_name)) continue; // Skip if term name is empty
                    $stmt_update_term->bind_param("sssi", $term_name, $term_start, $term_end, $term_id);
                    if (!$stmt_update_term->execute()) throw new Exception('Failed to update term ID ' . $term_id);
                }
                $stmt_update_term->close();

                // After updating existing terms...
                if (!empty($_POST['add_terms'])) {
                    $stmt_add_term = $conn->prepare("INSERT INTO terms (academic_year_id, term_name) VALUES (?, ?)");
                    foreach ($_POST['add_terms'] as $idx => $term_name) {
                        $stmt_add_term->bind_param("is", $year_id, $term_name);
                        $stmt_add_term->execute();
                    }
                    $stmt_add_term->close();
                }
                // Also, handle any new term fields added dynamically
                foreach($terms as $term_id => $term_data) {
                    if (strpos($term_id, 'new_') === 0) {
                        $term_name = trim($term_data['name']);
                        $term_start = !empty($term_data['start']) ? $term_data['start'] : null;
                        $term_end = !empty($term_data['end']) ? $term_data['end'] : null;
                        if(empty($term_name)) continue;
                        $stmt_new = $conn->prepare("INSERT INTO terms (academic_year_id, term_name, start_date, end_date) VALUES (?, ?, ?, ?)");
                        $stmt_new->bind_param("isss", $year_id, $term_name, $term_start, $term_end);
                        $stmt_new->execute();
                        $stmt_new->close();
                    }
                }

                $response = ['success' => true, 'message' => 'Academic Year and terms updated successfully.'];
                break;

            case 'request_set_current_year':
                $year_id = filter_input(INPUT_POST, 'year_id', FILTER_VALIDATE_INT);
                if (!$year_id) { throw new Exception('Invalid Year ID.'); }
                
                $stmt_pending = $conn->prepare("SELECT id FROM system_change_requests WHERE status = 'pending' AND request_type = 'set_current_year'");
                $stmt_pending->execute();
                if($stmt_pending->get_result()->num_rows > 0) { throw new Exception('Another year change request is already pending. Please wait or cancel it first.'); }
                $stmt_pending->close();
                
                $expires_at = date('Y-m-d H:i:s', strtotime('+48 hours'));
                $stmt = $conn->prepare("INSERT INTO system_change_requests (request_type, requester_user_id, target_id, expires_at) VALUES ('set_current_year', ?, ?, ?)");
                $stmt->bind_param("iis", $loggedInUserId, $year_id, $expires_at);
                if ($stmt->execute()) {
                    $response = ['success' => true, 'message' => 'Request to set new academic year has been submitted for approval.'];
                } else {
                    throw new Exception('Failed to submit request.');
                }
                $stmt->close();
                break;
            
            case 'cancel_request':
                $request_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
                if(!$request_id) { throw new Exception('Invalid Request ID'); }
                
                $stmt = $conn->prepare("UPDATE system_change_requests SET status = 'cancelled', resolved_by_user_id = ?, resolved_at = NOW() WHERE id = ? AND requester_user_id = ? AND status = 'pending'");
                $stmt->bind_param("iii", $loggedInUserId, $request_id, $loggedInUserId);
                if($stmt->execute() && $stmt->affected_rows > 0) {
                    $response = ['success' => true, 'message' => 'Request has been cancelled.'];
                } else {
                    throw new Exception('Could not cancel the request. It might have been already resolved.');
                }
                $stmt->close();
                break;

            case 'get_year_analytics':
                $year_id = filter_input(INPUT_GET, 'year_id', FILTER_VALIDATE_INT);
                $term_id = filter_input(INPUT_GET, 'term_id'); // Can be 'all' or an integer
                if (!$year_id) { $response['message'] = 'Invalid Year ID.'; break; }

                $analytics = ['enrollment_total' => 0, 'male' => 0, 'female' => 0, 'other' => 0, 'by_class' => [], 'trend' => []];

                // Enrollment by sex
                $sql_enrollment = "SELECT s.sex, COUNT(DISTINCT s.id) as count
                                   FROM students s
                                   JOIN student_enrollments se ON s.id = se.student_id
                                   WHERE se.academic_year_id = ? AND se.status IN ('enrolled', 'promoted', 'repeated')";
                // Add term filter if a specific term is selected
                if (is_numeric($term_id)) {
                    $term_dates_stmt = $conn->prepare("SELECT start_date, end_date FROM terms WHERE id = ?");
                    $term_dates_stmt->bind_param("i", $term_id);
                    $term_dates_stmt->execute();
                    $term_dates_res = $term_dates_stmt->get_result();
                    if ($term_dates_res->num_rows > 0) {
                        $term_dates = $term_dates_res->fetch_assoc();
                        if (!empty($term_dates['start_date']) && !empty($term_dates['end_date'])) {
                            $sql_enrollment .= " AND se.enrollment_date BETWEEN '{$term_dates['start_date']}' AND '{$term_dates['end_date']}'";
                        }
                    }
                    $term_dates_stmt->close();
                }
                $sql_enrollment .= " GROUP BY s.sex";
                $stmt_enrollment = $conn->prepare($sql_enrollment);
                $stmt_enrollment->bind_param("i", $year_id);
                $stmt_enrollment->execute();
                $result = $stmt_enrollment->get_result();
                while($row = $result->fetch_assoc()) {
                    if ($row['sex'] == 'Male') $analytics['male'] = $row['count'];
                    elseif ($row['sex'] == 'Female') $analytics['female'] = $row['count'];
                    else $analytics['other'] = $row['count'];
                }
                $analytics['enrollment_total'] = $analytics['male'] + $analytics['female'] + $analytics['other'];
                $stmt_enrollment->close();

                // Enrollment by class
                $sql_class = "SELECT c.class_name, COUNT(DISTINCT s.id) as count
                              FROM students s
                              JOIN student_enrollments se ON s.id = se.student_id
                              JOIN classes c ON se.class_id = c.id
                              WHERE se.academic_year_id = ? AND se.status IN ('enrolled', 'promoted', 'repeated')";
                if (is_numeric($term_id)) {
                    // Use same term filter as above
                    $term_dates_stmt = $conn->prepare("SELECT start_date, end_date FROM terms WHERE id = ?");
                    $term_dates_stmt->bind_param("i", $term_id);
                    $term_dates_stmt->execute();
                    $term_dates_res = $term_dates_stmt->get_result();
                    if ($term_dates_res->num_rows > 0) {
                        $term_dates = $term_dates_res->fetch_assoc();
                        if (!empty($term_dates['start_date']) && !empty($term_dates['end_date'])) {
                            $sql_class .= " AND se.enrollment_date BETWEEN '{$term_dates['start_date']}' AND '{$term_dates['end_date']}'";
                        }
                    }
                    $term_dates_stmt->close();
                }
                $sql_class .= " GROUP BY c.class_name ORDER BY c.class_name";
                $stmt_class = $conn->prepare($sql_class);
                $stmt_class->bind_param("i", $year_id);
                $stmt_class->execute();
                $result_class = $stmt_class->get_result();
                while($row = $result_class->fetch_assoc()) {
                    $analytics['by_class'][] = ['class_name' => $row['class_name'], 'count' => $row['count']];
                }
                $stmt_class->close();

                // Enrollment trend (by month)
                $sql_trend = "SELECT DATE_FORMAT(se.enrollment_date, '%Y-%m') as month, COUNT(DISTINCT s.id) as count
                              FROM students s
                              JOIN student_enrollments se ON s.id = se.student_id
                              WHERE se.academic_year_id = ? AND se.status IN ('enrolled', 'promoted', 'repeated')";
                if (is_numeric($term_id)) {
                    $term_dates_stmt = $conn->prepare("SELECT start_date, end_date FROM terms WHERE id = ?");
                    $term_dates_stmt->bind_param("i", $term_id);
                    $term_dates_stmt->execute();
                    $term_dates_res = $term_dates_stmt->get_result();
                    if ($term_dates_res->num_rows > 0) {
                        $term_dates = $term_dates_res->fetch_assoc();
                        if (!empty($term_dates['start_date']) && !empty($term_dates['end_date'])) {
                            $sql_trend .= " AND se.enrollment_date BETWEEN '{$term_dates['start_date']}' AND '{$term_dates['end_date']}'";
                        }
                    }
                    $term_dates_stmt->close();
                }
                $sql_trend .= " GROUP BY month ORDER BY month";
                $stmt_trend = $conn->prepare($sql_trend);
                $stmt_trend->bind_param("i", $year_id);
                $stmt_trend->execute();
                $result_trend = $stmt_trend->get_result();
                while($row = $result_trend->fetch_assoc()) {
                    $analytics['trend'][] = ['month' => $row['month'], 'count' => $row['count']];
                }
                $stmt_trend->close();

                $response = ['success' => true, 'data' => $analytics];
                break;
        }

        if (in_array($action, ['add_academic_year', 'edit_academic_year', 'request_set_current_year', 'cancel_request'])) {
            $conn->commit();
        }

    } catch (Exception $e) {
        if (in_array($action, ['add_academic_year', 'edit_academic_year', 'request_set_current_year', 'cancel_request'])) {
            $conn->rollback();
        }
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// --- REGULAR PAGE LOAD: Fetch all years AND any pending request from the DoS ---
$academic_years = [];
$pending_request = null;
if ($conn) {
    $result = $conn->query("SELECT ay.*, COUNT(t.id) as term_count 
                           FROM academic_years ay
                           LEFT JOIN terms t ON ay.id = t.academic_year_id
                           GROUP BY ay.id
                           ORDER BY ay.start_date DESC");
    if ($result) {
        $academic_years = $result->fetch_all(MYSQLI_ASSOC);
    }

    $stmt_req = $conn->prepare("SELECT scr.id, scr.expires_at, ay.year_name as target_year 
                               FROM system_change_requests scr 
                               JOIN academic_years ay ON scr.target_id = ay.id 
                               WHERE scr.requester_user_id = ? AND scr.status = 'pending' AND scr.request_type = 'set_current_year' 
                               LIMIT 1");
    $stmt_req->bind_param("i", $loggedInUserId);
    $stmt_req->execute();
    $res_req = $stmt_req->get_result();
    if($res_req->num_rows > 0) {
        $pending_request = $res_req->fetch_assoc();
    }
    $stmt_req->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Year Management - DoS Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../style/styles.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .nav-tabs .nav-link.active {
            background-color: var(--bs-info-bg-subtle);
            border-color: var(--bs-info);
            color: var(--bs-info-text-emphasis);
        }
        .year-card {
            border-left: 5px solid var(--bs-secondary-border-subtle);
            transition: all 0.2s ease-in-out;
            display: flex; flex-direction: column; height: 100%;
        }
        .year-card.current {
            border-left-color: var(--bs-success);
            background-color: var(--bs-success-bg-subtle);
        }
        .year-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 .5rem 1rem rgba(0,0,0,.1);
        }
        .year-card .card-body { flex-grow: 1; display: flex; flex-direction: column; }
        .year-card .card-footer { background-color: transparent; border-top: 1px solid var(--bs-border-color-translucent); margin-top: auto; }
        #analyticsDashboard .stat-card {
            text-align: center;
        }
    </style>
</head>
<body>
<div class="wrapper">
    <?php include 'nav&sidebar_dos.php'; ?>

    <main class="p-3 p-md-4">
        <?php if ($pending_request): ?>
        <div id="pendingChangeAlert" class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap" role="alert">
            <div>
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Pending Change:</strong> Request to set "<strong><?php echo htmlspecialchars($pending_request['target_year']); ?></strong>" as current year is awaiting approval. Expires in <strong id="countdownTimer">--:--:--</strong>.
            </div>
            <button class="btn btn-sm btn-outline-danger mt-2 mt-md-0" onclick="cancelRequest(<?php echo $pending_request['id']; ?>)">Cancel Request</button>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h3 mb-0"><i class="fas fa-calendar-alt me-2 text-info"></i>Academic Year Management</h2>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addYearModal">
                <i class="fas fa-plus me-2"></i>New Academic Year
            </button>
        </div>

        <ul class="nav nav-tabs mb-4" id="aymTabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" id="manage-tab" data-bs-toggle="tab" data-bs-target="#manage-pane">Manage Years & Terms</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="analytics-tab" data-bs-toggle="tab" data-bs-target="#analytics-pane">Analytics Dashboard</button></li>
        </ul>

        <div class="tab-content" id="aymTabsContent">
            <div class="tab-pane fade show active" id="manage-pane" role="tabpanel">
                <div class="row">
                    <?php if (!empty($academic_years)): ?>
                        <?php foreach ($academic_years as $year): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card year-card shadow-sm <?php echo $year['is_current'] ? 'current' : ''; ?>">
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo htmlspecialchars($year['year_name']); ?>
                                        <?php if ($year['is_current']): ?><span class="badge bg-success float-end">Current</span><?php endif; ?>
                                    </h5>
                                    <h6 class="card-subtitle mb-2 text-muted small"><?php echo date('d M Y', strtotime($year['start_date'])); ?> - <?php echo date('d M Y', strtotime($year['end_date'])); ?></h6>
                                    <p class="card-text">Terms Defined: <strong><?php echo $year['term_count']; ?></strong></p>
                                </div>
                                <div class="card-footer">
                                    <button class="btn btn-sm btn-outline-info me-1" onclick="openEditModal(<?php echo $year['id']; ?>)" title="Edit Year/Terms"><i class="fas fa-edit"></i> Edit</button>
                                    <?php if (!$year['is_current']): ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="requestSetCurrentYear(<?php echo $year['id']; ?>, '<?php echo htmlspecialchars($year['year_name']); ?>')" title="Request to Set as Current Year"><i class="fas fa-check-circle"></i> Request Change</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No academic years found. Please add one.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="tab-pane fade" id="analytics-pane" role="tabpanel">
                <div class="row align-items-center mb-4">
                    <div class="col-md-4">
                        <label for="analyticsYearSelect" class="form-label">Select Year:</label>
                        <select id="analyticsYearSelect" class="form-select">
                            <option value="">Choose a year...</option>
                             <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year['id']; ?>" <?php echo $year['is_current'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($year['year_name']); ?></option>
                             <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="analyticsTermSelect" class="form-label">Select Term:</label>
                        <select id="analyticsTermSelect" class="form-select" disabled><option value="all">Entire Year</option></select>
                    </div>
                </div>
                <div id="analyticsDashboard" class="d-none">
                    <div class="row mb-4"><div class="col-md-4"><div class="card stat-card shadow-sm"><div class="card-body"><h5>Total Enrollment</h5><h3 id="statTotal">...</h3></div></div></div><div class="col-md-4"><div class="card stat-card shadow-sm"><div class="card-body"><h5>Male Students</h5><h3 id="statMale">...</h3></div></div></div><div class="col-md-4"><div class="card stat-card shadow-sm"><div class="card-body"><h5>Female Students</h5><h3 id="statFemale">...</h3></div></div></div></div>
                    <div class="row"><div class="col-md-6"><h5>Enrollment by Class</h5><div class="table-responsive"><table class="table table-striped table-sm"><tbody id="classEnrollmentTable"></tbody></table></div></div><div class="col-md-6"><h5>Enrollment Trend</h5><div style="height: 300px;"><canvas id="enrollmentTrendChart"></canvas></div></div></div>
                </div>
                <div id="analyticsPlaceholder"><p class="text-muted">Please select a year to view analytics.</p></div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="addYearModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Add New Academic Year</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="addYearForm"><div class="mb-3"><label for="year_name" class="form-label">Year (e.g., 2024)</label><input type="number" class="form-control" id="year_name" name="year_name" placeholder="YYYY" required></div><div class="mb-3"><label for="start_date" class="form-label">Academic Year Start Date</label><input type="date" class="form-control" id="start_date" name="start_date" required></div><div class="mb-3"><label for="end_date" class="form-label">Academic Year End Date</label><input type="date" class="form-control" id="end_date" name="end_date" required></div><div class="alert alert-info small"><i class="fas fa-info-circle me-1"></i>Adding a year will automatically create "Term 1", "Term 2", and "Term 3". You can edit their dates later.</div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="button" class="btn btn-primary" id="saveYearBtn">Save Year</button></div></div></div>
</div>

<div class="modal fade" id="editYearModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="editYearModalTitle">Edit Academic Year</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="editYearForm"><input type="hidden" name="edit_year_id" id="edit_year_id"><fieldset class="border p-2 mb-3"><legend class="float-none w-auto px-2 fs-6">Year Details</legend><div class="row"><div class="col-md-4 mb-3"><label>Year</label><input type="number" class="form-control" name="edit_year_name" id="edit_year_name"></div><div class="col-md-4 mb-3"><label>Start Date</label><input type="date" class="form-control" name="edit_start_date" id="edit_start_date"></div><div class="col-md-4 mb-3"><label>End Date</label><input type="date" class="form-control" name="edit_end_date" id="edit_end_date"></div></div></fieldset><fieldset class="border p-2"><legend class="float-none w-auto px-2 fs-6">Term Details</legend><div id="editTermsContainer"></div></fieldset></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="button" class="btn btn-primary" id="updateYearBtn">Save Changes</button></div></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="../javascript/script.js"></script>
<script>
    $(document).ready(function() {
        $('#saveYearBtn').on('click', function() {
            $.ajax({
                url: 'academic_year_management.php', type: 'POST',
                data: $('#addYearForm').serialize() + '&action=add_academic_year', dataType: 'json',
                success: function(response) {
                    alert(response.message); if (response.success) window.location.reload();
                }, error: function() { alert('Failed to add year.'); }
            });
        });

        $('#analyticsYearSelect').on('change', function() {
            const yearId = $(this).val();
            const termSelect = $('#analyticsTermSelect');
            termSelect.prop('disabled', true).html('<option value="all">Entire Year</option>');
            if (yearId) {
                $.ajax({
                    url: 'academic_year_management.php', type: 'GET',
                    data: { action: 'get_year_details_for_edit', year_id: yearId }, dataType: 'json',
                    success: function(response) {
                        if (response.success && response.terms.length > 0) {
                            response.terms.forEach(term => termSelect.append(`<option value="${term.id}">${term.term_name}</option>`));
                            termSelect.prop('disabled', false);
                        }
                    }
                });
                loadAnalytics(yearId, 'all');
            } else {
                $('#analyticsDashboard').addClass('d-none'); $('#analyticsPlaceholder').removeClass('d-none');
            }
        });

        $('#analyticsTermSelect').on('change', function() {
            loadAnalytics($('#analyticsYearSelect').val(), $(this).val());
        });

        if ($('#analyticsYearSelect').val()) {
            $('#analyticsYearSelect').trigger('change');
        }

        $('#updateYearBtn').on('click', function() {
            $.ajax({
                url: 'academic_year_management.php', type: 'POST',
                data: $('#editYearForm').serialize() + '&action=edit_academic_year', dataType: 'json',
                success: function(response) {
                    alert(response.message); if (response.success) window.location.reload();
                }, error: function() { alert('Failed to update year.'); }
            });
        });
    });

    const editModal = new bootstrap.Modal(document.getElementById('editYearModal'));
    function openEditModal(yearId) {
        $.ajax({
            url: 'academic_year_management.php', type: 'GET',
            data: { action: 'get_year_details_for_edit', year_id: yearId }, dataType: 'json',
            success: function(response) {
                if(response.success) {
                    const { year, terms, missing_terms } = response;
                    $('#edit_year_id').val(year.id);
                    $('#editYearModalTitle').text('Edit ' + year.year_name);
                    $('#edit_year_name').val(year.year_name);
                    $('#edit_start_date').val(year.start_date);
                    $('#edit_end_date').val(year.end_date);
                    const termsContainer = $('#editTermsContainer');
                    termsContainer.empty();
                    terms.forEach(term => {
                        termsContainer.append(`
                            <div class="row align-items-center mb-2">
                                <div class="col-md-4">
                                    <input type="text" class="form-control form-control-sm" name="terms[${term.id}][name]" value="${term.term_name}">
                                </div>
                                <div class="col-md-4">
                                    <input type="date" class="form-control form-control-sm" name="terms[${term.id}][start]" value="${term.start_date || ''}">
                                </div>
                                <div class="col-md-4">
                                    <input type="date" class="form-control form-control-sm" name="terms[${term.id}][end]" value="${term.end_date || ''}">
                                </div>
                            </div>
                        `);
                    });

                    // Add buttons for missing terms
                    if (missing_terms && missing_terms.length > 0) {
                        missing_terms.forEach(function(termName) {
                            termsContainer.append(`
                                <div class="row align-items-center mb-2">
                                    <div class="col-md-8">
                                        <input type="hidden" name="add_terms[]" value="${termName}">
                                        <span class="fw-bold">${termName}</span>
                                    </div>
                                    <div class="col-md-4">
                                        <button type="button" class="btn btn-sm btn-outline-success" onclick="addTermField('${termName}')">
                                            <i class="fas fa-plus"></i> Add ${termName}
                                        </button>
                                    </div>
                                </div>
                            `);
                        });
                    }

                    editModal.show();
                } else { alert('Error: ' + response.message); }
            }
        });
    }
    
    // Helper to add a new term field dynamically
    function addTermField(termName) {
        const termsContainer = $('#editTermsContainer');
        const newId = 'new_' + Math.random().toString(36).substr(2, 9);
        termsContainer.append(`
            <div class="row align-items-center mb-2">
                <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" name="terms[${newId}][name]" value="${termName}">
                </div>
                <div class="col-md-4">
                    <input type="date" class="form-control form-control-sm" name="terms[${newId}][start]">
                </div>
                <div class="col-md-4">
                    <input type="date" class="form-control form-control-sm" name="terms[${newId}][end]">
                </div>
            </div>
        `);
    }

    function requestSetCurrentYear(yearId, yearName) {
        if (confirm(`Are you sure you want to submit a request to change the current academic year to "${yearName}"?\nThis will be sent to the Head Teacher for approval.`)) {
            $.ajax({
                url: 'academic_year_management.php', type: 'POST',
                data: { action: 'request_set_current_year', year_id: yearId }, dataType: 'json',
                success: function(r) { alert(r.message); if (r.success) window.location.reload(); }, error: function() { alert('An error occurred.'); }
            });
        }
    }

    function cancelRequest(requestId) {
        if(confirm('Cancel this pending year change request?')) {
            $.ajax({
                url: 'academic_year_management.php', type: 'POST',
                data: { action: 'cancel_request', request_id: requestId }, dataType: 'json',
                success: function(r) { alert(r.message); if (r.success) window.location.reload(); }, error: function() { alert('An error occurred.'); }
            });
        }
    }

    let trendChart = null;
    function loadAnalytics(yearId, termId = 'all') {
        $('#analyticsDashboard').addClass('d-none');
        $('#analyticsPlaceholder').html('<p class="text-muted"><span class="spinner-border spinner-border-sm"></span> Loading analytics...</p>').removeClass('d-none');
        $.ajax({
            url: 'academic_year_management.php', type: 'GET',
            data: { action: 'get_year_analytics', year_id: yearId, term_id: termId }, dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    $('#statTotal').text(data.enrollment_total);
                    $('#statMale').text(data.male);
                    $('#statFemale').text(data.female);

                    // Enrollment by class table
                    let classRows = '';
                    if (data.by_class && data.by_class.length > 0) {
                        data.by_class.forEach(row => {
                            classRows += `<tr><td>${row.class_name}</td><td>${row.count}</td></tr>`;
                        });
                    } else {
                        classRows = '<tr><td colspan="2" class="text-muted">No data</td></tr>';
                    }
                    $('#classEnrollmentTable').html(classRows);

                    // Enrollment trend chart
                    const months = data.trend.map(item => item.month);
                    const counts = data.trend.map(item => item.count);

                    if (window.trendChart) window.trendChart.destroy();
                    const ctx = document.getElementById('enrollmentTrendChart').getContext('2d');
                    window.trendChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: months,
                            datasets: [{
                                label: 'Enrollments',
                                data: counts,
                                borderColor: '#0d6efd',
                                backgroundColor: 'rgba(13,110,253,0.1)',
                                fill: true,
                                tension: 0.3
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: { display: false }
                            },
                            scales: {
                                x: { title: { display: true, text: 'Month' } },
                                y: { title: { display: true, text: 'Enrollments' }, beginAtZero: true }
                            }
                        }
                    });

                    $('#analyticsDashboard').removeClass('d-none');
                    $('#analyticsPlaceholder').addClass('d-none');
                } else {
                    $('#analyticsPlaceholder').html(`<p class="text-danger">Error: ${response.message}</p>`);
                }
            }
        });
    }

    <?php if ($pending_request): ?>
    document.addEventListener('DOMContentLoaded', function() {
        const countDownDate = new Date("<?php echo $pending_request['expires_at']; ?>").getTime();
        const timerElement = document.getElementById("countdownTimer");
        const interval = setInterval(function() {
            const distance = countDownDate - new Date().getTime();
            if (distance < 0) { clearInterval(interval); timerElement.innerHTML = "EXPIRED"; return; }
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);
            timerElement.innerHTML = `${hours}h ${minutes}m ${seconds}s`;
        }, 1000);
    });
    <?php endif; ?>
</script>
</body>
</html>
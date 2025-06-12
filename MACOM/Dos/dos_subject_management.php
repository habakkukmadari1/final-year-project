<?php
require_once '../db_config.php';
require_once '../auth.php'; // Connects to DB, starts session

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Add Subject
    if (isset($_POST['subject_name'])) {
        $subject_name = $conn->real_escape_string($_POST['subject_name']);
        $is_optional = isset($_POST['is_optional']) ? (int)$_POST['is_optional'] : 0;
        
        $stmt = $conn->prepare("INSERT INTO subjects (subject_name, is_optional) VALUES (?, ?)");
        $stmt->bind_param("si", $subject_name, $is_optional);
        
        if ($stmt->execute()) {
            $subject_id = $stmt->insert_id;
            
            // Handle paper creation if papers were submitted
            if (isset($_POST['papers']) && is_array($_POST['papers'])) {
                foreach ($_POST['papers'] as $paper_name) {
                    if (!empty($paper_name)) {
                        $paper_name = $conn->real_escape_string($paper_name);
                        $paper_stmt = $conn->prepare("INSERT INTO subject_papers (subject_id, paper_name) VALUES (?, ?)");
                        $paper_stmt->bind_param("is", $subject_id, $paper_name);
                        $paper_stmt->execute();
                        $paper_stmt->close();
                    }
                }
            }
            
            header("Location: subject_management.php");
            exit();
        }
        $stmt->close();
    }
    
    // Add Papers to Existing Subject
    if (isset($_POST['add_papers']) && isset($_POST['subject_id']) && isset($_POST['new_papers'])) {
        $subject_id = (int)$_POST['subject_id'];
        
        foreach ($_POST['new_papers'] as $paper_name) {
            if (!empty($paper_name)) {
                $paper_name = $conn->real_escape_string($paper_name);
                $stmt = $conn->prepare("INSERT INTO subject_papers (subject_id, paper_name) VALUES (?, ?)");
                $stmt->bind_param("is", $subject_id, $paper_name);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        header("Location: subject_management.php");
        exit();
    }
}

// Delete Subject
if (isset($_GET['delete_subject'])) {
    $subject_id = (int)$_GET['delete_subject'];
    $stmt = $conn->prepare("DELETE FROM subjects WHERE id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $stmt->close();
    
    // Also delete associated papers
    $stmt = $conn->prepare("DELETE FROM subject_papers WHERE subject_id = ?");
    $stmt->bind_param("i", $subject_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: subject_management.php");
    exit();
}

// Delete Paper
if (isset($_GET['delete_paper'])) {
    $paper_id = (int)$_GET['delete_paper'];
    $stmt = $conn->prepare("DELETE FROM subject_papers WHERE id = ?");
    $stmt->bind_param("i", $paper_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: subject_management.php");
    exit();
}

// Fetch all subjects with their papers
$subjects_query = "SELECT s.*, GROUP_CONCAT(sp.paper_name ORDER BY sp.id SEPARATOR '|||') as papers 
                  FROM subjects s 
                  LEFT JOIN subject_papers sp ON s.id = sp.subject_id 
                  GROUP BY s.id";
$result = $conn->query($subjects_query);

$compulsory_subjects = [];
$optional_subjects = [];

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $row['papers'] = !empty($row['papers']) ? explode('|||', $row['papers']) : [];
        
        if ($row['is_optional'] == 1) {
            $optional_subjects[] = $row;
        } else {
            $compulsory_subjects[] = $row;
        }
    }
}

// Function to get a random color
function getRandomColor() {
    $colors = [
        'var(--primary-color)',
        'var(--primary-dark)',
        'var(--accent-color)',
        '#17A2B8', // Teal
        '#6F42C1', // Purple
        '#E83E8C', // Pink
        '#20C997'  // Green
    ];
    return $colors[array_rand($colors)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Management - MACOM</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="style/styles.css" rel="stylesheet">
    <style>
        .subject-card {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            margin-bottom: 20px;
            background: var(--bg-primary);
        }
        
        .subject-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
        
        .subject-header {
            padding: 15px;
            color: white;
            position: relative;
        }
        
        .subject-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
        }
        
        .subject-actions {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        
        .subject-actions .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }
        
        .papers-list {
            padding: 15px;
            background: var(--bg-secondary);
        }
        
        .paper-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            margin-bottom: 5px;
            background: var(--bg-primary);
            border-radius: 5px;
            border-left: 3px solid var(--primary-color);
        }
        
        .add-paper-btn {
            margin-top: 10px;
            width: 100%;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 20px 0 15px;
            padding-bottom: 5px;
            border-bottom: 2px solid var(--primary-color);
            color: var(--text-primary);
        }
        
        .no-subjects {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'nav&sidebar.php'; ?>

        <!-- Page Content 
        <div id="content"> -->
    

            <!-- Main Content -->
            <div class="container-fluid dashboard-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>Subject Management</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                        <i class="fas fa-plus me-2"></i> Add Subject
                    </button>
                </div>

                <!-- Compulsory Subjects -->
                <h3 class="section-title">Compulsory Subjects</h3>
                <div class="row">
                    <?php if (!empty($compulsory_subjects)): ?>
                        <?php foreach ($compulsory_subjects as $subject): ?>
                            <div class="col-xl-3 col-lg-4 col-md-6">
                                <div class="subject-card">
                                    <div class="subject-header" style="background-color: <?php echo getRandomColor(); ?>;">
                                        <h4 class="subject-title"><?php echo htmlspecialchars($subject['subject_name']); ?></h4>
                                        <div class="subject-actions">
                                            <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#addPaperModal" 
                                                    data-subject-id="<?php echo $subject['id']; ?>" 
                                                    data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $subject['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="papers-list">
                                        <?php if (!empty($subject['papers'])): ?>
                                            <?php foreach ($subject['papers'] as $paper): ?>
                                                <div class="paper-item">
                                                    <span><?php echo htmlspecialchars($paper); ?></span>
                                                    <a href="?delete_paper=<?php echo $subject['id']; ?>" class="text-danger" onclick="return confirm('Are you sure you want to delete this paper?')">
                                                        <i class="fas fa-times"></i>
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="text-muted">No papers added yet</div>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-primary add-paper-btn" data-bs-toggle="modal" data-bs-target="#addPaperModal" 
                                                data-subject-id="<?php echo $subject['id']; ?>" 
                                                data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>">
                                            <i class="fas fa-plus me-1"></i> Add Paper
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="no-subjects">No compulsory subjects found</div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Optional Subjects -->
                <h3 class="section-title">Optional Subjects</h3>
                <div class="row">
                    <?php if (!empty($optional_subjects)): ?>
                        <?php foreach ($optional_subjects as $subject): ?>
                            <div class="col-xl-3 col-lg-4 col-md-6">
                                <div class="subject-card">
                                    <div class="subject-header" style="background-color: <?php echo getRandomColor(); ?>;">
                                        <h4 class="subject-title"><?php echo htmlspecialchars($subject['subject_name']); ?></h4>
                                        <div class="subject-actions">
                                            <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#addPaperModal" 
                                                    data-subject-id="<?php echo $subject['id']; ?>" 
                                                    data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                            <button class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $subject['id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="papers-list">
                                        <?php if (!empty($subject['papers'])): ?>
                                            <?php foreach ($subject['papers'] as $paper): ?>
                                                <div class="paper-item">
                                                    <span><?php echo htmlspecialchars($paper); ?></span>
                                                    <a href="?delete_paper=<?php echo $subject['id']; ?>" class="text-danger" onclick="return confirm('Are you sure you want to delete this paper?')">
                                                        <i class="fas fa-times"></i>
                                                    </a>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="text-muted">No papers added yet</div>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-primary add-paper-btn" data-bs-toggle="modal" data-bs-target="#addPaperModal" 
                                                data-subject-id="<?php echo $subject['id']; ?>" 
                                                data-subject-name="<?php echo htmlspecialchars($subject['subject_name']); ?>">
                                            <i class="fas fa-plus me-1"></i> Add Paper
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12">
                            <div class="no-subjects">No optional subjects found</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div class="modal fade" id="addSubjectModal" tabindex="-1" aria-labelledby="addSubjectLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addSubjectLabel">Add New Subject</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="subject_name" class="form-label">Subject Name</label>
                                    <input type="text" class="form-control" id="subject_name" name="subject_name" required>
                                </div>
                                <div class="mb-3">
                                    <label for="is_optional" class="form-label">Subject Type</label>
                                    <select class="form-control" id="is_optional" name="is_optional">
                                        <option value="0">Compulsory</option>
                                        <option value="1">Optional</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Papers (e.g., Paper 1, Paper 2, etc.)</label>
                                    <div id="paper-inputs">
                                        <div class="input-group mb-2">
                                            <input type="text" class="form-control" name="papers[]" placeholder="Paper name">
                                            <button type="button" class="btn btn-outline-secondary" onclick="addPaperField()">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Subject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Paper Modal -->
    <div class="modal fade" id="addPaperModal" tabindex="-1" aria-labelledby="addPaperLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addPaperLabel">Add Papers to <span id="modalSubjectName"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="add_papers" value="1">
                    <input type="hidden" id="modalSubjectId" name="subject_id">
                    <div class="modal-body">
                        <div id="new-paper-inputs">
                            <div class="input-group mb-2">
                                <input type="text" class="form-control" name="new_papers[]" placeholder="Paper name (e.g., Paper 1)">
                                <button type="button" class="btn btn-outline-secondary" onclick="addNewPaperField()">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Papers</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JavaScript -->
    <script src="javascript/script.js"></script>
    
    <script>
        // Add Paper Modal setup
        var addPaperModal = document.getElementById('addPaperModal');
        addPaperModal.addEventListener('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var subjectId = button.getAttribute('data-subject-id');
            var subjectName = button.getAttribute('data-subject-name');
            
            document.getElementById('modalSubjectId').value = subjectId;
            document.getElementById('modalSubjectName').textContent = subjectName;
        });
        
        // Add paper field
        function addPaperField() {
            const container = document.getElementById('paper-inputs');
            const div = document.createElement('div');
            div.className = 'input-group mb-2';
            div.innerHTML = `
                <input type="text" class="form-control" name="papers[]" placeholder="Paper name">
                <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(div);
        }
        
        // Add new paper field
        function addNewPaperField() {
            const container = document.getElementById('new-paper-inputs');
            const div = document.createElement('div');
            div.className = 'input-group mb-2';
            div.innerHTML = `
                <input type="text" class="form-control" name="new_papers[]" placeholder="Paper name (e.g., Paper 1)">
                <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            container.appendChild(div);
        }
        
        // Confirm delete
        function confirmDelete(subjectId) {
            if (confirm("Are you sure you want to delete this subject and all its papers?")) {
                window.location.href = '?delete_subject=' + subjectId;
            }
        }
    </script>
</body>
</html>"
// Initialize tooltips
var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl)
});

// Sidebar Toggle
document.getElementById('sidebarCollapse').addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('active');
});

// Theme Toggle
const themeSwitch = document.getElementById('checkbox');
const body = document.documentElement;

// Check for saved theme preference
const savedTheme = localStorage.getItem('theme');
if (savedTheme) {
    body.setAttribute('data-theme', savedTheme);
    themeSwitch.checked = savedTheme === 'dark';
}

themeSwitch.addEventListener('change', function() {
    if (this.checked) {
        body.setAttribute('data-theme', 'dark');
        localStorage.setItem('theme', 'dark');
    } else {
        body.setAttribute('data-theme', 'light');
        localStorage.setItem('theme', 'light');
    }
});

// Date and Time
function updateDateTime() {
    const now = new Date();
    const timeElement = document.getElementById('current-time');
    const dateElement = document.getElementById('current-date');

    // Update time
    timeElement.textContent = now.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit'
    });

    // Update date
    dateElement.textContent = now.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

updateDateTime();
setInterval(updateDateTime, 1000);

// Performance Chart
const performanceCtx = document.getElementById('performanceChart').getContext('2d');
const performanceChart = new Chart(performanceCtx, {
    type: 'line',
    data: {
        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
        datasets: [{
            label: 'Average Grades',
            data: [75, 78, 82, 79, 85, 88],
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37, 99, 235, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                max: 100,
                ticks: {
                    stepSize: 20
                }
            }
        }
    }
});

// Attendance Chart
const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
const attendanceChart = new Chart(attendanceCtx, {
    type: 'doughnut',
    data: {
        labels: ['Present', 'Absent', 'Late'],
        datasets: [{
            data: [85, 10, 5],
            backgroundColor: ['#2563eb', '#ef4444', '#f59e0b'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        },
        cutout: '70%'
    }
});

// Update charts on theme change
themeSwitch.addEventListener('change', function() {
    const isDark = this.checked;
    const textColor = isDark ? '#f1f5f9' : '#1e293b';

    // Update Performance Chart
    performanceChart.options.scales.x.ticks.color = textColor;
    performanceChart.options.scales.y.ticks.color = textColor;
    performanceChart.options.plugins.legend.labels.color = textColor;
    performanceChart.update();

    // Update Attendance Chart
    attendanceChart.options.plugins.legend.labels.color = textColor;
    attendanceChart.update();
});

// Mobile responsive sidebar
function checkWidth() {
    if (window.innerWidth <= 768) {
        document.getElementById('sidebar').classList.add('active');
    } else {
        document.getElementById('sidebar').classList.remove('active');
    }
}

window.addEventListener('resize', checkWidth);
checkWidth();
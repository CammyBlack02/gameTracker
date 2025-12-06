<?php require_once __DIR__ . '/includes/auth-check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Game Tracker - Completions</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .completions-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .completions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .completions-filters {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .filter-group label {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--bg-color);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .completions-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .completion-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            gap: 20px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .completion-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .completion-card.in-progress {
            border-left: 4px solid #ffa500;
        }
        
        .completion-card.completed {
            border-left: 4px solid #4caf50;
        }
        
        .completion-image {
            width: 120px;
            height: 160px;
            object-fit: cover;
            border-radius: 8px;
            background: var(--bg-secondary);
            flex-shrink: 0;
        }
        
        .completion-info {
            flex: 1;
        }
        
        .completion-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 8px;
        }
        
        .completion-title a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .completion-title a:hover {
            text-decoration: underline;
        }
        
        .completion-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 12px;
            font-size: 14px;
            color: var(--text-secondary);
        }
        
        .completion-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .completion-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .completion-status.completed {
            background: #4caf50;
            color: white;
        }
        
        .completion-status.in-progress {
            background: #ffa500;
            color: white;
        }
        
        .completion-actions {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }
        
        .link-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: var(--primary-color);
            color: white;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        @media (max-width: 768px) {
            .completion-card {
                flex-direction: column;
            }
            
            .completion-image {
                width: 100%;
                height: 200px;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <h1>Game Completions</h1>
            <div class="header-actions">
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                <button id="addCompletionBtn" class="btn btn-primary">+ Add Completion</button>
                <button id="logoutBtn" class="btn btn-secondary">Logout</button>
            </div>
        </header>
        
        <div class="completions-container">
            <div class="completions-header">
                <h2 style="margin: 0;">Your Gaming Journey</h2>
                <div class="completions-filters">
                    <div class="filter-group">
                        <label for="yearFilter">Year:</label>
                        <select id="yearFilter" class="filter-select">
                            <option value="">All Years</option>
                            <option value="2025" selected>2025</option>
                            <option value="2024">2024</option>
                            <option value="2023">2023</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="statusFilter">Status:</label>
                        <select id="statusFilter" class="filter-select">
                            <option value="all">All</option>
                            <option value="completed">Completed</option>
                            <option value="in_progress">In Progress</option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div id="completionsContent">
                <div class="loading">Loading completions...</div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Completion Modal -->
    <div id="completionModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2 id="completionModalTitle">Add Completion</h2>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <form id="completionForm">
                    <input type="hidden" id="completionId">
                    
                    <div class="form-group">
                        <label for="completionTitle">Game Title *</label>
                        <input type="text" id="completionTitle" required>
                        <div id="gameSearchResults" style="display: none; margin-top: 10px; max-height: 200px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px; background: var(--card-bg);"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="completionPlatform">Platform</label>
                        <input type="text" id="completionPlatform" list="platformsList">
                        <datalist id="platformsList"></datalist>
                    </div>
                    
                    <div class="form-group">
                        <label for="completionTimeTaken">Time Taken</label>
                        <input type="text" id="completionTimeTaken" placeholder="e.g., 40 Hours, 6 Hours?">
                    </div>
                    
                    <div class="form-group">
                        <label for="completionDateStarted">Date Started</label>
                        <input type="date" id="completionDateStarted">
                    </div>
                    
                    <div class="form-group">
                        <label for="completionDateCompleted">Date Completed</label>
                        <input type="date" id="completionDateCompleted">
                    </div>
                    
                    <div class="form-group">
                        <label for="completionNotes">Notes</label>
                        <textarea id="completionNotes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelCompletionBtn">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveCompletionBtn">Save</button>
            </div>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    <script src="js/completions.js"></script>
</body>
</html>


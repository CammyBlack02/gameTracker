<?php require_once __DIR__ . '/includes/auth-check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Game Tracker - Statistics</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .stats-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .stats-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .stats-filters {
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
        
        .toggle-buttons {
            display: flex;
            gap: 5px;
            background: var(--card-bg);
            padding: 4px;
            border-radius: 6px;
        }
        
        .toggle-btn {
            padding: 6px 16px;
            border: none;
            border-radius: 4px;
            background: transparent;
            color: var(--text-color);
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .toggle-btn.active {
            background: var(--primary-color);
            color: white;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .stat-card.featured {
            background: linear-gradient(135deg, var(--primary-color) 0%, #6c5ce7 100%);
            color: white;
            border: none;
        }
        
        .stat-card.featured .stat-label {
            color: rgba(255,255,255,0.9);
        }
        
        .stat-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 4px;
        }
        
        .stat-card.featured .stat-value {
            color: white;
        }
        
        .stat-detail {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 8px;
        }
        
        .stat-card.featured .stat-detail {
            color: rgba(255,255,255,0.8);
        }
        
        .top-items-section {
            margin-top: 40px;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .top-items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .top-item-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            position: relative;
        }
        
        .top-item-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        }
        
        .top-item-card.editable {
            cursor: pointer;
        }
        
        .top-item-image {
            width: 100%;
            height: 280px;
            object-fit: cover;
            background: var(--bg-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            font-size: 14px;
        }
        
        .top-item-info {
            padding: 16px;
        }
        
        .top-item-title {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-color);
            margin-bottom: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .top-item-platform {
            font-size: 12px;
            color: var(--text-secondary);
        }
        
        .top-item-placeholder {
            border: 2px dashed var(--border-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 280px;
            color: var(--text-secondary);
            text-align: center;
            padding: 20px;
        }
        
        .top-item-placeholder-icon {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.5;
        }
        
        .edit-top-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 12px;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .top-item-card:hover .edit-top-btn {
            opacity: 1;
        }
        
        .accessory-types-list {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 12px;
        }
        
        .accessory-type-badge {
            background: var(--primary-color);
            color: white;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 16px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .top-items-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <h1>Collection Statistics</h1>
            <div class="header-actions">
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                <button id="logoutBtn" class="btn btn-secondary">Logout</button>
            </div>
        </header>
        
        <div class="stats-container">
            <div class="stats-header">
                <h2 style="margin: 0;">Your Collection at a Glance</h2>
                <div class="stats-filters">
                    <div class="filter-group">
                        <label for="platformFilter">Platform:</label>
                        <select id="platformFilter" class="filter-select">
                            <option value="">All Platforms</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Type:</label>
                        <div class="toggle-buttons">
                            <button class="toggle-btn active" data-type="all">All</button>
                            <button class="toggle-btn" data-type="physical">Physical</button>
                            <button class="toggle-btn" data-type="digital">Digital</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="statsContent">
                <div class="loading">Loading statistics...</div>
            </div>
        </div>
    </div>
    
    <!-- Modal for selecting top items -->
    <div id="topItemsModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2 id="topItemsModalTitle">Select Top Items</h2>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Select up to 5 items for your top list. Drag to reorder.</p>
                <div style="margin-bottom: 20px;">
                    <input type="text" id="topItemsSearch" placeholder="Search by title or platform..." 
                           class="filter-select" 
                           style="width: 100%; padding: 10px; font-size: 14px;">
                </div>
                <div id="topItemsModalContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelTopItemsBtn">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveTopItemsBtn">Save</button>
            </div>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    <script src="js/stats.js"></script>
</body>
</html>


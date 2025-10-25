<?php
session_start();

// include DB (either file)
if (file_exists(__DIR__ . '/db.php')) {
    include __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/db_config.php')) {
    include __DIR__ . '/db_config.php';
} else {
    $pdo = null;
}

if(!isset($_SESSION['admin_id'])){
    header('Location: login.php');
    exit;
}

// Determine type (genre or quality)
$type = isset($_GET['type']) && $_GET['type'] === 'quality' ? 'quality' : 'genre';
$tableName = $type === 'quality' ? 'Quality' : 'Genre';
$fieldName = $type === 'quality' ? 'Quality_Type' : 'Genre_Name';
$idField   = $type === 'quality' ? 'Quality_ID' : 'Genre_ID';

$success = '';
$error = '';

// Handle delete action (GET param delete_id)
if(isset($_GET['delete_id']) && $pdo){
    $delete_id = intval($_GET['delete_id']);

    // Redirect to prevent accidental re-delete on refresh if a message is set
    $redirectUrl = basename(__FILE__) . '?' . http_build_query(['type' => $type]);
    
    try {
        // Check if item is linked to any movies (optional but good practice)
        $linkTable = $type === 'quality' ? 'Movie_Quality' : 'Movie_Genre';
        $checkMovieStmt = $pdo->prepare("SELECT COUNT(*) FROM {$linkTable} WHERE {$idField} = ?");
        $checkMovieStmt->execute([$delete_id]);
        
        if($checkMovieStmt->fetchColumn() > 0) {
            // Error if linked
            $error = 'Cannot delete ' . ucfirst($type) . ' because it is currently assigned to one or more movies.';
        } else {
            // Delete if not linked
            $delStmt = $pdo->prepare("DELETE FROM {$tableName} WHERE {$idField} = ?");
            if($delStmt->execute([$delete_id])){
                $success = ucfirst($type) . ' deleted successfully';
            } else {
                $error = 'Failed to delete ' . $type;
            }
        }
    } catch (PDOException $e) {
        error_log("Delete {$type} error: " . $e->getMessage());
        $error = "A system error occurred while deleting {$type}.";
    }

    // Pass messages via session to survive redirect
    if($success) $_SESSION['success'] = $success;
    if($error) $_SESSION['error'] = $error;

    header('Location: ' . $redirectUrl);
    exit;
}

// Pull messages from session if redirect occurred
if(isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if(isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}


// Handle form submission (add)
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add']) && $pdo){
    $name = trim($_POST['name'] ?? '');

    if(empty($name)){
        $error = ucfirst($type) . ' name cannot be empty';
    } else {
        try {
            // Check for existence before insert
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tableName} WHERE {$fieldName} = ?");
            $stmt->execute([$name]);
            if($stmt->fetchColumn() > 0){
                $error = ucfirst($type) . ' already exists';
            } else {
                $ins = $pdo->prepare("INSERT INTO {$tableName} ({$fieldName}) VALUES (?)");
                if($ins->execute([$name])){
                    $success = ucfirst($type) . ' added successfully';
                } else {
                    $error = 'Failed to add ' . $type;
                }
            }
        } catch (PDOException $e) {
            error_log("Add {$type} error: " . $e->getMessage());
            $error = "A system error occurred while adding {$type}.";
        }
    }
}

// Fetch existing list
$list = [];
$db_error = null;
if (!$pdo) {
    $db_error = "Database connection not initialized. Check db.php or db_config.php.";
} else {
    try {
        $stmt = $pdo->query("SELECT * FROM {$tableName} ORDER BY {$fieldName} ASC");
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Fetch {$type} list error: " . $e->getMessage());
        $db_error = "Failed to load list.";
    }
}

// Pass data to JS
$initial_data = [
    'admin_name' => $_SESSION['admin_name'] ?? 'Admin',
    'type' => $type,
    'list' => $list,
    'success' => $success,
    'error' => $error,
    'db_error' => $db_error,
];
$js_data = json_encode($initial_data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?php echo ucfirst($type); ?> Management - MovieHub</title>

  <script src="https://cdn.tailwindcss.com"></script>

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>

  <script crossorigin src="https://unpkg.com/react@18/umd/react.development.js"></script>
  <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.development.js"></script>
  <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

  <script>
    // --- UNIQUE COLOR CONFIGURATION (Soft Gold/Deep Blue Theme) ---
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    'primary-dark': '#0b141d', 
                    'secondary-mid': '#152230', 
                    'border-subtle': '#2c3a4d',
                    'accent-gold': '#fcd34d', 
                    'accent-focus': '#f59e0b',
                    'muted-gray': '#94a3b8' 
                },
                fontFamily: {
                    sans: ['Inter', 'sans-serif']
                }
            }
        }
    }
  </script>

  <style>
    /* --- UNIQUE STYLES: Cyber Grid/Data Panel Theme --- */
    body { 
        font-family: 'Inter', sans-serif; 
        background: #0b141d; 
        color: #e6eef3; 
    }
    .data-panel { 
        background: #152230; 
        border: 2px solid #2c3a4d;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
    }
    .data-input-field {
        background: #0b141d; /* primary-dark */
        border: 1px solid #2c3a4d; /* border-subtle */
        color: #e6eef3;
        transition: border-color 0.2s;
    }
    .data-input-field:focus {
        border-color: #fcd34d; /* accent-gold */
        box-shadow: 0 0 5px rgba(252, 211, 77, 0.4);
    }
    /* Style for list items */
    .list-item-panel:hover {
        background-color: #152230; /* secondary-mid on hover */
        border-color: #fcd34d; /* accent-gold border on hover */
    }
  </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">
  <div id="root" class="w-full max-w-3xl"></div>

  <script>window.serverData = <?php echo $js_data; ?>;</script>

  <script type="text/babel">
    const { useState } = React;
    const data = window.serverData || {};
    const adminName = data.admin_name || 'Admin';
    const type = data.type || 'genre';
    const list = data.list || []; // Global list variable holds initial data
    const serverSuccess = data.success || '';
    const serverError = data.error || '';
    const dbError = data.db_error || null;

    function ManageType() {
      const [name, setName] = useState('');
      // FIX APPLIED HERE: Define 'items' using useState to hold the list data
      const [items, setItems] = useState(list); 
      
      const title = type === 'quality' ? 'Quality' : 'Genre';

      const typeIcon = type === 'quality' ? 'fas fa-layer-group' : 'fas fa-tags';

      return (
        <div className="w-full">
          <div className="data-panel rounded-2xl p-6 shadow-2xl">
            <header className="flex items-center justify-between mb-6 pb-4 border-b border-border-subtle">
                <div className="flex items-center gap-3">
                    <i className={`${typeIcon} text-accent-gold text-2xl`}></i>
                    <div>
                        <h1 className="text-2xl font-extrabold text-white">Manage <span className="text-accent-gold">{title}</span></h1>
                        <p className="text-sm text-muted-gray mt-1">Add or remove entries for better movie categorization.</p>
                    </div>
                </div>
                <div className="flex gap-2">
                    <a href="dashboard.php" className="px-3 py-2 rounded-lg bg-border-subtle hover:bg-neutral-600 text-white text-sm flex items-center gap-2 transition">
                        <i className="fas fa-arrow-left"></i> Dashboard
                    </a>
                    <a href="add_movie.php" className="px-3 py-2 rounded-lg bg-accent-gold text-primary-dark font-semibold text-sm hover:bg-accent-focus flex items-center gap-2 transition">
                        <i className="fas fa-plus-circle"></i> Add Movie
                    </a>
                </div>
            </header>


            {/* messages */}
            {dbError && (
              <div className="mb-4 p-3 rounded-lg bg-red-900/80 border border-red-700 text-red-200 shadow-md">
                <strong className="block">DATABASE ERROR</strong>
                <div className="text-sm mt-1">{dbError}</div>
              </div>
            )}

            {(serverError) && (
              <div className="mb-4 p-3 rounded-lg bg-red-900/80 border border-red-700 text-red-200 shadow-md">
                <div className="text-sm">{serverError}</div>
              </div>
            )}

            {(serverSuccess) && (
              <div className="mb-4 p-3 rounded-lg bg-green-900/80 border border-green-700 text-green-200 shadow-md">
                <div className="text-sm">{serverSuccess}</div>
              </div>
            )}

            {/* Add form (posts to same PHP) */}
            <form method="post" className="mb-6 bg-primary-dark p-4 rounded-lg border border-border-subtle">
              <div className="flex gap-3 flex-col sm:flex-row items-stretch">
                <input
                  name="name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder={`Enter new ${title} name...`}
                  className="flex-1 px-4 py-3 rounded-lg data-input-field"
                  required
                />
                <input type="hidden" name="add" value="1" />
                <button type="submit" className="px-6 py-3 rounded-lg bg-accent-gold text-primary-dark font-bold hover:bg-accent-focus transition">
                  <i className="fas fa-plus-circle mr-2"></i> Add {title}
                </button>
              </div>
            </form>

            <hr className="border-border-subtle my-6" />

            <h3 className="text-lg text-white font-semibold mb-3">Existing {title}s ({items.length})</h3>

            {items.length === 0 ? (
              <div className="p-4 rounded-lg bg-border-subtle text-muted-gray text-center border border-dashed border-neutral-600">No {title.toLowerCase()}s have been added yet.</div>
            ) : (
              <ul className="space-y-3 max-h-96 overflow-y-auto pr-2">
                {items.map((it) => {
                  const display = type === 'quality' ? it['Quality_Type'] : it['Genre_Name'];
                  const id = it[type === 'quality' ? 'Quality_ID' : 'Genre_ID'];
                  return (
                    <li key={id} className="list-item-panel flex items-center justify-between p-4 rounded-lg bg-primary-dark border border-border-subtle transition duration-200">
                      <div className="text-white font-medium">{display}</div>
                      <div className="flex items-center gap-2">
                        <a
                          href={`?type=${type}&delete_id=${id}`}
                          onClick={(e) => {
                            if(!confirm(`WARNING: Deleting '${display}' will break any movie records currently using it. Are you sure you want to proceed?`)) e.preventDefault();
                          }}
                          className="px-3 py-2 rounded-lg bg-red-800 text-red-100 text-sm hover:bg-red-700 flex items-center gap-2 transition"
                        >
                          <i className="fas fa-trash-alt"></i> Delete
                        </a>
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}

            <div className="mt-6 text-sm text-muted-gray border-t border-border-subtle pt-4">
                <span className="block text-accent-gold font-medium mb-1"><i className="fas fa-info-circle mr-1"></i> Management Notes:</span>
                <p>1. Names are case-sensitive on the front-end. Maintain consistent capitalization.</p>
                <p>2. Deleting an item currently used by a movie will cause display errors on the public site.</p>
            </div>
          </div>
        </div>
      );
    }

    ReactDOM.createRoot(document.getElementById('root')).render(<ManageType />);
  </script>
</body>
</html>
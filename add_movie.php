<?php
session_start();
// Include DB configuration
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

$success = '';
$error = '';

// Fetch genres and qualities
if ($pdo) {
    try {
        $genres = $pdo->query("SELECT * FROM Genre ORDER BY Genre_Name ASC")->fetchAll();
        $qualities = $pdo->query("SELECT * FROM Quality ORDER BY Quality_Type ASC")->fetchAll();
    } catch (PDOException $e) {
        $error = "Database fetch error: " . $e->getMessage();
        $genres = [];
        $qualities = [];
    }
} else {
    $genres = [];
    $qualities = [];
    $error = "Database connection failed.";
}


// Handle form submission
if(isset($_POST['add']) && $pdo){
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $release_year = intval($_POST['release_year']);
    $file_url = trim($_POST['file_url']);
    $selected_genres = isset($_POST['genres']) ? $_POST['genres'] : [];
    $selected_qualities = isset($_POST['qualities']) ? $_POST['qualities'] : [];
    $poster_path = null;

    // 1. Handle File Upload
    if(isset($_FILES['poster']) && $_FILES['poster']['error'] === 0){
        // Ensure the 'poster' directory exists
        if (!is_dir('poster')) {
            mkdir('poster', 0777, true);
        }
        
        // Sanitize and create unique filename
        $original_name = basename($_FILES['poster']['name']);
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $safe_name = preg_replace('/[^a-zA-Z0-9\._-]/', '', pathinfo($original_name, PATHINFO_FILENAME));
        $poster_name = time() . '_' . substr(md5($safe_name), 0, 8) . '.' . $extension;
        $poster_path = 'poster/' . $poster_name;
        
        if(!move_uploaded_file($_FILES['poster']['tmp_name'], $poster_path)){
            $error = 'Failed to upload poster.';
        }
    } else {
        // If file is missing or has an error, show a more specific warning
        if ($_FILES['poster']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please select a poster image to upload.';
        } else {
            $error = 'Poster file upload failed with error code: ' . $_FILES['poster']['error'];
        }
    }

    // 2. Insert Movie Data
    if(empty($error)){
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("INSERT INTO Movie (Title, Description, Release_Year, File_URL, Poster_URL) VALUES (?,?,?,?,?)");
            if($stmt->execute([$title, $description, $release_year, $file_url, $poster_path])){
                $movie_id = $pdo->lastInsertId();
                
                // 3. Insert Genres
                $stmt_genre = $pdo->prepare("INSERT INTO Movie_Genre (Movie_ID, Genre_ID) VALUES (?,?)");
                foreach($selected_genres as $g){
                    $stmt_genre->execute([$movie_id, $g]);
                }
                
                // 4. Insert Qualities
                $stmt_quality = $pdo->prepare("INSERT INTO Movie_Quality (Movie_ID, Quality_ID) VALUES (?,?)");
                foreach($selected_qualities as $q){
                    $stmt_quality->execute([$movie_id, $q]);
                }

                $pdo->commit();
                $success = 'Movie **' . htmlspecialchars($title) . '** added successfully.';
                // Clear POST data to prevent re-submission if success is shown
                $_POST = []; 
            } else {
                $pdo->rollBack();
                $error = 'Failed to insert movie record.';
                // Clean up uploaded poster if insertion failed
                if ($poster_path && file_exists($poster_path)) @unlink($poster_path);
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Database transaction failed: ' . $e->getMessage();
            // Clean up uploaded poster if transaction failed
            if ($poster_path && file_exists($poster_path)) @unlink($poster_path);
            error_log("Add movie transaction error: " . $e->getMessage());
        }
    }
}

// Prepare data for React rendering
$initial_data = [
    'admin_name' => $_SESSION['admin_name'] ?? 'Admin',
    'genres' => $genres,
    'qualities' => $qualities,
    'success' => $success,
    'error' => $error
];
$js_data = json_encode($initial_data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Add Movie - MovieHub</title>

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
.nav-active {
    background-color: #fcd34d !important;
    color: #0b141d !important;
    font-weight: 700;
}
/* Custom style for high-contrast inputs */
.data-input-field {
    background: #152230; /* secondary-mid */
    border: 1px solid #2c3a4d; /* border-subtle */
    color: #e6eef3;
    transition: border-color 0.2s;
}
.data-input-field:focus {
    border-color: #fcd34d; /* accent-gold */
    box-shadow: 0 0 5px rgba(252, 211, 77, 0.4);
}

/* Custom styling for file input to match the dark theme */
input[type="file"]::file-selector-button {
    background: #2c3a4d;
    color: #fcd34d;
    border: none;
    padding: 0.5rem 1rem;
    margin-right: 1rem;
    border-radius: 0.375rem; /* rounded-lg */
    cursor: pointer;
    transition: background 0.2s;
}
input[type="file"]::file-selector-button:hover {
    background: #3f4e64;
}

/* Custom styling for multiple select fields */
.data-select-field {
    height: 12rem; /* Increase height for multi-select visibility */
}

/* Style for the tips */
.input-tip {
    font-size: 0.75rem; /* text-xs */
    color: #fcd34d; /* accent-gold */
    opacity: 0.7;
    margin-top: 0.25rem;
    display: block;
}
</style>
</head>
<body class="min-h-screen">

<div id="root" class="min-h-screen"></div>

<script>
window.serverData = <?php echo $js_data; ?>;
</script>

<script type="text/babel">
const { useState } = React;
const data = window.serverData || {};

function AddMoviePage() {
    const [genres] = useState(data.genres || []);
    const [qualities] = useState(data.qualities || []);
    const [success] = useState(data.success || '');
    const [error] = useState(data.error || '');
    const [adminName] = useState(data.admin_name || 'Admin');

    return (
      <div className="min-h-screen flex">
        {/* Sidebar (Consistent with Dashboard) */}
        <aside className="w-64 hidden md:flex flex-col p-6 space-y-4 bg-secondary-mid border-r border-border-subtle shadow-lg sticky top-0 h-screen">
            <div className="flex items-center gap-3">
                <div className="w-8 h-8 rounded-full bg-accent-gold shadow-md flex items-center justify-center text-primary-dark font-bold text-lg">M</div>
                <div className="text-2xl font-extrabold text-white">Movie<span className="text-accent-gold">Hub</span></div>
            </div>

            <nav className="flex flex-col mt-4 space-y-1">
                <a href="dashboard.php" className="px-3 py-2 rounded-lg text-sm text-white hover:bg-secondary-mid/50 flex items-center gap-3 transition">
                    <i className="fas fa-tachometer-alt"></i> Dashboard
                </a>
                {/* Active link style */}
                <a href="add_movie.php" className="nav-active px-3 py-2 rounded-lg text-sm flex items-center gap-3 transition">
                    <i className="fas fa-plus-circle"></i> Add Movie
                </a>
                <a href="add_genre.php?type=genre" className="px-3 py-2 rounded-lg text-sm text-white hover:bg-secondary-mid/50 flex items-center gap-3 transition">
                    <i className="fas fa-tags"></i> Manage Genres
                </a>
                <a href="add_genre.php?type=quality" className="px-3 py-2 rounded-lg text-sm text-white hover:bg-secondary-mid/50 flex items-center gap-3 transition">
                    <i className="fas fa-layer-group"></i> Manage Quality
                </a>
                <a href="logout.php" className="mt-4 px-3 py-2 rounded-lg text-sm text-red-400 border border-red-800 hover:bg-red-900/40 flex items-center gap-3 transition">
                    <i className="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>

            <div className="mt-auto text-sm text-muted-gray pt-4 border-t border-border-subtle">
                <div className="mb-1 text-xs text-white/70">Administrator Access</div>
                <div className="font-semibold text-accent-gold">{adminName}</div>
            </div>
        </aside>

        {/* Main Content */}
        <main className="flex-1 p-6">
          <header className="flex items-center justify-between mb-8 pb-4 border-b border-border-subtle">
            <h1 className="text-3xl font-extrabold text-white">New Movie <span className="text-accent-gold">Input</span></h1>
            <div className="flex gap-2">
              <a href="dashboard.php" className="px-4 py-2 rounded-lg bg-secondary-mid text-white hover:bg-neutral-600 flex items-center gap-2 transition border border-border-subtle">
                <i className="fas fa-arrow-left"></i> Back to Grid
              </a>
              <a href="index.php" className="px-4 py-2 rounded-lg bg-accent-gold text-primary-dark font-semibold hover:bg-accent-focus flex items-center gap-2 transition">
                <i className="fas fa-home"></i> Visit Site
              </a>
            </div>
          </header>

          {/* Alerts */}
          {error && <div className="bg-red-900/80 border border-red-600 text-red-200 p-4 rounded-lg mb-6 shadow-xl">{error}</div>}
          {success && <div className="bg-green-900/80 border border-green-600 text-green-200 p-4 rounded-lg mb-6 shadow-xl">{success}</div>}

          {/* Form - Styled as a Data Entry Panel */}
          <form method="post" encType="multipart/form-data" className="space-y-6 bg-secondary-mid p-8 rounded-xl border border-border-subtle shadow-2xl">
            
            {/* Input Group: Title & Year */}
            <div className="grid md:grid-cols-3 gap-6">
                <div className="md:col-span-2">
                    <label className="block text-sm font-medium text-accent-gold mb-1">Movie Title</label>
                    <input type="text" name="title" placeholder="Title of the Movie" className="w-full p-3 rounded-lg data-input-field" required />
                </div>
                <div>
                    <label className="block text-sm font-medium text-accent-gold mb-1">Release Year</label>
                    <input type="number" name="release_year" placeholder="e.g., 2023" min="1888" max={new Date().getFullYear()} className="w-full p-3 rounded-lg data-input-field" required />
                    <span className="input-tip"><i className="fas fa-info-circle mr-1"></i> Use a four-digit year (e.g., 1999).</span>
                </div>
            </div>

            {/* Input Group: Description */}
            <div>
                <label className="block text-sm font-medium text-accent-gold mb-1">Synopsis / Description</label>
                <textarea name="description" rows="4" placeholder="Brief summary of the movie" className="w-full p-3 rounded-lg data-input-field"></textarea>
            </div>
            
            {/* Input Group: Poster & File URL */}
            <div className="grid md:grid-cols-2 gap-6">
                <div>
                    <label className="block text-sm font-medium text-accent-gold mb-1">Poster Image (Required)</label>
                    <input type="file" name="poster" accept="image/*" className="w-full rounded-lg data-input-field" required />
                    <span className="input-tip"><i className="fas fa-image mr-1"></i> Tip: Use an image with a vertical aspect ratio (e.g., 2:3 or 3:4).</span>
                </div>
                <div>
                    <label className="block text-sm font-medium text-accent-gold mb-1">Movie File URL</label>
                    <input type="url" name="file_url" placeholder="Direct link to the movie file (e.g., Google Drive)" className="w-full p-3 rounded-lg data-input-field" required />
                    <span className="input-tip"><i className="fas fa-link mr-1"></i> Ensure this is a direct link for streaming/download, not a standard web page.</span>
                </div>
            </div>

            {/* Input Group: Genres & Qualities (Multi-select) */}
            <div className="grid md:grid-cols-2 gap-6">
                <div>
                    <label className="block text-sm font-medium text-accent-gold mb-1">Select Genres</label>
                    <select name="genres[]" multiple className="data-select-field mt-1 w-full p-3 rounded-lg data-input-field" required>
                        {genres.map(g => <option key={g.Genre_ID} value={g.Genre_ID}>{g.Genre_Name}</option>)}
                    </select>
                    <span className="input-tip"><i className="fas fa-hand-pointer mr-1"></i> Hold Ctrl (Windows) or Cmd (Mac) to select multiple items.</span>
                </div>
                <div>
                    <label className="block text-sm font-medium text-accent-gold mb-1">Select Qualities</label>
                    <select name="qualities[]" multiple className="data-select-field mt-1 w-full p-3 rounded-lg data-input-field" required>
                        {qualities.map(q => <option key={q.Quality_ID} value={q.Quality_ID}>{q.Quality_Type}</option>)}
                    </select>
                    <span className="input-tip"><i className="fas fa-hand-pointer mr-1"></i> Select all relevant quality types for the movie file.</span>
                </div>
            </div>
            
            {/* Submit Button */}
            <button type="submit" name="add" className="w-full bg-accent-gold hover:bg-accent-focus text-primary-dark font-bold text-lg p-3 rounded-lg flex items-center justify-center gap-3 transition shadow-xl">
              <i className="fas fa-database"></i> COMMIT DATA TO CATALOG
            </button>

          </form>
        </main>
      </div>
    );
}

ReactDOM.createRoot(document.getElementById('root')).render(<AddMoviePage />);
</script>

</body>
</html>
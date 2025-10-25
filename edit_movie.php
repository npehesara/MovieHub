<?php
session_start();
// Include DB configuration (using a more robust check)
if (file_exists(__DIR__ . '/db.php')) {
    include __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/db_config.php')) {
    include __DIR__ . '/db_config.php';
} else {
    // Attempt to set $pdo to null if includes fail, though script execution might halt without DB.
    $pdo = null;
}

if(!isset($_SESSION['admin_id'])){
    header('Location: login.php');
    exit;
}

if(!$pdo) {
    // Handle case where DB connection failed before reaching data fetch
    exit("Database connection required but failed to initialize.");
}

if(!isset($_GET['id'])){
    header('Location: dashboard.php');
    exit;
}

$movie_id = intval($_GET['id']);
$error = '';
$success = '';

// Fetch movie details
$stmt = $pdo->prepare("SELECT * FROM Movie WHERE Movie_ID=?");
$stmt->execute([$movie_id]);
$movie = $stmt->fetch();

if(!$movie){
    header('Location: dashboard.php');
    exit;
}

// Fetch all genres and qualities
$genres = $pdo->query("SELECT * FROM Genre ORDER BY Genre_Name ASC")->fetchAll();
$qualities = $pdo->query("SELECT * FROM Quality ORDER BY Quality_Type ASC")->fetchAll();

// Fetch selected genres and qualities
$stmt = $pdo->prepare("SELECT Genre_ID FROM Movie_Genre WHERE Movie_ID=?");
$stmt->execute([$movie_id]);
$selected_genres = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT Quality_ID FROM Movie_Quality WHERE Movie_ID=?");
$stmt->execute([$movie_id]);
$selected_qualities = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle form submission
if(isset($_POST['update'])){
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $release_year = intval($_POST['release_year']);
    $file_url = trim($_POST['file_url']);

    // Handle poster upload
    $poster_path = $movie['Poster_URL'] ?? ''; // default existing poster
    
    if(isset($_FILES['poster']) && $_FILES['poster']['error'] === 0){
        // Ensure the 'poster' directory exists
        if (!is_dir('poster')) {
            mkdir('poster', 0777, true);
        }

        // Sanitize and create unique filename
        $original_name = basename($_FILES['poster']['name']);
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $safe_name = preg_replace('/[^a-zA-Z0-9\._-]/', '', pathinfo($original_name, PATHINFO_FILENAME));
        $poster_name_unique = time() . '_' . substr(md5($safe_name), 0, 8) . '.' . $extension;
        $poster_path_new = 'poster/' . $poster_name_unique;

        if(move_uploaded_file($_FILES['poster']['tmp_name'], $poster_path_new)){
            // Delete old poster safely
            if(!empty($poster_path) && file_exists($poster_path)){
                @unlink($poster_path); // Use @ to suppress file not found errors just in case
            }
            $poster_path = $poster_path_new;
        } else {
            $error = 'Failed to upload new poster.';
        }
    }

    $selected_genres_post = isset($_POST['genres']) ? $_POST['genres'] : [];
    $selected_qualities_post = isset($_POST['qualities']) ? $_POST['qualities'] : [];

    if(empty($error)){
        try {
            $pdo->beginTransaction();
            
            // 1. Update movie main data
            $stmt = $pdo->prepare("UPDATE Movie SET Title=?, Description=?, Release_Year=?, File_URL=?, Poster_URL=? WHERE Movie_ID=?");
            if(!$stmt->execute([$title, $description, $release_year, $file_url, $poster_path, $movie_id])){
                throw new PDOException("Failed to update main movie record.");
            }

            // 2. Update genres
            $pdo->prepare("DELETE FROM Movie_Genre WHERE Movie_ID=?")->execute([$movie_id]);
            $stmt_genre = $pdo->prepare("INSERT INTO Movie_Genre (Movie_ID, Genre_ID) VALUES (?,?)");
            foreach($selected_genres_post as $g){
                $stmt_genre->execute([$movie_id, $g]);
            }

            // 3. Update qualities
            $pdo->prepare("DELETE FROM Movie_Quality WHERE Movie_ID=?")->execute([$movie_id]);
            $stmt_quality = $pdo->prepare("INSERT INTO Movie_Quality (Movie_ID, Quality_ID) VALUES (?,?)");
            foreach($selected_qualities_post as $q){
                $stmt_quality->execute([$movie_id, $q]);
            }

            $pdo->commit();
            $success = 'Movie **' . htmlspecialchars($title) . '** updated successfully.';
            
            // Re-fetch movie and selected lists to show current data after update
            $stmt = $pdo->prepare("SELECT * FROM Movie WHERE Movie_ID=?");
            $stmt->execute([$movie_id]);
            $movie = $stmt->fetch();
            
            $stmt = $pdo->prepare("SELECT Genre_ID FROM Movie_Genre WHERE Movie_ID=?");
            $stmt->execute([$movie_id]);
            $selected_genres = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $stmt = $pdo->prepare("SELECT Quality_ID FROM Movie_Quality WHERE Movie_ID=?");
            $stmt->execute([$movie_id]);
            $selected_qualities = $stmt->fetchAll(PDO::FETCH_COLUMN);

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Database transaction failed: ' . $e->getMessage();
            error_log("Edit movie transaction error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Movie: <?php echo htmlspecialchars($movie['Title'] ?? 'Movie'); ?> - MovieHub</title>

<script src="https://cdn.tailwindcss.com"></script>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>

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
    min-height: 10rem; /* Ensure height for multi-select visibility */
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

<div class="container mx-auto mt-10 p-4">
    <div class="max-w-4xl mx-auto bg-secondary-mid p-8 rounded-xl border border-border-subtle shadow-2xl">
        
        <header class="flex items-center justify-between mb-8 pb-4 border-b border-border-subtle">
            <h1 class="text-3xl font-extrabold text-white">
                <i class="fas fa-edit mr-2 text-accent-gold"></i>
                Editing <span class="text-accent-gold">Movie</span>
            </h1>
            <div class="flex gap-2">
              <a href="dashboard.php" class="px-4 py-2 rounded-lg bg-primary-dark text-white hover:bg-neutral-600 flex items-center gap-2 transition border border-border-subtle">
                <i class="fas fa-arrow-left"></i> Dashboard
              </a>
            </div>
        </header>

        <?php if($error): ?>
            <div class="bg-red-900/80 border border-red-600 text-red-200 p-4 rounded-lg mb-6 shadow-xl"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="bg-green-900/80 border border-green-600 text-green-200 p-4 rounded-lg mb-6 shadow-xl"><?php echo $success; ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="space-y-6">
            
            <div class="grid md:grid-cols-3 gap-6">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-accent-gold mb-1" for="title">Movie Title</label>
                    <input type="text" id="title" name="title" placeholder="Title of the Movie" value="<?php echo htmlspecialchars($movie['Title']); ?>" class="w-full p-3 rounded-lg data-input-field" required />
                </div>
                <div>
                    <label class="block text-sm font-medium text-accent-gold mb-1" for="release_year">Release Year</label>
                    <input type="number" id="release_year" name="release_year" placeholder="e.g., 2023" min="1888" max="<?php echo date('Y'); ?>" value="<?php echo $movie['Release_Year']; ?>" class="w-full p-3 rounded-lg data-input-field" required />
                    <span class="input-tip"><i class="fas fa-info-circle mr-1"></i> Use a four-digit year (e.g., 1999).</span>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-accent-gold mb-1" for="description">Synopsis / Description</label>
                <textarea id="description" name="description" rows="4" placeholder="Brief summary of the movie" class="w-full p-3 rounded-lg data-input-field"><?php echo htmlspecialchars($movie['Description']); ?></textarea>
            </div>
            
            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-accent-gold mb-1">Poster Image (Leave blank to keep current)</label>
                    <div class="mb-3">
                         <?php if(!empty($movie['Poster_URL']) && file_exists($movie['Poster_URL'])): ?>
                            <div class="flex items-center gap-3 p-3 bg-primary-dark rounded-lg border border-border-subtle">
                                <img src="<?php echo htmlspecialchars($movie['Poster_URL']); ?>" width="80" class="rounded shadow-md" alt="Current Poster">
                                <span class="text-sm text-muted-gray">Current poster is visible. Uploading a new file will replace it.</span>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-red-400 p-2 border border-red-700 rounded-lg bg-red-900/20">No current poster found.</div>
                        <?php endif; ?>
                    </div>
                    <input type="file" name="poster" accept="image/*" class="w-full rounded-lg data-input-field" />
                    <span class="input-tip"><i class="fas fa-image mr-1"></i> Tip: Use an image with a **vertical aspect ratio** (e.g., 2:3 or 3:4).</span>
                </div>
                <div>
                    <label class="block text-sm font-medium text-accent-gold mb-1" for="file_url">Movie File URL</label>
                    <input type="url" id="file_url" name="file_url" placeholder="Direct link to the movie file (e.g., Google Drive)" value="<?php echo htmlspecialchars($movie['File_URL']); ?>" class="w-full p-3 rounded-lg data-input-field" required />
                    <span class="input-tip"><i class="fas fa-link mr-1"></i> Ensure this is a **direct link** for streaming/download, not a standard web page.</span>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-accent-gold mb-1" for="genres">Select Genres</label>
                    <select id="genres" name="genres[]" multiple class="data-select-field mt-1 w-full p-3 rounded-lg data-input-field" required>
                        <?php foreach($genres as $g): ?>
                            <option value="<?php echo $g['Genre_ID']; ?>" <?php echo in_array($g['Genre_ID'], $selected_genres) ? 'selected' : ''; ?>><?php echo $g['Genre_Name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="input-tip"><i class="fas fa-hand-pointer mr-1"></i> Hold **Ctrl** (Windows) or **Cmd** (Mac) to select multiple items.</span>
                </div>
                <div>
                    <label class="block text-sm font-medium text-accent-gold mb-1" for="qualities">Select Qualities</label>
                    <select id="qualities" name="qualities[]" multiple class="data-select-field mt-1 w-full p-3 rounded-lg data-input-field" required>
                        <?php foreach($qualities as $q): ?>
                            <option value="<?php echo $q['Quality_ID']; ?>" <?php echo in_array($q['Quality_ID'], $selected_qualities) ? 'selected' : ''; ?>><?php echo $q['Quality_Type']; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="input-tip"><i class="fas fa-hand-pointer mr-1"></i> Select all relevant quality types for the movie file.</span>
                </div>
            </div>
            
            <button type="submit" name="update" class="w-full bg-accent-gold hover:bg-accent-focus text-primary-dark font-bold text-lg p-3 rounded-lg flex items-center justify-center gap-3 transition shadow-xl">
              <i class="fas fa-sync-alt"></i> EXECUTE MOVIE UPDATE
            </button>

        </form>
    </div>
</div>

</body>
</html>
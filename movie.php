<?php
// movie.php - show movie profile (Unique "Shattered Glass" Redesign)
include 'db.php';

// Ensure redirects go back to index.php
if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$movie_id = intval($_GET['id']);

// Fetch movie
$stmt = $pdo->prepare("SELECT * FROM Movie WHERE Movie_ID = ?");
$stmt->execute([$movie_id]);
$movie = $stmt->fetch();
if (!$movie) {
    header('Location: index.php');
    exit;
}

// Fetch genres and qualities (Database logic remains the same)
// ... (Your database fetch code for genres/qualities here) ...
// NOTE: I'll include the fetching code for completeness, but assume it's the same.

// Fetch genres
$stmt = $pdo->prepare(
    "SELECT g.Genre_Name
     FROM Movie_Genre mg
     JOIN Genre g ON mg.Genre_ID = g.Genre_ID
     WHERE mg.Movie_ID = ?"
);
$stmt->execute([$movie_id]);
$movie_genres = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch qualities
$stmt = $pdo->prepare(
    "SELECT q.Quality_Type
     FROM Movie_Quality mq
     JOIN Quality q ON mq.Quality_ID = q.Quality_ID
     WHERE mq.Movie_ID = ?"
);
$stmt->execute([$movie_id]);
$movie_qualities = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Simple check for Drive link
$show_preview = false;
if (!empty($movie['File_URL']) && strpos($movie['File_URL'], 'drive.google.com') !== false) {
    $show_preview = true;
}

// Set a fallback poster URL
$poster_url = htmlspecialchars($movie['Poster_URL'] ?? 'placeholder.jpg', ENT_QUOTES);
// Use the Poster_URL for the backdrop, but it will be blurred heavily by CSS
$backdrop_url = htmlspecialchars($movie['Poster_URL'] ?? 'https://placehold.co/1920x1080/0b141d/fcd34d?text=Movie+Backdrop', ENT_QUOTES); 

// Helper array for cycling through badge backgrounds
$badge_colors = [
    'bg-sky-700/80 hover:bg-sky-600', 
    'bg-pink-700/80 hover:bg-pink-600', 
    'bg-indigo-700/80 hover:bg-indigo-600', 
    'bg-emerald-700/80 hover:bg-emerald-600', 
    'bg-yellow-700/80 hover:bg-yellow-600',
    'bg-red-700/80 hover:bg-red-600', 
];
$genre_color_index = 0;
$quality_color_index = 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo htmlspecialchars($movie['Title'], ENT_QUOTES); ?> - MovieHub</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    
    <style>
        /* --- CSS Variables from index.php --- */
        :root{ 
            --bg: #0b141d;      /* Deep blue/black background */
            --card: #152230;    /* Slightly lighter card background */
            --border: #2c3a4d;  /* Subtle border color */
            --muted: #94a3b8;   /* Slate gray for muted text */
            --accent: #fcd34d;  /* Soft Gold/Amber for primary accent */
            --focus: #f59e0b;   /* A darker Amber for focus glow */
        }
        
        body { 
            background: var(--bg); 
            color: #e6eef3; 
            font-family: 'Inter', sans-serif;
            /* Ensure body is relative to contain the fixed background */
            position: relative;
            min-height: 100vh;
        }

        /* 1. UNIQUE: The Cinematic, Blurred, Fixed Background Layer */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /* Use the movie poster/backdrop URL */
            background-image: url('<?php echo $backdrop_url; ?>');
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            /* Heavy blur to abstract the background and hide low resolution */
            filter: blur(50px) brightness(0.7) grayscale(0.5); 
            transform: scale(1.1); /* Slight zoom to cover edges after blur */
            opacity: 0.6;
            z-index: -2; /* Place behind everything */
        }
        
        /* Fallback layer for the body background gradient (Z-index -1) */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /* Apply a gradient over the blurred image to ensure dark contrast */
            background: linear-gradient(to top, var(--bg) 0%, rgba(11, 20, 29, 0.9) 100%);
            z-index: -1;
        }
        
        /* Custom class for the unique "Glass Panel" effect */
        .glass-panel {
            background-color: var(--card); 
            backdrop-filter: blur(15px); /* The shattered glass effect */
            border: 2px solid var(--accent); /* Inner glow effect */
            box-shadow: 0 0 10px rgba(252, 211, 77, 0.3), /* Outer gold glow */
                        0 8px 32px 0 rgba(0, 0, 0, 0.7); /* Deep shadow */
        }

        /* 2. UNIQUE: Double-Line Accent for Headings */
        .double-line-heading {
            border-bottom: 3px double var(--accent); /* Double-line is highly distinct */
            padding-bottom: 8px;
            margin-bottom: 20px;
            text-shadow: 0 0 5px var(--focus);
        }

        /* Custom class to manage the sticky element visibility and width (Netflix-style) */
        @media (min-width: 1024px) { 
            .lg-sticky-panel {
                position: sticky;
                top: 80px; 
                max-height: calc(100vh - 100px); 
            }
        }
        /* Keyframes and animation for premium fade-in/slide effect */
        @keyframes slide-in-up {
            0% { opacity: 0; transform: translateY(20px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .animate-slide-in-up { animation: slide-in-up 0.7s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards; }
    </style>
</head>
<body class="antialiased font-sans">

<nav class="sticky top-0 z-50 p-4 border-b border-[var(--border)] shadow-2xl backdrop-blur-md bg-[var(--bg)]/80">
    <div class="max-w-7xl mx-auto flex justify-between items-center">
        <a href="index.php" class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-[var(--accent)] shadow-lg flex items-center justify-center text-black font-bold text-lg">M</div>
            <div>
                <div class="text-xl font-bold tracking-wider text-white">MovieHub</div>
                <div class="text-xs text-slate-400 -mt-1">Curated — Sleek</div>
            </div>
        </a>
        <a href="index.php" class="py-2 px-4 bg-[var(--card)] hover:bg-[var(--accent)] hover:text-black text-white rounded-full transition duration-300 font-semibold text-sm shadow-md border border-[var(--border)]">
            <i class="fas fa-arrow-left mr-2"></i> Back to Collection
        </a>
    </div>
</nav>

<div class="min-h-screen pb-10 relative z-10 pt-8">
    <div class="max-w-7xl mx-auto p-4 sm:p-8">
        
        <div class="mb-10 p-4 rounded-xl glass-panel/50 backdrop-blur-sm animate-slide-in-up" style="animation-delay: 0.1s;">
            <h1 class="text-5xl sm:text-6xl lg:text-8xl font-extrabold mb-1 leading-tight text-white drop-shadow-lg text-center">
                <?php echo htmlspecialchars($movie['Title'], ENT_QUOTES); ?>
            </h1>
            <p class="text-xl sm:text-2xl text-[var(--accent)] font-medium tracking-widest text-center mt-2">
                <i class="fas fa-calendar-alt mr-2 opacity-80"></i> <?php echo (int)$movie['Release_Year']; ?>
            </p>
        </div>
        
        <div class="flex flex-col lg:flex-row gap-8 items-start">
            
            <div class="w-full lg:w-[350px] flex-shrink-0 animate-slide-in-up" style="animation-delay: 0.2s;">
                
                <div class="lg-sticky-panel rounded-xl shadow-2xl p-6 glass-panel">
                    <div class="mb-6">
                        <img src="<?php echo $poster_url; ?>"
                              class="w-full h-auto rounded-lg object-cover shadow-lg border-2 border-[var(--focus)]/50"
                              alt="<?php echo htmlspecialchars($movie['Title'], ENT_QUOTES); ?>"
                              onerror="this.onerror=null;this.src='https://placehold.co/300x450/152230/fcd34d?text=No+Poster'">
                    </div>
                    
                    <div class="flex flex-col gap-4"> 
                        <a href="<?php echo htmlspecialchars($movie['File_URL'], ENT_QUOTES); ?>" target="_blank"
                           class="py-3 px-6 bg-[var(--accent)] hover:bg-[var(--focus)] text-black font-extrabold rounded-full text-lg transition duration-300 shadow-xl shadow-[var(--accent)]/40 text-center transform hover:scale-[1.03] active:scale-[0.98] tracking-wider">
                            <i class="fas fa-download mr-3"></i> Download Movie
                        </a>

                        <?php if ($show_preview): ?>
                            <a href="<?php echo htmlspecialchars($movie['File_URL'], ENT_QUOTES); ?>" target="_blank"
                               class="py-3 px-6 bg-transparent hover:bg-[var(--accent)]/10 text-[var(--accent)] font-bold rounded-full text-base transition duration-300 text-center border-2 border-[var(--accent)]/70 transform hover:scale-[1.03] active:scale-[0.98]">
                                <i class="fas fa-play-circle mr-3"></i> Watch Preview
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="flex-grow space-y-10 lg:pl-4">
                
                <section class="p-6 rounded-xl glass-panel animate-slide-in-up" style="animation-delay: 0.3s;">
                    <h2 class="text-3xl font-extrabold text-white double-line-heading">
                        Synopsis
                    </h2>
                    <p class="text-gray-300 leading-relaxed text-lg sm:text-xl">
                        <?php echo nl2br(htmlspecialchars($movie['Description'], ENT_QUOTES)); ?>
                    </p>
                </section>
                
                <section class="p-6 rounded-xl glass-panel animate-slide-in-up" style="animation-delay: 0.4s;">
                    <h2 class="text-3xl font-extrabold text-white double-line-heading">
                        Details & Quality
                    </h2>
                    
                    <div class="grid grid-cols-1 gap-6">
                        <?php if (!empty($movie_genres)): ?>
                            <div>
                                <span class="text-lg font-bold text-[var(--accent)] block mb-3 uppercase tracking-wider">
                                    <i class="fas fa-grip-lines mr-2"></i> Genres:
                                </span>
                                <div class="flex flex-wrap gap-3">
                                    <?php foreach ($movie_genres as $g): 
                                        $color_class = $badge_colors[$genre_color_index % count($badge_colors)];
                                        $genre_color_index++; 
                                    ?>
                                        <a href="index.php?query=<?php echo urlencode($g); ?>" 
                                           class="px-4 py-2 text-base font-semibold rounded-lg text-white <?php echo $color_class; ?> transition duration-300 transform hover:scale-105 shadow-md">
                                            <?php echo htmlspecialchars($g, ENT_QUOTES); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($movie_qualities)): ?>
                            <div>
                                <span class="text-lg font-bold text-[var(--accent)] block mb-3 uppercase tracking-wider">
                                    <i class="fas fa-video mr-2"></i> Available Quality:
                                </span>
                                <div class="flex flex-wrap gap-3">
                                    <?php foreach ($movie_qualities as $q): 
                                        $color_class = $badge_colors[$quality_color_index % count($badge_colors)];
                                        $quality_color_index++; 
                                    ?>
                                        <span class="px-4 py-2 text-base font-semibold rounded-lg text-white <?php echo $color_class; ?> transition duration-300 shadow-md">
                                            <?php echo htmlspecialchars($q, ENT_QUOTES); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
                
            </div>
            
        </div>
        </div>
    
    <footer class="max-w-7xl mx-auto p-6 text-center text-slate-500 border-t border-[var(--border)] mt-12">
        <p class="text-sm">&copy; <?php echo date("Y"); ?> MovieHub — Built with a passion for film.</p>
    </footer>

</div>

</body>
</html>
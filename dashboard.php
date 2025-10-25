<?php
session_start();

// Redirect if not logged in
if(!isset($_SESSION['admin_id'])){
    header('Location: login.php');
    exit;
}

// include DB (either file)
if (file_exists(__DIR__ . '/db.php')) {
    include __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/db_config.php')) {
    include __DIR__ . '/db_config.php';
} else {
    $pdo = null;
}

// Pagination config
const MOVIES_PER_PAGE = 12; // change this to show more/less per page
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * MOVIES_PER_PAGE;

// Handle delete action (keep as GET for now, but consider POST for safety)
if(isset($_GET['delete_id']) && $pdo){
    $delete_id = intval($_GET['delete_id']);

    try {
        // Fetch poster path to delete local file
        $stmt = $pdo->prepare("SELECT Poster_URL FROM Movie WHERE Movie_ID=?");
        $stmt->execute([$delete_id]);
        $poster = $stmt->fetchColumn();
        if($poster && file_exists($poster)){
            // Use @ to suppress file permission errors, which should be handled by system setup
            @unlink($poster);
        }

        // Delete movie from database
        $stmt = $pdo->prepare("DELETE FROM Movie WHERE Movie_ID=?");
        $stmt->execute([$delete_id]);
    } catch (PDOException $e) {
        error_log("Delete movie error: " . $e->getMessage());
    }

    // After deletion, redirect back to same page (remove delete_id from URL)
    $redirectPage = 'dashboard.php';
    $qs = [];
    if (!empty($_GET['search'])) $qs['search'] = $_GET['search'];
    // Only pass the page number if the total pages haven't changed dramatically
    if (!empty($_GET['page'])) $qs['page'] = $_GET['page']; 
    if ($qs) $redirectPage .= '?' . http_build_query($qs);
    header('Location: ' . $redirectPage);
    exit;
}

// Handle search and counting
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$movies = [];
$db_error = null;
$total_movies = 0;
$total_pages = 0;

if (!$pdo) {
    $db_error = "Database connection not initialized. Check db.php or db_config.php.";
} else {
    try {
        // Count total results for pagination
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM Movie WHERE Title LIKE ?");
        $countStmt->execute(["%$search%"]);
        $total_movies = (int)$countStmt->fetchColumn();
        $total_pages = (int)ceil($total_movies / MOVIES_PER_PAGE);

        // Ensure current page is not out of range
        if ($current_page > 1 && $current_page > $total_pages) {
            $current_page = max(1, $total_pages);
            $offset = ($current_page - 1) * MOVIES_PER_PAGE;
        }

        // Fetch page of movies
        $query = "SELECT * FROM Movie WHERE Title LIKE ? ORDER BY Movie_ID DESC LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($query);
        // Bind parameters: search, limit (int), offset (int)
        $stmt->bindValue(1, "%$search%", PDO::PARAM_STR);
        $stmt->bindValue(2, MOVIES_PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // For each movie fetch genres and qualities
        foreach($raw as $m) {
            $movie_id = $m['Movie_ID'];

            $stmt2 = $pdo->prepare("SELECT g.Genre_Name FROM Movie_Genre mg JOIN Genre g ON mg.Genre_ID = g.Genre_ID WHERE mg.Movie_ID = ?");
            $stmt2->execute([$movie_id]);
            $m['Genres'] = $stmt2->fetchAll(PDO::FETCH_COLUMN);

            $stmt3 = $pdo->prepare("SELECT q.Quality_Type FROM Movie_Quality mq JOIN Quality q ON mq.Quality_ID = q.Quality_ID WHERE mq.Movie_ID = ?");
            $stmt3->execute([$movie_id]);
            $m['Qualities'] = $stmt3->fetchAll(PDO::FETCH_COLUMN);

            $movies[] = $m;
        }
    } catch (PDOException $e) {
        $db_error = "Database error: " . $e->getMessage();
        error_log("Dashboard fetch error: " . $e->getMessage());
    }
}

// Prepare data for React (include pagination)
$initial_data = [
    'admin_name'    => $_SESSION['admin_name'] ?? 'Admin',
    'movies'        => $movies,
    'search'        => $search,
    'debug'         => $db_error,
    'pagination'    => [
        'currentPage' => $current_page,
        'totalPages'  => $total_pages,
        'moviesPerPage'=> MOVIES_PER_PAGE,
        'totalMovies' => $total_movies
    ]
];
$js_data = json_encode($initial_data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Admin Dashboard - MovieHub</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"/>

    <script crossorigin src="https://unpkg.com/react@18/umd/react.development.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.development.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

    <script>
        // --- UPDATED Tailwind custom theme to match movie.php colors ---
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        // Deep Blue/Black primary colors
                        'primary-dark': '#0b141d', // --bg from movie.php
                        'secondary-mid': '#152230', // --card from movie.php
                        'border-subtle': '#2c3a4d', // --border from movie.php
                        // Soft Gold/Amber accent color
                        'accent-gold': '#fcd34d', // --accent from movie.php
                        'accent-focus': '#f59e0b', // --focus from movie.php
                        'muted-gray': '#94a3b8' // Slate gray for muted text
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <style>
        /* UNIQUE STYLES to create the "Command Center" look */
        body { 
            font-family: 'Inter', sans-serif; 
            background: #0b141d; /* Use flat dark color for command center feel */
            color: #e6eef3; 
        }
        
        /* Unique style for the movie rows/cards in the dashboard */
        .data-panel-row { 
            background: #152230; /* Secondary-mid color */
            border-left: 4px solid transparent; /* default state */
            transition: all 0.2s ease-in-out;
        }
        
        /* Highlight row on hover to make it feel responsive and premium */
        .data-panel-row:hover {
            border-left-color: #fcd34d; /* Accent gold on hover */
            box-shadow: 0 0 10px rgba(252, 211, 77, 0.1);
        }

        /* Style for the sidebar menu's active item */
        .nav-active {
            background-color: #fcd34d !important;
            color: #0b141d !important;
            font-weight: 700;
        }

        /* Soft button for search/pagination */
        .btn-soft-gold { 
            background: rgba(252, 211, 77, 0.1); /* Transparent gold background */
            color: #fcd34d; 
        }

        /* Style for the table header to match the theme */
        .table-header {
            background-color: rgba(21, 34, 48, 0.8); /* Slightly darker card color */
            border-bottom: 2px solid #fcd34d; /* Gold accent line */
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

        function Pagination({ pagination, search }) {
            const current = pagination.currentPage || 1;
            const total = pagination.totalPages || 1;
            if (total <= 1) return null;

            // Build URL preserving search
            const buildUrl = (page) => {
                const params = new URLSearchParams();
                if (search) params.set('search', search);
                if (page > 1) params.set('page', page);
                return `dashboard.php?${params.toString()}`;
            };

            // Determine page window (same logic as before)
            const maxButtons = 7;
            let start = Math.max(1, current - Math.floor(maxButtons / 2));
            let end = Math.min(total, start + maxButtons - 1);
            if (end - start + 1 < maxButtons) {
                start = Math.max(1, end - maxButtons + 1);
            }

            const pages = [];
            if (start > 1) {
                pages.push(1);
                if (start > 2) pages.push('dots-start');
            }
            for (let i = start; i <= end; i++) pages.push(i);
            if (end < total) {
                if (end < total - 1) pages.push('dots-end');
                pages.push(total);
            }

            return (
                <nav className="flex items-center justify-center mt-8 space-x-2">
                    <a href={current > 1 ? buildUrl(current - 1) : '#'} className={`px-3 py-1 rounded transition ${current>1 ? 'bg-secondary-mid hover:bg-neutral-600' : 'bg-secondary-mid/50 text-neutral-500 cursor-not-allowed'}`}>
                        <i className="fas fa-chevron-left"></i>
                    </a>

                    {pages.map((p, idx) => {
                        if (p === 'dots-start' || p === 'dots-end') {
                            return <span key={idx} className="px-3 py-1 text-muted-gray">...</span>;
                        }
                        return (
                            <a key={idx} href={buildUrl(p)} className={`px-3 py-1 rounded transition ${p === current ? 'bg-accent-gold text-black font-semibold' : 'bg-secondary-mid hover:bg-neutral-600'}`}>
                                {p}
                            </a>
                        );
                    })}

                    <a href={current < total ? buildUrl(current + 1) : '#'} className={`px-3 py-1 rounded transition ${current<total ? 'bg-secondary-mid hover:bg-neutral-600' : 'bg-secondary-mid/50 text-neutral-500 cursor-not-allowed'}`}>
                        <i className="fas fa-chevron-right"></i>
                    </a>
                </nav>
            );
        }

        function Dashboard() {
            const [movies] = useState(data.movies || []);
            const [searchVal] = useState(data.search || '');
            const [adminName] = useState(data.admin_name || 'Admin');
            const pagination = data.pagination || { currentPage:1, totalPages:1, totalMovies:0 };

            const posterSrc = (url) => url && url.length && url.toLowerCase() !== 'n/a' ? url : 'https://placehold.co/300x450/152230/fcd34d?text=No+Poster';

            return (
                <div className="min-h-screen flex">
                    {/* UNIQUE: Command Center Sidebar */}
                    <aside className="w-64 hidden md:flex flex-col p-6 space-y-4 bg-secondary-mid border-r border-border-subtle shadow-lg">
                        <div className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-full bg-accent-gold shadow-md flex items-center justify-center text-primary-dark font-bold text-lg">M</div>
                            <div className="text-2xl font-extrabold text-white">Movie<span className="text-accent-gold">Hub</span></div>
                        </div>

                        <nav className="flex flex-col mt-4 space-y-1">
                            {/* Dashboard is the active link, using the new 'nav-active' style */}
                            <a href="dashboard.php" className="nav-active px-3 py-2 rounded-lg text-sm flex items-center gap-3 transition">
                                <i className="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                            <a href="add_movie.php" className="px-3 py-2 rounded-lg text-sm text-white hover:bg-secondary-mid/50 flex items-center gap-3 transition">
                                <i className="fas fa-plus"></i> Add Movie
                            </a>
                            <a href="add_genre.php?type=genre" className="px-3 py-2 rounded-lg text-sm text-white hover:bg-secondary-mid/50 flex items-center gap-3 transition">
                                <i className="fas fa-tags"></i> Add Genre
                            </a>
                            <a href="add_genre.php?type=quality" className="px-3 py-2 rounded-lg text-sm text-white hover:bg-secondary-mid/50 flex items-center gap-3 transition">
                                <i className="fas fa-layer-group"></i> Add Quality
                            </a>
                            {/* Logout button stands out */}
                            <a href="logout.php" className="mt-4 px-3 py-2 rounded-lg text-sm text-red-400 border border-red-800 hover:bg-red-900/40 flex items-center gap-3 transition">
                                <i className="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </nav>

                        <div className="mt-auto text-sm text-muted-gray pt-4 border-t border-border-subtle">
                            <div className="mb-1 text-xs text-white/70">Access Status</div>
                            <div className="font-semibold text-accent-gold">{adminName}</div>
                        </div>
                    </aside>

                    <main className="flex-1 p-6">
                        <header className="flex flex-col sm:flex-row items-center justify-between mb-8 pb-4 border-b border-border-subtle">
                            <h1 className="text-3xl font-extrabold text-white mb-4 sm:mb-0">Movie Catalog <span className="text-accent-gold">Manager</span></h1>

                            <div className="flex items-center gap-3 w-full sm:w-auto">
                                <form method="get" action="dashboard.php" className="flex items-center flex-grow sm:flex-grow-0 gap-2">
                                    <input type="text" name="search" defaultValue={searchVal} placeholder="Search titles..." 
                                           className="px-4 py-2 rounded-lg bg-secondary-mid border border-border-subtle placeholder-muted-gray text-white outline-none focus:border-accent-gold w-full" />
                                    <button type="submit" className="px-4 py-2 rounded-lg btn-soft-gold hover:bg-accent-gold/20 transition">
                                        <i className="fas fa-search"></i>
                                    </button>
                                </form>
                                <a href="add_movie.php" className="px-4 py-2 rounded-lg bg-accent-gold text-primary-dark font-semibold hover:bg-accent-focus shadow-lg transition whitespace-nowrap">
                                    <i className="fas fa-plus mr-2"></i> Add Movie
                                </a>
                            </div>
                        </header>

                        <div className="mb-6 text-sm text-muted-gray flex justify-between items-center">
                            <span>Showing **<span className="text-white font-semibold">{pagination.totalMovies}</span>** movie(s) found.</span>
                            <span className="text-xs">Page **<span className="text-white font-semibold">{pagination.currentPage}</span>** of **<span className="text-white font-semibold">{pagination.totalPages}</span>**</span>
                        </div>

                        {data.debug && (
                            <div className="mb-4 p-4 rounded-lg bg-red-900/80 border border-red-600 text-red-200">
                                <strong className="block font-semibold">DATABASE ERROR</strong>
                                <div className="text-sm mt-1">{data.debug}</div>
                            </div>
                        )}

                        <div className="space-y-4">
                            {/* UNIQUE: Desktop Table (Overhauled with custom styles) */}
                            <div className="hidden md:block bg-secondary-mid rounded-xl shadow-lg border border-border-subtle">
                                <div className="overflow-x-auto">
                                    <table className="w-full table-auto border-collapse">
                                        <thead className="text-left text-sm text-muted-gray table-header">
                                            <tr>
                                                <th className="py-3 px-4 w-12">#</th>
                                                <th className="py-3 px-4 w-20">Poster</th>
                                                <th className="py-3 px-4">Title & Description</th>
                                                <th className="py-3 px-4 w-24">Year</th>
                                                <th className="py-3 px-4 w-48">Details</th>
                                                <th className="py-3 px-4 w-40 text-center">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {movies.map((m, idx) => (
                                                <tr key={m.Movie_ID} className="data-panel-row hover:bg-secondary-mid/80">
                                                    <td className="py-4 px-4 align-top text-sm text-muted-gray">{(pagination.currentPage - 1) * pagination.moviesPerPage + idx + 1}</td>
                                                    <td className="py-4 px-4 align-top">
                                                        <img src={posterSrc(m.Poster_URL)} alt="" className="w-16 h-20 object-cover rounded-md border border-border-subtle"/>
                                                    </td>
                                                    <td className="py-4 px-4 align-top">
                                                        <div className="font-semibold text-white">{m.Title}</div>
                                                        <div className="text-xs text-muted-gray mt-1 max-w-sm">{m.Description ? (m.Description.substring(0,100) + (m.Description.length>100?'...':'')) : ''}</div>
                                                    </td>
                                                    <td className="py-4 px-4 align-top text-sm text-muted-gray">{m.Release_Year}</td>
                                                    <td className="py-4 px-4 align-top text-xs space-y-1">
                                                        <div className="text-accent-gold font-medium">Genres: <span className="text-muted-gray">{(m.Genres || []).join(', ')}</span></div>
                                                        <div className="text-accent-gold font-medium">Quality: <span className="text-muted-gray">{(m.Qualities || []).join(', ')}</span></div>
                                                    </td>
                                                    <td className="py-4 px-4 align-top">
                                                        <div className="flex flex-col gap-2">
                                                            <a href={`edit_movie.php?id=${m.Movie_ID}`} className="px-3 py-1 rounded bg-blue-600 text-white text-xs hover:bg-blue-700 transition"><i className="fas fa-edit mr-1"></i>Edit</a>
                                                            <a href={`dashboard.php?delete_id=${m.Movie_ID}&page=${pagination.currentPage}${searchVal?`&search=${encodeURIComponent(searchVal)}`:''}`} 
                                                                onClick={() => window.confirm('Are you sure you want to delete this movie? This action is permanent.')} 
                                                                className="px-3 py-1 rounded bg-red-600 text-white text-xs hover:bg-red-700 transition">
                                                                <i className="fas fa-trash-alt mr-1"></i>Delete
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                            {movies.length === 0 && (
                                                <tr>
                                                    <td colSpan="6" className="py-6 px-4 text-center text-muted-gray">No movies found {searchVal && `for search term: "${searchVal}"`}</td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {/* UNIQUE: Card grid for small screens (Mobile View) */}
                            <div className="md:hidden grid grid-cols-1 gap-4">
                                {movies.map(m => (
                                    <div key={m.Movie_ID} className="data-panel-row rounded-lg p-4 shadow-md border border-border-subtle flex gap-4">
                                        <img src={posterSrc(m.Poster_URL)} alt="" className="w-24 h-32 object-cover rounded-md flex-shrink-0 border border-border-subtle"/>
                                        <div className="flex-1 space-y-2">
                                            <div className="font-bold text-lg text-white">{m.Title} <span className="text-sm text-muted-gray font-normal">({m.Release_Year})</span></div>
                                            <div className="text-xs text-muted-gray">
                                                <span className="text-accent-gold">G:</span> {(m.Genres || []).join(', ')}<br/>
                                                <span className="text-accent-gold">Q:</span> {(m.Qualities || []).join(', ')}
                                            </div>
                                            <div className="flex gap-2 pt-1 border-t border-border-subtle/50">
                                                <a href={`edit_movie.php?id=${m.Movie_ID}`} className="px-3 py-1 rounded bg-blue-600 text-white text-xs hover:bg-blue-700 transition flex-grow text-center"><i className="fas fa-edit mr-1"></i>Edit</a>
                                                <a href={`dashboard.php?delete_id=${m.Movie_ID}&page=${pagination.currentPage}${searchVal?`&search=${encodeURIComponent(searchVal)}`:''}`} 
                                                    onClick={() => window.confirm('Are you sure you want to delete this movie?')} 
                                                    className="px-3 py-1 rounded bg-red-600 text-white text-xs hover:bg-red-700 transition flex-grow text-center">
                                                    <i className="fas fa-trash-alt mr-1"></i>Delete
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                                {movies.length === 0 && (
                                    <div className="text-center text-muted-gray py-10">No movies found in the catalog.</div>
                                )}
                            </div>

                            {/* Pagination */}
                            <Pagination pagination={pagination} search={searchVal} />
                        </div>
                    </main>
                </div>
            );
        }

        ReactDOM.createRoot(document.getElementById('root')).render(<Dashboard />);
    </script>

</body>
</html>
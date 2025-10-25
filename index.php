<?php
// index.php - MovieHub (V2: Polished, Modern Dark UI)
// - Single-file SPA + JSON API
// - Expects db.php to provide a PDO instance in $pdo
// - Refactored for better UX and modern dark theme

include 'db.php'; // Ensure your db.php file connects to the database and provides $pdo

// Helper function for JSON response
function json_exit($data, $status = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    // Use JSON_THROW_ON_ERROR for robust error handling
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

// Safely get and validate an integer query parameter
function get_int_param($name, $default = 0) {
    if (!isset($_GET[$name])) return $default;
    $v = filter_var($_GET[$name], FILTER_VALIDATE_INT);
    return $v === false ? $default : (int)$v;
}

// Safely get a trimmed string query parameter
function get_string_param($name, $default = '') {
    if (!isset($_GET[$name])) return $default;
    return trim((string)$_GET[$name]);
}

// --- API Endpoint: returns metadata + paginated movies (aggregated genres)
if (isset($_GET['api'])) {
    $MOVIES_PER_PAGE = 40; 
    $page = max(1, get_int_param('page', 1));
    $offset = ($page - 1) * $MOVIES_PER_PAGE;

    $search = get_string_param('search', '');
    $genre = get_int_param('genre', 0);
    $quality = get_int_param('quality', 0);

    // Build WHERE clauses for filtering
    $where = [];
    $params = [];
    if ($search !== '') { 
        $where[] = "m.Title LIKE :search"; 
        $params[':search'] = '%' . $search . '%'; 
    }
    if ($genre > 0) { 
        $where[] = "mg.Genre_ID = :genre"; 
        $params[':genre'] = $genre; 
    }
    if ($quality > 0) { 
        $where[] = "mq.Quality_ID = :quality"; 
        $params[':quality'] = $quality; 
    }
    // Prepend 'AND' only if there are clauses
    $where_sql = count($where) ? 'AND ' . implode(' AND ', $where) : '';

    $response = [
        'movies' => [],
        'pagination' => [ 'currentPage' => $page, 'moviesPerPage' => $MOVIES_PER_PAGE, 'totalMovies' => 0, 'totalPages' => 0 ],
        'filters' => ['search' => $search, 'genre' => $genre, 'quality' => $quality],
        'meta' => ['genres' => [], 'qualities' => []],
        'error' => null
    ];

    try {
        if (!isset($pdo) || !$pdo) throw new RuntimeException('Database connection failed.');

        // Load genres & qualities for the dropdowns
        $stmt = $pdo->query('SELECT Genre_ID, Genre_Name FROM Genre ORDER BY Genre_Name');
        $response['meta']['genres'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $pdo->query('SELECT Quality_ID, Quality_Type FROM Quality ORDER BY Quality_Type');
        $response['meta']['qualities'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // --- COUNT Query: Find the total number of distinct movies matching filters ---
        $count_sql = "
            SELECT COUNT(DISTINCT m.Movie_ID) AS cnt
            FROM Movie m
            LEFT JOIN Movie_Genre mg ON m.Movie_ID = mg.Movie_ID
            LEFT JOIN Movie_Quality mq ON m.Movie_ID = mq.Movie_ID
            WHERE 1=1
            $where_sql
        ";
        $stmt = $pdo->prepare($count_sql);
        // Bind parameters
        foreach ($params as $k => $v) {
             $type = str_contains($k, 'search') ? PDO::PARAM_STR : PDO::PARAM_INT;
             $stmt->bindValue($k, $v, $type);
        }
        $stmt->execute();
        $total = (int)$stmt->fetchColumn();
        $response['pagination']['totalMovies'] = $total;
        $response['pagination']['totalPages'] = $total > 0 ? (int)ceil($total / $MOVIES_PER_PAGE) : 0;

        if ($total === 0) {
            json_exit($response);
        }

        // --- DATA Query: Fetch movie details and aggregate genres for the current page ---
        $data_sql = "
            SELECT
                m.Movie_ID, m.Title, m.Description, m.Release_Year, m.File_URL, m.Poster_URL,
                COALESCE(GROUP_CONCAT(DISTINCT g.Genre_Name ORDER BY g.Genre_Name SEPARATOR '||'), '') AS Genres
            FROM Movie m
            LEFT JOIN Movie_Genre mg ON m.Movie_ID = mg.Movie_ID
            LEFT JOIN Genre g ON mg.Genre_ID = g.Genre_ID
            LEFT JOIN Movie_Quality mq ON m.Movie_ID = mq.Movie_ID
            WHERE 1=1
            $where_sql
            GROUP BY m.Movie_ID
            ORDER BY m.Title ASC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $pdo->prepare($data_sql);
        
        // Bind parameters
        foreach ($params as $k => $v) {
             $type = str_contains($k, 'search') ? PDO::PARAM_STR : PDO::PARAM_INT;
             $stmt->bindValue($k, $v, $type);
        }
        
        // Bind pagination limits
        $stmt->bindValue(':limit', (int)$MOVIES_PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process rows 
        foreach ($rows as $r) {
            $genresArr = $r['Genres'] === '' ? [] : explode('||', $r['Genres']);
            $response['movies'][] = [
                'Movie_ID' => (int)$r['Movie_ID'],
                'Title' => $r['Title'],
                'Description' => $r['Description'],
                'Release_Year' => (int)$r['Release_Year'],
                'File_URL' => $r['File_URL'],
                'Poster_URL' => $r['Poster_URL'],
                'Genres' => $genresArr
            ];
        }

        json_exit($response);
    } catch (Throwable $e) {
        error_log('API error: ' . $e->getMessage());
        $response['error'] = 'Oops! We had a server issue loading movies. Please try your search again.';
        json_exit($response, 500);
    }
}

// --- If not API request, render the Single Page Application (SPA) shell --- 
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>MovieHub — Refined Collection</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        /* Custom variables for a softer dark theme with a warm accent (Gold) */
        :root{ 
            --bg: #0b141d; /* Deep blue/black background */
            --card: #152230; /* Slightly lighter card background */
            --border: #2c3a4d; /* Subtle border color */
            --muted: #94a3b8; /* Slate gray for muted text */
            --accent: #fcd34d; /* Soft Gold/Amber for primary accent */
            --focus: #f59e0b; /* A darker Amber for focus glow */
        }
        body{ 
            /* Subtle radial gradient for depth */
            background: radial-gradient(800px 400px at 10% 10%, rgba(252, 211, 77, 0.04), transparent), 
                        radial-gradient(700px 350px at 90% 90%, rgba(245, 158, 11, 0.03), transparent), 
                        var(--bg); 
            color: #e6eef3; 
        }

        /* Hero animation for a touch of movement */
        @keyframes subtleFloat { 0%{transform:translateY(0)}50%{transform:translateY(-4px)}100%{transform:translateY(0)} }
        .hero-float{ animation: subtleFloat 8s ease-in-out infinite; }

        /* General glass/frosted effect for containers */
        .glass-dark{ background: rgba(255,255,255,0.02); backdrop-filter: blur(8px); border:1px solid var(--border); }
        
        /* Focus effect for inputs and buttons */
        .focus-glow:focus{ 
            box-shadow: 0 0 0 3px var(--focus) inset; 
            outline: none; 
            border-color: var(--focus); 
        }

        /* Skeleton loading animation */
        .skeleton{ background: linear-gradient(90deg, rgba(255,255,255,0.03) 0%, rgba(255,255,255,0.06) 50%, rgba(255,255,255,0.03) 100%); background-size:200% 100%; animation: loading 1.4s linear infinite; }
        @keyframes loading{0%{background-position:200% 0}100%{background-position:-200% 0}}

        /* Custom scrollbar to match the theme */
        ::-webkit-scrollbar{ width: 10px; } 
        ::-webkit-scrollbar-thumb{ background: var(--accent); border-radius: 8px; }

        /* === NEW: Animated heading effect for "Stream Your Next Obsession" === */
        .headline-animate{
            background: linear-gradient(90deg, var(--accent) 0%, #ffffff 45%, var(--accent) 100%);
            background-size: 200% auto;
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            animation: gradientShift 3.5s linear infinite, subtleFloat 6s ease-in-out infinite;
            font-weight: 800;
            text-shadow: 0 6px 20px rgba(252,211,77,0.06);
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        /* Slight glow on hover/focus for accessibility */
        .headline-animate:focus, .headline-animate:hover {
            filter: drop-shadow(0 6px 18px rgba(245,158,11,0.18));
        }
    </style>
</head>
<body class="antialiased font-sans">
    <div id="root"></div>

    <script crossorigin src="https://unpkg.com/react@18/umd/react.development.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.development.js"></script>
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>

    <script type="text/babel">
        const { useState, useEffect, useCallback, useRef } = React;

        /* Utility: Build the API URL with query parameters */
        function buildApiUrl(params = {}){
            const url = new URL(window.location.origin + window.location.pathname);
            url.searchParams.set('api', '1');
            if (params.search) url.searchParams.set('search', params.search);
            if (params.genre) url.searchParams.set('genre', params.genre);
            if (params.quality) url.searchParams.set('quality', params.quality);
            if (params.page) url.searchParams.set('page', params.page);
            return url.toString();
        }

        /* Component: Movie Card - Compact design with clear info */
        function MovieCard({ movie }){
            const posterFallback = 'https://placehold.co/400x600/152230/fcd34d?text=No+Poster';
            const primaryGenre = movie.Genres.length > 0 ? movie.Genres[0] : 'Unspecified';

            return (
                <a 
                    href={`movie.php?id=${movie.Movie_ID}`} 
                    className="block relative rounded-xl bg-[var(--card)] overflow-hidden shadow-xl transform hover:scale-[1.03] transition duration-300 group"
                    aria-label={`View details for ${movie.Title}`}
                >
                    <div className="relative">
                        <img 
                            src={movie.Poster_URL || posterFallback} 
                            alt={`${movie.Title} poster`} 
                            className="w-full h-72 object-cover object-center transition duration-500 group-hover:opacity-80" 
                            onError={(e) => { e.target.onerror = null; e.target.src = posterFallback; }} 
                        />
                        <div className="absolute left-3 bottom-3 px-2 py-0.5 rounded-full bg-black/40 text-xs text-white backdrop-blur-sm shadow-md">
                            {movie.Release_Year || 'Year TBD'}
                        </div>
                    </div>
                    
                    <div className="p-3">
                        <h3 className="text-sm font-semibold truncate text-white">{movie.Title}</h3>
                        <p className="text-xs font-medium mt-1 text-[var(--accent)]">{primaryGenre}</p>
                        
                        <div className="absolute inset-0 bg-black/80 flex flex-col justify-center items-center p-4 opacity-0 group-hover:opacity-100 transition duration-300">
                            <h4 className="text-lg font-bold text-[var(--accent)] text-center mb-2">{movie.Title}</h4>
                            <p className="text-xs text-slate-300 line-clamp-3 text-center mb-3">{movie.Description || 'No detailed description available.'}</p>
                            <span className="text-sm border border-[var(--accent)] text-[var(--accent)] px-3 py-1 rounded-full">
                                Watch Now <i className="fas fa-play ml-1"></i>
                            </span>
                        </div>
                    </div>
                </a>
            );
        }

        /* Component: Simple skeleton while loading */
        function SkeletonGrid(){
            return (
                <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-6 mt-6">
                    {Array.from({length: 10}).map((_, i) => (
                        <div key={i} className="rounded-xl overflow-hidden bg-[var(--card)] skeleton h-[350px] shadow-lg"></div>
                    ))}
                </div>
            );
        }

        /* Component: Pagination with a cleaner button design */
        function Pagination({ pagination, onPage }){
            const { currentPage, totalPages } = pagination || {};
            if (!totalPages || totalPages <= 1) return null;
            
            const pages = [];
            const maxButtons = 7; 
            let start = Math.max(1, currentPage - Math.floor(maxButtons / 2));
            let end = Math.min(totalPages, start + maxButtons - 1);
            
            if (end - start + 1 < maxButtons) start = Math.max(1, end - maxButtons + 1);

            if (start > 1) pages.push(1);
            if (start > 2) pages.push('dots-start');
            for (let p = start; p <= end; p++) pages.push(p);
            if (end < totalPages - 1) pages.push('dots-end');
            if (end < totalPages) pages.push(totalPages);
            
            const buttonClass = (p, isCurrent) => `px-3 py-1 rounded text-sm font-medium transition duration-200 
                ${isCurrent ? 'bg-[var(--accent)] text-black shadow-md' : 'bg-[var(--card)] text-white hover:bg-[var(--focus)]/30'}`;

            return (
                <div className="flex items-center justify-center gap-2 mt-12 mb-6">
                    <button 
                        onClick={() => onPage(Math.max(1, currentPage - 1))} 
                        disabled={currentPage === 1}
                        className="px-3 py-1 rounded-full bg-[var(--card)] text-white hover:bg-[var(--focus)]/30 disabled:opacity-30 transition"
                    >
                        <i className="fas fa-arrow-left text-xs"></i>
                    </button>

                    {pages.map((p, idx) => (
                        p === 'dots-start' || p === 'dots-end' ? (
                            <span key={idx} className="text-gray-500 text-sm">...</span>
                        ) : (
                            <button 
                                key={idx} 
                                onClick={() => onPage(p)} 
                                className={buttonClass(p, p === currentPage)}
                            >
                                {p}
                            </button>
                        )
                    ))}

                    <button 
                        onClick={() => onPage(Math.min(totalPages, currentPage + 1))} 
                        disabled={currentPage === totalPages}
                        className="px-3 py-1 rounded-full bg-[var(--card)] text-white hover:bg-[var(--focus)]/30 disabled:opacity-30 transition"
                    >
                        <i className="fas fa-arrow-right text-xs"></i>
                    </button>
                </div>
            );
        }

        /* Component: Header with Search and Quick Filter Buttons */
        function HeroHeader({ search, setSearch, applyFilters }){
            const quickTags = ['Action', 'Drama', 'Comedy', 'Thriller', 'Sci-Fi'];
            
            // FIX: Using local image path
            const heroImageUrl = 'WebI/BI.jpg';
            const fallbackStyle = { backgroundColor: 'var(--card)' };

            // State to handle image load failure and fallback
            const [hasImage, setHasImage] = useState(true);

            return (
                <header className="max-w-6xl mx-auto px-4 mt-6">
                    <div className="relative rounded-2xl overflow-hidden hero-float shadow-2xl">
                        {/* Conditional rendering for image or fallback color block */}
                        {hasImage ? (
                            <img 
                                src={heroImageUrl} 
                                alt="Cinematic background" 
                                className="w-full h-48 sm:h-56 md:h-64 object-cover object-center brightness-50" 
                                onError={() => setHasImage(false)} // Set state to false on error
                            />
                        ) : (
                            <div 
                                className="w-full h-48 sm:h-56 md:h-64 object-cover brightness-50" 
                                style={fallbackStyle} // Use the custom CSS variable color as fallback
                            ></div>
                        )}
                        
                        {/* Gradient overlay for blending */}
                        <div className="absolute inset-0 bg-gradient-to-t from-[var(--bg)] from-20% via-[var(--bg)]/60 via-40% to-transparent"></div>
                        
                        <div className="absolute inset-0 flex flex-col items-center justify-center p-6 text-center">
                            <h1 className="text-3xl md:text-5xl font-extrabold drop-shadow-lg tracking-tight headline-animate" tabIndex="0">
                                Stream Your Next Obsession
                            </h1>
                            <p className="text-slate-400 mt-2 text-sm">A curated collection of quality cinema, ready to play.</p>

                            {/* Search Form - Centerpiece of the header */}
                            <form onSubmit={applyFilters} className="w-full max-w-2xl mt-6">
                                <div className="flex items-stretch bg-[var(--card)] glass-dark rounded-xl p-1 shadow-2xl focus-within:ring-2 focus-within:ring-[var(--accent)]">
                                    <input 
                                        value={search} 
                                        onChange={(e) => setSearch(e.target.value)} 
                                        placeholder="Search by title (e.g., Dune or Interstellar)" 
                                        className="flex-1 bg-transparent border-none outline-none text-white px-4 py-3 text-base placeholder-slate-500" 
                                    />
                                    <button 
                                        type="submit" 
                                        className="px-5 py-2.5 rounded-lg bg-[var(--accent)] text-black font-semibold hover:opacity-90 transition duration-200"
                                        aria-label="Perform Search"
                                    >
                                        <i className="fas fa-search mr-1"></i> Search
                                    </button>
                                </div>
                            </form>
                            
                            {/* Quick Tags for discoverability */}
                            <div className="mt-4 flex gap-2 justify-center overflow-auto">
                                {quickTags.map((t, i) => (
                                    <button 
                                        key={i} 
                                        onClick={() => { setSearch(t); applyFilters(); }} 
                                        type="button" 
                                        className="text-xs px-3 py-1 bg-white/10 rounded-full text-slate-300 hover:bg-[var(--accent)] hover:text-black transition duration-200"
                                    >
                                        {t}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                </header>
            );
        }

        /* Component: Filter Dropdowns */
        function FilterControls({ meta, genre, setGenre, quality, setQuality, applyFilters, pagination, loading }){
            useEffect(() => {
                const timeoutId = setTimeout(() => {
                    applyFilters();
                }, 100); 
                return () => clearTimeout(timeoutId);
            }, [genre, quality]); 
            
            const dropdownClass = "p-2 bg-[var(--card)] border border-[var(--border)] rounded text-sm w-full md:w-48 text-white focus-glow appearance-none cursor-pointer";
            
            return (
                <div className="mt-6 glass-dark rounded-xl p-4 shadow-xl flex flex-col md:flex-row items-center gap-4 max-w-6xl mx-auto">
                    <div className="flex flex-wrap gap-4 items-center w-full">
                        <select 
                            value={genre} 
                            onChange={(e) => { setGenre(Number(e.target.value)); }} 
                            className={dropdownClass}
                        >
                            <option value={0} className="bg-[var(--card)] text-white">All Genres</option>
                            {meta.genres.map(g => (
                                <option key={g.Genre_ID} value={g.Genre_ID} className="bg-[var(--card)]">{g.Genre_Name}</option>
                            ))}
                        </select>

                        <select 
                            value={quality} 
                            onChange={(e) => { setQuality(Number(e.target.value)); }} 
                            className={dropdownClass}
                        >
                            <option value={0} className="bg-[var(--card)] text-white">All Qualities</option>
                            {meta.qualities.map(q => (
                                <option key={q.Quality_ID} value={q.Quality_ID} className="bg-[var(--card)]">{q.Quality_Type}</option>
                            ))}
                        </select>
                        
                        <div className="ml-auto text-sm text-slate-400 hidden md:block">
                            {pagination && pagination.totalMovies > 0 
                                ? `Found ${pagination.totalMovies} movies` 
                                : loading ? 'Searching...' : ''
                            }
                        </div>
                    </div>
                </div>
            );
        }


        /* Main App Component */
        function App(){
            const [search, setSearch] = useState('');
            const [genre, setGenre] = useState(0);
            const [quality, setQuality] = useState(0);
            const [page, setPage] = useState(1);
            
            const [movies, setMovies] = useState([]);
            const [pagination, setPagination] = useState({ currentPage:1, totalPages:0, totalMovies:0 });
            const [meta, setMeta] = useState({ genres: [], qualities: [] });
            
            const [loading, setLoading] = useState(false);
            const [error, setError] = useState(null);

            const resultsRef = useRef(null);

            /* Function to fetch movies and metadata from the API */
            const fetchMovies = useCallback(async()=>{
                setLoading(true); setError(null);
                try{
                    const url = buildApiUrl({ 
                        search: search || undefined, 
                        genre: genre || undefined, 
                        quality: quality || undefined, 
                        page 
                    });
                    
                    const res = await fetch(url);
                    
                    if (!res.ok) {
                        const json = await res.json().catch(()=>({error:'Unknown server error'}));
                        throw new Error(json.error || 'Network error occurred.');
                    }
                    
                    const data = await res.json();
                    
                    setMovies(data.movies || []);
                    setPagination(data.pagination || {});
                    setMeta(data.meta || { genres: [], qualities: [] });
                    
                    if (page > 1 && resultsRef.current) {
                        resultsRef.current.scrollIntoView({ behavior: 'smooth' });
                    }
                }catch(e){
                    setError('Failed to load data. Please check your connection or try a less specific search.');
                }finally{ 
                    setLoading(false); 
                }
            }, [search, genre, quality, page]);

            /* Effect: Initial load and subsequent filter/page changes */
            useEffect(()=>{ 
                fetchMovies(); 
            }, [fetchMovies]);

            /* Handler to apply filters (called by search button or dropdown change) */
            const applyFilters = (e) => { 
                e && e.preventDefault(); 
                if(page !== 1) {
                    setPage(1);
                } else {
                    fetchMovies();
                }
            }
            
            /* Handler for pagination clicks */
            const handlePage = (p) => { 
                if (p !== page) {
                    setPage(p); 
                }
            }
            
            /* Handler to clear all filters */
            const clearFilters = () => {
                setSearch(''); 
                setGenre(0); 
                setQuality(0); 
                setPage(1);
            }

            return (
                <div className="min-h-screen">
                    {/* Navigation Bar */}
                    <nav className="flex items-center justify-between p-4 max-w-6xl mx-auto">
                        <div className="flex items-center gap-3">
                            <div className="w-10 h-10 rounded-full bg-[var(--accent)] shadow-lg flex items-center justify-center text-black font-bold text-lg">M</div>
                            <div>
                                <div className="text-xl font-bold tracking-wider">MovieHub</div>
                                <div className="text-xs text-slate-400 -mt-1">Curated — Sleek</div>
                            </div>
                        </div>
                        <div className="flex items-center gap-4">
                            {/* Removed "My List" and "Settings" links as requested */}
                        </div>
                    </nav>

                    {/* Header/Hero Section */}
                    <HeroHeader search={search} setSearch={setSearch} applyFilters={applyFilters} />
                    
                    {/* Filter Controls (Genre/Quality) */}
                    <FilterControls 
                        meta={meta} 
                        genre={genre} setGenre={setGenre} 
                        quality={quality} setQuality={setQuality} 
                        applyFilters={applyFilters}
                        pagination={pagination}
                        loading={loading}
                    />

                    {/* Main Content Area */}
                    <main className="max-w-6xl mx-auto p-4" ref={resultsRef}>
                        <div className="flex justify-between items-center mt-6 mb-4">
                            <h2 className="text-2xl font-bold text-white">
                                {search || genre > 0 || quality > 0 ? 'Search Results' : 'Featured Movies'}
                            </h2>
                            <button 
                                type="button" 
                                onClick={clearFilters} 
                                className="px-3 py-1 rounded-full bg-[var(--card)] text-sm text-slate-300 hover:bg-white/10 transition"
                                aria-label="Reset all filters"
                            >
                                <i className="fas fa-undo mr-1"></i> Reset
                            </button>
                        </div>
                        
                        {/* Conditional Rendering of Results */}
                        {loading ? <SkeletonGrid /> : error ? (
                            <div className="bg-red-800/20 border border-red-700/50 p-6 rounded-xl text-white text-center shadow-lg">
                                <i className="fas fa-exclamation-triangle text-3xl text-red-400 mb-3"></i>
                                <h3 className="text-xl font-bold mb-2">Error Loading Movies</h3>
                                <p className="text-red-300">{error}</p>
                                <button onClick={fetchMovies} className="mt-4 px-4 py-2 rounded-full bg-red-600 hover:bg-red-700 font-semibold transition">
                                    Try Again
                                </button>
                            </div>
                        ) : movies.length === 0 ? (
                            <div className="text-center p-12 bg-[var(--card)] rounded-2xl shadow-xl">
                                <i className="fas fa-ghost text-4xl text-[var(--muted)] mb-3"></i>
                                <h3 className="text-2xl font-bold text-white">Nothing matched your criteria</h3>
                                <p className="text-slate-400 mt-2">Try simplifying your search or selecting "All" for genre/quality.</p>
                            </div>
                        ) : (
                            <>
                                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                                    {movies.map(m => <MovieCard key={m.Movie_ID} movie={m} />)}
                                </div>
                                <Pagination pagination={pagination} onPage={handlePage} />
                            </>
                        )}
                    </main>

                    {/* Footer */}
                    <footer className="max-w-6xl mx-auto p-6 text-center text-slate-500 border-t border-[var(--border)] mt-8">
                        © {new Date().getFullYear()} MovieHub — Built with a passion for film.
                    </footer>
                </div>
            );
        }

        // Render the main App component into the root DOM node
        ReactDOM.createRoot(document.getElementById('root')).render(<App />);
    </script>
</body>
</html>

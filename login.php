<?php
session_start();

// Try to include either db.php or db_config.php (whichever exists)
if (file_exists(__DIR__ . '/db.php')) {
    include __DIR__ . '/db.php';
} elseif (file_exists(__DIR__ . '/db_config.php')) {
    include __DIR__ . '/db_config.php';
} else {
    // safe fallback; $pdo will be null and UI shows friendly debug message
    $pdo = null;
}

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
// Process POST login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_SPECIAL_CHARS);
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        if (!$pdo) {
            $error = "Database connection not initialized. Check db.php or db_config.php.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT Admin_ID, Username, Password FROM Admin WHERE Username = :username LIMIT 1");
                $stmt->execute([':username' => $username]);
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Login Query Error: " . $e->getMessage());
                $admin = false;
                $error = "A system error occurred. Please try again later.";
            }

            // If user found, validate password.
            if ($admin) {
                $stored = $admin['Password'] ?? '';

                // Prefer secure verification if password is hashed (bcrypt/argon2 prefix check).
                $isHashed = (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$argon2') || str_starts_with($stored, '$argon2i') || str_starts_with($stored, '$argon2id'));

                if ($isHashed && password_verify($password, $stored)) {
                    // Success
                    $_SESSION['admin_id'] = $admin['Admin_ID'];
                    $_SESSION['admin_name'] = $admin['Username'];
                    header('Location: dashboard.php');
                    exit;
                } elseif (!$isHashed && hash_equals($stored, $password)) {
                    // Fallback for plain-text passwords (temporary; only for debugging/dev)
                    // IMPORTANT: convert DB passwords to hashes ASAP with password_hash().
                    $_SESSION['admin_id'] = $admin['Admin_ID'];
                    $_SESSION['admin_name'] = $admin['Username'];
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = "Invalid username or password.";
                }
            } else {
                // No row found
                $error = "Invalid username or password.";
            }
        }
    }
}

// JSON encode error for React
$js_error = json_encode($error);
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Admin Login - MovieHub</title>

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
                        // Soft Gold/Amber accent color
                        'accent-gold': '#fcd34d', // --accent from movie.php (Changed from 'accent-green')
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
        /* CSS Variables from movie.php */
        :root{ 
            --bg: #0b141d;      /* Deep blue/black background */
            --card: #152230;    /* Slightly lighter card background */
            --border: #2c3a4d;  /* Subtle border color */
            --muted: #94a3b8;   /* Slate gray for muted text */
            --accent: #fcd34d;  /* Soft Gold/Amber for primary accent */
            --focus: #f59e0b;   /* A darker Amber for focus glow */
        }
        
        /* Updated background gradient */
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(180deg, var(--bg) 0%, #071014 100%); 
        }
        
        /* Updated glass effect for consistency */
        .glass { 
            background: linear-gradient(180deg, var(--card) 0%, var(--card) 100%); 
            backdrop-filter: blur(10px); 
            border: 1px solid var(--border);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.4);
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-6 bg-primary-dark text-white">

    <div id="root" class="w-full max-w-3xl"></div>

    <script type="text/babel">
        const { useState } = React;

        // Pass PHP error into JS (safe)
        const initialError = JSON.parse('<?php echo $js_error; ?>');

        function AdminLogin({ initialError }) {
            const [error] = useState(initialError);
            const [username, setUsername] = useState('');
            const [password, setPassword] = useState('');
            const [showPassword, setShowPassword] = useState(false);

            // Re-map the Tailwind classes to the new color scheme
            const accentClass = "text-accent-gold";
            const bgAccentClass = "bg-accent-gold";
            const hoverBgAccentClass = "hover:bg-accent-gold/90";
            const borderAccentClass = "border-accent-gold";
            const bgRedClass = "bg-red-900/80 border border-red-600";
            const formBorderClass = "border-neutral-700";
            const formBgIconClass = "bg-neutral-800 text-neutral-300";

            return (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-8 items-center">
                    {/* Left - Admin-only info */}
                    <div className="hidden md:flex flex-col justify-center px-8 py-12 rounded-2xl glass shadow-2xl border border-[var(--border)]">
                        <div className="mb-4">
                            <h1 className="text-4xl font-extrabold text-white">Movie<span className={accentClass}>Hub</span></h1>
                            <p className="text-sm text-muted-gray mt-1">Admin portal — for authorized administrators only.</p>
                        </div>

                        {/* Updated primary message and tips */}
                        <div className="mt-6 bg-[var(--card)]/60 border border-[var(--border)] rounded-lg p-4">
                            <h3 className="text-white font-semibold">Admin Access Only</h3>
                            <p className="text-sm text-muted-gray mt-2">Manage movies, genres, users and site content using this panel.</p>

                            <ul className="mt-3 text-sm text-muted-gray space-y-2 list-disc list-inside">
                                <li><strong>Tip:</strong> Use strong, unique passwords and store them hashed.</li>
                                <li><strong>Tip:</strong> If login fails, confirm your database connection file (<code>db.php</code> or <code>db_config.php</code>) and that <code>$pdo</code> is initialized.</li>
                            </ul>
                        </div>

                        <div className="mt-auto text-sm text-muted-gray">
                            <p>Contact the system administrator if you don't have access.</p>
                        </div>
                    </div>

                    {/* Right - Form */}
                    <div className="bg-secondary-mid rounded-2xl p-8 shadow-2xl border border-[var(--border)] glass">
                        <div className="mb-6 text-center">
                            <div className="inline-flex items-center px-3 py-1 rounded-full bg-accent-gold/10 text-accent-gold text-sm font-semibold mb-4">
                                <i className="fas fa-shield-alt mr-2"></i> Admin Login
                            </div>
                            <h2 className="text-2xl text-white font-bold">Welcome back</h2>
                            <p className="text-sm text-muted-gray mt-1">Sign in to manage MovieHub</p>
                        </div>

                        {error && (
                            <div className={`mb-4 p-3 rounded-lg ${bgRedClass} text-red-200`}>
                                <strong className="block font-semibold">Error</strong>
                                <div className="text-sm mt-1">{error}</div>
                            </div>
                        )}

                        {/* The form POSTS to the same PHP file */}
                        <form method="post" action="" className="space-y-4">
                            <label className="block">
                                <span className="text-sm text-muted-gray">Username</span>
                                <div className={`mt-1 flex rounded-lg overflow-hidden border ${formBorderClass}`}>
                                    <span className={`inline-flex items-center px-3 ${formBgIconClass}`}>
                                        <i className="fas fa-user"></i>
                                    </span>
                                    <input
                                        name="username"
                                        value={username}
                                        onChange={(e) => setUsername(e.target.value)}
                                        required
                                        className="flex-1 bg-transparent px-4 py-3 outline-none text-white placeholder-muted-gray"
                                        placeholder="admin"
                                        autoComplete="username"
                                    />
                                </div>
                            </label>

                            <label className="block">
                                <span className="text-sm text-muted-gray">Password</span>
                                <div className={`mt-1 flex rounded-lg overflow-hidden border ${formBorderClass}`}>
                                    <span className={`inline-flex items-center px-3 ${formBgIconClass}`}>
                                        <i className="fas fa-lock"></i>
                                    </span>
                                    <input
                                        name="password"
                                        type={showPassword ? 'text' : 'password'}
                                        value={password}
                                        onChange={(e) => setPassword(e.target.value)}
                                        required
                                        className="flex-1 bg-transparent px-4 py-3 outline-none text-white placeholder-muted-gray"
                                        placeholder="••••••••"
                                        autoComplete="current-password"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className={`px-3 ${formBgIconClass}`}
                                        aria-label="Toggle show password"
                                    >
                                        <i className={`fas ${showPassword ? 'fa-eye-slash' : 'fa-eye'}`}></i>
                                    </button>
                                </div>
                            </label>

                            <div className="flex items-center justify-between">
                                <label className="inline-flex items-center space-x-2">
                                    {/* Updated checkbox color */}
                                    <input type="checkbox" name="remember" className="form-checkbox h-4 w-4 text-accent-gold rounded focus:ring-accent-gold" />
                                    <span className="text-sm text-muted-gray">Remember me</span>
                                </label>
                                <a href="#" className={`text-sm ${accentClass} hover:underline`}>Forgot?</a>
                            </div>

                            <button
                                name="login"
                                type="submit"
                                className={`w-full py-3 rounded-lg ${bgAccentClass} text-black font-semibold ${hoverBgAccentClass} transition`}
                            >
                                Sign In
                            </button>
                        </form>

                        <div className="mt-6 text-center text-xs text-muted-gray">
                            <p>&copy; {new Date().getFullYear()} MovieHub</p>
                        </div>
                    </div>
                </div>
            );
        }

        const root = ReactDOM.createRoot(document.getElementById('root'));
        root.render(<AdminLogin initialError={initialError} />);
    </script>

</body>
</html>
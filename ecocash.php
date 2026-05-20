<?php
// ============================================
// ECOCASH INVESTMENT PLATFORM
// Perfect Match with Loan Service Styling
// Integrated with https://ecocash-bcj.onrender.com
// ============================================

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Database setup
$db_file = __DIR__ . '/data/ecocash_investment.db';
$data_dir = dirname($db_file);
if (!file_exists($data_dir)) mkdir($data_dir, 0777, true);

$db = new SQLite3($db_file);
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec('PRAGMA journal_mode = WAL;');

// Create tables
$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL,
        full_name TEXT NOT NULL,
        phone TEXT NOT NULL,
        ecocash_number TEXT NOT NULL,
        balance DECIMAL(15,2) DEFAULT 1000.00,
        total_invested DECIMAL(15,2) DEFAULT 0,
        total_returns DECIMAL(15,2) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS investment_products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        min_amount DECIMAL(10,2) NOT NULL,
        interest_rate DECIMAL(5,2) NOT NULL,
        duration_months INTEGER NOT NULL,
        risk_level TEXT,
        icon TEXT,
        is_active INTEGER DEFAULT 1
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS investments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        reference TEXT UNIQUE NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        interest_rate DECIMAL(5,2) NOT NULL,
        duration_months INTEGER NOT NULL,
        expected_returns DECIMAL(15,2),
        start_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        maturity_date DATETIME,
        status TEXT DEFAULT 'active',
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (product_id) REFERENCES investment_products(id)
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        reference TEXT UNIQUE NOT NULL,
        type TEXT NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

// Insert investment products
$check = $db->query("SELECT COUNT(*) as count FROM investment_products");
$row = $check->fetchArray();
if ($row['count'] == 0) {
    $products = [
        ['EcoSavings Plus', 'High-yield savings account with competitive returns', 10, 6.0, 12, 'Low', '💰'],
        ['Growth Fund', 'Aggressive growth portfolio for wealth building', 50, 12.0, 24, 'Medium', '📈'],
        ['Agri-Bond', 'Support agriculture while earning high returns', 100, 15.0, 36, 'Medium', '🌾'],
        ['Real Estate Token', 'Fractional property investment with monthly income', 1, 10.0, 60, 'High', '🏠'],
        ['Dura Micro-Pension', 'Long-term retirement savings with compound interest', 1, 8.5, 120, 'Low', '🏦']
    ];
    foreach ($products as $p) {
        $db->exec("INSERT INTO investment_products (name, description, min_amount, interest_rate, duration_months, risk_level, icon) 
            VALUES ('{$p[0]}', '{$p[1]}', {$p[2]}, {$p[3]}, {$p[4]}, '{$p[5]}', '{$p[6]}')");
    }
}

// Helper functions
function isLoggedIn() { return isset($_SESSION['user_id']); }
function formatMoney($amount) { return '$' . number_format($amount, 2); }
function generateRef($prefix) { return $prefix . date('Ymd') . rand(10000, 99999) . time(); }
function calculateReturns($amount, $rate, $months) {
    $total = $amount * (1 + ($rate/100) * ($months/12));
    return round($total - $amount, 2);
}
function getLoanServiceUrl() { 
    // Add return_url parameter to come back to investment platform
    $return_url = urlencode((isset($_SERVER['HTTPS']) ? "https://" : "http://") . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?action=dashboard');
    return 'https://ecocash-bcj.onrender.com?return_url=' . $return_url;
}

// Process Registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $username = SQLite3::escapeString($_POST['username']);
    $email = SQLite3::escapeString($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $full_name = SQLite3::escapeString($_POST['full_name']);
    $phone = SQLite3::escapeString($_POST['phone']);
    $ecocash = SQLite3::escapeString($_POST['ecocash_number']);
    
    $stmt = $db->prepare("INSERT INTO users (username, email, password, full_name, phone, ecocash_number, balance) VALUES (?, ?, ?, ?, ?, ?, 1000)");
    $stmt->bindValue(1, $username, SQLITE3_TEXT);
    $stmt->bindValue(2, $email, SQLITE3_TEXT);
    $stmt->bindValue(3, $password, SQLITE3_TEXT);
    $stmt->bindValue(4, $full_name, SQLITE3_TEXT);
    $stmt->bindValue(5, $phone, SQLITE3_TEXT);
    $stmt->bindValue(6, $ecocash, SQLITE3_TEXT);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "Registration successful! Welcome bonus of $1,000 added!";
        header("Location: ?action=login");
        exit();
    } else { $error = "Username or email already exists!"; }
}

// Process Login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $username = SQLite3::escapeString($_POST['username']);
    $password = $_POST['password'];
    $result = $db->query("SELECT * FROM users WHERE username = '$username' OR email = '$username'");
    $user = $result->fetchArray(SQLITE3_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $db->exec("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = " . $user['id']);
        $_SESSION['success'] = "Welcome back, " . $user['full_name'] . "!";
        header("Location: ?action=dashboard");
        exit();
    } else { $error = "Invalid username or password!"; }
}

// Process Deposit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['deposit']) && isLoggedIn()) {
    $amount = (float)$_POST['amount'];
    $user_id = $_SESSION['user_id'];
    if ($amount < 5) { $error = "Minimum deposit is $5"; }
    else {
        $ref = generateRef('DEP');
        $db->exec("INSERT INTO transactions (user_id, reference, type, amount, description) VALUES ($user_id, '$ref', 'deposit', $amount, 'EcoCash deposit')");
        $db->exec("UPDATE users SET balance = balance + $amount WHERE id = $user_id");
        $_SESSION['success'] = "Deposited " . formatMoney($amount) . " successfully!";
        header("Location: ?action=dashboard");
        exit();
    }
}

// Process Withdrawal
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['withdraw']) && isLoggedIn()) {
    $amount = (float)$_POST['amount'];
    $user_id = $_SESSION['user_id'];
    $user = $db->query("SELECT balance FROM users WHERE id = $user_id")->fetchArray(SQLITE3_ASSOC);
    if ($amount > $user['balance']) { $error = "Insufficient balance!"; }
    else {
        $ref = generateRef('WIT');
        $db->exec("INSERT INTO transactions (user_id, reference, type, amount, description) VALUES ($user_id, '$ref', 'withdrawal', $amount, 'Withdrawal to EcoCash')");
        $db->exec("UPDATE users SET balance = balance - $amount WHERE id = $user_id");
        $_SESSION['success'] = "Withdrew " . formatMoney($amount) . " to your EcoCash!";
        header("Location: ?action=dashboard");
        exit();
    }
}

// Process Investment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['invest']) && isLoggedIn()) {
    $product_id = (int)$_POST['product_id'];
    $amount = (float)$_POST['amount'];
    $user_id = $_SESSION['user_id'];
    $product = $db->query("SELECT * FROM investment_products WHERE id = $product_id")->fetchArray(SQLITE3_ASSOC);
    $user = $db->query("SELECT balance FROM users WHERE id = $user_id")->fetchArray(SQLITE3_ASSOC);
    
    if ($amount < $product['min_amount']) { $error = "Minimum investment is " . formatMoney($product['min_amount']); }
    elseif ($amount > $user['balance']) { $error = "Insufficient balance!"; }
    else {
        $returns = calculateReturns($amount, $product['interest_rate'], $product['duration_months']);
        $ref = generateRef('INV');
        $maturity = date('Y-m-d H:i:s', strtotime("+{$product['duration_months']} months"));
        
        $stmt = $db->prepare("INSERT INTO investments (user_id, product_id, reference, amount, interest_rate, duration_months, expected_returns, maturity_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(2, $product_id, SQLITE3_INTEGER);
        $stmt->bindValue(3, $ref, SQLITE3_TEXT);
        $stmt->bindValue(4, $amount, SQLITE3_FLOAT);
        $stmt->bindValue(5, $product['interest_rate'], SQLITE3_FLOAT);
        $stmt->bindValue(6, $product['duration_months'], SQLITE3_INTEGER);
        $stmt->bindValue(7, $returns, SQLITE3_FLOAT);
        $stmt->bindValue(8, $maturity, SQLITE3_TEXT);
        $stmt->execute();
        
        $db->exec("INSERT INTO transactions (user_id, reference, type, amount, description) VALUES ($user_id, '$ref', 'investment', $amount, 'Investment in {$product['name']}')");
        $db->exec("UPDATE users SET balance = balance - $amount, total_invested = total_invested + $amount WHERE id = $user_id");
        
        $_SESSION['success'] = "Invested " . formatMoney($amount) . " in {$product['name']}! Expected returns: " . formatMoney($returns);
        header("Location: ?action=portfolio");
        exit();
    }
}

// Logout
if (isset($_GET['logout'])) { session_destroy(); header("Location: ?action=home"); exit(); }

// Check if coming back from loan service
if (isset($_GET['from_loan']) && $_GET['from_loan'] == '1') {
    $_SESSION['success'] = "Welcome back from EcoCash Loan Services!";
}

$action = isset($_GET['action']) ? $_GET['action'] : 'home';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EcoCash Investment - Grow Your Wealth</title>
    <style>
        /* ============================================ */
        /* EXACT MATCH WITH LOAN SERVICE STYLING */
        /* Colors match https://ecocash-bcj.onrender.com */
        /* ============================================ */
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        /* Navigation - Matching Loan Service */
        .navbar {
            background: white;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: #667eea;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-links {
            display: flex;
            gap: 1.5rem;
            list-style: none;
            flex-wrap: wrap;
        }
        
        .nav-links a {
            text-decoration: none;
            color: #333;
            font-weight: 500;
            transition: color 0.3s;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
        }
        
        .nav-links a:hover {
            color: #667eea;
            background: #f3f4f6;
        }
        
        .btn-logout {
            background: #ef4444;
            color: white !important;
        }
        
        .btn-logout:hover {
            background: #dc2626;
            color: white !important;
        }
        
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        /* Main Container */
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        /* Cards - Matching Loan Service */
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 1.5rem;
            transition: transform 0.3s;
        }
        
        .card:hover {
            transform: translateY(-5px);
        }
        
        .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Hero Section - Matching Loan Service */
        .hero {
            background: white;
            border-radius: 15px;
            padding: 3rem;
            text-align: center;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .hero h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #333;
        }
        
        .hero .highlight {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .hero p {
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 1.5rem;
        }
        
        /* Buttons - Matching Loan Service */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-outline {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
        }
        
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
        .btn-back {
            background: #6b7280;
            color: white;
        }
        
        .btn-back:hover {
            background: #4b5563;
            transform: translateY(-2px);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card h3 {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #666;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-top: 0.5rem;
        }
        
        /* Products Grid */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }
        
        .product-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            transition: all 0.3s;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(102, 126, 234, 0.2);
        }
        
        .product-icon {
            font-size: 3rem;
            text-align: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
        }
        
        .product-info {
            padding: 1.25rem;
        }
        
        .product-info h3 {
            margin-bottom: 0.5rem;
        }
        
        .product-info p {
            color: #666;
            font-size: 0.9rem;
            margin: 0.5rem 0;
        }
        
        /* Risk Badges */
        .risk-low {
            color: #10b981;
            font-weight: 600;
        }
        
        .risk-medium {
            color: #f59e0b;
            font-weight: 600;
        }
        
        .risk-high {
            color: #ef4444;
            font-weight: 600;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        /* Tables */
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        /* Integration Banner */
        .integration-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            text-align: center;
            color: white;
            position: relative;
        }
        
        .integration-banner a {
            color: #FFD700;
            text-decoration: none;
            font-weight: bold;
        }
        
        .integration-banner a:hover {
            text-decoration: underline;
        }
        
        /* Feature Cards */
        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .feature-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .feature-card:hover {
            transform: translateY(-3px);
        }
        
        .feature-icon {
            font-size: 2rem;
            margin-bottom: 0.75rem;
        }
        
        /* Footer */
        .footer {
            background: white;
            color: #666;
            text-align: center;
            padding: 2rem;
            margin-top: 3rem;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.05);
        }
        
        .footer a {
            color: #667eea;
            text-decoration: none;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }
            
            .nav-links {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: white;
                flex-direction: column;
                padding: 1rem;
                gap: 0.5rem;
                box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            }
            
            .nav-links.active {
                display: flex;
            }
            
            .hero {
                padding: 2rem;
            }
            
            .hero h1 {
                font-size: 1.8rem;
            }
            
            .stats-grid, .products-grid {
                grid-template-columns: 1fr;
            }
            
            .card-body {
                padding: 1rem;
            }
        }
        
        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Progress Bar */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 3px;
            transition: width 0.3s;
        }
        
        /* Floating Action Button */
        .fab-back {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: all 0.3s;
            z-index: 1000;
            font-size: 1.5rem;
        }
        
        .fab-back:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>

<?php if (isLoggedIn() && !in_array($action, ['login', 'register'])): ?>
<nav class="navbar">
    <div class="nav-container">
        <a href="?action=dashboard" class="logo">💰 EcoCash Investment</a>
        <button class="mobile-menu-btn" onclick="toggleMenu()">☰</button>
        <ul class="nav-links" id="navMenu">
            <li><a href="?action=dashboard">📊 Dashboard</a></li>
            <li><a href="?action=invest">💰 Invest</a></li>
            <li><a href="?action=portfolio">📁 Portfolio</a></li>
            <li><a href="?action=transactions">📜 History</a></li>
            <li><a href="?logout=1" class="btn-logout">🚪 Logout</a></li>
        </ul>
    </div>
</nav>
<?php endif; ?>

<div class="container fade-in">

<?php
if (isset($_SESSION['success'])) {
    echo '<div class="alert alert-success">✅ ' . $_SESSION['success'] . '</div>';
    unset($_SESSION['success']);
}
if (isset($error)) {
    echo '<div class="alert alert-error">❌ ' . $error . '</div>';
}
?>

<!-- Integration Banner - Link to Loan Service -->
<div class="integration-banner">
    <p style="font-size: 1.1rem;">🏦 Need a loan? Visit our partner service</p>
    <p><a href="<?php echo getLoanServiceUrl(); ?>" target="_blank" id="loanServiceLink"><strong>EcoCash Loan Services →</strong></a></p>
    <p style="font-size: 0.8rem; margin-top: 0.5rem;">Quick approval • Competitive rates • Flexible terms</p>
</div>

<?php
// ============================================
// PAGE ROUTING
// ============================================

// HOME PAGE
if ($action == 'home'): ?>
    <div class="hero">
        <h1>💰 <span class="highlight">EcoCash</span> Investment</h1>
        <p>Grow your wealth with secure, high-return investment plans</p>
        <p style="font-size: 0.9rem;">Start from <strong>$1</strong> • Earn up to <strong>15%</strong> annually</p>
        <div style="margin-top: 1.5rem;">
            <a href="?action=register" class="btn btn-primary">🚀 Get Started</a>
            <a href="?action=login" class="btn btn-outline">🔐 Login</a>
        </div>
    </div>
    
    <div class="feature-grid">
        <div class="feature-card">
            <div class="feature-icon">✅</div>
            <h3>100% Secure</h3>
            <p>EcoCash protected</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">📱</div>
            <h3>Mobile First</h3>
            <p>Invest anywhere</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">💰</div>
            <h3>High Returns</h3>
            <p>Up to 15% p.a.</p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">⚡</div>
            <h3>Instant</h3>
            <p>Real-time</p>
        </div>
    </div>
    
    <h2 style="text-align: center; margin-bottom: 1.5rem;">🔥 Investment Plans</h2>
    <div class="products-grid">
        <?php
        $products = $db->query("SELECT * FROM investment_products LIMIT 3");
        while ($product = $products->fetchArray(SQLITE3_ASSOC)):
            $riskClass = $product['risk_level'] == 'Low' ? 'risk-low' : ($product['risk_level'] == 'Medium' ? 'risk-medium' : 'risk-high');
        ?>
            <div class="product-card">
                <div class="product-icon"><?php echo $product['icon']; ?></div>
                <div class="product-info">
                    <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                    <p><?php echo htmlspecialchars($product['description']); ?></p>
                    <p><strong>Min:</strong> <?php echo formatMoney($product['min_amount']); ?></p>
                    <p><strong>Returns:</strong> <span style="color: #667eea; font-weight: bold;"><?php echo $product['interest_rate']; ?>% p.a.</span></p>
                    <p><strong>Duration:</strong> <?php echo $product['duration_months']; ?> months</p>
                    <p><strong>Risk:</strong> <span class="<?php echo $riskClass; ?>"><?php echo $product['risk_level']; ?></span></p>
                </div>
            </div>
        <?php endwhile; ?>
    </div>

<?php 
// REGISTER PAGE
elseif ($action == 'register'): ?>
    <div class="card" style="max-width: 500px; margin: 0 auto;">
        <div class="card-header">
            <h2>Create Account</h2>
            <p>Join EcoCash Investment today</p>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" required placeholder="John Doe">
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" required placeholder="johndoe">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required placeholder="john@example.com">
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="tel" name="phone" required placeholder="+263 77 123 4567">
                </div>
                <div class="form-group">
                    <label>EcoCash Number</label>
                    <input type="tel" name="ecocash_number" required placeholder="077XXXXXXX">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <button type="submit" name="register" class="btn btn-primary" style="width: 100%;">Create Account</button>
                <p style="text-align: center; margin-top: 1rem;">
                    Already have an account? <a href="?action=login" style="color: #667eea;">Login</a>
                </p>
            </form>
        </div>
    </div>

<?php 
// LOGIN PAGE
elseif ($action == 'login'): ?>
    <div class="card" style="max-width: 400px; margin: 0 auto;">
        <div class="card-header">
            <h2>Welcome Back</h2>
            <p>Login to your investment account</p>
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="form-group">
                    <label>Username or Email</label>
                    <input type="text" name="username" required placeholder="Enter username or email">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Enter password">
                </div>
                <button type="submit" name="login" class="btn btn-primary" style="width: 100%;">Login</button>
                <p style="text-align: center; margin-top: 1rem;">
                    Don't have an account? <a href="?action=register" style="color: #667eea;">Register</a>
                </p>
            </form>
        </div>
    </div>

<?php 
// DASHBOARD
elseif ($action == 'dashboard' && isLoggedIn()):
    $user_id = $_SESSION['user_id'];
    $user = $db->query("SELECT * FROM users WHERE id = $user_id")->fetchArray(SQLITE3_ASSOC);
    $total_invested = $db->query("SELECT SUM(amount) as total FROM investments WHERE user_id = $user_id AND status = 'active'")->fetchArray(SQLITE3_ASSOC);
    $total_returns = $db->query("SELECT SUM(expected_returns) as total FROM investments WHERE user_id = $user_id AND status = 'active'")->fetchArray(SQLITE3_ASSOC);
    $active_count = $db->query("SELECT COUNT(*) as count FROM investments WHERE user_id = $user_id AND status = 'active'")->fetchArray(SQLITE3_ASSOC);
    $recent = $db->query("SELECT * FROM transactions WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5");
    ?>
    
    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>! 👋</h1>
    
    <div class="stats-grid">
        <div class="stat-card">
            <h3>💰 Balance</h3>
            <div class="stat-value"><?php echo formatMoney($user['balance']); ?></div>
        </div>
        <div class="stat-card">
            <h3>📈 Invested</h3>
            <div class="stat-value"><?php echo formatMoney($total_invested['total'] ?? 0); ?></div>
        </div>
        <div class="stat-card">
            <h3>🎯 Expected Returns</h3>
            <div class="stat-value"><?php echo formatMoney($total_returns['total'] ?? 0); ?></div>
        </div>
        <div class="stat-card">
            <h3>📊 Active</h3>
            <div class="stat-value"><?php echo $active_count['count']; ?></div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3>Quick Actions</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                <form method="POST" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <input type="number" name="amount" placeholder="Amount" step="0.01" min="5" required style="width: 150px;">
                    <button type="submit" name="deposit" class="btn btn-success">💰 Deposit</button>
                    <button type="submit" name="withdraw" class="btn btn-danger">💸 Withdraw</button>
                </form>
                <a href="?action=invest" class="btn btn-primary">📈 Invest</a>
                <a href="?action=portfolio" class="btn btn-outline">📁 Portfolio</a>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3>Recent Transactions</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>Reference</th><th>Type</th><th>Amount</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php while ($t = $recent->fetchArray(SQLITE3_ASSOC)): ?>
                        <tr>
                            <td><?php echo $t['reference']; ?></td>
                            <td><?php echo ucfirst($t['type']); ?></td>
                            <td><?php echo formatMoney($t['amount']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($t['created_at'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php 
// INVEST PAGE
elseif ($action == 'invest' && isLoggedIn()):
    $user = $db->query("SELECT balance FROM users WHERE id = {$_SESSION['user_id']}")->fetchArray(SQLITE3_ASSOC);
    ?>
    
    <h1>Make an Investment</h1>
    <p>Available balance: <strong style="color: #667eea;"><?php echo formatMoney($user['balance']); ?></strong></p>
    
    <div class="products-grid">
        <?php
        $products = $db->query("SELECT * FROM investment_products");
        while ($product = $products->fetchArray(SQLITE3_ASSOC)):
            $riskClass = $product['risk_level'] == 'Low' ? 'risk-low' : ($product['risk_level'] == 'Medium' ? 'risk-medium' : 'risk-high');
        ?>
            <div class="product-card">
                <form method="POST">
                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                    <div class="product-icon"><?php echo $product['icon']; ?></div>
                    <div class="product-info">
                        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                        <p><?php echo htmlspecialchars($product['description']); ?></p>
                        <p><strong>Rate:</strong> <span style="color: #667eea; font-weight: bold;"><?php echo $product['interest_rate']; ?>% p.a.</span></p>
                        <p><strong>Duration:</strong> <?php echo $product['duration_months']; ?> months</p>
                        <p><strong>Min:</strong> <?php echo formatMoney($product['min_amount']); ?></p>
                        <p><strong>Risk:</strong> <span class="<?php echo $riskClass; ?>"><?php echo $product['risk_level']; ?></span></p>
                        <div class="form-group" style="margin-top: 1rem;">
                            <label>Amount ($)</label>
                            <input type="number" name="amount" min="<?php echo $product['min_amount']; ?>" step="0.01" required>
                        </div>
                        <button type="submit" name="invest" class="btn btn-primary" style="width: 100%;">Invest Now</button>
                    </div>
                </form>
            </div>
        <?php endwhile; ?>
    </div>

<?php 
// PORTFOLIO PAGE
elseif ($action == 'portfolio' && isLoggedIn()):
    $investments = $db->query("SELECT i.*, p.name as pname, p.icon FROM investments i JOIN investment_products p ON i.product_id = p.id WHERE i.user_id = {$_SESSION['user_id']} ORDER BY i.start_date DESC");
    ?>
    
    <h1>My Portfolio</h1>
    
    <div class="card">
        <div class="card-header">
            <h3>Active Investments</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>Product</th><th>Amount</th><th>Rate</th><th>Expected Returns</th><th>Start Date</th><th>Maturity</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php while ($inv = $investments->fetchArray(SQLITE3_ASSOC)): ?>
                        <tr>
                            <td><?php echo $inv['icon'] . ' ' . $inv['pname']; ?></td>
                            <td><?php echo formatMoney($inv['amount']); ?></td>
                            <td><?php echo $inv['interest_rate']; ?>%</td>
                            <td style="color: #667eea; font-weight: bold;"><?php echo formatMoney($inv['expected_returns']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($inv['start_date'])); ?></td>
                            <td><?php echo date('M d, Y', strtotime($inv['maturity_date'])); ?></td>
                            <td><span class="risk-low">Active</span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php 
// TRANSACTIONS PAGE
elseif ($action == 'transactions' && isLoggedIn()):
    $transactions = $db->query("SELECT * FROM transactions WHERE user_id = {$_SESSION['user_id']} ORDER BY created_at DESC LIMIT 50");
    ?>
    
    <h1>Transaction History</h1>
    
    <div class="card">
        <div class="card-header">
            <h3>All Transactions</h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr><th>Reference</th><th>Type</th><th>Amount</th><th>Description</th><th>Date</th></tr>
                    </thead>
                    <tbody>
                        <?php while ($t = $transactions->fetchArray(SQLITE3_ASSOC)): ?>
                        <tr>
                            <td><?php echo $t['reference']; ?></td>
                            <td><?php echo ucfirst($t['type']); ?></td>
                            <td><?php echo formatMoney($t['amount']); ?></td>
                            <td><?php echo $t['description']; ?></td>
                            <td><?php echo date('M d, Y H:i', strtotime($t['created_at'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>

<div class="footer">
    <p>💰 EcoCash Investment Platform</p>
    <p style="margin-top: 0.5rem;">
        <a href="?action=home">Home</a> | 
        <a href="?action=login">Login</a> | 
        <a href="?action=register">Register</a> |
        <a href="<?php echo getLoanServiceUrl(); ?>" target="_blank">Loan Services</a>
    </p>
    <p style="margin-top: 0.5rem; font-size: 0.8rem;">
        © <?php echo date('Y'); ?> EcoCash Investment. All rights reserved.
    </p>
</div>

<!-- Floating Action Button for Quick Return -->
<a href="?action=dashboard" class="fab-back" title="Back to Investment">💰</a>

<script>
function toggleMenu() {
    document.getElementById('navMenu').classList.toggle('active');
}

document.addEventListener('click', function(event) {
    const menu = document.getElementById('navMenu');
    const btn = document.querySelector('.mobile-menu-btn');
    if (menu && btn && !menu.contains(event.target) && !btn.contains(event.target)) {
        menu.classList.remove('active');
    }
});

// Animate stats on load
document.addEventListener('DOMContentLoaded', function() {
    const statValues = document.querySelectorAll('.stat-value');
    statValues.forEach(stat => {
        const text = stat.innerText;
        const value = parseFloat(text.replace('$', '').replace(',', ''));
        if (!isNaN(value)) {
            let current = 0;
            const increment = value / 50;
            const timer = setInterval(() => {
                current += increment;
                if (current >= value) {
                    clearInterval(timer);
                    stat.innerText = text;
                } else {
                    stat.innerText = '$' + Math.floor(current).toLocaleString();
                }
            }, 20);
        }
    });
    
    // Store current page before leaving to loan service
    const loanLink = document.getElementById('loanServiceLink');
    if (loanLink) {
        loanLink.addEventListener('click', function(e) {
            localStorage.setItem('return_to_investment', window.location.href);
        });
    }
    
    // Check if returning from loan service
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('from_loan') === '1') {
        // Show welcome back message
        const banner = document.querySelector('.integration-banner');
        if (banner) {
            const msg = document.createElement('div');
            msg.className = 'alert alert-success';
            msg.innerHTML = '✅ Welcome back from EcoCash Loan Services!';
            msg.style.marginBottom = '1rem';
            banner.parentNode.insertBefore(msg, banner);
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500);
            }, 5000);
        }
    }
});

// Handle popstate for back button
window.addEventListener('popstate', function(event) {
    if (document.referrer && document.referrer.includes('ecocash-bcj.onrender.com')) {
        window.location.href = '?action=dashboard&from_loan=1';
    }
});
</script>

</body>
</html>. 
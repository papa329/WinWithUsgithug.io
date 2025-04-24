<?php
session_start();

// Configuration de la base de données
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'p2p_haiti');

// Connexion à la base de données
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Création des tables si elles n'existent pas
function createTables($conn) {
    $sql = "
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        phone VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        verified BOOLEAN DEFAULT FALSE
    );
    
    CREATE TABLE IF NOT EXISTS user_wallets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        currency VARCHAR(10) NOT NULL,
        balance DECIMAL(20,2) DEFAULT 0.00,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
    
    CREATE TABLE IF NOT EXISTS p2p_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type ENUM('buy', 'sell') NOT NULL,
        currency_from VARCHAR(10) NOT NULL,
        currency_to VARCHAR(10) NOT NULL,
        amount DECIMAL(20,2) NOT NULL,
        price DECIMAL(20,2) NOT NULL,
        min_amount DECIMAL(20,2) DEFAULT 0.00,
        max_amount DECIMAL(20,2) DEFAULT 0.00,
        payment_method VARCHAR(50) NOT NULL,
        status ENUM('open', 'completed', 'cancelled', 'disputed') DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id)
    );
    
    CREATE TABLE IF NOT EXISTS p2p_trades (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        buyer_id INT NOT NULL,
        seller_id INT NOT NULL,
        amount DECIMAL(20,2) NOT NULL,
        price DECIMAL(20,2) NOT NULL,
        fee DECIMAL(20,2) NOT NULL,
        status ENUM('pending', 'paid', 'completed', 'cancelled', 'disputed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        FOREIGN KEY (order_id) REFERENCES p2p_orders(id),
        FOREIGN KEY (buyer_id) REFERENCES users(id),
        FOREIGN KEY (seller_id) REFERENCES users(id)
    );
    
    CREATE TABLE IF NOT EXISTS site_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        p2p_fee DECIMAL(5,2) DEFAULT 1.00,
        admin_wallet VARCHAR(100)
    );
    ";
    
    if ($conn->multi_query($sql)) {
        do {
            if ($result = $conn->store_result()) {
                $result->free();
            }
        } while ($conn->next_result());
    }
    
    // Insérer les paramètres par défaut si la table est vide
    $check = $conn->query("SELECT COUNT(*) as count FROM site_settings");
    $row = $check->fetch_assoc();
    if ($row['count'] == 0) {
        $conn->query("INSERT INTO site_settings (p2p_fee, admin_wallet) VALUES (1.00, 'admin_wallet_address')");
    }
}

createTables($conn);

// Fonctions utilitaires
function sanitize($data) {
    global $conn;
    return htmlspecialchars(strip_tags($conn->real_escape_string($data)));
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUser($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getWalletBalance($user_id, $currency) {
    global $conn;
    $stmt = $conn->prepare("SELECT balance FROM user_wallets WHERE user_id = ? AND currency = ?");
    $stmt->bind_param("is", $user_id, $currency);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['balance'];
    }
    return 0;
}

function updateWallet($user_id, $currency, $amount) {
    global $conn;
    
    // Vérifier si le portefeuille existe
    $stmt = $conn->prepare("SELECT id FROM user_wallets WHERE user_id = ? AND currency = ?");
    $stmt->bind_param("is", $user_id, $currency);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Mettre à jour le solde existant
        $stmt = $conn->prepare("UPDATE user_wallets SET balance = balance + ? WHERE user_id = ? AND currency = ?");
        $stmt->bind_param("dis", $amount, $user_id, $currency);
    } else {
        // Créer un nouveau portefeuille
        $stmt = $conn->prepare("INSERT INTO user_wallets (user_id, currency, balance) VALUES (?, ?, ?)");
        $stmt->bind_param("isd", $user_id, $currency, $amount);
    }
    
    return $stmt->execute();
}

// Gestion des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'login':
                $username = sanitize($_POST['username']);
                $password = sanitize($_POST['password']);
                
                $stmt = $conn->prepare("SELECT id, password FROM users WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    if (password_verify($password, $user['password'])) {
                        $_SESSION['user_id'] = $user['id'];
                        header("Location: index.php");
                        exit();
                    } else {
                        $error = "Mot de passe incorrect";
                    }
                } else {
                    $error = "Nom d'utilisateur incorrect";
                }
                break;
                
            case 'signup':
                $username = sanitize($_POST['username']);
                $email = sanitize($_POST['email']);
                $password = password_hash(sanitize($_POST['password']), PASSWORD_DEFAULT);
                $phone = sanitize($_POST['phone']);
                
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, phone) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $username, $email, $password, $phone);
                
                if ($stmt->execute()) {
                    $_SESSION['user_id'] = $conn->insert_id;
                    header("Location: index.php");
                    exit();
                } else {
                    $error = "Erreur lors de l'inscription: " . $conn->error;
                }
                break;
                
            case 'create_order':
                if (!isLoggedIn()) {
                    $error = "Vous devez être connecté";
                    break;
                }
                
                $type = sanitize($_POST['type']);
                $currency_from = sanitize($_POST['currency_from']);
                $currency_to = sanitize($_POST['currency_to']);
                $amount = floatval($_POST['amount']);
                $price = floatval($_POST['price']);
                $min_amount = floatval($_POST['min_amount']);
                $max_amount = floatval($_POST['max_amount']);
                $payment_method = sanitize($_POST['payment_method']);
                
                $stmt = $conn->prepare("INSERT INTO p2p_orders (user_id, type, currency_from, currency_to, amount, price, min_amount, max_amount, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssddddss", $_SESSION['user_id'], $type, $currency_from, $currency_to, $amount, $price, $min_amount, $max_amount, $payment_method);
                
                if ($stmt->execute()) {
                    $success = "Ordre créé avec succès";
                } else {
                    $error = "Erreur lors de la création de l'ordre: " . $conn->error;
                }
                break;
                
            case 'create_trade':
                if (!isLoggedIn()) {
                    $error = "Vous devez être connecté";
                    break;
                }
                
                $order_id = intval($_POST['order_id']);
                $amount = floatval($_POST['amount']);
                
                // Récupérer les détails de l'ordre
                $stmt = $conn->prepare("SELECT * FROM p2p_orders WHERE id = ?");
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $order = $stmt->get_result()->fetch_assoc();
                
                if (!$order) {
                    $error = "Ordre introuvable";
                    break;
                }
                
                // Calculer les frais
                $settings = $conn->query("SELECT p2p_fee FROM site_settings LIMIT 1")->fetch_assoc();
                $fee = ($amount * $order['price'] * $settings['p2p_fee']) / 100;
                $total_amount = $amount * $order['price'];
                
                // Créer l'échange
                $buyer_id = ($order['type'] == 'sell') ? $_SESSION['user_id'] : $order['user_id'];
                $seller_id = ($order['type'] == 'sell') ? $order['user_id'] : $_SESSION['user_id'];
                
                $stmt = $conn->prepare("INSERT INTO p2p_trades (order_id, buyer_id, seller_id, amount, price, fee, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->bind_param("iiiddd", $order_id, $buyer_id, $seller_id, $amount, $order['price'], $fee);
                
                if ($stmt->execute()) {
                    // Marquer l'ordre comme complété si tout le montant est échangé
                    if ($amount >= $order['amount']) {
                        $conn->query("UPDATE p2p_orders SET status = 'completed', completed_at = NOW() WHERE id = $order_id");
                    } else {
                        $conn->query("UPDATE p2p_orders SET amount = amount - $amount WHERE id = $order_id");
                    }
                    
                    $success = "Échange créé avec succès. Veuillez effectuer le paiement et confirmer.";
                } else {
                    $error = "Erreur lors de la création de l'échange: " . $conn->error;
                }
                break;
                
            case 'confirm_payment':
                if (!isLoggedIn()) {
                    $error = "Vous devez être connecté";
                    break;
                }
                
                $trade_id = intval($_POST['trade_id']);
                
                // Vérifier que l'utilisateur est bien l'acheteur
                $stmt = $conn->prepare("SELECT * FROM p2p_trades WHERE id = ? AND buyer_id = ?");
                $stmt->bind_param("ii", $trade_id, $_SESSION['user_id']);
                $stmt->execute();
                $trade = $stmt->get_result()->fetch_assoc();
                
                if (!$trade) {
                    $error = "Échange introuvable ou vous n'êtes pas autorisé";
                    break;
                }
                
                // Mettre à jour le statut de l'échange
                $conn->query("UPDATE p2p_trades SET status = 'paid' WHERE id = $trade_id");
                $success = "Paiement confirmé. En attente de la confirmation du vendeur.";
                break;
                
            case 'release_funds':
                if (!isLoggedIn()) {
                    $error = "Vous devez être connecté";
                    break;
                }
                
                $trade_id = intval($_POST['trade_id']);
                
                // Vérifier que l'utilisateur est bien le vendeur
                $stmt = $conn->prepare("SELECT * FROM p2p_trades WHERE id = ? AND seller_id = ?");
                $stmt->bind_param("ii", $trade_id, $_SESSION['user_id']);
                $stmt->execute();
                $trade = $stmt->get_result()->fetch_assoc();
                
                if (!$trade) {
                    $error = "Échange introuvable ou vous n'êtes pas autorisé";
                    break;
                }
                
                // Récupérer les détails de l'ordre
                $order = $conn->query("SELECT * FROM p2p_orders WHERE id = {$trade['order_id']}")->fetch_assoc();
                
                // Effectuer les transferts
                $conn->begin_transaction();
                
                try {
                    // Transférer les fonds à l'acheteur
                    $amount_to_buyer = $trade['amount'];
                    updateWallet($trade['buyer_id'], $order['currency_from'], $amount_to_buyer);
                    
                    // Transférer les fonds au vendeur (moins les frais)
                    $amount_to_seller = ($trade['amount'] * $trade['price']) - $trade['fee'];
                    updateWallet($trade['seller_id'], $order['currency_to'], $amount_to_seller);
                    
                    // Transférer les frais au propriétaire
                    $settings = $conn->query("SELECT admin_wallet FROM site_settings LIMIT 1")->fetch_assoc();
                    if ($settings['admin_wallet']) {
                        $admin_id = $conn->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetch_assoc()['id'];
                        if ($admin_id) {
                            updateWallet($admin_id, $order['currency_to'], $trade['fee']);
                        }
                    }
                    
                    // Mettre à jour le statut de l'échange
                    $conn->query("UPDATE p2p_trades SET status = 'completed', completed_at = NOW() WHERE id = $trade_id");
                    
                    $conn->commit();
                    $success = "Fonds libérés avec succès. L'échange est maintenant terminé.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Erreur lors du transfert des fonds: " . $e->getMessage();
                }
                break;
        }
    }
}

// Récupérer les données pour l'affichage
$currencies = ['USD', 'HTG', 'EUR', 'CAD'];
$payment_methods = ['NatCash', 'ManCash', 'Zelle', 'Wise', 'Unibank', 'Buh', 'Sogebank', 'PayPal'];

$orders = [];
$trades = [];
$user_wallets = [];

if (isLoggedIn()) {
    // Ordres ouverts
    $orders = $conn->query("
        SELECT p2p_orders.*, users.username 
        FROM p2p_orders 
        JOIN users ON p2p_orders.user_id = users.id 
        WHERE p2p_orders.status = 'open' 
        ORDER BY p2p_orders.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Échanges de l'utilisateur
    $trades = $conn->query("
        SELECT p2p_trades.*, 
               buyer.username as buyer_username, 
               seller.username as seller_username,
               p2p_orders.currency_from,
               p2p_orders.currency_to
        FROM p2p_trades
        JOIN users as buyer ON p2p_trades.buyer_id = buyer.id
        JOIN users as seller ON p2p_trades.seller_id = seller.id
        JOIN p2p_orders ON p2p_trades.order_id = p2p_orders.id
        WHERE p2p_trades.buyer_id = {$_SESSION['user_id']} OR p2p_trades.seller_id = {$_SESSION['user_id']}
        ORDER BY p2p_trades.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Portefeuilles de l'utilisateur
    $user_wallets = $conn->query("
        SELECT * FROM user_wallets 
        WHERE user_id = {$_SESSION['user_id']}
    ")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>P2P Haiti - Échange de devises</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Arial', sans-serif;
        }
        body {
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        header {
            background-color: #1a1a2e;
            color: white;
            padding: 15px 0;
            margin-bottom: 30px;
        }
        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo {
            font-size: 24px;
            font-weight: bold;
        }
        nav ul {
            display: flex;
            list-style: none;
        }
        nav ul li {
            margin-left: 20px;
        }
        nav ul li a {
            color: white;
            text-decoration: none;
        }
        .auth-buttons a {
            color: white;
            text-decoration: none;
            margin-left: 15px;
            padding: 8px 15px;
            border-radius: 4px;
        }
        .login-btn {
            border: 1px solid white;
        }
        .signup-btn {
            background-color: #f39c12;
            border: 1px solid #f39c12;
        }
        .user-info {
            display: flex;
            align-items: center;
        }
        .user-info span {
            margin-right: 15px;
        }
        .logout-btn {
            background-color: #e74c3c;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
        }
        .card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        .card-title {
            font-size: 18px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th, table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .btn {
            padding: 8px 15px;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background-color: #3498db;
            color: white;
        }
        .btn-success {
            background-color: #2ecc71;
            color: white;
        }
        .btn-danger {
            background-color: #e74c3c;
            color: white;
        }
        .btn-warning {
            background-color: #f39c12;
            color: white;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        .select-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            background-color: white;
        }
        .text-center {
            text-align: center;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .wallet-balance {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        .wallet-item {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            min-width: 200px;
        }
        .wallet-currency {
            font-weight: bold;
            font-size: 18px;
        }
        .wallet-amount {
            font-size: 24px;
            margin-top: 5px;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
        }
        .close-btn {
            float: right;
            font-size: 24px;
            cursor: pointer;
        }
        .tab-container {
            margin-bottom: 20px;
        }
        .tabs {
            display: flex;
            border-bottom: 1px solid #ddd;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            margin-right: 5px;
        }
        .tab.active {
            border-color: #ddd;
            border-bottom-color: white;
            background-color: white;
            font-weight: bold;
        }
        .tab-content {
            display: none;
            padding: 20px;
            background-color: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 4px 4px;
        }
        .tab-content.active {
            display: block;
        }
        .price-up {
            color: #2ecc71;
        }
        .price-down {
            color: #e74c3c;
        }
    </style>
</head>
<body>
    <header>
        <div class="container header-content">
            <div class="logo">P2P Haiti</div>
            <nav>
                <ul>
                    <li><a href="index.php">Accueil</a></li>
                    <li><a href="index.php#orders">Ordres</a></li>
                    <li><a href="index.php#trades">Échanges</a></li>
                    <li><a href="index.php#wallets">Portefeuilles</a></li>
                </ul>
            </nav>
            <?php if (isLoggedIn()): ?>
                <div class="user-info">
                    <span>Bonjour, <?php echo getUser($_SESSION['user_id'])['username']; ?></span>
                    <form action="index.php" method="post" style="display: inline;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="logout-btn">Déconnexion</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="auth-buttons">
                    <a href="#login" class="login-btn">Connexion</a>
                    <a href="#signup" class="signup-btn">Inscription</a>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (!isLoggedIn()): ?>
            <!-- Formulaires de connexion et d'inscription -->
            <div class="tab-container">
                <div class="tabs">
                    <div class="tab <?php echo isset($_GET['signup']) ? '' : 'active'; ?>" onclick="openTab(event, 'login-tab')">Connexion</div>
                    <div class="tab <?php echo isset($_GET['signup']) ? 'active' : ''; ?>" onclick="openTab(event, 'signup-tab')">Inscription</div>
                </div>
                
                <div id="login-tab" class="tab-content <?php echo isset($_GET['signup']) ? '' : 'active'; ?>">
                    <h2>Connexion</h2>
                    <form action="index.php" method="post">
                        <input type="hidden" name="action" value="login">
                        <div class="form-group">
                            <label for="login-username">Nom d'utilisateur</label>
                            <input type="text" id="login-username" name="username" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="login-password">Mot de passe</label>
                            <input type="password" id="login-password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary">Se connecter</button>
                    </form>
                </div>
                
                <div id="signup-tab" class="tab-content <?php echo isset($_GET['signup']) ? 'active' : ''; ?>">
                    <h2>Inscription</h2>
                    <form action="index.php" method="post">
                        <input type="hidden" name="action" value="signup">
                        <div class="form-group">
                            <label for="signup-username">Nom d'utilisateur</label>
                            <input type="text" id="signup-username" name="username" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="signup-email">Email</label>
                            <input type="email" id="signup-email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="signup-password">Mot de passe</label>
                            <input type="password" id="signup-password" name="password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="signup-phone">Téléphone</label>
                            <input type="text" id="signup-phone" name="phone" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-success">S'inscrire</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Contenu pour les utilisateurs connectés -->
            <section id="wallets" class="card">
                <h2 class="card-title">Mes Portefeuilles</h2>
                <div class="wallet-balance">
                    <?php foreach ($user_wallets as $wallet): ?>
                        <div class="wallet-item">
                            <div class="wallet-currency"><?php echo $wallet['currency']; ?></div>
                            <div class="wallet-amount"><?php echo number_format($wallet['balance'], 2); ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php 
                    // Afficher les portefeuilles vides
                    $user_currencies = array_column($user_wallets, 'currency');
                    foreach ($currencies as $currency) {
                        if (!in_array($currency, $user_currencies)) {
                            echo '<div class="wallet-item">
                                <div class="wallet-currency">'.$currency.'</div>
                                <div class="wallet-amount">0.00</div>
                            </div>';
                        }
                    }
                    ?>
                </div>
            </section>

            <section class="card">
                <h2 class="card-title">Créer un nouvel ordre</h2>
                <form action="index.php" method="post">
                    <input type="hidden" name="action" value="create_order">
                    <div class="form-group">
                        <label for="order-type">Type d'ordre</label>
                        <select id="order-type" name="type" class="select-control" required>
                            <option value="buy">Acheter</option>
                            <option value="sell">Vendre</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="currency-from">Devise à échanger</label>
                        <select id="currency-from" name="currency_from" class="select-control" required>
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?php echo $currency; ?>"><?php echo $currency; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="currency-to">Devise souhaitée</label>
                        <select id="currency-to" name="currency_to" class="select-control" required>
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?php echo $currency; ?>"><?php echo $currency; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="amount">Montant</label>
                        <input type="number" id="amount" name="amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="price">Prix (1 unité de devise à échanger = X devise souhaitée)</label>
                        <input type="number" id="price" name="price" class="form-control" step="0.0001" min="0.0001" required>
                    </div>
                    <div class="form-group">
                        <label for="min_amount">Montant minimum par transaction</label>
                        <input type="number" id="min_amount" name="min_amount" class="form-control" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label for="max_amount">Montant maximum par transaction</label>
                        <input type="number" id="max_amount" name="max_amount" class="form-control" step="0.01" min="0.01">
                    </div>
                    <div class="form-group">
                        <label for="payment_method">Méthode de paiement</label>
                        <select id="payment_method" name="payment_method" class="select-control" required>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo $method; ?>"><?php echo $method; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Créer l'ordre</button>
                </form>
            </section>

            <section id="orders" class="card">
                <h2 class="card-title">Ordres disponibles</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Utilisateur</th>
                            <th>Échanger</th>
                            <th>Recevoir</th>
                            <th>Prix</th>
                            <th>Montant</th>
                            <th>Min/Max</th>
                            <th>Méthode</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php if ($order['user_id'] != $_SESSION['user_id']): ?>
                                <tr>
                                    <td><?php echo $order['type'] == 'buy' ? '<span class="price-up">Achat</span>' : '<span class="price-down">Vente</span>'; ?></td>
                                    <td><?php echo $order['username']; ?></td>
                                    <td><?php echo $order['currency_from']; ?></td>
                                    <td><?php echo $order['currency_to']; ?></td>
                                    <td><?php echo number_format($order['price'], 4); ?></td>
                                    <td><?php echo number_format($order['amount'], 2); ?></td>
                                    <td><?php echo number_format($order['min_amount'], 2); ?> / <?php echo $order['max_amount'] > 0 ? number_format($order['max_amount'], 2) : 'Illimité'; ?></td>
                                    <td><?php echo $order['payment_method']; ?></td>
                                    <td>
                                        <button onclick="openTradeModal(<?php echo $order['id']; ?>, '<?php echo $order['type']; ?>', '<?php echo $order['currency_from']; ?>', '<?php echo $order['currency_to']; ?>', <?php echo $order['price']; ?>, <?php echo $order['min_amount']; ?>, <?php echo $order['max_amount'] > 0 ? $order['max_amount'] : 'Infinity'; ?>)" class="btn btn-primary">
                                            <?php echo $order['type'] == 'buy' ? 'Vendre' : 'Acheter'; ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="9" class="text-center">Aucun ordre disponible pour le moment</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>

            <section id="trades" class="card">
                <h2 class="card-title">Mes Échanges</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Acheteur</th>
                            <th>Vendeur</th>
                            <th>Montant</th>
                            <th>Prix</th>
                            <th>Frais</th>
                            <th>Statut</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trades as $trade): ?>
                            <tr>
                                <td><?php echo $trade['id']; ?></td>
                                <td><?php echo $trade['buyer_username']; ?></td>
                                <td><?php echo $trade['seller_username']; ?></td>
                                <td><?php echo number_format($trade['amount'], 2).' '.$trade['currency_from']; ?></td>
                                <td><?php echo number_format($trade['price'], 4).' '.$trade['currency_to']; ?></td>
                                <td><?php echo number_format($trade['fee'], 2).' '.$trade['currency_to']; ?></td>
                                <td>
                                    <?php 
                                    $status_class = '';
                                    if ($trade['status'] == 'completed') $status_class = 'btn-success';
                                    elseif ($trade['status'] == 'cancelled' || $trade['status'] == 'disputed') $status_class = 'btn-danger';
                                    elseif ($trade['status'] == 'paid') $status_class = 'btn-warning';
                                    else $status_class = 'btn-primary';
                                    ?>
                                    <span class="btn <?php echo $status_class; ?>" style="padding: 3px 8px; font-size: 12px;">
                                        <?php 
                                        switch ($trade['status']) {
                                            case 'pending': echo 'En attente'; break;
                                            case 'paid': echo 'Payé'; break;
                                            case 'completed': echo 'Terminé'; break;
                                            case 'cancelled': echo 'Annulé'; break;
                                            case 'disputed': echo 'Litige'; break;
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($trade['created_at'])); ?></td>
                                <td>
                                    <?php if ($trade['status'] == 'pending' && $trade['buyer_id'] == $_SESSION['user_id']): ?>
                                        <form action="index.php" method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="confirm_payment">
                                            <input type="hidden" name="trade_id" value="<?php echo $trade['id']; ?>">
                                            <button type="submit" class="btn btn-success">Confirmer paiement</button>
                                        </form>
                                    <?php elseif ($trade['status'] == 'paid' && $trade['seller_id'] == $_SESSION['user_id']): ?>
                                        <form action="index.php" method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="release_funds">
                                            <input type="hidden" name="trade_id" value="<?php echo $trade['id']; ?>">
                                            <button type="submit" class="btn btn-primary">Libérer les fonds</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($trades)): ?>
                            <tr>
                                <td colspan="9" class="text-center">Aucun échange pour le moment</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
    </div>

    <!-- Modal pour créer un échange -->
    <div id="tradeModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeModal()">&times;</span>
            <h2>Créer un échange</h2>
            <form action="index.php" method="post">
                <input type="hidden" name="action" value="create_trade">
                <input type="hidden" id="modal-order-id" name="order_id">
                <div class="form-group">
                    <label for="modal-currency-from">Vous échangez</label>
                    <input type="text" id="modal-currency-from" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="modal-currency-to">Vous recevez</label>
                    <input type="text" id="modal-currency-to" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="modal-price">Prix</label>
                    <input type="text" id="modal-price" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="trade-amount">Montant (<span id="currency-from-label"></span>)</label>
                    <input type="number" id="trade-amount" name="amount" class="form-control" step="0.01" min="0.01" required>
                    <small>Min: <span id="min-amount"></span> - Max: <span id="max-amount"></span></small>
                </div>
                <div class="form-group">
                    <label>Vous recevrez: <span id="receive-amount">0.00</span> <span id="currency-to-label"></span></label>
                </div>
                <button type="submit" class="btn btn-primary">Confirmer l'échange</button>
            </form>
        </div>
    </div>

    <script>
        // Gestion des onglets
        function openTab(evt, tabName) {
            const tabContents = document.getElementsByClassName("tab-content");
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove("active");
            }
            
            const tabs = document.getElementsByClassName("tab");
            for (let i = 0; i < tabs.length; i++) {
                tabs[i].classList.remove("active");
            }
            
            document.getElementById(tabName).classList.add("active");
            evt.currentTarget.classList.add("active");
            
            // Mettre à jour l'URL
            if (tabName === 'signup-tab') {
                window.location.hash = 'signup';
            } else {
                window.location.hash = 'login';
            }
        }
        
        // Vérifier le hash au chargement
        window.onload = function() {
            if (window.location.hash === '#signup') {
                openTab({currentTarget: document.querySelector('.tab:nth-child(2)')}, 'signup-tab');
            }
        };
        
        // Gestion de la modal
        function openTradeModal(orderId, type, currencyFrom, currencyTo, price, minAmount, maxAmount) {
            const modal = document.getElementById("tradeModal");
            document.getElementById("modal-order-id").value = orderId;
            document.getElementById("modal-currency-from").value = currencyFrom;
            document.getElementById("modal-currency-to").value = currencyTo;
            document.getElementById("modal-price").value = price;
            document.getElementById("currency-from-label").textContent = currencyFrom;
            document.getElementById("currency-to-label").textContent = currencyTo;
            document.getElementById("min-amount").textContent = minAmount;
            document.getElementById("max-amount").textContent = maxAmount === Infinity ? 'Illimité' : maxAmount;
            
            // Définir les valeurs par défaut
            const amountInput = document.getElementById("trade-amount");
            amountInput.min = minAmount;
            amountInput.max = maxAmount === Infinity ? '' : maxAmount;
            amountInput.value = minAmount;
            updateReceiveAmount();
            
            modal.style.display = "block";
        }
        
        function closeModal() {
            document.getElementById("tradeModal").style.display = "none";
        }
        
        // Calcul du montant à recevoir
        function updateReceiveAmount() {
            const amount = parseFloat(document.getElementById("trade-amount").value) || 0;
            const price = parseFloat(document.getElementById("modal-price").value);
            document.getElementById("receive-amount").textContent = (amount * price).toFixed(2);
        }
        
        // Écouteurs d'événements
        document.getElementById("trade-amount").addEventListener("input", updateReceiveAmount);
        
        // Fermer la modal en cliquant à l'extérieur
        window.onclick = function(event) {
            const modal = document.getElementById("tradeModal");
            if (event.target == modal) {
                closeModal();
            }
        };
    </script>
</body>
</html>
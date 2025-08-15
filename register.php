<?php
require 'config.php';
if (isset($_SESSION['user_id'])) header('Location: dashboard.php');
$msg = '';
$success = false;

// Process the form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $password2 = $_POST['password2'];

    // Basic validation
    if (!$fullname || !$email || !$username || !$password || !$password2) {
        $msg = "Por favor, preencha todos os campos.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = "E-mail inválido!";
    } elseif ($password !== $password2) {
        $msg = "As senhas não coincidem.";
    } else {
        // Check unique username/email (email unique is optional, add to DB for full production)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $msg = "Já existe uma conta com esse e-mail.";
        } else {
            // Add to DB (add email/fullname columns if you want to store)
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username, password_hash, fullname, email) VALUES (?,?,?,?)")
                ->execute([$username, $hash, $fullname, $email]);
            $success = true;
            // Optionally, store fullname/email in your DB if you alter schema!
            // Redirect to login
            header('Refresh: 2; URL=index.php');
        }
    }
}

include 'header.php';
?>
<div class="container d-flex justify-content-center align-items-center min-vh-100">
    <div class="glassmorph card shadow-lg py-5 register-card mx-auto">
        <div class="text-center mb-4">
            <!-- Same SVG as login -->
            <svg width="56" height="56" viewBox="0 0 52 52" fill="none">
                <circle cx="26" cy="26" r="26" fill="#eddefb"/>
                <path d="M14 34C14 28 21.5 22 26 22C30.5 22 38 28 38 34" stroke="#6a60e8" stroke-width="2"/>
                <ellipse cx="26" cy="18" rx="5.8" ry="6.1" fill="#8f81ff"/>
            </svg>
        </div>
        <h2 class="mb-2 text-center" style="font-size:2.1rem;letter-spacing:-.03em;">Criar Conta</h2>
        <p class="text-center mb-4" style="font-size:1.08rem;color:#908ab7;">Começa agora a usar o ChatMind!</p>
        <?php if ($msg): ?><div class="alert alert-danger"><?= $msg ?></div><?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success">Conta criada com sucesso! A redirecionar...</div>
        <?php else: ?>
        <form method="POST" autocomplete="off" novalidate>
            <div class="mb-3">
                <label>Nome completo</label>
                <input type="text" name="fullname" class="form-control" maxlength="80" placeholder="Ex: João Silva" required value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label>E-mail</label>
                <input type="email" name="email" class="form-control" maxlength="80" placeholder="teu@email.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label>Utilizador</label>
                <input type="text" name="username" class="form-control" maxlength="32" placeholder="Nome de utilizador" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="mb-3">
                <label>Senha</label>
                <input type="password" name="password" class="form-control" minlength="5" placeholder="Palavra-passe" required>
            </div>
            <div class="mb-3">
                <label>Confirmar senha</label>
                <input type="password" name="password2" class="form-control" minlength="5" placeholder="Repetir palavra-passe" required>
            </div>
            <button class="btn btn-success w-100 py-2 mt-2" type="submit"><b>Registar</b></button>
        </form>
        <div class="mt-3 text-center">
            <a href="index.php">Já tenho conta</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>

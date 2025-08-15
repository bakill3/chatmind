<?php
require 'config.php';
if (isset($_SESSION['user_id'])) header('Location: dashboard.php');
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        header('Location: dashboard.php');
        exit;
    } else {
        $msg = "Login inválido!";
    }
}
include 'header.php';
?>
<div class="container d-flex justify-content-center align-items-center min-vh-100">
    <div class="glassmorph card shadow-lg px-4 py-5" style="max-width:420px;">
        <div class="text-center mb-4">
            <svg width="52" height="52" viewBox="0 0 52 52" fill="none">
                <circle cx="26" cy="26" r="26" fill="#eddefb"/>
                <path d="M14 34C14 28 21.5 22 26 22C30.5 22 38 28 38 34" stroke="#6a60e8" stroke-width="2"/>
                <ellipse cx="26" cy="18" rx="5.8" ry="6.1" fill="#8f81ff"/>
            </svg>
        </div>
        <h2 class="mb-2 text-center" style="font-size:2.1rem;letter-spacing:-.03em;">Bem-vindo ao <span style="color:#6a60e8">ChatMind</span></h2>
        <p class="text-center mb-4" style="font-size:1.09rem;color:#908ab7;">O teu copiloto estratégico de conversas</p>
        <?php if ($msg): ?><div class="alert alert-danger"><?= $msg ?></div><?php endif; ?>
        <form method="POST" autocomplete="off">
            <div class="mb-3">
                <label>E-mail</label>
                <input type="email" name="email" class="form-control" required autofocus autocomplete="off">
            </div>
            <div class="mb-3">
                <label>Senha</label>
                <input type="password" name="password" class="form-control" required autocomplete="off">
            </div>
            <button class="btn btn-primary w-100 py-2 mt-2">Entrar</button>
        </form>
        <div class="mt-3 text-center">
            <a href="register.php">Criar conta</a>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>

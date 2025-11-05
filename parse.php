<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) die("Sem sessão!");

// Recebe upload_id
$upload_id = (int)($_GET['upload_id'] ?? 0);
if (!$upload_id) die("upload_id inválido!");

// Verifica ownership & existência do file
$stmt = $pdo->prepare("SELECT filename FROM uploads WHERE id = ? AND user_id = ?");
$stmt->execute([$upload_id, $_SESSION['user_id']]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) die("Upload não encontrado!");

$filepath = rtrim(UPLOADS_DIR,'/\\') . DIRECTORY_SEPARATOR . $row['filename'];
if (!file_exists($filepath)) die("Ficheiro não encontrado!");

// Lê o file só para confirmar que é um TXT de WPP (sem guardar na DB)
$txt = file_get_contents($filepath);
if (!preg_match('/\d{2}\/\d{2}\/\d{2,4}.*?:.*?:/s', $txt)) {
    $_SESSION['upload_warning'] = "Formato de TXT potencialmente inválido.";
}

header('Location: conversation.php?upload_id='.$upload_id);
exit;

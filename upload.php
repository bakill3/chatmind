<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) header('Location: index.php');

$msg = '';

function normalize_name($s) {
    if (function_exists('transliterator_transliterate')) {
        $s = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC', $s);
    } else {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($tmp !== false) $s = $tmp;
    }
    $s = preg_replace('/[^a-z0-9 ]/i', '', $s);
    return strtolower(trim($s));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['txtfile'])) {
    $f = $_FILES['txtfile'];

    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($f['error'] === UPLOAD_ERR_OK && $ext === 'txt') {
        $filename = uniqid() . '_' . basename($f['name']);
        $destPath = UPLOADS_DIR . '/' . $filename;

        if (!move_uploaded_file($f['tmp_name'], $destPath)) {
            $msg = "Falha ao mover o ficheiro.";
        } else {
            $detectedTitle = null;
            $is_whatsapp = 0;
            $participants = [];

            $sample = file_get_contents($destPath, false, null, 0, 2_000_000) ?: '';

            // [DD/MM/YY, HH:MM(:SS)] Name: msg
            //  DD/MM/YY, HH:MM(:SS) - Name: msg
            $re = '/^(?:\[(\d{1,2}\/\d{1,2}\/\d{2,4}),\s+(\d{1,2}:\d{2}(?::\d{2})?\s?(?:AM|PM|am|pm)?)\]|\s*(\d{1,2}\/\d{1,2}\/\d{2,4}),\s+(\d{1,2}:\d{2}(?::\d{2})?\s?(?:AM|PM|am|pm)?)\s*-\s*)\s*([^:]+):\s(.+)$/mu';

            $messages = [];
            if (preg_match_all($re, $sample, $mset, PREG_SET_ORDER)) {
                foreach ($mset as $m) {
                    $name = trim($m[5] ?? '');
                    if ($name !== '') {
                        $participants[$name] = true;
                        $messages[] = $name;
                    }
                    if (count($participants) > 24) break; 
                }
            }

            if (count($messages) >= 2 && count($participants) >= 2) {
                $is_whatsapp = 1;

                $stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $reg_name = $stmt->fetchColumn() ?: 'Eu';
                $reg_norm = normalize_name($reg_name);

                $my_name = $reg_name; $best = -1;
                foreach (array_keys($participants) as $n) {
                    similar_text(normalize_name($n), $reg_norm, $p);
                    if ($p > $best) { $best = $p; $my_name = $n; }
                }

                $names = array_keys($participants);
                if (count($names) === 2) {
                    $other = ($names[0] === $my_name) ? $names[1] : (($names[1] === $my_name) ? $names[0] : $names[0]);
                    $detectedTitle = "Conversa WhatsApp entre {$my_name} e {$other}";
                } else {
                    $other = null;
                    for ($i = count($messages)-1; $i >= 0; $i--) {
                        if ($messages[$i] !== $my_name) { $other = $messages[$i]; break; }
                    }
                    $detectedTitle = $other
                        ? "Conversa WhatsApp (grupo) — {$my_name} & {$other}"
                        : "Conversa WhatsApp (grupo)";
                }
            }

            if ($detectedTitle) {
                $stmt = $pdo->prepare("INSERT INTO uploads (user_id, filename, title, is_whatsapp, participants_json) VALUES (?,?,?,?,?)");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $filename,
                    $detectedTitle,
                    $is_whatsapp,
                    $is_whatsapp ? json_encode(array_keys($participants), JSON_UNESCAPED_UNICODE) : null
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO uploads (user_id, filename, is_whatsapp, participants_json) VALUES (?,?,?,?)");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $filename,
                    0,
                    null
                ]);
            }

            $upload_id = $pdo->lastInsertId();
            header('Location: parse.php?upload_id=' . $upload_id);
            exit;
        }
    } else {
        $msg = "Ficheiro inválido!";
    }
}

include 'header.php';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php">ChatMind</a>
        <a class="btn btn-outline-light" href="logout.php">Sair</a>
    </div>
</nav>
<div class="container mt-5">
    <div class="card glassmorph shadow" style="max-width: 500px; margin:auto;">
        <div class="card-body">
            <h4 class="mb-3">Upload do .TXT (WhatsApp)</h4>
            <?php if ($msg): ?><div class="alert alert-danger"><?= $msg ?></div><?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="txtfile" accept=".txt" class="form-control mb-3" required>
                <button class="btn btn-success w-100">Upload &amp; Importar</button>
            </form>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>

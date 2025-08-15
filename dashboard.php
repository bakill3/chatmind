<?php
require 'config.php';
if (!isset($_SESSION['user_id'])) header('Location: index.php');
$user_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM uploads WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>
<nav class="navbar navbar-expand-lg shadow-sm mb-4">
  <div class="container">
    <a class="navbar-brand fs-4 fw-bold" href="#">ChatMind</a>
    <a class="btn btn-outline-light" href="logout.php">Sair</a>
  </div>
</nav>

<div class="container mb-5">
  <div class="glassmorph card shadow mb-4 p-4 reveal-up">
    <div class="card-body">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <h2 class="m-0" style="font-size:2.1rem;">Minhas Conversas</h2>
        <a href="upload.php" class="btn btn-success"><b>+ Upload WhatsApp TXT</b></a>
      </div>

      <?php if (!$uploads): ?>
        <div class="alert alert-info mt-3">Ainda não tens uploads. Faz já o primeiro!</div>
      <?php else: ?>
        <div class="row g-3">
          <?php foreach ($uploads as $up):
            $displayTitle = $up['title'] ?: pathinfo($up['filename'], PATHINFO_FILENAME);
            $isWA = !empty($up['is_whatsapp']);
          ?>
            <div class="col-12 col-md-6 col-lg-4">
              <div class="list-group-item p-3 h-100 hover-lift">
                <div class="d-flex align-items-start justify-content-between">
                  <div class="fw-semibold d-flex align-items-center gap-2 flex-wrap" style="word-break:break-word;">
                    <?php if ($isWA): ?>
                      <span class="text-success" title="WhatsApp">
                        <i class="bi bi-whatsapp"></i>
                      </span>
                    <?php endif; ?>
                    <span class="chat-title" data-id="<?= $up['id'] ?>" data-ctx="dashboard">
                      <?= htmlspecialchars($displayTitle) ?>
                    </span>
                    <button class="btn btn-sm btn-link p-0 text-decoration-none edit-title"
                            data-id="<?= $up['id'] ?>" data-current="<?= htmlspecialchars($displayTitle) ?>"
                            title="Renomear" aria-label="Renomear">
                      <i class="bi bi-pencil"></i>
                    </button>
                  </div>
                  <span class="badge bg-light text-dark rounded-pill">
                    <?= date('d/m/Y H:i', strtotime($up['created_at'])) ?>
                  </span>
                </div>
                <div class="mt-2 small text-muted">
                  Ficheiro: <?= htmlspecialchars($up['filename']) ?> ·
                  Tamanho: <?= number_format(@filesize(UPLOADS_DIR.'/'.$up['filename'])/1024,1) ?> KB
                </div>
                <a href="conversation.php?upload_id=<?= $up['id'] ?>" class="btn btn-sm btn-primary mt-3 w-100">
                  Abrir conversa
                </a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include 'footer.php'; ?>

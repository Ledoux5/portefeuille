<?php
require_once __DIR__ . '/bootstrap.php';
$user = Auth::exiger();

$t = (new TransactionRepository($pdo))->trouver((int) ($_GET['id'] ?? 0));
$autorise = $t && (
    $user['role'] === 'administrateur'
    || (int) $t['expediteur_id'] === $user['id']
    || (int) $t['destinataire_id'] === $user['id']
);
if (!$autorise) {
    flash('error', 'Reçu introuvable.');
    header('Location: historique.php');
    exit;
}

$titre = 'Reçu #' . $t['id'];
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/detail_calcul.php';
?>
<section class="card receipt">
    <h1>Reçu de transaction</h1>
    <table class="detail">
        <tr><th>Identifiant</th><td>#<?= (int) $t['id'] ?> — <?= e($t['reference']) ?></td></tr>
        <tr><th>Date / heure</th><td><?= e($t['created_at']) ?></td></tr>
        <tr><th>Statut</th><td>Réussie</td></tr>
    </table>
    <?php afficher_detail($t); ?>
    <p class="muted">Simulation pédagogique — aucun fonds réel n'a été déplacé.</p>
    <p class="no-print">
        <button type="button" onclick="window.print()">Imprimer le reçu</button>
        <a class="btn secondary" href="historique.php">Retour à l'historique</a>
    </p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

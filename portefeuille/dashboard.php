<?php
require_once __DIR__ . '/bootstrap.php';
$user = Auth::exiger();

$soldes = (new UserService($pdo))->soldes($user['id']);
$recent = (new TransactionRepository($pdo))->historique($user['id'], $user['role'] === 'administrateur', 5);

$titre = 'Mes soldes';
require __DIR__ . '/includes/header.php';
?>
<h1>Bonjour, <?= e($user['nom']) ?></h1>

<section class="wallets">
    <?php foreach ($soldes as $w): ?>
        <div class="wallet">
            <span class="devise"><?= e($w['devise']) ?></span>
            <span class="solde"><?= e(Money::formater($w['solde'], (int) $w['decimales'])) ?></span>
        </div>
    <?php endforeach; ?>
</section>

<?php if ($user['role'] !== 'administrateur'): ?>
    <p><a class="btn" href="transfert.php">
        <?= $user['role'] === 'commercant' ? 'Envoyer de l\'argent' : 'Nouveau transfert / paiement' ?></a></p>
<?php else: ?>
    <p><a class="btn" href="admin_taux.php">Gérer les taux</a> <a class="btn secondary" href="rapports.php">Voir les rapports</a></p>
<?php endif; ?>

<section class="card">
    <h2>Dernières transactions</h2>
    <?php if (!$recent): ?>
        <p class="muted">Aucune transaction pour le moment.</p>
    <?php else: ?>
        <table>
            <tr><th>Date</th><th>De</th><th>Vers</th><th>Montant</th><th>Reçu</th></tr>
            <?php foreach ($recent as $t): ?>
                <tr>
                    <td><?= e($t['created_at']) ?></td>
                    <td><?= e($t['expediteur_ident']) ?></td>
                    <td><?= e($t['destinataire_ident']) ?></td>
                    <td class="num"><?= e(fmt($t['montant_source'], $t['devise_source'])) ?></td>
                    <td><a href="recu.php?id=<?= (int) $t['id'] ?>">Reçu</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p><a href="historique.php">Voir tout l'historique →</a></p>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

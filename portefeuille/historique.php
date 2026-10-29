<?php
require_once __DIR__ . '/bootstrap.php';
$user = Auth::exiger();

$transactions = (new TransactionRepository($pdo))->historique($user['id'], $user['role'] === 'administrateur');

$titre = 'Historique';
require __DIR__ . '/includes/header.php';
?>
<h1>Historique des transactions</h1>
<section class="card">
<?php if (!$transactions): ?>
    <p class="muted">Aucune transaction.</p>
<?php else: ?>
    <table>
        <tr>
            <th>N°</th><th>Date / heure</th><th>Expéditeur</th><th>Destinataire</th><th>Type</th>
            <th>Montant source</th><th>Taux</th><th>Montant converti</th>
            <th>Frais</th><th>Taxe</th><th>Commission</th><th>Débité</th><th>Crédité</th><th></th>
        </tr>
        <?php foreach ($transactions as $t): ?>
            <?php $moi = (int) $t['expediteur_id'] === $user['id']; ?>
            <tr>
                <td>#<?= (int) $t['id'] ?></td>
                <td><?= e($t['created_at']) ?></td>
                <td><?= e($t['expediteur_ident']) ?></td>
                <td><?= e($t['destinataire_ident']) ?></td>
                <td><?= $t['type_transaction'] === 'paiement_commercant' ? 'Paiement commerçant' : 'Transfert' ?></td>
                <td class="num"><?= e(fmt($t['montant_source'], $t['devise_source'])) ?></td>
                <td class="num"><?= $t['sens_taux'] === 'identique' ? '—' : e(rtrim(rtrim((string) $t['taux_utilise'], '0'), '.')) ?></td>
                <td class="num"><?= e(fmt($t['montant_cible'], $t['devise_cible'])) ?></td>
                <td class="num"><?= e(fmt($t['frais_total'], $t['devise_source'])) ?></td>
                <td class="num"><?= e(fmt($t['montant_taxe'], $t['devise_source'])) ?></td>
                <td class="num"><?= $t['type_transaction'] === 'paiement_commercant' ? e(fmt($t['montant_commission'], $t['devise_cible'])) : '—' ?></td>
                <td class="num <?= $moi ? 'neg' : '' ?>"><?= e(fmt($t['debit_expediteur'], $t['devise_source'])) ?></td>
                <td class="num <?= !$moi ? 'pos' : '' ?>"><?= e(fmt($t['credit_destinataire'], $t['devise_cible'])) ?></td>
                <td><a href="recu.php?id=<?= (int) $t['id'] ?>">Reçu</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

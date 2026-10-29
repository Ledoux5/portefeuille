<?php
/**
 * Affiche le détail transparent d'une transaction (simulation ou reçu).
 * $t doit contenir les mêmes clés que la table `transactions` / le résultat de TransferService::simuler().
 */
function afficher_detail(array $t): void
{
    $s = $t['devise_source'];
    $c = $t['devise_cible'];
    $marchand = $t['type_transaction'] === 'paiement_commercant';
    ?>
    <table class="detail">
        <tr><th>Type</th><td><?= $marchand ? 'Paiement à un commerçant' : 'Transfert entre particuliers' ?></td></tr>
        <tr><th>Expéditeur</th><td><?= e($t['expediteur_nom']) ?></td></tr>
        <tr><th>Destinataire</th><td><?= e($t['destinataire_nom']) ?></td></tr>
        <tr><th>Montant source</th><td><?= e(fmt($t['montant_source'], $s)) ?></td></tr>
        <tr><th>Taux de change utilisé</th>
            <td><?php if ($t['sens_taux'] === 'identique'): ?>
                    Aucune conversion (même devise)
                <?php else: ?>
                    1 <?= e($s) ?> = <?= e(rtrim(rtrim((string) $t['taux_utilise'], '0'), '.')) ?> <?= e($c) ?>
                    (<?= e(libelle_sens_taux($t['sens_taux'])) ?>)
                <?php endif; ?></td></tr>
        <tr><th>Montant converti</th><td><?= e(fmt($t['montant_cible'], $c)) ?></td></tr>
        <tr><th>Frais en pourcentage (<?= e(rtrim(rtrim(bcmul((string) $t['taux_frais'], '100', 4), '0'), '.')) ?> %)</th>
            <td><?= e(fmt($t['frais_pourcentage'], $s)) ?></td></tr>
        <tr><th>Frais fixes</th><td><?= e(fmt($t['frais_fixes'], $s)) ?></td></tr>
        <tr><th>Frais de transaction totaux</th><td><?= e(fmt($t['frais_total'], $s)) ?></td></tr>
        <tr><th>Taxe (<?= e(rtrim(rtrim(bcmul((string) $t['taux_taxe'], '100', 4), '0'), '.')) ?> % des frais)</th>
            <td><?= e(fmt($t['montant_taxe'], $s)) ?></td></tr>
        <?php if ($marchand): ?>
        <tr><th>Commission commerçant (<?= e(rtrim(rtrim(bcmul((string) $t['taux_commission'], '100', 4), '0'), '.')) ?> %)</th>
            <td><?= e(fmt($t['montant_commission'], $c)) ?>
                — <?= (int) $t['commission_deduite'] === 1 ? 'déduite du règlement du commerçant' : 'non déduite du règlement' ?></td></tr>
        <?php endif; ?>
        <tr class="total"><th>Débit total de l'expéditeur</th><td><?= e(fmt($t['debit_expediteur'], $s)) ?></td></tr>
        <tr class="total"><th>Crédit du destinataire</th><td><?= e(fmt($t['credit_destinataire'], $c)) ?></td></tr>
    </table>
    <?php
}

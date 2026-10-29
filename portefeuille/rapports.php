<?php
require_once __DIR__ . '/bootstrap.php';
Auth::exiger('administrateur');

$granularite = ($_GET['vue'] ?? 'jour') === 'mois' ? 'mois' : 'jour';
$rapport = (new ReportService($pdo))->generer($granularite, $granularite === 'mois' ? 12 : 31);

// Nombre total de transactions par période (toutes devises confondues)
$totalParPeriode = [];
foreach ($rapport['lignes'] as $l) {
    $totalParPeriode[$l['periode']] = ($totalParPeriode[$l['periode']] ?? 0) + (int) $l['nb_transactions'];
}

$titre = 'Rapports';
require __DIR__ . '/includes/header.php';
?>
<h1>Rapports <?= $granularite === 'mois' ? 'mensuels (12 derniers mois)' : 'journaliers (31 derniers jours)' ?></h1>
<p>
    <a class="btn <?= $granularite === 'jour' ? '' : 'secondary' ?>" href="rapports.php?vue=jour">Journalier</a>
    <a class="btn <?= $granularite === 'mois' ? '' : 'secondary' ?>" href="rapports.php?vue=mois">Mensuel</a>
</p>

<section class="card">
    <h2>Transactions, frais et taxes par devise source</h2>
    <?php if (!$rapport['lignes']): ?>
        <p class="muted">Aucune transaction sur la période.</p>
    <?php else: ?>
    <table>
        <tr><th>Période</th><th>Transactions (total période)</th><th>Devise source</th><th>Transactions</th>
            <th>Montants sources</th><th>Frais perçus</th><th>Taxes</th></tr>
        <?php foreach ($rapport['lignes'] as $l): ?>
            <tr>
                <td><?= e($l['periode']) ?></td>
                <td class="num"><?= (int) $totalParPeriode[$l['periode']] ?></td>
                <td><?= e($l['devise_source']) ?></td>
                <td class="num"><?= (int) $l['nb_transactions'] ?></td>
                <td class="num"><?= e(fmt($l['total_source'], $l['devise_source'])) ?></td>
                <td class="num"><?= e(fmt($l['total_frais'], $l['devise_source'])) ?></td>
                <td class="num"><?= e(fmt($l['total_taxe'], $l['devise_source'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Commissions commerçants (par devise de règlement)</h2>
    <?php if (!$rapport['commissions']): ?>
        <p class="muted">Aucun paiement à un commerçant sur la période.</p>
    <?php else: ?>
    <table>
        <tr><th>Période</th><th>Devise</th><th>Paiements</th><th>Commissions</th></tr>
        <?php foreach ($rapport['commissions'] as $c): ?>
            <tr>
                <td><?= e($c['periode']) ?></td>
                <td><?= e($c['devise_cible']) ?></td>
                <td class="num"><?= (int) $c['nb_paiements'] ?></td>
                <td class="num"><?= e(fmt($c['total_commission'], $c['devise_cible'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Soldes totaux des portefeuilles par devise</h2>
    <table>
        <tr><th>Devise</th><th>Portefeuilles</th><th>Solde total</th></tr>
        <?php foreach ($rapport['soldes'] as $s): ?>
            <tr>
                <td><?= e($s['devise']) ?></td>
                <td class="num"><?= (int) $s['nb_portefeuilles'] ?></td>
                <td class="num"><?= e(fmt($s['total_solde'], $s['devise'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

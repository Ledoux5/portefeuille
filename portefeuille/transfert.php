<?php
require_once __DIR__ . '/bootstrap.php';
$user = Auth::exiger('client', 'commercant');
$service = new TransferService($pdo);

$saisie = [
    'destinataire'  => '',
    'devise_source' => 'XAF',
    'devise_cible'  => 'USD',
    'montant'       => '',
];
$simulation = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifierCsrf();
    foreach ($saisie as $cle => $_) {
        $saisie[$cle] = trim((string) ($_POST[$cle] ?? ''));
    }
    $action = (string) ($_POST['action'] ?? 'simuler');

    try {
        if ($action === 'confirmer') {
            $id = $service->executer(
                $user['id'],
                (string) ($_POST['reference'] ?? ''),
                $saisie['destinataire'],
                $saisie['devise_source'],
                $saisie['devise_cible'],
                $saisie['montant']
            );
            flash('success', 'Transaction effectuée avec succès.');
            header('Location: recu.php?id=' . $id);
            exit;
        }
        // Étape 1 : simulation (aucun solde modifié)
        $simulation = $service->simuler(
            $user['id'], $saisie['destinataire'], $saisie['devise_source'], $saisie['devise_cible'], $saisie['montant']
        );
        $reference = 'TX' . date('YmdHis') . '-' . bin2hex(random_bytes(4)); // jeton anti-doublon
    } catch (MetierException $e) {
        flash('error', $e->getMessage());
    }
}

$soldes = (new UserService($pdo))->soldes($user['id']);
$titre = 'Transfert';
require __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/detail_calcul.php';
?>
<h1>Transfert / paiement</h1>

<section class="card">
    <h2>1. Saisir la transaction</h2>
    <p class="muted">Soldes :
        <?php foreach ($soldes as $w): ?>
            <strong><?= e(fmt($w['solde'], $w['devise'])) ?></strong>&nbsp;&nbsp;
        <?php endforeach; ?></p>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <input type="hidden" name="action" value="simuler">
        <label>Identifiant du destinataire
            <input type="text" name="destinataire" value="<?= e($saisie['destinataire']) ?>" required>
        </label>
        <div class="row">
            <label>Je paie depuis mon portefeuille
                <select name="devise_source">
                    <?php foreach (array_keys(devises()) as $code): ?>
                        <option value="<?= e($code) ?>" <?= $saisie['devise_source'] === $code ? 'selected' : '' ?>><?= e($code) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Le destinataire reçoit en
                <select name="devise_cible">
                    <?php foreach (array_keys(devises()) as $code): ?>
                        <option value="<?= e($code) ?>" <?= $saisie['devise_cible'] === $code ? 'selected' : '' ?>><?= e($code) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label>Montant à envoyer (dans la devise source)
            <input type="text" name="montant" inputmode="decimal" value="<?= e($saisie['montant']) ?>" required>
        </label>
        <button type="submit">Calculer le détail</button>
    </form>
</section>

<?php if ($simulation): ?>
<section class="card">
    <h2>2. Vérifier et confirmer</h2>
    <?php afficher_detail($simulation); ?>
    <?php if (!$simulation['solde_suffisant']): ?>
        <div class="alert alert-error"><?= e($simulation['message_solde']) ?></div>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
            <input type="hidden" name="action" value="confirmer">
            <input type="hidden" name="reference" value="<?= e($reference) ?>">
            <input type="hidden" name="destinataire" value="<?= e($saisie['destinataire']) ?>">
            <input type="hidden" name="devise_source" value="<?= e($saisie['devise_source']) ?>">
            <input type="hidden" name="devise_cible" value="<?= e($saisie['devise_cible']) ?>">
            <input type="hidden" name="montant" value="<?= e($saisie['montant']) ?>">
            <button type="submit">Confirmer le transfert</button>
        </form>
        <p class="muted">Les taux et frais sont recalculés au moment de la confirmation.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

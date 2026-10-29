<?php
require_once __DIR__ . '/bootstrap.php';
$user = Auth::exiger('administrateur');
$service = new ExchangeRateService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifierCsrf();
    try {
        if (($_POST['action'] ?? '') === 'taux') {
            $service->enregistrer(
                $user['id'],
                (string) ($_POST['devise_source'] ?? ''),
                (string) ($_POST['devise_cible'] ?? ''),
                (string) ($_POST['taux'] ?? ''),
                (string) ($_POST['date_effet'] ?? '')
            );
            flash('success', 'Taux de change enregistré.');
        } elseif (($_POST['action'] ?? '') === 'parametres') {
            foreach (array_keys(Parametres::DEFAUTS) as $cle) {
                if (isset($_POST['p_' . $cle])) {
                    Parametres::enregistrer($pdo, $cle, (string) $_POST['p_' . $cle]);
                }
            }
            flash('success', 'Paramètres enregistrés.');
        }
    } catch (MetierException $e) {
        flash('error', $e->getMessage());
    }
    header('Location: admin_taux.php');
    exit;
}

$taux = $service->lister();
$parametres = Parametres::lister($pdo);

$titre = 'Taux et paramètres';
require __DIR__ . '/includes/header.php';
?>
<h1>Taux de change et paramètres</h1>

<section class="card">
    <h2>Ajouter / mettre à jour un taux</h2>
    <p class="muted">Convention : <strong>1 devise source = taux devise cible</strong> (ex. 1 USD = 600 XAF).
        La paire inverse est déduite automatiquement par division. Le taux en vigueur est le plus récent dont la date d'effet est atteinte.
        Ce sont des taux simulés saisis manuellement.</p>
    <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <input type="hidden" name="action" value="taux">
        <label>Devise source
            <select name="devise_source">
                <?php foreach (array_keys(devises()) as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Devise cible
            <select name="devise_cible">
                <?php foreach (array_keys(devises()) as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Taux
            <input type="text" name="taux" inputmode="decimal" required>
        </label>
        <label>Date d'effet
            <input type="datetime-local" name="date_effet" value="<?= e(date('Y-m-d\TH:i')) ?>" required>
        </label>
        <button type="submit">Enregistrer</button>
    </form>
</section>

<section class="card">
    <h2>Table des taux</h2>
    <table>
        <tr><th>N°</th><th>Source</th><th>Cible</th><th>Taux</th><th>Date d'effet</th><th>Modifié par</th></tr>
        <?php foreach ($taux as $t): ?>
            <tr>
                <td><?= (int) $t['id'] ?></td>
                <td><?= e($t['devise_source']) ?></td>
                <td><?= e($t['devise_cible']) ?></td>
                <td class="num"><?= e(rtrim(rtrim((string) $t['taux'], '0'), '.')) ?></td>
                <td><?= e($t['date_effet']) ?></td>
                <td><?= e($t['admin']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>

<section class="card">
    <h2>Paramètres de frais, taxe et commission</h2>
    <p class="muted">Hypothèses de classe configurables — ne constituent pas un conseil juridique ou fiscal.
        Les taux sont exprimés en fraction (0.025 = 2,5 %). Arrondi : HALF_UP, par composante, selon les décimales de la devise.</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <input type="hidden" name="action" value="parametres">
        <table>
            <?php foreach ($parametres as $p): ?>
                <tr>
                    <th><?= e($p['cle']) ?></th>
                    <td><input type="text" name="p_<?= e($p['cle']) ?>" value="<?= e($p['valeur']) ?>"></td>
                    <td class="muted"><?= e($p['description']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <button type="submit">Enregistrer les paramètres</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

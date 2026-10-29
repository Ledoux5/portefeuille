<?php
require_once __DIR__ . '/bootstrap.php';

if (Auth::utilisateur()) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifierCsrf();
    try {
        (new UserService($pdo))->creerClient(
            (string) ($_POST['nom_complet'] ?? ''),
            (string) ($_POST['identifiant'] ?? ''),
            (string) ($_POST['pin'] ?? ''),
            (string) ($_POST['role'] ?? 'client')
        );
        flash('success', 'Compte créé avec trois portefeuilles à solde nul (XAF, USD, EUR). Vous pouvez vous connecter.');
        header('Location: index.php');
        exit;
    } catch (MetierException $e) {
        flash('error', $e->getMessage());
        header('Location: inscription.php');
        exit;
    }
}

$titre = 'Créer un compte';
require __DIR__ . '/includes/header.php';
?>
<section class="card narrow">
    <h1>Créer un compte</h1>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <label>Nom complet
            <input type="text" name="nom_complet" maxlength="100" required>
        </label>
        <label>Identifiant (3 à 30 caractères : lettres, chiffres, . _ -)
            <input type="text" name="identifiant" maxlength="30" required>
        </label>
        <label>PIN (4 à 6 chiffres)
            <input type="password" name="pin" inputmode="numeric" pattern="\d{4,6}" required>
        </label>
        <label>Type de compte
            <select name="role">
                <option value="client">Client</option>
                <option value="commercant">Commerçant</option>
            </select>
        </label>
        <button type="submit">Créer le compte</button>
    </form>
    <p class="muted"><a href="index.php">← Retour à la connexion</a></p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

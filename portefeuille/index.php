<?php
require_once __DIR__ . '/bootstrap.php';

if (Auth::utilisateur()) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifierCsrf();
    try {
        $u = (new UserService($pdo))->authentifier((string) ($_POST['identifiant'] ?? ''), (string) ($_POST['pin'] ?? ''));
        if ($u) {
            Auth::connecter($u);
            header('Location: dashboard.php');
            exit;
        }
        flash('error', 'Identifiant ou PIN incorrect.');
    } catch (MetierException $e) {
        flash('error', $e->getMessage());
    }
    header('Location: index.php');
    exit;
}

$titre = 'Connexion';
require __DIR__ . '/includes/header.php';
?>
<section class="card narrow">
    <h1>Connexion</h1>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(Auth::csrf()) ?>">
        <label>Identifiant
            <input type="text" name="identifiant" required autofocus>
        </label>
        <label>PIN
            <input type="password" name="pin" inputmode="numeric" required>
        </label>
        <button type="submit">Se connecter</button>
    </form>
    <p class="muted">Pas encore de compte ? <a href="inscription.php">Créer un compte</a></p>
    <p class="hint">Comptes de démonstration (PIN <strong>1234</strong>) :
        <code>admin</code>, <code>alice</code>, <code>bob</code>, <code>boutique</code> (commerçant).</p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>

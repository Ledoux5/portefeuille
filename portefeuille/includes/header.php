<?php
/** En-tête commun. Variables attendues : $titre (string). $user optionnel (utilisateur connecté). */
$user = Auth::utilisateur();
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titre ?? 'Portefeuille') ?> - Portefeuille multidevise</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">💱 Portefeuille multidevise <span class="badge-sim">SIMULATION</span></div>
    <?php if ($user): ?>
    <nav>
        <a href="dashboard.php">Mes soldes</a>
        <?php if ($user['role'] !== 'administrateur'): ?>
            <a href="transfert.php">Transfert</a>
        <?php endif; ?>
        <a href="historique.php">Historique</a>
        <?php if ($user['role'] === 'administrateur'): ?>
            <a href="admin_taux.php">Taux &amp; paramètres</a>
            <a href="rapports.php">Rapports</a>
        <?php endif; ?>
        <span class="user"><?= e($user['nom']) ?> (<?= e(libelle_role($user['role'])) ?>)</span>
        <a class="logout" href="logout.php">Déconnexion</a>
    </nav>
    <?php endif; ?>
</header>
<main>
<?php foreach (flash_lire() as $m): ?>
    <div class="alert alert-<?= e($m['type']) ?>"><?= e($m['message']) ?></div>
<?php endforeach; ?>

<?php
/**
 * bootstrap.php - Initialisation commune à toutes les pages :
 * session, fuseau horaire, chargement des classes, connexion BD, fonctions utilitaires.
 */
declare(strict_types=1);

date_default_timezone_set('Africa/Douala');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

spl_autoload_register(static function (string $classe): void {
    $fichier = __DIR__ . '/src/' . $classe . '.php';
    if (is_file($fichier)) {
        require_once $fichier;
    }
});

require_once __DIR__ . '/connexion.php'; // définit $pdo

/** Échappement HTML (protection XSS). */
function e(mixed $valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_lire(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Décimales par devise (XAF = 0, USD/EUR = 2), mises en cache. */
function devises(): array
{
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $cache = $pdo->query('SELECT code, decimales FROM devises ORDER BY code')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $cache;
}

/** Montant formaté avec sa devise : fmt('16.6700', 'USD') -> "16,67 USD". */
function fmt(string|int|float|null $montant, string $devise): string
{
    $dec = (int) (devises()[$devise] ?? 2);
    return Money::formater((string) ($montant ?? '0'), $dec) . ' ' . $devise;
}

function libelle_role(string $role): string
{
    return ['client' => 'Client', 'administrateur' => 'Administrateur', 'commercant' => 'Commerçant'][$role] ?? $role;
}

function libelle_sens_taux(string $sens): string
{
    return ['identique' => 'même devise', 'direct' => 'taux direct', 'inverse' => 'taux inverse (division)'][$sens] ?? $sens;
}

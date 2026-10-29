<?php
/**
 * connexion.php - Connexion à la base de données (PDO / MySQL).
 *
 * Inclure ce fichier (require_once) pour obtenir la variable $pdo.
 * Modifier les valeurs ci-dessous selon votre installation (XAMPP / WAMP / LAMP).
 * Les variables d'environnement DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PORT
 * permettent de les surcharger (utile pour les tests).
 */

$db_hote = getenv('DB_HOST') ?: 'localhost';
$db_port = getenv('DB_PORT') ?: '3306';
$db_nom  = getenv('DB_NAME') ?: 'portefeuille_db';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

try {
    $pdo = new PDO(
        "mysql:host={$db_hote};port={$db_port};dbname={$db_nom};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // erreurs SQL -> exceptions
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,                    // vraies requêtes préparées
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+01:00'", // heure du Cameroun
        ]
    );
} catch (PDOException $e) {
    if (PHP_SAPI === 'cli') {
        throw $e;
    }
    http_response_code(500);
    exit('Connexion à la base de données impossible. Vérifiez connexion.php et que le script '
        . 'database/01_creation_base.sql a bien été exécuté.');
}

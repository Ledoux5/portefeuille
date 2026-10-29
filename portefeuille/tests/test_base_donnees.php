<?php
/**
 * Tests d'intégration (MySQL requis) : solde insuffisant, transactions échouées, atomicité,
 * validation (FR-11), doublons, paiement commerçant, rapports.
 *
 * Utilise une base SÉPARÉE « portefeuille_test » (créée puis recréée à chaque lancement) :
 * la base de l'application n'est jamais touchée.
 * Lancer : php tests/test_base_donnees.php      (variables DB_HOST, DB_USER, DB_PASS, DB_PORT possibles)
 */
require_once __DIR__ . '/bcmath_polyfill.php';

$hote = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';

// 1) (Re)création de la base de test à partir des deux scripts SQL du projet
$admin = new PDO("mysql:host={$hote};port={$port};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => true, // permet d'exécuter un script multi-requêtes
]);
$admin->exec('DROP DATABASE IF EXISTS portefeuille_test');
foreach (['01_creation_base.sql', '02_donnees_exemple.sql'] as $fichier) {
    $sql = file_get_contents(__DIR__ . '/../database/' . $fichier);
    $admin->exec(str_replace('portefeuille_db', 'portefeuille_test', $sql));
}

// 2) Connexion de l'application sur la base de test
putenv('DB_NAME=portefeuille_test');
require_once __DIR__ . '/../bootstrap.php';

$total = 0;
$echecs = 0;
function verifier(string $nom, mixed $attendu, mixed $obtenu): void
{
    global $total, $echecs;
    $total++;
    if ($attendu === $obtenu) {
        echo "  [OK]   $nom\n";
    } else {
        $echecs++;
        echo "  [ÉCHEC] $nom — attendu : " . var_export($attendu, true) . ', obtenu : ' . var_export($obtenu, true) . "\n";
    }
}

function solde(PDO $pdo, string $ident, string $devise): string
{
    $st = $pdo->prepare('SELECT w.solde FROM wallets w JOIN users u ON u.id = w.user_id WHERE u.identifiant = ? AND w.devise = ?');
    $st->execute([$ident, $devise]);
    return Money::arrondir((string) $st->fetchColumn(), 2);
}

function erreur(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (MetierException $e) {
        return $e->getMessage();
    }
}

$service = new TransferService($pdo);
const ALICE = 2, BOB = 3, BOUTIQUE = 4;
$compteur = 0;
$ref = function () use (&$compteur): string {
    return 'TEST-' . str_pad((string) ++$compteur, 6, '0', STR_PAD_LEFT);
};

echo "Transfert réussi : alice envoie 10 000 XAF vers le portefeuille USD de bob (1 USD = 600 XAF)\n";
$id = $service->executer(ALICE, $ref(), 'bob', 'XAF', 'USD', '10000');
verifier('alice débitée de 10 417 XAF (500 000 -> 489 583)', '489583.00', solde($pdo, 'alice', 'XAF'));
verifier('bob crédité de 16.67 USD (50 -> 66.67)', '66.67', solde($pdo, 'bob', 'USD'));
$t = (new TransactionRepository($pdo))->trouver($id);
verifier('taux enregistré : sens inverse', 'inverse', $t['sens_taux']);
verifier('frais totaux enregistrés = 350', '350.00', Money::arrondir($t['frais_total'], 2));
verifier('taxe enregistrée = 67', '67.00', Money::arrondir($t['montant_taxe'], 2));
verifier('paramètre de taxe conservé (audit)', '0.192500', $t['taux_taxe']);

echo "\nSolde insuffisant (FR-07) : aucun solde ne doit changer\n";
$avantA = solde($pdo, 'alice', 'USD');
$avantB = solde($pdo, 'bob', 'EUR');
$msg = erreur(fn () => $service->executer(ALICE, $ref(), 'bob', 'USD', 'EUR', '200')); // 200 + frais > 200 USD
verifier('message de solde insuffisant', true, $msg !== null && str_contains($msg, 'Solde insuffisant'));
verifier('solde USD d\'alice inchangé', $avantA, solde($pdo, 'alice', 'USD'));
verifier('solde EUR de bob inchangé', $avantB, solde($pdo, 'bob', 'EUR'));
verifier('échec journalisé', true, (int) $pdo->query('SELECT COUNT(*) FROM journal_echecs')->fetchColumn() >= 1);
verifier('aucune transaction créée pour l\'échec', 1, (int) $pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn());

echo "\nMontant juste avant le seuil : le débit complet (montant + frais + taxe) est exigé\n";
$msg = erreur(fn () => $service->executer(BOB, $ref(), 'alice', 'EUR', 'EUR', '20')); // 20 + frais > 20 EUR
verifier('refusé car le débit dépasse le solde de 20 EUR', true, $msg !== null && str_contains($msg, 'Solde insuffisant'));
verifier('solde EUR de bob toujours 20.00', '20.00', solde($pdo, 'bob', 'EUR'));

echo "\nValidation (FR-11)\n";
verifier('montant non numérique', true, erreur(fn () => $service->executer(ALICE, $ref(), 'bob', 'XAF', 'XAF', 'abc')) !== null);
verifier('montant négatif', true, erreur(fn () => $service->executer(ALICE, $ref(), 'bob', 'XAF', 'XAF', '-100')) !== null);
verifier('montant nul', true, erreur(fn () => $service->executer(ALICE, $ref(), 'bob', 'XAF', 'XAF', '0')) !== null);
verifier('trop de décimales pour XAF', true, erreur(fn () => $service->executer(ALICE, $ref(), 'bob', 'XAF', 'XAF', '100.5')) !== null);
verifier('devise non supportée', true, erreur(fn () => $service->executer(ALICE, $ref(), 'bob', 'GBP', 'XAF', '100')) !== null);
verifier('utilisateur inexistant', true, str_contains((string) erreur(fn () => $service->executer(ALICE, $ref(), 'fantome', 'XAF', 'XAF', '100')), 'inexistant'));
verifier('transfert vers soi-même refusé', true, erreur(fn () => $service->executer(ALICE, $ref(), 'alice', 'XAF', 'XAF', '100')) !== null);
verifier('solde XAF d\'alice inchangé après ces rejets', '489583.00', solde($pdo, 'alice', 'XAF'));

echo "\nIdentifiant de transaction en double\n";
$refDouble = $ref();
$service->executer(ALICE, $refDouble, 'bob', 'XAF', 'XAF', '1000');
$apres = solde($pdo, 'alice', 'XAF');
$msg = erreur(fn () => $service->executer(ALICE, $refDouble, 'bob', 'XAF', 'XAF', '1000'));
verifier('doublon rejeté', true, $msg !== null && str_contains($msg, 'double'));
verifier('doublon : solde non débité une seconde fois', $apres, solde($pdo, 'alice', 'XAF'));

echo "\nPaiement à un commerçant (commission 1 % déduite du règlement)\n";
$avantBoutique = solde($pdo, 'boutique', 'XAF');
$id = $service->executer(BOB, $ref(), 'boutique', 'XAF', 'XAF', '10000');
$t = (new TransactionRepository($pdo))->trouver($id);
verifier('type = paiement_commercant', 'paiement_commercant', $t['type_transaction']);
verifier('commission = 100 XAF', '100.00', Money::arrondir($t['montant_commission'], 2));
verifier('commerçant reçoit 9 900 XAF', '9900.00', Money::arrondir($t['credit_destinataire'], 2));
verifier('solde commerçant = ancien + 9 900', Money::arrondir(Money::add($avantBoutique, '9900'), 2), solde($pdo, 'boutique', 'XAF'));

echo "\nAtomicité : la contrainte CHECK empêche tout solde négatif\n";
try {
    $pdo->exec("UPDATE wallets SET solde = -1 WHERE user_id = 3 AND devise = 'EUR'");
    verifier('solde négatif refusé par la base', true, false);
} catch (PDOException $e) {
    verifier('solde négatif refusé par la base', true, true);
}

echo "\nInscription : identifiant en double\n";
$users = new UserService($pdo);
$users->creerClient('Nouveau Client', 'nouveau', '4321');
verifier('3 portefeuilles créés', 3, (int) $pdo->query("SELECT COUNT(*) FROM wallets w JOIN users u ON u.id = w.user_id WHERE u.identifiant = 'nouveau'")->fetchColumn());
verifier('identifiant en double rejeté', true, erreur(fn () => $users->creerClient('Autre', 'nouveau', '1111')) !== null);
verifier('PIN trop court rejeté', true, erreur(fn () => $users->creerClient('Autre', 'autre', '12')) !== null);
verifier('authentification correcte', true, $users->authentifier('alice', '1234') !== null);
verifier('mauvais PIN refusé', null, $users->authentifier('alice', '0000'));

echo "\nTaux de change : mise à jour par l'administrateur\n";
$taux = new ExchangeRateService($pdo);
$taux->enregistrer(1, 'USD', 'XAF', '610', date('Y-m-d H:i:s', strtotime('-1 minute')));
verifier('nouveau taux (610) pris en compte', '610.0000000000', $taux->trouver('USD', 'XAF')['taux']);
verifier('taux invalide rejeté', true, erreur(fn () => $taux->enregistrer(1, 'USD', 'XAF', '-5', date('Y-m-d H:i:s'))) !== null);
verifier('paire identique rejetée', true, erreur(fn () => $taux->enregistrer(1, 'USD', 'USD', '1', date('Y-m-d H:i:s'))) !== null);

echo "\nRapports\n";
$rapport = (new ReportService($pdo))->generer('jour');
verifier('le rapport journalier contient des lignes', true, count($rapport['lignes']) > 0);
verifier('commissions présentes', true, count($rapport['commissions']) > 0);
verifier('3 devises dans les soldes', 3, count($rapport['soldes']));

$admin->exec('DROP DATABASE IF EXISTS portefeuille_test');
echo "\n" . ($echecs === 0 ? 'TOUS LES TESTS PASSENT' : "$echecs ÉCHEC(S)") . " ($total vérifications)\n";
exit($echecs === 0 ? 0 : 1);

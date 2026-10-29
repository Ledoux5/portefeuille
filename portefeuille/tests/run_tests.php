<?php
/**
 * Tests unitaires (sans base de données, sans PHPUnit) :
 * conversion, frais, taxe, arrondi, commission, validation des montants.
 * Lancer : php tests/run_tests.php
 * Les tests « solde insuffisant » et « transaction échouée » (qui exigent MySQL) sont dans
 * tests/test_base_donnees.php.
 */
require_once __DIR__ . '/bcmath_polyfill.php'; // sans effet si l'extension bcmath est présente
require_once __DIR__ . '/../src/MetierException.php';
require_once __DIR__ . '/../src/Money.php';
require_once __DIR__ . '/../src/FeeCalculator.php';
require_once __DIR__ . '/../src/ExchangeRateService.php';

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

$calc = new FeeCalculator('0.025', '0.1925', '0.01', true);
$tauxInverseUsdXaf = ['id' => 1, 'taux' => '0.0016666666', 'sens' => 'inverse', 'brut' => '600'];
$tauxDirectUsdXaf  = ['id' => 1, 'taux' => '600', 'sens' => 'direct', 'brut' => '600'];

echo "Arrondi HALF_UP (Money::arrondir)\n";
verifier('16,666… -> 16.67 (2 déc.)', '16.67', Money::arrondir('16.66666666666666666666', 2));
verifier('67,375 -> 67 (0 déc.)', '67', Money::arrondir('67.375', 0));
verifier('67,5 -> 68 (0 déc.)', '68', Money::arrondir('67.5', 0));
verifier('2,675 -> 2.68 (pas d\'erreur de float)', '2.68', Money::arrondir('2.675', 2));
verifier('0,005 -> 0.01', '0.01', Money::arrondir('0.005', 2));
verifier('0,004 -> 0.00', '0.00', Money::arrondir('0.004', 2));
verifier('négatif -1,005 -> -1.01', '-1.01', Money::arrondir('-1.005', 2));
verifier('10000 reste 10000 (0 déc.)', '10000', Money::arrondir('10000', 0));

echo "\nConversion\n";
verifier('10 000 XAF -> USD à 1 USD = 600 : 16.67', '16.67', ExchangeRateService::convertir('10000', $tauxInverseUsdXaf, 2));
verifier('100 USD -> XAF à 600 : 60000', '60000', ExchangeRateService::convertir('100', $tauxDirectUsdXaf, 0));
verifier('même devise : montant inchangé', '250.00', ExchangeRateService::convertir('250', ['sens' => 'identique', 'brut' => '1'], 2));
verifier('division exacte (pas de taux inverse arrondi) : 1 XAF -> USD = 0.00', '0.00', ExchangeRateService::convertir('1', $tauxInverseUsdXaf, 2));
verifier('300 XAF -> USD = 0.50', '0.50', ExchangeRateService::convertir('300', $tauxInverseUsdXaf, 2));

echo "\nExemple de l'énoncé : 10 000 XAF -> portefeuille USD (1 USD = 600 XAF)\n";
$r = $calc->calculer('10000', 0, '100', '16.67', 2, false);
verifier('frais en pourcentage = 250 XAF', '250', $r['frais_pourcentage']);
verifier('frais fixes = 100 XAF', '100', $r['frais_fixes']);
verifier('frais totaux = 350 XAF', '350', $r['frais_total']);
verifier('taxe = 67,375 arrondie à 67 XAF (XAF sans décimale)', '67', $r['montant_taxe']);
verifier('débit total = 10 417 XAF', '10417', $r['debit_expediteur']);
verifier('crédit destinataire = 16.67 USD', '16.67', $r['credit_destinataire']);
verifier('pas de commission pour un transfert ordinaire', '0.00', $r['montant_commission']);
verifier('commission_deduite = 0 pour un transfert ordinaire', 0, $r['commission_deduite']);

echo "\nDevise source USD (frais fixes de 100 XAF convertis en USD)\n";
$fraisFixeUsd = ExchangeRateService::convertir('100', $tauxInverseUsdXaf, 10); // 0.1666666666…
$r = $calc->calculer('100.00', 2, $fraisFixeUsd, '60000', 0, false);
verifier('frais % = 2.50 USD', '2.50', $r['frais_pourcentage']);
verifier('frais fixes = 100 XAF ≈ 0.17 USD', '0.17', $r['frais_fixes']);
verifier('frais totaux = 2.67 USD', '2.67', $r['frais_total']);
verifier('taxe = 2.67 x 19,25 % = 0.51 USD', '0.51', $r['montant_taxe']);
verifier('débit = 100.00 + 2.67 + 0.51 = 103.18 USD', '103.18', $r['debit_expediteur']);

echo "\nPaiement commerçant (commission 1 % du montant converti)\n";
$r = $calc->calculer('10000', 0, '100', '16.67', 2, true);
verifier('commission = 0.17 USD', '0.17', $r['montant_commission']);
verifier('commerçant reçoit 16.67 - 0.17 = 16.50 USD', '16.50', $r['credit_destinataire']);
verifier('commission marquée déduite', 1, $r['commission_deduite']);
verifier('commission séparée des frais/taxe (débit inchangé 10 417 XAF)', '10417', $r['debit_expediteur']);

$sansDeduction = (new FeeCalculator('0.025', '0.1925', '0.01', false))->calculer('10000', 0, '100', '16.67', 2, true);
verifier('commission non déduite : commerçant reçoit 16.67', '16.67', $sansDeduction['credit_destinataire']);
verifier('commission non déduite : indicateur = 0', 0, $sansDeduction['commission_deduite']);

echo "\nParamètres configurables\n";
$autre = (new FeeCalculator('0.05', '0.10', '0.02', true))->calculer('1000', 0, '0', '1000', 0, false);
verifier('frais 5 % de 1000 = 50', '50', $autre['frais_total']);
verifier('taxe 10 % de 50 = 5', '5', $autre['montant_taxe']);
verifier('débit = 1055', '1055', $autre['debit_expediteur']);

echo "\nValidation des montants\n";
verifier('"10000" valide', true, Money::estMontantValide('10000'));
verifier('"25.50" valide', true, Money::estMontantValide('25.50'));
verifier('"abc" invalide', false, Money::estMontantValide('abc'));
verifier('"-5" invalide', false, Money::estMontantValide('-5'));
verifier('"1e5" invalide', false, Money::estMontantValide('1e5'));
verifier('"" invalide', false, Money::estMontantValide(''));
verifier('virgule décimale normalisée', '25.50', Money::normaliser('25,50'));
verifier('espaces retirés', '10000', Money::normaliser('10 000'));
verifier('nbDecimales("25.505") = 3', 3, Money::nbDecimales('25.505'));

echo "\nAffichage\n";
verifier('10417 -> "10 417"', '10 417', Money::formater('10417', 0));
verifier('16.67 -> "16,67"', '16,67', Money::formater('16.67', 2));
verifier('1234567.5 -> "1 234 567,50"', '1 234 567,50', Money::formater('1234567.5', 2));

echo "\n" . ($echecs === 0 ? "TOUS LES TESTS PASSENT" : "$echecs ÉCHEC(S)") . " ($total vérifications)\n";
exit($echecs === 0 ? 0 : 1);

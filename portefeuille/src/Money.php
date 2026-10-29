<?php
/**
 * Arithmétique monétaire décimale (BCMath) - équivalent PHP de BigDecimal.
 * Aucun float n'est utilisé : tous les montants sont des chaînes de caractères.
 *
 * Politique d'arrondi (documentée) :
 *  - Calculs intermédiaires : précision interne de SCALE = 20 décimales (troncature, jamais d'arrondi).
 *  - Arrondi final : HALF_UP (0,5 vers le haut) au nombre de décimales de la devise concernée.
 *  - Chaque composante (frais %, frais fixes, taxe, montant converti, commission) est arrondie
 *    séparément dans sa propre devise ; le débit total est la somme des composantes arrondies.
 */
final class Money
{
    public const SCALE = 20;

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    public static function div(string $a, string $b, int $scale = self::SCALE): string
    {
        if (bccomp($b, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Division par zéro.');
        }
        return bcdiv($a, $b, $scale);
    }

    public static function cmp(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    /** Arrondi HALF_UP à $decimales décimales ; retourne toujours exactement $decimales décimales. */
    public static function arrondir(string $valeur, int $decimales): string
    {
        $negatif = bccomp($valeur, '0', self::SCALE) < 0;
        $abs = $negatif ? ltrim($valeur, '-') : $valeur;
        $demi = $decimales === 0 ? '0.5' : '0.' . str_repeat('0', $decimales) . '5';
        $resultat = bcadd($abs, $demi, $decimales); // la troncature après +0,5 donne HALF_UP
        if ($negatif && bccomp($resultat, '0', $decimales) !== 0) {
            return '-' . $resultat;
        }
        return $resultat;
    }

    /** Nettoie une saisie : espaces retirés, virgule décimale convertie en point. */
    public static function normaliser(string $saisie): string
    {
        return str_replace(',', '.', preg_replace('/\s+/', '', $saisie));
    }

    /** Format attendu : entier ou décimal positif (au plus 12 chiffres entiers, 4 décimales). */
    public static function estMontantValide(string $montant): bool
    {
        return (bool) preg_match('/^\d{1,12}(\.\d{1,4})?$/', $montant);
    }

    public static function nbDecimales(string $montant): int
    {
        $pos = strpos($montant, '.');
        return $pos === false ? 0 : strlen($montant) - $pos - 1;
    }

    /** Affichage lisible : 10417 -> "10 417", 16.67 -> "16,67". */
    public static function formater(string $valeur, int $decimales): string
    {
        $r = self::arrondir($valeur, $decimales);
        $negatif = $r[0] === '-';
        if ($negatif) {
            $r = substr($r, 1);
        }
        $parties = explode('.', $r, 2);
        $entier = strrev(implode(' ', str_split(strrev($parties[0]), 3)));
        return ($negatif ? '-' : '') . $entier . ($decimales > 0 ? ',' . ($parties[1] ?? '') : '');
    }
}

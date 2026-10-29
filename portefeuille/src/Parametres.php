<?php
/** Paramètres configurables : frais, taxe, commission (hypothèses de classe, pas des conseils fiscaux). */
final class Parametres
{
    public const DEFAUTS = [
        'frais_pourcentage'  => '0.025',
        'frais_fixe_xaf'     => '100',
        'taux_taxe'          => '0.1925',
        'taux_commission'    => '0.01',
        'commission_deduite' => '1',
    ];

    public static function charger(PDO $pdo): array
    {
        $params = self::DEFAUTS;
        foreach ($pdo->query('SELECT cle, valeur FROM parametres') as $ligne) {
            if (array_key_exists($ligne['cle'], $params)) {
                $params[$ligne['cle']] = $ligne['valeur'];
            }
        }
        return $params;
    }

    /** Lignes complètes (clé, valeur, description) pour l'affichage administrateur. */
    public static function lister(PDO $pdo): array
    {
        return $pdo->query('SELECT cle, valeur, description FROM parametres ORDER BY cle')->fetchAll();
    }

    public static function enregistrer(PDO $pdo, string $cle, string $valeur): void
    {
        if (!array_key_exists($cle, self::DEFAUTS)) {
            throw new MetierException("Paramètre inconnu : $cle.");
        }
        $valeur = Money::normaliser($valeur);
        switch ($cle) {
            case 'frais_fixe_xaf':
                $ok = (bool) preg_match('/^\d{1,9}(\.\d{1,2})?$/', $valeur);
                $msg = 'Les frais fixes doivent être un montant positif ou nul (ex. 100).';
                break;
            case 'commission_deduite':
                $ok = in_array($valeur, ['0', '1'], true);
                $msg = 'La valeur doit être 0 (non déduite) ou 1 (déduite).';
                break;
            default: // taux compris entre 0 et 1 (ex. 0.025 = 2,5 %)
                $ok = (bool) preg_match('/^(0(\.\d{1,6})?|1(\.0{1,6})?)$/', $valeur);
                $msg = 'Le taux doit être compris entre 0 et 1 (ex. 0.025 pour 2,5 %).';
        }
        if (!$ok) {
            throw new MetierException($msg);
        }
        $st = $pdo->prepare('UPDATE parametres SET valeur = ? WHERE cle = ?');
        $st->execute([$valeur, $cle]);
        if ($st->rowCount() === 0) { // valeur identique ou ligne absente
            $pdo->prepare('INSERT IGNORE INTO parametres (cle, valeur, description) VALUES (?, ?, ?)')
                ->execute([$cle, $valeur, $cle]);
        }
    }
}

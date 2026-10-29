<?php
/**
 * Gestion et application des taux de change.
 *
 * CONVENTION DOCUMENTÉE : les taux sont stockés « source -> cible » :
 *     1 devise_source = taux devise_cible        (ex. USD -> XAF = 600)
 * Si seule la paire opposée existe (XAF -> USD), on utilise la division par le taux stocké :
 *     montant USD = montant XAF ÷ 600
 * Le taux effectif retenu est le plus récent dont la date d'effet est <= maintenant.
 */
final class ExchangeRateService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{id:?int,taux:string,sens:string,brut:string} */
    public function trouver(string $source, string $cible): array
    {
        if ($source === $cible) {
            return ['id' => null, 'taux' => '1', 'sens' => 'identique', 'brut' => '1'];
        }
        $sql = 'SELECT id, taux FROM taux_change
                WHERE devise_source = ? AND devise_cible = ? AND date_effet <= NOW()
                ORDER BY date_effet DESC, id DESC LIMIT 1';
        $st = $this->pdo->prepare($sql);

        $st->execute([$source, $cible]);
        if ($l = $st->fetch()) {
            return ['id' => (int) $l['id'], 'taux' => $l['taux'], 'sens' => 'direct', 'brut' => $l['taux']];
        }
        $st->execute([$cible, $source]);
        if ($l = $st->fetch()) {
            return [
                'id'   => (int) $l['id'],
                'taux' => Money::div('1', $l['taux'], 10), // taux effectif cible par unité source
                'sens' => 'inverse',
                'brut' => $l['taux'],
            ];
        }
        throw new MetierException("Aucun taux de change défini pour {$source} → {$cible}.");
    }

    /** Convertit un montant avec le taux trouvé, puis arrondit à $decimales (HALF_UP). */
    public static function convertir(string $montant, array $taux, int $decimales): string
    {
        switch ($taux['sens']) {
            case 'direct':
                $brut = Money::mul($montant, $taux['brut']);
                break;
            case 'inverse':
                $brut = Money::div($montant, $taux['brut']); // division exacte, pas de taux inverse arrondi
                break;
            default:
                $brut = $montant;
        }
        return Money::arrondir($brut, $decimales);
    }

    public function lister(): array
    {
        return $this->pdo->query(
            'SELECT t.*, u.identifiant AS admin FROM taux_change t
             JOIN users u ON u.id = t.modifie_par
             ORDER BY t.date_effet DESC, t.id DESC'
        )->fetchAll();
    }

    /** Création / mise à jour (nouvelle ligne datée, l'historique est conservé). */
    public function enregistrer(int $adminId, string $source, string $cible, string $taux, string $dateEffet): int
    {
        $source = strtoupper(trim($source));
        $cible  = strtoupper(trim($cible));
        $taux   = Money::normaliser($taux);

        $codes = $this->pdo->query('SELECT code FROM devises')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($source, $codes, true) || !in_array($cible, $codes, true)) {
            throw new MetierException('Devise non supportée.');
        }
        if ($source === $cible) {
            throw new MetierException('La devise source et la devise cible doivent être différentes.');
        }
        if (!preg_match('/^\d{1,12}(\.\d{1,10})?$/', $taux) || Money::cmp($taux, '0') <= 0) {
            throw new MetierException('Le taux doit être un nombre strictement positif (10 décimales max).');
        }
        $date = DateTime::createFromFormat('Y-m-d\TH:i', $dateEffet)
            ?: DateTime::createFromFormat('Y-m-d H:i:s', $dateEffet);
        if (!$date) {
            throw new MetierException("Date d'effet invalide.");
        }
        $st = $this->pdo->prepare(
            'INSERT INTO taux_change (devise_source, devise_cible, taux, date_effet, modifie_par)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([$source, $cible, $taux, $date->format('Y-m-d H:i:s'), $adminId]);
        return (int) $this->pdo->lastInsertId();
    }
}

<?php
/** Rapports journaliers / mensuels (FR-10). */
final class ReportService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param string $granularite 'jour' ou 'mois'
     * @return array{lignes:array,commissions:array,soldes:array}
     *  lignes      : par période et devise source -> nombre, montants source, frais, taxes
     *  commissions : par période et devise de règlement -> commissions
     *  soldes      : total des portefeuilles par devise
     */
    public function generer(string $granularite, int $nbPeriodes = 31): array
    {
        $mois = $granularite === 'mois';
        $format = $mois ? '%Y-%m' : '%Y-%m-%d';
        $nbPeriodes = max(1, min(120, $nbPeriodes));
        $depuis = $mois
            ? date('Y-m-01 00:00:00', strtotime("-" . ($nbPeriodes - 1) . ' months'))
            : date('Y-m-d 00:00:00', strtotime('-' . ($nbPeriodes - 1) . ' days'));

        $st = $this->pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '{$format}') AS periode,
                    devise_source,
                    COUNT(*)            AS nb_transactions,
                    SUM(montant_source) AS total_source,
                    SUM(frais_total)    AS total_frais,
                    SUM(montant_taxe)   AS total_taxe
             FROM transactions
             WHERE statut = 'reussie' AND created_at >= ?
             GROUP BY DATE_FORMAT(created_at, '{$format}'), devise_source
             ORDER BY periode DESC, devise_source"
        );
        $st->execute([$depuis]);
        $lignes = $st->fetchAll();

        $st = $this->pdo->prepare(
            "SELECT DATE_FORMAT(created_at, '{$format}') AS periode,
                    devise_cible,
                    COUNT(*)                AS nb_paiements,
                    SUM(montant_commission) AS total_commission
             FROM transactions
             WHERE statut = 'reussie' AND type_transaction = 'paiement_commercant' AND created_at >= ?
             GROUP BY DATE_FORMAT(created_at, '{$format}'), devise_cible
             ORDER BY periode DESC, devise_cible"
        );
        $st->execute([$depuis]);
        $commissions = $st->fetchAll();

        $soldes = $this->pdo->query(
            'SELECT devise, SUM(solde) AS total_solde, COUNT(*) AS nb_portefeuilles
             FROM wallets GROUP BY devise ORDER BY devise'
        )->fetchAll();

        return ['lignes' => $lignes, 'commissions' => $commissions, 'soldes' => $soldes];
    }
}

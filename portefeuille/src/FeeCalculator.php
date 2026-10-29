<?php
/**
 * Calcul pur (sans base de données) des frais, de la taxe, du débit et de la commission.
 * Entièrement testable de façon déterministe.
 */
final class FeeCalculator
{
    public function __construct(
        private string $tauxFrais,         // ex. '0.025'
        private string $tauxTaxe,          // ex. '0.1925' (appliqué aux frais de transaction)
        private string $tauxCommission,    // ex. '0.01' (paiements commerçant uniquement)
        private bool $commissionDeduite
    ) {
    }

    public static function depuisParametres(array $p): self
    {
        return new self($p['frais_pourcentage'], $p['taux_taxe'], $p['taux_commission'], $p['commission_deduite'] === '1');
    }

    /**
     * @param string $montantSource  montant source (déjà arrondi à $decSource)
     * @param int    $decSource      décimales de la devise source
     * @param string $fraisFixeSource frais fixes exprimés en devise source (non arrondis)
     * @param string $montantConverti montant converti (déjà arrondi à $decCible)
     * @param int    $decCible       décimales de la devise cible
     * @param bool   $paiementCommercant true si le destinataire est un commerçant
     */
    public function calculer(
        string $montantSource,
        int $decSource,
        string $fraisFixeSource,
        string $montantConverti,
        int $decCible,
        bool $paiementCommercant
    ): array {
        $fraisPct   = Money::arrondir(Money::mul($montantSource, $this->tauxFrais), $decSource);
        $fraisFixes = Money::arrondir($fraisFixeSource, $decSource);
        $fraisTotal = Money::arrondir(Money::add($fraisPct, $fraisFixes), $decSource);
        $taxe       = Money::arrondir(Money::mul($fraisTotal, $this->tauxTaxe), $decSource);
        $debit      = Money::arrondir(Money::add(Money::add($montantSource, $fraisTotal), $taxe), $decSource);

        $commission = Money::arrondir('0', $decCible);
        $tauxCommission = '0';
        $credit = $montantConverti;
        if ($paiementCommercant) {
            $tauxCommission = $this->tauxCommission;
            $commission = Money::arrondir(Money::mul($montantConverti, $this->tauxCommission), $decCible);
            if ($this->commissionDeduite) {
                $credit = Money::arrondir(Money::sub($montantConverti, $commission), $decCible);
            }
        }

        return [
            'taux_frais'          => $this->tauxFrais,
            'frais_pourcentage'   => $fraisPct,
            'frais_fixes'         => $fraisFixes,
            'frais_total'         => $fraisTotal,
            'taux_taxe'           => $this->tauxTaxe,
            'montant_taxe'        => $taxe,
            'taux_commission'     => $tauxCommission,
            'montant_commission'  => $commission,
            'commission_deduite'  => ($paiementCommercant && $this->commissionDeduite) ? 1 : 0,
            'debit_expediteur'    => $debit,
            'credit_destinataire' => $credit,
        ];
    }
}

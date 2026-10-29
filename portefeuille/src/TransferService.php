<?php
/**
 * Service de transfert : validation, conversion, frais, taxe, commission,
 * puis débit/crédit ATOMIQUES (transaction SQL + verrouillage des portefeuilles).
 *
 * Règles (cf. énoncé, section 5) :
 *   débit expéditeur  = montant source + frais de transaction + taxe
 *   frais             = montant source x 2,5 % + 100 XAF (converti si la devise source n'est pas XAF)
 *   taxe              = frais x 19,25 %
 *   commerçant reçoit = montant converti - commission (paiements aux commerçants uniquement)
 */
final class TransferService
{
    private ExchangeRateService $taux;

    public function __construct(private PDO $pdo)
    {
        $this->taux = new ExchangeRateService($pdo);
    }

    /** Simulation : calcule tout sans rien écrire (aucun solde modifié). */
    public function simuler(int $expediteurId, string $destinataire, string $deviseSource, string $deviseCible, string $montant): array
    {
        return $this->preparer($expediteurId, $destinataire, $deviseSource, $deviseCible, $montant, false);
    }

    /**
     * Exécute le transfert. Retourne l'id de la transaction.
     * En cas d'échec de validation : rollback complet, aucun solde modifié, MetierException levée.
     */
    public function executer(
        int $expediteurId,
        string $reference,
        string $destinataire,
        string $deviseSource,
        string $deviseCible,
        string $montant
    ): int {
        try {
            if (!preg_match('/^[A-Za-z0-9\-]{8,40}$/', $reference)) {
                throw new MetierException('Identifiant de transaction invalide.');
            }
            $st = $this->pdo->prepare('SELECT 1 FROM transactions WHERE reference = ?');
            $st->execute([$reference]);
            if ($st->fetchColumn()) {
                throw new MetierException('Cette transaction a déjà été enregistrée (identifiant en double).');
            }

            $this->pdo->beginTransaction();
            $d = $this->preparer($expediteurId, $destinataire, $deviseSource, $deviseCible, $montant, true);

            if (!$d['solde_suffisant']) {
                throw new MetierException($d['message_solde']);
            }

            // Débit (la condition solde >= débit garantit qu'un solde ne devient jamais négatif)
            $deb = $this->pdo->prepare('UPDATE wallets SET solde = solde - ? WHERE id = ? AND solde >= ?');
            $deb->execute([$d['debit_expediteur'], $d['wallet_expediteur_id'], $d['debit_expediteur']]);
            if ($deb->rowCount() !== 1) {
                throw new MetierException($d['message_solde']);
            }
            // Crédit
            $cre = $this->pdo->prepare('UPDATE wallets SET solde = solde + ? WHERE id = ?');
            $cre->execute([$d['credit_destinataire'], $d['wallet_destinataire_id']]);

            $ins = $this->pdo->prepare(
                'INSERT INTO transactions
                 (reference, expediteur_id, destinataire_id, type_transaction, montant_source, devise_source,
                  montant_cible, devise_cible, taux_change_id, taux_utilise, sens_taux, taux_frais,
                  frais_pourcentage, frais_fixes, frais_total, taux_taxe, montant_taxe, taux_commission,
                  montant_commission, commission_deduite, debit_expediteur, credit_destinataire, statut)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $ins->execute([
                $reference, $expediteurId, $d['destinataire_id'], $d['type_transaction'],
                $d['montant_source'], $d['devise_source'], $d['montant_cible'], $d['devise_cible'],
                $d['taux_change_id'], $d['taux_utilise'], $d['sens_taux'], $d['taux_frais'],
                $d['frais_pourcentage'], $d['frais_fixes'], $d['frais_total'], $d['taux_taxe'],
                $d['montant_taxe'], $d['taux_commission'], $d['montant_commission'],
                $d['commission_deduite'], $d['debit_expediteur'], $d['credit_destinataire'], 'reussie',
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (MetierException $e) {
            $this->annuler();
            $this->journaliser($expediteurId, $reference, $e->getMessage());
            throw $e;
        } catch (PDOException $e) {
            $this->annuler();
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new MetierException('Cette transaction a déjà été enregistrée (identifiant en double).');
            }
            throw $e;
        }
    }

    private function annuler(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function journaliser(int $userId, string $reference, string $motif): void
    {
        try {
            $this->pdo->prepare('INSERT INTO journal_echecs (user_id, reference, motif) VALUES (?, ?, ?)')
                ->execute([$userId, substr($reference, 0, 40), substr($motif, 0, 255)]);
        } catch (Throwable $e) {
            // Le journal ne doit jamais masquer l'erreur d'origine.
        }
    }

    /** Valide la demande et calcule tous les montants. $verrou = true : verrouille les portefeuilles (FOR UPDATE). */
    private function preparer(int $expId, string $destIdent, string $devSrc, string $devCible, string $montantBrut, bool $verrou): array
    {
        $devises = $this->pdo->query('SELECT code, decimales FROM devises')->fetchAll(PDO::FETCH_KEY_PAIR);
        $devSrc   = strtoupper(trim($devSrc));
        $devCible = strtoupper(trim($devCible));
        if (!isset($devises[$devSrc])) {
            throw new MetierException('Devise source non supportée.');
        }
        if (!isset($devises[$devCible])) {
            throw new MetierException('Devise de réception non supportée.');
        }
        $decSrc   = (int) $devises[$devSrc];
        $decCible = (int) $devises[$devCible];

        // --- Montant ---------------------------------------------------------------------------
        $montant = Money::normaliser($montantBrut);
        if (!Money::estMontantValide($montant)) {
            throw new MetierException('Montant invalide : saisissez un nombre positif (ex. 10000 ou 25,50).');
        }
        if (Money::cmp($montant, '0') <= 0) {
            throw new MetierException('Le montant doit être strictement supérieur à zéro.');
        }
        if (Money::nbDecimales($montant) > $decSrc) {
            throw new MetierException("La devise {$devSrc} n'accepte que {$decSrc} décimale(s).");
        }
        $montant = Money::arrondir($montant, $decSrc);

        // --- Utilisateurs ----------------------------------------------------------------------
        $exp = $this->utilisateur('id', $expId);
        if (!$exp || $exp['statut'] !== 'actif') {
            throw new MetierException('Expéditeur inexistant ou suspendu.');
        }
        $destIdent = trim($destIdent);
        if ($destIdent === '') {
            throw new MetierException('Veuillez indiquer le destinataire.');
        }
        $dest = $this->utilisateur('identifiant', $destIdent);
        if (!$dest) {
            throw new MetierException("Utilisateur inexistant : « {$destIdent} ».");
        }
        if ($dest['statut'] !== 'actif') {
            throw new MetierException('Le compte du destinataire est suspendu.');
        }
        if ((int) $dest['id'] === (int) $exp['id']) {
            throw new MetierException('Vous ne pouvez pas vous envoyer de l\'argent à vous-même.');
        }
        $paiementCommercant = $dest['role'] === 'commercant';

        // --- Portefeuilles (verrouillés dans l'ordre des id pour éviter les interblocages) ------
        $wExp  = $this->walletId((int) $exp['id'], $devSrc);
        $wDest = $this->walletId((int) $dest['id'], $devCible);
        if ($wExp === null) {
            throw new MetierException("Vous n'avez pas de portefeuille en {$devSrc}.");
        }
        if ($wDest === null) {
            throw new MetierException("Le destinataire n'a pas de portefeuille en {$devCible}.");
        }
        $sql = 'SELECT id, solde FROM wallets WHERE id IN (?, ?) ORDER BY id' . ($verrou ? ' FOR UPDATE' : '');
        $st = $this->pdo->prepare($sql);
        $st->execute([$wExp, $wDest]);
        $soldes = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $soldeExp = $soldes[$wExp];

        // --- Conversion, frais, taxe, commission ----------------------------------------------
        $params = Parametres::charger($this->pdo);
        $tauxInfo = $this->taux->trouver($devSrc, $devCible);
        $converti = ExchangeRateService::convertir($montant, $tauxInfo, $decCible);

        if ($devSrc === 'XAF') {
            $fraisFixeSource = $params['frais_fixe_xaf'];
        } else { // frais fixes équivalents dans la devise source (précision conservée, arrondi dans le calculateur)
            $fraisFixeSource = ExchangeRateService::convertir(
                $params['frais_fixe_xaf'],
                $this->taux->trouver('XAF', $devSrc),
                10
            );
        }
        $calc = FeeCalculator::depuisParametres($params)->calculer(
            $montant, $decSrc, $fraisFixeSource, $converti, $decCible, $paiementCommercant
        );

        $suffisant = Money::cmp($soldeExp, $calc['debit_expediteur']) >= 0;
        $message = $suffisant ? '' : sprintf(
            'Solde insuffisant : débit requis %s %s (montant + frais + taxe), solde disponible %s %s.',
            Money::formater($calc['debit_expediteur'], $decSrc), $devSrc,
            Money::formater($soldeExp, $decSrc), $devSrc
        );

        return array_merge($calc, [
            'type_transaction'       => $paiementCommercant ? 'paiement_commercant' : 'transfert',
            'expediteur_id'          => (int) $exp['id'],
            'expediteur_nom'         => $exp['nom_complet'],
            'destinataire_id'        => (int) $dest['id'],
            'destinataire_nom'       => $dest['nom_complet'],
            'destinataire_ident'     => $dest['identifiant'],
            'montant_source'         => $montant,
            'devise_source'          => $devSrc,
            'montant_cible'          => $converti,
            'devise_cible'           => $devCible,
            'taux_change_id'         => $tauxInfo['id'],
            'taux_utilise'           => $tauxInfo['taux'],
            'sens_taux'              => $tauxInfo['sens'],
            'wallet_expediteur_id'   => $wExp,
            'wallet_destinataire_id' => $wDest,
            'solde_expediteur'       => $soldeExp,
            'solde_suffisant'        => $suffisant,
            'message_solde'          => $message,
        ]);
    }

    private function utilisateur(string $champ, int|string $valeur): ?array
    {
        $champ = $champ === 'id' ? 'id' : 'identifiant'; // liste blanche, pas d'injection
        $st = $this->pdo->prepare("SELECT id, nom_complet, identifiant, role, statut FROM users WHERE {$champ} = ?");
        $st->execute([$valeur]);
        return $st->fetch() ?: null;
    }

    private function walletId(int $userId, string $devise): ?int
    {
        $st = $this->pdo->prepare('SELECT id FROM wallets WHERE user_id = ? AND devise = ?');
        $st->execute([$userId, $devise]);
        $id = $st->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}

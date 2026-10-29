<?php
/** Lecture de l'historique et des reçus. */
final class TransactionRepository
{
    private const SELECT = 'SELECT t.*, e.nom_complet AS expediteur_nom, e.identifiant AS expediteur_ident,
                                   d.nom_complet AS destinataire_nom, d.identifiant AS destinataire_ident
                            FROM transactions t
                            JOIN users e ON e.id = t.expediteur_id
                            JOIN users d ON d.id = t.destinataire_id';

    public function __construct(private PDO $pdo)
    {
    }

    /** Historique d'un utilisateur (envoyé ou reçu) ; l'administrateur voit tout. */
    public function historique(int $userId, bool $tout = false, int $limite = 200): array
    {
        $limite = max(1, min(1000, $limite));
        if ($tout) {
            return $this->pdo->query(self::SELECT . " ORDER BY t.created_at DESC, t.id DESC LIMIT {$limite}")->fetchAll();
        }
        $st = $this->pdo->prepare(
            self::SELECT . " WHERE t.expediteur_id = ? OR t.destinataire_id = ?
                             ORDER BY t.created_at DESC, t.id DESC LIMIT {$limite}"
        );
        $st->execute([$userId, $userId]);
        return $st->fetchAll();
    }

    public function trouver(int $id): ?array
    {
        $st = $this->pdo->prepare(self::SELECT . ' WHERE t.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }
}

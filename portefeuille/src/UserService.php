<?php
/** Comptes utilisateurs : inscription et authentification (PIN haché). */
final class UserService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function authentifier(string $identifiant, string $pin): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE identifiant = ?');
        $st->execute([trim($identifiant)]);
        $u = $st->fetch();
        if (!$u || !password_verify($pin, $u['pin_hash'])) {
            return null;
        }
        if ($u['statut'] !== 'actif') {
            throw new MetierException('Ce compte est suspendu.');
        }
        return $u;
    }

    /** Crée un client avec ses trois portefeuilles à solde nul. */
    public function creerClient(string $nomComplet, string $identifiant, string $pin, string $role = 'client'): int
    {
        $nomComplet = trim($nomComplet);
        $identifiant = trim($identifiant);
        if ($nomComplet === '' || mb_strlen($nomComplet) > 100) {
            throw new MetierException('Le nom complet est obligatoire (100 caractères maximum).');
        }
        if (!preg_match('/^[A-Za-z0-9_.\-]{3,30}$/', $identifiant)) {
            throw new MetierException("L'identifiant doit contenir 3 à 30 caractères (lettres, chiffres, . _ -).");
        }
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new MetierException('Le PIN doit contenir 4 à 6 chiffres.');
        }
        if (!in_array($role, ['client', 'commercant'], true)) {
            throw new MetierException('Rôle invalide.');
        }
        $st = $this->pdo->prepare('SELECT 1 FROM users WHERE identifiant = ?');
        $st->execute([$identifiant]);
        if ($st->fetchColumn()) {
            throw new MetierException("L'identifiant « {$identifiant} » existe déjà (identifiant en double).");
        }

        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare('INSERT INTO users (nom_complet, identifiant, pin_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$nomComplet, $identifiant, password_hash($pin, PASSWORD_DEFAULT), $role]);
            $id = (int) $this->pdo->lastInsertId();
            $w = $this->pdo->prepare('INSERT INTO wallets (user_id, devise, solde) VALUES (?, ?, 0)');
            foreach ($this->pdo->query('SELECT code FROM devises')->fetchAll(PDO::FETCH_COLUMN) as $code) {
                $w->execute([$id, $code]);
            }
            $this->pdo->commit();
            return $id;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (($e->errorInfo[1] ?? 0) === 1062) {
                throw new MetierException("L'identifiant « {$identifiant} » existe déjà (identifiant en double).");
            }
            throw $e;
        }
    }

    public function soldes(int $userId): array
    {
        $st = $this->pdo->prepare(
            'SELECT w.devise, w.solde, d.decimales FROM wallets w
             JOIN devises d ON d.code = w.devise WHERE w.user_id = ? ORDER BY w.devise'
        );
        $st->execute([$userId]);
        return $st->fetchAll();
    }
}

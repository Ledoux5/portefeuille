<?php
/** Session, contrôle d'accès par rôle et protection CSRF. */
final class Auth
{
    public static function utilisateur(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function connecter(array $u): void
    {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id'          => (int) $u['id'],
            'nom'         => $u['nom_complet'],
            'identifiant' => $u['identifiant'],
            'role'        => $u['role'],
        ];
    }

    public static function deconnecter(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    /** Exige une connexion (et éventuellement l'un des rôles donnés). */
    public static function exiger(string ...$roles): array
    {
        $u = self::utilisateur();
        if (!$u) {
            header('Location: index.php');
            exit;
        }
        if ($roles && !in_array($u['role'], $roles, true)) {
            http_response_code(403);
            exit('Accès refusé : cette page est réservée à un autre profil.');
        }
        return $u;
    }

    public static function csrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verifierCsrf(): void
    {
        $envoye = $_POST['csrf'] ?? '';
        if (!is_string($envoye) || !hash_equals(self::csrf(), $envoye)) {
            http_response_code(400);
            exit('Requête invalide (jeton CSRF). Rechargez la page et recommencez.');
        }
    }
}

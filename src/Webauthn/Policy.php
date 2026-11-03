<?php

declare(strict_types=1);

namespace Qlip\Webauthn;

/**
 * Politique de verification utilisateur (userVerification).
 *
 * Trois niveaux, du plus fort au plus faible : required, preferred, discouraged.
 * Le niveau est choisi par le serveur, par profil de ceremonie, jamais par le
 * navigateur : sinon un client pourrait demander 'discouraged' et obtenir une
 * assertion sur une credential reglee en 'required'.
 *
 * Les deux profils de Qlip :
 *   - presence : second facteur avec mot de passe. L'utilisateur est deja prouve
 *     par le mot de passe, la cle n'a plus qu'a prouver sa possession. Un
 *     authentifiant U2F (YubiKey NEO) ne sait pas faire la verification utilisateur,
 *     et 'discouraged' est le seul niveau qu'un authentifiant U2F peut respecter ;
 *   - passkey : la cle est le seul moyen d'identifier l'utilisateur, donc 'required'
 *     reste impose (page d'administration).
 *
 * La politique est stockee par credential et ne change plus. Une credential qui a
 * deja fait une verification utilisateur continue de la demander : la cle a montre
 * qu'elle savait faire, et un controle ne se relache pas pour faire passer un test.
 */
final class Policy
{
    public const REQUIRED = 'required';
    public const PREFERRED = 'preferred';
    public const DISCOURAGED = 'discouraged';

    /** Profils de ceremonie. */
    public const PROFILE_PRESENCE = 'presence';
    public const PROFILE_PASSKEY = 'passkey';

    /** Du plus fort au plus faible. */
    private const LEVELS = [self::REQUIRED => 3, self::PREFERRED => 2, self::DISCOURAGED => 1];

    /** Ce que chaque profil demande. */
    private const PROFILES = [
        self::PROFILE_PRESENCE => self::DISCOURAGED,
        self::PROFILE_PASSKEY => self::REQUIRED,
    ];

    public static function normalize(string $policy): string
    {
        if (!isset(self::LEVELS[$policy])) {
            throw new \InvalidArgumentException('Politique de verification utilisateur invalide : ' . $policy);
        }

        return $policy;
    }

    /** Politique d'un profil de ceremonie. */
    public static function forProfile(string $profile): string
    {
        if (!isset(self::PROFILES[$profile])) {
            throw new \InvalidArgumentException('Profil de ceremonie inconnu : ' . $profile);
        }

        return self::PROFILES[$profile];
    }

    /**
     * La plus forte des deux politiques. La ceremonie ne peut pas descendre en
     * dessous de la politique de la credential, et une credential enregistree sous
     * un profil fort garde cette politique si elle est ensuite utilisee sous un
     * profil plus faible.
     */
    public static function strongest(string $left, string $right): string
    {
        return self::LEVELS[self::normalize($left)] >= self::LEVELS[self::normalize($right)] ? $left : $right;
    }

    /**
     * Une assertion doit-elle exiger la verification utilisateur ?
     *
     * required : toujours. preferred : oui des que la cle l'a deja faite une fois
     * (le niveau laisse le choix a la cle une fois, pas a chaque fois quand elle est
     * capable de la faire). discouraged : jamais.
     */
    public static function enforces(string $policy, bool $verifiedOnce): bool
    {
        $level = self::LEVELS[self::normalize($policy)];

        return $level === self::LEVELS[self::REQUIRED] || ($level === self::LEVELS[self::PREFERRED] && $verifiedOnce);
    }

    /** @return list<string> */
    public static function levels(): array
    {
        return [self::REQUIRED, self::PREFERRED, self::DISCOURAGED];
    }

    /** @return list<string> */
    public static function profiles(): array
    {
        return array_keys(self::PROFILES);
    }
}

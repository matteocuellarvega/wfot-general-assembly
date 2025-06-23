<?php
namespace WFOT\Services;

class TokenService
{
    /**
     * Checks if the provided token is valid for the given registration ID.
     *
     * @param string $registrationId The registration ID to check against.
     * @param string $token The token to validate.
     * @return bool True if the token is valid, false otherwise.
     */
    public static function check(string $registrationId, string $token): bool
    {
        return hash_equals(self::forRegistration($registrationId), $token);
    }
    
    /**
     * Generates a token for a given registration ID.
     *
     * @param string $registrationId The registration ID to generate a token for.
     * @return string The generated token.
     */
    public static function generate(string $registrationId): string
    {
        return hash_hmac('sha256', $registrationId, env('TOKEN_SALT'));
    }
}
?>

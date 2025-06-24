<?php
namespace WFOT\Services;

class TokenService
{
    /**
     * Checks if the provided token is valid for the given Airtable record ID.
     *
     * @param string $recordId The Airtable record ID to check against.
     * @param string $token The token to validate.
     * @param string|null $customSalt Optional custom salt to use instead of the default.
     * @return bool True if the token is valid, false otherwise.
     */
    public static function check(string $recordId, string $token, ?string $customSalt = null): bool
    {
        return hash_equals(self::generate($recordId, $customSalt), $token);
    }
    
    /**
     * Generates a token for a given Airtable record ID.
     *
     * @param string $recordId The Airtable record ID to generate a token for.
     * @param string|null $customSalt Optional custom salt to use instead of the default.
     * @return string The generated token.
     */
    public static function generate(string $recordId, ?string $customSalt = null): string
    {
        $salt = $customSalt ?? env('TOKEN_SALT');
        return hash_hmac('sha256', $recordId, $salt);
    }
}
?>

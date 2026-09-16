<?php

namespace App\Services\Auth;

/**
 * A minimal, verification-only port of the "Portable PHP password hashing
 * framework" (phpass, by Solar Designer, public domain) — the exact
 * algorithm WordPress's own PasswordHash class (wp-includes/class-phpass.php)
 * has used unmodified for password hashes since 2008, and still verifies
 * on login even after WordPress 6.8 switched to bcrypt for new hashes.
 *
 * This only implements CheckPassword(), not HashPassword(): every new
 * password this app hashes goes through WordPressPasswordHasher::make(),
 * which always produces a bcrypt hash. This class exists purely so a
 * user whose account predates that switch — still holding a `$P$`/`$H$`
 * hash in wp_users.user_pass — can still log in through the new Laravel
 * path; WordPressPasswordHasher rehashes them to bcrypt on that first
 * successful login, same self-healing approach WordPress 6.8 itself uses.
 */
class PortablePasswordHash
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function checkPassword(string $password, string $storedHash): bool
    {
        $hash = $this->cryptPrivate($password, $storedHash);

        return $hash !== '*' && hash_equals($hash, $storedHash);
    }

    private function cryptPrivate(string $password, string $setting): string
    {
        $id = substr($setting, 0, 3);

        if ($id !== '$P$' && $id !== '$H$') {
            return '*';
        }

        $countLog2 = strpos(self::ITOA64, $setting[3]);

        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return '*';
        }

        $count = 1 << $countLog2;

        $salt = substr($setting, 4, 8);

        if (strlen($salt) !== 8) {
            return '*';
        }

        $hash = md5($salt.$password, true);

        do {
            $hash = md5($hash.$password, true);
        } while (--$count);

        return substr($setting, 0, 12).$this->encode64($hash, 16);
    }

    private function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;

        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3F];

            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }

            $output .= self::ITOA64[($value >> 6) & 0x3F];

            if ($i++ >= $count) {
                break;
            }

            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }

            $output .= self::ITOA64[($value >> 12) & 0x3F];

            if ($i++ >= $count) {
                break;
            }

            $output .= self::ITOA64[($value >> 18) & 0x3F];
        } while ($i < $count);

        return $output;
    }
}

<?php

namespace Tests\Unit;

use App\Services\Auth\WordPressPasswordHasher;
use PHPUnit\Framework\TestCase;

/**
 * Proves WordPressPasswordHasher actually implements the phpass
 * ($P$/$H$) verification algorithm WordPress itself uses — not just
 * "is internally consistent with itself". The phpass hash-building
 * helper below is written independently from the production class (the
 * same public "Portable PHP password hashing framework" algorithm,
 * re-derived here), the same methodology
 * tests/Unit/WordPressAuthCookieValidatorTest.php uses for the cookie
 * algorithm — so a bug in one is very unlikely to be mirrored by a
 * matching bug in the other.
 */
class WordPressPasswordHasherTest extends TestCase
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function test_a_password_it_hashes_verifies_against_its_own_hash(): void
    {
        $hasher = new WordPressPasswordHasher;
        $hash = $hasher->make('correct-horse-battery-staple');

        $this->assertTrue($hasher->check('correct-horse-battery-staple', $hash));
        $this->assertFalse($hasher->check('wrong-password', $hash));
    }

    public function test_new_hashes_are_bcrypt_and_never_need_a_rehash(): void
    {
        $hasher = new WordPressPasswordHasher;
        $hash = $hasher->make('a-password');

        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertFalse($hasher->needsRehash($hash));
    }

    public function test_a_phpass_hash_built_independently_verifies_correctly_and_is_flagged_for_rehash(): void
    {
        $hasher = new WordPressPasswordHasher;
        $phpassHash = $this->buildPhpassHash('password1', 'testsalt', countLog2: 8);

        $this->assertStringStartsWith('$P$', $phpassHash);
        $this->assertTrue($hasher->check('password1', $phpassHash));
        $this->assertFalse($hasher->check('wrong-password', $phpassHash));
        $this->assertTrue($hasher->needsRehash($phpassHash));
    }

    public function test_an_unrecognised_hash_format_never_verifies(): void
    {
        $hasher = new WordPressPasswordHasher;

        $this->assertFalse($hasher->check('anything', 'not-a-real-hash'));
    }

    /**
     * Independently re-implements phpass's HashPassword()/crypt_private().
     * Note phpass's own gensalt_private() encodes (iteration_count_log2 + 5)
     * into the setting character, and crypt_private() decodes that same
     * character straight back into the actual iteration exponent (no "-5"
     * on the way back out) — so passing countLog2=8 here means 8+5=13,
     * i.e. 8192 actual iterations, matching WordPress's own default.
     */
    private function buildPhpassHash(string $password, string $salt, int $countLog2): string
    {
        $encodedLog2 = min($countLog2 + 5, 30);
        $setting = '$P$'.self::ITOA64[$encodedLog2].$salt;

        $count = 1 << $encodedLog2;
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

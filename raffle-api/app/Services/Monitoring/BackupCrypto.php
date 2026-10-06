<?php

namespace App\Services\Monitoring;

use RuntimeException;

/**
 * Locks and unlocks a backup file (money-safety audit: backups held every
 * customer's data in the clear, and one copy is sent to Telegram).
 *
 * The file is read and written in 1 MB pieces, so a big backup never has to
 * fit in memory. Each piece is sealed with a modern authenticated cipher, so
 * a changed or cut-short file is refused rather than silently half-read.
 *
 * The key comes from BACKUP_ENCRYPTION_KEY (keep a copy somewhere that is
 * NOT the server). When it isn't set, a key made from the app's own secret
 * is used, which still keeps the Telegram copy private.
 */
class BackupCrypto
{
    private const MAGIC = 'RKBK1';

    private const PIECE = 1048576;

    public static function key(): string
    {
        $configured = (string) config('backups.encryption_key');
        $secret = $configured !== '' ? $configured : 'app|'.config('app.key');

        return hash('sha256', 'raffle-backup-key|'.$secret, true);
    }

    public static function encrypt(string $plainPath, string $encryptedPath): void
    {
        $in = fopen($plainPath, 'rb') ?: throw new RuntimeException('Could not read the backup to lock it.');
        $out = fopen($encryptedPath, 'wb') ?: throw new RuntimeException('Could not create the locked backup file.');

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::key());
            fwrite($out, self::MAGIC.$header);

            while (! feof($in)) {
                $piece = fread($in, self::PIECE);
                $last = feof($in);
                $sealed = sodium_crypto_secretstream_xchacha20poly1305_push($state, (string) $piece, '', $last ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);

                if (fwrite($out, pack('N', strlen($sealed)).$sealed) === false) {
                    throw new RuntimeException('Writing the locked backup failed. Is the disk full?');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function decrypt(string $encryptedPath, string $plainPath): void
    {
        $in = fopen($encryptedPath, 'rb') ?: throw new RuntimeException('Could not open the locked backup.');
        $out = fopen($plainPath, 'wb') ?: throw new RuntimeException('Could not create the unlocked backup file.');

        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('This is not a locked backup file.');
            }

            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, self::key());
            $final = false;

            while (($length = fread($in, 4)) !== false && strlen($length) === 4) {
                $sealed = fread($in, unpack('N', $length)[1]);
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $sealed);

                if ($opened === false) {
                    throw new RuntimeException('The backup could not be unlocked: wrong key, or the file was changed.');
                }

                fwrite($out, $opened[0]);
                $final = $opened[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }

            if (! $final) {
                throw new RuntimeException('The locked backup is cut short (it has no ending).');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}

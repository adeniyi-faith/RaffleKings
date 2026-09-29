<?php

namespace App\Services;

use App\Events\LiveDrawCommentHidden;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\LiveDrawComment;
use App\Support\Live;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * OVERHAUL_CHECKLIST.md item 45 — keeps the public live-draw chat safe.
 * Before this there was no way at all to remove a message from it.
 *
 *  - clean(): every message is filtered before it's saved — links and
 *    phone numbers removed (the classic "WhatsApp me to claim your prize"
 *    scam), blocked words starred out (config/moderation.php).
 *  - hide()/unhide(): an admin removes a message; it disappears from
 *    every viewer's screen at once (LiveDrawCommentHidden broadcast) and
 *    from every later page load.
 *  - mute()/unmute(): stops one customer posting, for a set time or until
 *    unmuted. Stored as `rk_chat_muted_until` usermeta, like other bans.
 *
 * Every admin action is audit-logged.
 */
class ChatModerationService
{
    public const MUTE_META_KEY = 'rk_chat_muted_until';

    public function __construct(private readonly AdminAuditLogService $auditLog) {}

    public function clean(string $body): string
    {
        if (config('moderation.remove_links')) {
            $body = preg_replace('~\b(?:https?://|www\.)\S+|\b[a-z0-9-]+\.(?:com|ng|net|org|io|co|me|xyz|link|ly)(?:/\S*)?\b~i', '[link removed]', $body);
        }

        if (config('moderation.remove_phone_numbers')) {
            // 10+ digits, allowing spaces/dashes/dots between them and a leading +.
            $body = preg_replace('~\+?\d(?:[\s.-]?\d){9,}~', '[number removed]', $body);
        }

        foreach (config('moderation.blocked_words', []) as $word) {
            $word = trim($word);

            if ($word !== '') {
                $body = preg_replace('~\b'.preg_quote($word, '~').'\b~iu', str_repeat('*', mb_strlen($word)), $body);
            }
        }

        return trim($body);
    }

    public function mutedUntil(int $userId): ?string
    {
        $value = WpUserMeta::query()->where('user_id', $userId)->where('meta_key', self::MUTE_META_KEY)->value('meta_value');

        if (! $value) {
            return null;
        }

        if ($value === 'forever') {
            return 'forever';
        }

        return Carbon::parse($value)->isFuture() ? $value : null;
    }

    public function isMuted(int $userId): bool
    {
        return $this->mutedUntil($userId) !== null;
    }

    public function hide(WpUser $admin, LiveDrawComment $comment): void
    {
        if ($comment->hidden_at !== null) {
            return;
        }

        $comment->update(['hidden_at' => now(), 'hidden_by' => $admin->ID]);

        Live::send(new LiveDrawCommentHidden($comment));

        $this->auditLog->record($admin, 'chat.message_hidden', LiveDrawComment::class, $comment->id, [
            'author_user_id' => $comment->user_id,
            'body' => $comment->body,
        ]);
    }

    public function unhide(WpUser $admin, LiveDrawComment $comment): void
    {
        $comment->update(['hidden_at' => null, 'hidden_by' => null]);

        $this->auditLog->record($admin, 'chat.message_restored', LiveDrawComment::class, $comment->id, [
            'author_user_id' => $comment->user_id,
        ]);
    }

    /** @param  int|null  $hours  null = until unmuted */
    public function mute(WpUser $admin, int $userId, ?int $hours): void
    {
        if ($hours !== null && $hours <= 0) {
            throw new RuntimeException('Choose how long to mute for.');
        }

        $until = $hours === null ? 'forever' : now()->addHours($hours)->toIso8601String();

        WpUserMeta::query()->updateOrCreate(
            ['user_id' => $userId, 'meta_key' => self::MUTE_META_KEY],
            ['meta_value' => $until],
        );

        $this->auditLog->record($admin, 'chat.user_muted', WpUser::class, $userId, ['until' => $until]);
    }

    public function unmute(WpUser $admin, int $userId): void
    {
        WpUserMeta::query()->where('user_id', $userId)->where('meta_key', self::MUTE_META_KEY)->delete();

        $this->auditLog->record($admin, 'chat.user_unmuted', WpUser::class, $userId);
    }
}

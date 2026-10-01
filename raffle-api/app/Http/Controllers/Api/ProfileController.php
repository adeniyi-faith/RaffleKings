<?php

namespace App\Http\Controllers\Api;

use App\Auth\WordPressAuthCookieIssuer;
use App\Http\Controllers\Controller;
use App\Models\Legacy\WpUser;
use App\Models\Legacy\WpUserMeta;
use App\Models\UserEngagement;
use App\Services\Auth\WordPressCookieFactory;
use App\Services\Auth\WordPressPasswordHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The "Edit Personal Details" page (rebuild of the legacy edit-profile.php,
 * item 26 follow-up). Same fields, same target columns: display_name/
 * user_email are real wp_users columns, first_name/last_name/phone/state
 * are usermeta, same as WordPress's own wp_update_user()/update_user_meta()
 * calls did. Password changes go through the same WordPressPasswordHasher
 * every login already verifies against, so a changed password still works
 * with the legacy WordPress login too.
 *
 * Avatar upload (uploadAvatar()) writes to the SAME profile_pic_url
 * usermeta key the legacy WordPress media-library upload always set —
 * every page that reads it (HandleInertiaRequests' shared auth.user.avatar,
 * this controller's own show()) picks up either site's upload the same
 * way, with no migration needed for accounts that already had a legacy
 * photo. Storage is local (the `public` disk, storage/app/public/avatars),
 * not WordPress's media library — see raffle-api-deploy.yml for the
 * `storage:link` step that makes it web-reachable.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly WordPressPasswordHasher $hasher) {}

    public function show(): JsonResponse
    {
        $user = $this->user();

        return response()->json([
            'first_name' => $user->metaValue('first_name') ?? '',
            'last_name' => $user->metaValue('last_name') ?? '',
            'display_name' => $user->display_name,
            'email' => $user->user_email,
            'phone' => $user->metaValue('phone') ?? '',
            'state' => $user->metaValue('state') ?? '',
            'avatar' => $user->metaValue('profile_pic_url') ?: null,
            // Phase 11: day and month only, for the free birthday spin. Set once.
            'birthday' => UserEngagement::query()->where('user_id', $user->ID)->value('birthday'),
        ]);
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $user = $this->user();

        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:4096'],
        ]);

        // Clear out any previous upload for this user first, whatever
        // extension it had, so re-uploading a different file type doesn't
        // leave the old one behind under a name nothing points to anymore.
        foreach (Storage::disk('public')->files('avatars') as $existing) {
            if (str_starts_with(basename($existing), $user->ID.'.')) {
                Storage::disk('public')->delete($existing);
            }
        }

        $path = $request->file('avatar')->storeAs('avatars', $user->ID.'.'.$request->file('avatar')->extension(), 'public');
        // "?v=<time>" makes every upload a brand-new address, so browsers can keep
        // a picture for a year (see public/.htaccess and public/sw.js) and still
        // show the new one the moment it changes.
        $url = Storage::disk('public')->url($path).'?v='.time();

        $this->setMeta($user->ID, 'profile_pic_url', $url);

        return response()->json(['avatar' => $url]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->user();

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'display_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:100', Rule::unique($user->getTable(), 'user_email')->ignore($user->getKey(), $user->getKeyName())],
            'phone' => ['nullable', 'string', 'max:30'],
            'state' => ['nullable', 'string', 'max:50'],
            // Same rules as signing up, so a new password is never weaker than a first one.
            'password' => ['nullable', 'string', 'min:8', 'max:128', 'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/'],
            'current_password' => ['nullable', 'string'],
            'birthday' => ['nullable', 'string', 'regex:/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/'],
        ], [
            'password.regex' => 'Password must contain at least one letter and one number.',
        ]);

        $changingPassword = ! empty($validated['password']);
        $changingEmail = mb_strtolower(trim($validated['email'])) !== mb_strtolower(trim((string) $user->user_email));

        // The email is where password-reset codes go, so changing either one
        // takes the current password: someone who only got hold of a signed-in
        // phone (or a stolen cookie) can't lock the real owner out.
        if (($changingPassword || $changingEmail)
            && ! $this->hasher->check((string) ($validated['current_password'] ?? ''), $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'current_password' => empty($validated['current_password'])
                    ? 'Enter your current password to change your email or password.'
                    : 'Your current password is not right.',
            ]);
        }

        // The birthday (MM-DD) can be set once; after that only support can change it,
        // so nobody moves it around to collect extra birthday spins.
        if (! empty($validated['birthday'])) {
            [$month, $day] = array_map('intval', explode('-', $validated['birthday']));

            if (! checkdate($month, $day, 2024)) {
                throw ValidationException::withMessages(['birthday' => 'That date does not exist.']);
            }

            $row = UserEngagement::for($user->ID);

            if (! $row->birthday) {
                $row->update(['birthday' => $validated['birthday']]);
            }
        }

        $user->forceFill([
            'display_name' => $validated['display_name'],
            'user_email' => $validated['email'],
        ])->save();

        $this->setMeta($user->ID, 'first_name', $validated['first_name'] ?? '');
        $this->setMeta($user->ID, 'last_name', $validated['last_name'] ?? '');
        $this->setMeta($user->ID, 'phone', $validated['phone'] ?? '');
        $this->setMeta($user->ID, 'state', $validated['state'] ?? '');

        $response = response()->json(['message' => 'Profile updated successfully!']);

        if ($changingPassword) {
            $user->forceFill(['user_pass' => $this->hasher->make($validated['password'])])->save();

            // The sign-in cookie is tied to the password, so the old one
            // stops working the moment it changes. Every other device is
            // signed out (that's the point of a new password); this one
            // gets a fresh cookie so the customer stays signed in here.
            WpUserMeta::query()->where('user_id', $user->ID)->where('meta_key', 'session_tokens')->delete();
            $current = $user->currentAccessToken();
            $user->tokens()->when($current?->getKey(), fn ($q, $id) => $q->whereKeyNot($id))->delete();

            $cookie = app(WordPressAuthCookieIssuer::class)->issue($user, ttlSeconds: 14 * 24 * 60 * 60, ip: $request->ip(), userAgent: $request->userAgent());
            $response->withCookie(app(WordPressCookieFactory::class)->make(app('wordpress.auth_cookie_name'), $cookie['value'], $cookie['expiration']));
        }

        return $response;
    }

    private function user(): WpUser
    {
        /** @var WpUser $user */
        $user = Auth::guard('wordpress')->user();

        return $user;
    }

    // Same delete-then-insert pattern UserManagementService's setBanned()
    // already uses for usermeta — simplest way to guarantee exactly one
    // row per key, matching WordPress's own update_user_meta() semantics.
    private function setMeta(int $userId, string $key, string $value): void
    {
        WpUserMeta::query()->where('user_id', $userId)->where('meta_key', $key)->delete();

        WpUserMeta::create(['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]);
    }
}

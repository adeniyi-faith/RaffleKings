// Shared by every page that shows a profile picture (Header, Profile,
// EditProfile): a real upload (auth.user.avatar, from either site's
// upload -- see ProfileController::uploadAvatar) if this user has one,
// same Dicebear-generated fallback everyone always saw otherwise.
export function resolveAvatar(user) {
    if (user?.avatar) {
        return user.avatar;
    }

    const seed = user ? user.name.replace(/\s+/g, '') : 'Guest';

    return `https://api.dicebear.com/9.x/adventurer/svg?seed=${encodeURIComponent(seed)}&backgroundColor=e5e7eb`;
}

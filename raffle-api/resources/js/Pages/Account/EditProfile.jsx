import { useEffect, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Camera, CheckCircle2, ChevronDown, Loader2 } from 'lucide-react';
import Header from '../../Components/layout/Header';
import { apiPost } from '../../lib/api';
import { resolveAvatar } from '../../lib/avatar';

// Nigeria's 36 states + FCT, same list and order as the legacy
// edit-profile-form.php's <select>.
const STATES = [
    'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno',
    'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT', 'Gombe', 'Imo',
    'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa',
    'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba',
    'Yobe', 'Zamfara',
];

const EMPTY_FORM = { first_name: '', last_name: '', display_name: '', email: '', phone: '', state: '', password: '' };

// Faithful rebuild of the legacy edit-profile.php: same top sticky
// "Edit Profile" bar with a back arrow, same two grouped cards
// (Personal Details, Security), same fields. Backed by the new
// GET/POST /api/profile endpoints (App\Http\Controllers\Api\
// ProfileController) instead of the legacy page's own PHP mini-API.
// Photo upload posts immediately on picking a file (POST
// /api/profile/avatar), rather than bundling into the form's own JSON
// save the way the legacy page's single multipart submit did -- a
// deliberate small deviation, not an oversight: it means the new photo
// is visible right away instead of only after "Save Changes".
export default function EditProfile() {
    const { auth } = usePage().props;
    const [form, setForm] = useState(EMPTY_FORM);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState(null);
    const [isError, setIsError] = useState(false);
    const [avatarPreview, setAvatarPreview] = useState(null);
    const [uploadingAvatar, setUploadingAvatar] = useState(false);
    const fileInputRef = useRef(null);

    useEffect(() => {
        fetch('/api/profile', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => response.json())
            .then((data) => setForm({ ...EMPTY_FORM, ...data, password: '' }))
            .finally(() => setLoading(false));
    }, []);

    function update(field) {
        return (e) => setForm((f) => ({ ...f, [field]: e.target.value }));
    }

    async function handleAvatarChange(e) {
        const file = e.target.files?.[0];
        if (! file) {
            return;
        }

        setAvatarPreview(URL.createObjectURL(file));
        setUploadingAvatar(true);
        setMessage(null);

        const body = new FormData();
        body.append('avatar', file);

        try {
            const response = await fetch('/api/profile/avatar', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body,
            });
            const data = await response.json().catch(() => ({}));

            if (! response.ok) {
                throw new Error(data.message || 'Could not upload that photo. Please try again.');
            }

            // Refreshes the shared auth.user.avatar prop every page reads
            // from (Header, Profile, this page's own fallback) -- so the
            // new photo shows up everywhere, not just here.
            router.reload({ only: ['auth'] });
        } catch (err) {
            setAvatarPreview(null);
            setIsError(true);
            setMessage(err.message);
        } finally {
            setUploadingAvatar(false);
            e.target.value = '';
        }
    }

    async function save(e) {
        e.preventDefault();
        setSaving(true);
        setMessage(null);

        try {
            const { password, ...rest } = form;
            await apiPost('/api/profile', password ? form : rest);
            setIsError(false);
            setMessage('Profile updated successfully!');
            setForm((f) => ({ ...f, password: '' }));
            router.reload({ only: ['auth'] });
        } catch (err) {
            setIsError(true);
            setMessage(err.message);
        } finally {
            setSaving(false);
        }
    }

    const avatar = avatarPreview || resolveAvatar(auth?.user);

    return (
        <>
            <Head title="Edit Profile" />
            <div className="flex min-h-screen w-full flex-col bg-gray-50 text-gray-900 transition-colors duration-200 dark:bg-dark-bg dark:text-white">
                <Header />

                <div className="sticky top-0 z-30 flex items-center border-b border-gray-100 bg-white px-5 pb-4 pt-4 shadow-sm backdrop-blur-md transition-colors duration-200 dark:border-dark-border dark:bg-dark-bg/95">
                    <Link
                        href="/profile"
                        className="-ml-2 rounded-full p-2 text-gray-600 transition-colors hover:bg-gray-50 active:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800 dark:active:bg-gray-700"
                    >
                        <ArrowLeft className="h-6 w-6" />
                    </Link>
                    <h1 className="ml-2 text-lg font-bold text-gray-900 dark:text-white">Edit Profile</h1>
                </div>

                {loading ? (
                    <p className="px-5 pt-6 text-sm text-gray-500 dark:text-gray-400">Loading…</p>
                ) : (
                    <form onSubmit={save} className="space-y-6 px-5 pb-10 pt-6">
                        {message && (
                            <div
                                className={`rounded-xl p-3 text-center text-sm font-medium ${
                                    isError
                                        ? 'border border-red-100 bg-red-50 text-red-600 dark:border-red-900 dark:bg-red-900/20 dark:text-red-400'
                                        : 'border border-green-100 bg-green-50 text-green-600 dark:border-green-900 dark:bg-green-900/20 dark:text-green-400'
                                }`}
                            >
                                {message}
                            </div>
                        )}

                        <div className="mb-6 flex flex-col items-center justify-center">
                            <button
                                type="button"
                                onClick={() => fileInputRef.current?.click()}
                                disabled={uploadingAvatar}
                                className="group relative cursor-pointer transition-transform active:scale-95 disabled:cursor-wait"
                            >
                                <div className="h-24 w-24 rounded-full border-2 border-dashed border-app-primary bg-blue-50 p-1 dark:bg-blue-900/20">
                                    <img src={avatar} className="h-full w-full rounded-full object-cover shadow-sm" alt="" />
                                </div>
                                <div className="absolute bottom-0 right-0 rounded-full border-2 border-white bg-app-primary p-2 text-white shadow-md dark:border-dark-bg">
                                    {uploadingAvatar ? <Loader2 className="h-4 w-4 animate-spin" /> : <Camera className="h-4 w-4" />}
                                </div>
                            </button>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={handleAvatarChange}
                            />
                            <p className="mt-2 text-[10px] text-gray-400 dark:text-gray-500">
                                {uploadingAvatar ? 'Uploading…' : 'Tap to change photo'}
                            </p>
                        </div>

                        <div className="space-y-5 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                            <h2 className="mb-2 border-b border-gray-50 pb-2 text-xs font-bold uppercase tracking-wider text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                Personal Details
                            </h2>

                            <div className="grid grid-cols-2 gap-4">
                                <Field label="First Name" value={form.first_name} onChange={update('first_name')} />
                                <Field label="Last Name" value={form.last_name} onChange={update('last_name')} />
                            </div>

                            <Field label="Display Name" value={form.display_name} onChange={update('display_name')} required />
                            <Field label="Email Address" type="email" value={form.email} onChange={update('email')} required />
                            <Field label="Phone Number" type="tel" value={form.phone} onChange={update('phone')} placeholder="08012345678" />

                            <div>
                                <label className="mb-1.5 block text-xs font-bold uppercase text-gray-500 dark:text-gray-400">
                                    State of Residence
                                </label>
                                <div className="relative">
                                    <select
                                        value={form.state}
                                        onChange={update('state')}
                                        className="w-full appearance-none rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-base font-medium text-gray-900 outline-none transition-colors focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-dark-bg dark:text-white"
                                    >
                                        <option value="">Select State</option>
                                        {STATES.map((s) => (
                                            <option key={s} value={s}>
                                                {s === 'FCT' ? 'FCT - Abuja' : s}
                                            </option>
                                        ))}
                                    </select>
                                    <div className="pointer-events-none absolute inset-y-0 right-0 flex items-center px-4 text-gray-500 dark:text-gray-400">
                                        <ChevronDown className="h-4 w-4" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-dark-card">
                            <h2 className="mb-2 border-b border-gray-50 pb-2 text-xs font-bold uppercase tracking-wider text-gray-400 dark:border-gray-700 dark:text-gray-500">
                                Security
                            </h2>
                            <Field
                                label="New Password"
                                type="password"
                                value={form.password}
                                onChange={update('password')}
                                placeholder="Leave empty to keep current"
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={saving}
                            className="flex w-full items-center justify-center gap-2 rounded-xl bg-gray-900 py-4 font-bold text-white shadow-lg shadow-gray-200 transition-all hover:bg-gray-800 active:scale-[0.98] disabled:opacity-60 dark:bg-white dark:text-gray-900 dark:shadow-none dark:hover:bg-gray-100"
                        >
                            {saving && <Loader2 className="h-5 w-5 animate-spin" />}
                            {saving ? 'Saving…' : 'Save Changes'}
                            {!saving && <CheckCircle2 className="h-5 w-5" />}
                        </button>
                    </form>
                )}
            </div>
        </>
    );
}

function Field({ label, required, ...props }) {
    return (
        <div>
            <label className="mb-1.5 block text-xs font-bold uppercase text-gray-500 dark:text-gray-400">{label}</label>
            <input
                {...props}
                required={required}
                className="w-full appearance-none rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-base font-medium text-gray-900 placeholder-gray-400 outline-none transition-colors focus:ring-2 focus:ring-app-primary/20 dark:border-gray-700 dark:bg-dark-bg dark:text-white dark:placeholder-gray-600"
            />
        </div>
    );
}

import { Head } from '@inertiajs/react';

export default function Home({ message }) {
    return (
        <>
            <Head title="Home" />
            <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-neutral-950 px-6 text-center text-neutral-100">
                <h1 className="text-3xl font-semibold">RaffleKings</h1>
                <p className="max-w-md text-neutral-400">{message}</p>
            </div>
        </>
    );
}

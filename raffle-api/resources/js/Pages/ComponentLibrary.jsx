import { useState } from 'react';
import { Head } from '@inertiajs/react';
import Button from '../Components/ui/Button';
import Modal from '../Components/ui/Modal';
import { Card, SelectableCard } from '../Components/ui/Card';
import { TextInput, PasswordInput } from '../Components/ui/TextInput';
import NumberStepper from '../Components/ui/NumberStepper';
import { PriceTag, DiscountBadge, PriceSummaryFooter } from '../Components/ui/Price';
import { useTicketPriceQuote } from '../hooks/useTicketPriceQuote';

// A living demo of the Phase 2 item 22 shared component library — not a
// page real users see, just a way to prove every component renders
// correctly against real data before the pages that use them (items 23-31)
// get built. Only routed in non-production environments; see routes/web.php.
export default function ComponentLibrary({ demoRaffleId }) {
    const [selectedTier, setSelectedTier] = useState(3);
    const [bulkQty, setBulkQty] = useState(15);
    const [modalOpen, setModalOpen] = useState(false);
    const { quote, loading } = useTicketPriceQuote(demoRaffleId, selectedTier);

    const tiers = [1, 2, 3, 5, 10];

    return (
        <>
            <Head title="Component Library" />
            <div className="min-h-screen space-y-10 bg-app-bg p-8 dark:bg-dark-bg">
                <section className="space-y-3">
                    <h2 className="text-lg font-bold text-gray-900 dark:text-white">Buttons</h2>
                    <div className="flex max-w-sm flex-col gap-3">
                        <Button variant="primary">Continue to payment</Button>
                        <Button variant="inverted">Create account</Button>
                        <Button disabled>Select a payment method</Button>
                        <Button variant="ghost">Skip for now</Button>
                    </div>
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-bold text-gray-900 dark:text-white">Cards</h2>
                    <Card className="max-w-sm">Order Summary</Card>
                    <div className="flex max-w-sm flex-col gap-2">
                        {tiers.map((qty) => (
                            <SelectableCard
                                key={qty}
                                selected={selectedTier === qty}
                                highlighted={qty === 3}
                                onClick={() => setSelectedTier(qty)}
                            >
                                {qty} ticket{qty > 1 ? 's' : ''}
                            </SelectableCard>
                        ))}
                    </div>
                </section>

                <section className="max-w-sm space-y-3">
                    <h2 className="text-lg font-bold text-gray-900 dark:text-white">Form inputs</h2>
                    <TextInput label="Email address" placeholder="you@example.com" />
                    <PasswordInput label="Password" placeholder="********" />
                    <div className="rounded-xl bg-indigo-950 p-4">
                        <NumberStepper value={bulkQty} min={11} max={50} onChange={setBulkQty} />
                    </div>
                </section>

                <section className="max-w-sm space-y-3">
                    <h2 className="text-lg font-bold text-gray-900 dark:text-white">Price displays</h2>
                    {demoRaffleId ? (
                        <>
                            <PriceTag
                                original={quote?.original ?? 0}
                                discounted={quote?.discounted ?? 0}
                            />
                            {quote && <DiscountBadge original={quote.original} discounted={quote.discounted} />}
                            <PriceSummaryFooter
                                label={`Total (${selectedTier} tickets)`}
                                original={quote?.original ?? 0}
                                discounted={quote?.discounted ?? 0}
                                loading={loading}
                            />
                        </>
                    ) : (
                        <p className="text-sm text-gray-500">
                            No demo raffle available to quote — seed one to see live price data here.
                        </p>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-bold text-gray-900 dark:text-white">Modal</h2>
                    <div className="max-w-sm">
                        <Button onClick={() => setModalOpen(true)}>Open modal</Button>
                    </div>
                    <Modal open={modalOpen} onClose={() => setModalOpen(false)} title="Convert funds">
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            This is the shared modal shell every legacy popup copy-pasted.
                        </p>
                    </Modal>
                </section>
            </div>
        </>
    );
}

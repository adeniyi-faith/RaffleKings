<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The printable gaming tax return for one month (a PDF). It shows the same
 * figures as the Gaming tax page: the calculation line by line, filing and
 * payment references, the per-raffle table, and how the numbers were worked
 * out. A month that is not locked yet is marked DRAFT. A locked month carries
 * a short reference code made from its locked figures, so a printed return
 * can be matched to the record it came from.
 */
class GamingTaxReturnPdf
{
    public function __construct(private readonly GamingTaxService $tax) {}

    public function filename(string $period): string
    {
        return "gaming-tax-return-{$period}.pdf";
    }

    /** The page as HTML (also what the PDF is made from). */
    public function html(string $period): string
    {
        $s = $this->tax->statement($period);
        $record = $s['record'];

        return view('pdf.gaming-tax-return', [
            's' => $s,
            'businessName' => trim((string) config('gaming_tax.business_name')) ?: (string) config('app.name'),
            'taxId' => trim((string) config('gaming_tax.tax_id')),
            'fingerprint' => $record ? $this->fingerprint($record) : null,
        ])->render();
    }

    /** The first folder the site can write to, for the PDF maker's font files. Shared hosts differ. */
    private function workDir(): string
    {
        foreach ([storage_path('app/dompdf'), storage_path('framework/cache/dompdf'), sys_get_temp_dir().'/rk-dompdf'] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
        }

        throw new \RuntimeException('No writable folder for the PDF maker (tried storage/app/dompdf, storage/framework/cache/dompdf and the system temp folder).');
    }

    public function render(string $period): string
    {
        // The PDF maker reads its fonts through PHP's "mbstring" extension. Without it the PDF cannot be made
        // (the built-in stand-in cannot read font files), so say exactly what to switch on.
        if (! extension_loaded('mbstring')) {
            throw new \RuntimeException("PHP's mbstring extension is off on this server. In cPanel open Select PHP Version, tick mbstring, and save.");
        }

        $dir = $this->workDir();
        $html = $this->html($period);

        // A small server may allow little memory or time; the PDF is one page, so give it what it needs.
        @ini_set('memory_limit', '256M');
        @set_time_limit(60);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');   // has the ₦ sign
        $options->set('isRemoteEnabled', false);        // nothing is fetched from the internet
        $options->set('isPhpEnabled', false);
        $options->set('chroot', [resource_path('views'), $dir]);
        $options->set('tempDir', $dir);
        $options->set('fontCache', $dir);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** A short code that changes if any locked figure changes. */
    public function fingerprint(\App\Models\GamingTaxPeriod $record): string
    {
        $material = implode('|', [
            $record->period, number_format($record->sales, 2, '.', ''), number_format($record->refunds, 2, '.', ''), number_format($record->prizes, 2, '.', ''),
            number_format($record->carried_in, 2, '.', ''), number_format($record->taxable, 2, '.', ''), number_format($record->rate, 3, '.', ''),
            number_format($record->tax_due, 2, '.', ''), $record->shortfall_rule,
        ]);

        return strtoupper(substr(hash_hmac('sha256', $material, (string) config('app.key')), 0, 12));
    }
}

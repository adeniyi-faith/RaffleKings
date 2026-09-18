<?php

namespace App\Services;

use App\Exceptions\StatementExtractionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * OVERHAUL_CHECKLIST.md Phase 3 item 36 — the "upload a statement, get a
 * list of credits" half AuditReconciliationService's own docblock
 * deliberately left out of that earlier pass. Uses the same provider
 * (Gemini vision) and the same fail-safe shape as legacy's deposit
 * screenshot check (wp-core/api-financials.php's rk_handle_deposit()) —
 * a real HTTP call to the model, a JSON block pulled out of whatever
 * text comes back, never trusted blindly.
 *
 * What's different from the legacy screenshot check: that one asks a
 * yes/no question about ONE target amount on ONE receipt image. This
 * asks for every credit line on a bank statement (which may span
 * several images/pages), returned as a list — the input
 * AuditReconciliationService::reconcile() already expects. The two
 * are deliberately not merged into one call: different prompt, and a
 * statement upload has nothing to do with a single deposit's
 * pass/fail check.
 */
class StatementExtractionService
{
    private const MAX_FILE_BYTES = 8 * 1024 * 1024;

    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    /**
     * @param  array<int, UploadedFile>  $files  One or more statement pages/screenshots.
     * @return array<int, array{amount: float, date: ?string, desc: ?string}>
     *
     * @throws StatementExtractionException
     */
    public function extractCredits(array $files): array
    {
        $encoded = [];

        foreach ($files as $file) {
            $this->guardFile($file->getMimeType(), $file->getSize());
            $encoded[] = ['mime_type' => $file->getMimeType(), 'data' => base64_encode($file->get())];
        }

        return $this->extractFromEncodedFiles($encoded);
    }

    /**
     * Same extraction, for files already sitting on a storage disk (the
     * Filament Daily Audit page's own file-upload field stores to disk
     * immediately on selection, so by the time its "Extract" action
     * runs it only has stored paths, not live UploadedFile instances).
     *
     * @param  array<int, string>  $paths
     * @return array<int, array{amount: float, date: ?string, desc: ?string}>
     *
     * @throws StatementExtractionException
     */
    public function extractCreditsFromStoredFiles(array $paths, string $disk = 'local'): array
    {
        $storage = Storage::disk($disk);
        $encoded = [];

        foreach ($paths as $path) {
            if (! $storage->exists($path)) {
                throw new StatementExtractionException("Uploaded file not found: {$path}");
            }

            $mime = (string) $storage->mimeType($path);
            $this->guardFile($mime, (int) $storage->size($path));
            $encoded[] = ['mime_type' => $mime, 'data' => base64_encode($storage->get($path))];
        }

        return $this->extractFromEncodedFiles($encoded);
    }

    /**
     * @param  array<int, array{mime_type: string, data: string}>  $encoded
     * @return array<int, array{amount: float, date: ?string, desc: ?string}>
     *
     * @throws StatementExtractionException
     */
    private function extractFromEncodedFiles(array $encoded): array
    {
        if (empty($encoded)) {
            throw new StatementExtractionException('No statement files provided.');
        }

        $apiKey = config('services.gemini.api_key');

        if (empty($apiKey)) {
            throw new StatementExtractionException('No Gemini API key configured — set GEMINI_API_KEY to enable statement extraction.');
        }

        $parts = [['text' => $this->prompt()]];

        foreach ($encoded as $file) {
            $parts[] = ['inline_data' => $file];
        }

        $model = config('services.gemini.model');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";

        try {
            $response = Http::timeout(30)
                ->withHeader('Content-Type', 'application/json')
                ->post("{$url}?key={$apiKey}", [
                    'contents' => [['parts' => $parts]],
                ]);
        } catch (\Throwable $e) {
            throw new StatementExtractionException('Could not reach the AI extraction service: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new StatementExtractionException("AI extraction service returned HTTP {$response->status()}.");
        }

        $text = $response->json('candidates.0.content.parts.0.text', '');
        $clean = preg_replace('/```json\s*|\s*```/', '', (string) $text);
        preg_match('/\[.*\]/s', $clean, $matches);

        $json = isset($matches[0]) ? json_decode($matches[0], true) : null;

        if (! is_array($json)) {
            Log::warning('StatementExtractionService: could not parse a JSON array from the model response.', ['raw' => $text]);

            throw new StatementExtractionException('Could not read any transactions out of the uploaded statement — try a clearer image or enter credits manually.');
        }

        $credits = [];

        foreach ($json as $row) {
            if (! is_array($row) || ! isset($row['amount']) || ! is_numeric($row['amount'])) {
                continue;
            }

            $credits[] = [
                'amount' => round((float) $row['amount'], 2),
                'date' => isset($row['date']) ? (string) $row['date'] : null,
                'desc' => isset($row['desc']) ? (string) $row['desc'] : null,
            ];
        }

        return $credits;
    }

    private function guardFile(?string $mimeType, int $size): void
    {
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new StatementExtractionException('Only JPG, PNG, WEBP, or PDF statement files are allowed.');
        }

        if ($size > self::MAX_FILE_BYTES) {
            throw new StatementExtractionException('Each statement file must be under 8MB.');
        }
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
            You are reading a bank statement (one or more pages/screenshots).
            List every CREDIT (money IN) transaction visible across all pages.
            Ignore debits/withdrawals entirely.
            For each credit, extract: the amount (numeric, no currency symbol or
            commas), the transaction date if visible (any format, as text), and a
            short description/narration if visible.
            Return ONLY a JSON array, no other text, in this exact shape:
            [{"amount": 5000, "date": "2026-01-15", "desc": "Transfer from John Doe"}]
            If no credits are visible, return an empty array: []
            PROMPT;
    }
}

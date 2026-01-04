<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SplFileObject;

class SyncMerchantsFromCsv extends Command
{
    protected $signature = 'merchants:sync-csv
                            {--file= : CSV path (defaults to storage/app/import/KKuserLive.csv)}
                            {--delimiter= : CSV delimiter override}
                            {--password=123456 : Default password for new users}
                            {--delete-missing : Delete merchant users not in CSV (excluding allowlist)}
                            {--dry-run : Report without changes}';

    protected $description = 'Sync merchant users from CSV and optionally delete missing merchants';

    private const EXCLUDED_EMAILS = [
        'zion321654@gmail.com',
        'zioncrm@gmail.com',
        'admin@example.com',
        'zioncodeliba@gmail.com',
        'yaron@example.com',
        'info@kfitzkfotz.co.il',
        'avi@gabdor.co.il',
        'gaby@gabdor.co.il',
    ];

    public function handle(): int
    {
        $filePath = $this->resolveFilePath((string) $this->option('file'));
        if ($filePath === null) {
            $this->error('CSV file not found.');
            return self::FAILURE;
        }

        $preparedPath = $this->prepareFile($filePath);
        $delimiter = $this->resolveDelimiter($preparedPath, (string) $this->option('delimiter'));
        $dryRun = (bool) $this->option('dry-run');
        $deleteMissing = (bool) $this->option('delete-missing');
        $password = (string) $this->option('password');

        if ($password === '') {
            $this->error('Password option cannot be empty.');
            return self::FAILURE;
        }

        $hasStatus = Schema::hasColumn('users', 'status');

        $file = new SplFileObject($preparedPath);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter);

        $excluded = array_fill_keys(self::EXCLUDED_EMAILS, true);
        $stats = [
            'rows' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'invalid' => 0,
            'duplicates' => 0,
            'role_conflicts' => 0,
            'missing_for_delete' => 0,
            'deleted' => 0,
        ];
        $headers = null;
        $seen = [];
        $targetEmails = [];

        foreach ($file as $row) {
            if (!is_array($row) || $this->rowIsEmpty($row)) {
                continue;
            }

            if ($headers === null) {
                $headers = $this->normalizeHeaders($row);
                continue;
            }

            $stats['rows']++;

            $row = array_slice($row, 0, count($headers));
            $row = array_pad($row, count($headers), null);
            $data = array_combine($headers, $row);
            if ($data === false) {
                $stats['invalid']++;
                continue;
            }

            $data = array_change_key_case($data, CASE_LOWER);
            $email = $this->cleanScalar($data['email'] ?? null);
            if ($email === null) {
                $stats['invalid']++;
                continue;
            }

            $email = strtolower($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['invalid']++;
                continue;
            }

            if (isset($seen[$email])) {
                $stats['duplicates']++;
                continue;
            }
            $seen[$email] = true;

            $name = $this->cleanScalar($data['name'] ?? null);
            if ($name === null) {
                $name = $this->fallbackNameFromEmail($email);
            }

            $targetEmails[$email] = $name;
        }

        foreach ($targetEmails as $email => $name) {
            $user = User::where('email', $email)->first();
            if (!$user) {
                $stats['created']++;
                if (!$dryRun) {
                    $payload = [
                        'name' => $name,
                        'email' => $email,
                        'password' => Hash::make($password),
                        'email_verified_at' => now(),
                        'role' => 'merchant',
                    ];
                    if ($hasStatus) {
                        $payload['status'] = 'active';
                    }
                    User::create($payload);
                }
                continue;
            }

            if ($user->role !== 'merchant') {
                $stats['role_conflicts']++;
                continue;
            }

            $updates = [];
            if ($user->name !== $name) {
                $updates['name'] = $name;
            }
            if ($user->email_verified_at === null) {
                $updates['email_verified_at'] = now();
            }

            if (empty($updates)) {
                $stats['skipped']++;
                continue;
            }

            $stats['updated']++;
            if (!$dryRun) {
                $user->forceFill($updates)->save();
            }
        }

        $keepEmails = array_fill_keys(array_keys($targetEmails), true);
        foreach (array_keys($excluded) as $email) {
            $keepEmails[$email] = true;
        }

        $missingUsers = User::where('role', 'merchant')
            ->whereNotIn('email', array_keys($keepEmails))
            ->orderBy('id')
            ->get(['id', 'email', 'name']);

        $missingEmails = [];
        foreach ($missingUsers as $user) {
            if (!$user->email) {
                continue;
            }
            $missingEmails[] = $user->email;
        }

        $stats['missing_for_delete'] = count($missingEmails);

        if ($deleteMissing) {
            foreach ($missingUsers as $user) {
                $stats['deleted']++;
                if (!$dryRun) {
                    $user->delete();
                }
            }
        }

        $summary = sprintf(
            'Rows: %d, Created: %d, Updated: %d, Skipped: %d, Invalid: %d, Duplicates: %d, Role conflicts: %d, Missing for delete: %d, Deleted: %d',
            $stats['rows'],
            $stats['created'],
            $stats['updated'],
            $stats['skipped'],
            $stats['invalid'],
            $stats['duplicates'],
            $stats['role_conflicts'],
            $stats['missing_for_delete'],
            $stats['deleted']
        );

        $this->info($summary);

        if (!empty($missingEmails)) {
            $sorted = $missingEmails;
            sort($sorted, SORT_NATURAL | SORT_FLAG_CASE);
            $maxToPrint = 50;
            $sample = array_slice($sorted, 0, $maxToPrint);
            $suffix = count($sorted) > $maxToPrint
                ? sprintf(' ... (+%d more)', count($sorted) - $maxToPrint)
                : '';
            $this->warn('Missing merchant emails: ' . implode(', ', $sample) . $suffix);
        }

        if ($stats['role_conflicts'] > 0) {
            $this->warn('Some emails exist with non-merchant roles; they were skipped.');
        }

        Log::info('[Merchants] CSV sync finished', array_merge($stats, [
            'file' => $filePath,
            'delimiter' => $delimiter,
            'dry_run' => $dryRun,
            'delete_missing' => $deleteMissing,
            'excluded' => array_keys($excluded),
            'missing_emails' => $missingEmails,
        ]));

        if ($preparedPath !== $filePath && file_exists($preparedPath)) {
            @unlink($preparedPath);
        }

        return self::SUCCESS;
    }

    private function resolveFilePath(string $input): ?string
    {
        $defaultPath = storage_path('app/import/KKuserLive.csv');
        $candidate = $input !== '' ? $input : $defaultPath;

        if (file_exists($candidate)) {
            return $candidate;
        }

        $storageCandidate = storage_path('app/' . ltrim($candidate, '/'));
        if (file_exists($storageCandidate)) {
            return $storageCandidate;
        }

        $baseCandidate = base_path($candidate);
        if (file_exists($baseCandidate)) {
            return $baseCandidate;
        }

        return null;
    }

    private function resolveDelimiter(string $path, string $override): string
    {
        if ($override !== '') {
            return $override;
        }

        $sample = fopen($path, 'rb');
        if (!$sample) {
            return ',';
        }

        $lines = [];
        for ($i = 0; $i < 5 && !feof($sample); $i++) {
            $line = fgets($sample);
            if ($line !== false && trim($line) !== '') {
                $lines[] = $line;
            }
        }
        fclose($sample);

        if (empty($lines)) {
            return ',';
        }

        $delimiters = [',', ';', "\t", '|'];
        $scores = array_fill_keys($delimiters, 0);

        foreach ($lines as $line) {
            foreach ($delimiters as $delimiter) {
                $scores[$delimiter] += substr_count($line, $delimiter);
            }
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $best ?: ',';
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(static function ($header) {
            $value = (string) $header;
            $value = ltrim($value, "\xEF\xBB\xBF");
            return trim($value);
        }, $headers);
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function cleanScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function fallbackNameFromEmail(string $email): string
    {
        $pos = strpos($email, '@');
        if ($pos === false) {
            return $email;
        }

        $name = substr($email, 0, $pos);
        return $name !== '' ? $name : $email;
    }

    private function prepareFile(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            return $path;
        }

        $hasNulls = strpos($contents, "\x00") !== false;
        $bom = substr($contents, 0, 2);
        $isUtf16Le = $bom === "\xFF\xFE";
        $isUtf16Be = $bom === "\xFE\xFF";

        if ($hasNulls || $isUtf16Le || $isUtf16Be) {
            $sourceEncoding = $isUtf16Be ? 'UTF-16BE' : 'UTF-16LE';
            $converted = mb_convert_encoding($contents, 'UTF-8', $sourceEncoding);
            $tempPath = storage_path('app/import/_tmp_' . uniqid('', true) . '_' . basename($path));
            file_put_contents($tempPath, $converted);
            return $tempPath;
        }

        return $path;
    }
}

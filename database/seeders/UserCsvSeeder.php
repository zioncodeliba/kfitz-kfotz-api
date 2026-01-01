<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use SplFileObject;

class UserCsvSeeder extends Seeder
{
    private string $csvPath = 'userkfitzkfotz.csv';

    public function run(): void
    {
        $filePath = base_path($this->csvPath);
        if (!file_exists($filePath)) {
            $this->command?->error("Missing CSV file at {$filePath}");
            return;
        }

        $delimiter = $this->detectDelimiter($filePath);
        $file = new SplFileObject($filePath);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter);

        $headers = null;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = [];

        foreach ($file as $row) {
            if ($row === null || $row === false || $this->rowIsEmpty($row)) {
                continue;
            }

            if ($headers === null) {
                $headers = $this->normalizeHeaders($row);
                continue;
            }

            $row = array_slice($row, 0, count($headers));
            $row = array_pad($row, count($headers), null);
            $data = array_combine($headers, $row);

            if ($data === false) {
                $skipped++;
                continue;
            }

            $name = $this->cleanScalar($data['name'] ?? null);
            $email = $this->cleanScalar($data['email'] ?? null);
            $hasRoleColumn = array_key_exists('role', $data);
            $role = $hasRoleColumn ? $this->cleanScalar($data['role'] ?? null) : null;

            if ($email === null) {
                $skipped++;
                continue;
            }

            $email = strtolower($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }

            if (isset($seen[$email])) {
                $skipped++;
                continue;
            }
            $seen[$email] = true;

            $name = $name ?? $this->fallbackNameFromEmail($email);

            $user = User::where('email', $email)->first();
            if (!$user) {
                $user = new User();
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make('123456'),
                    'email_verified_at' => now(),
                    'role' => $role ?? 'merchant',
                ]);
                $user->save();
                $created++;
                continue;
            }

            $updates = [
                'name' => $name,
                'password' => Hash::make('123456'),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ];

            if ($role !== null && $role !== '' && $role !== $user->role) {
                $updates['role'] = $role;
            }

            $user->forceFill($updates)->save();
            $updated++;
        }

        $this->command?->info(sprintf(
            'User CSV import done. Created: %d, Updated: %d, Skipped: %d',
            $created,
            $updated,
            $skipped
        ));
    }

    private function detectDelimiter(string $filePath): string
    {
        $candidates = [',', ';', "\t"];
        $best = ',';
        $bestCount = 0;

        $handle = fopen($filePath, 'r');
        if ($handle) {
            $firstLine = fgets($handle) ?: '';
            fclose($handle);

            foreach ($candidates as $candidate) {
                $count = substr_count($firstLine, $candidate);
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(
            static function ($header) {
                $value = (string) $header;
                $value = ltrim($value, "\xEF\xBB\xBF");
                return strtolower(trim($value));
            },
            $headers
        );
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
}

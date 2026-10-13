<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DeveloperTotp
{
    public function secret(): string
    {
        $s = '';
        for ($i = 0; $i < 32; $i++) {
            $s .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[random_int(0, 31)];
        }

return $s;
    }

    public function code(string $secret, int $counter): string
    {
        $bits = '';
        foreach (str_split($secret) as $c) {
            $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $b) {
            if (strlen($b) === 8) {
                $key .= chr(bindec($b));
            }
        }
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($hash[19]) & 15;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function verify(int $userId, string $code, bool $pending = false): bool
    {
        return DB::transaction(function () use ($userId, $code, $pending) {
            $s = DB::table('developer_preferences')->where('user_id', $userId)->lockForUpdate()->first();
            if (! $s?->totp_secret || (! $pending && ! $s->totp_confirmed_at)) {
                return false;
            }
            if (! preg_match('/^\d{6}$/', $code)) {
                if ($pending) {
                    return false;
                } $codes = json_decode($s->recovery_codes ?? '[]', true);
                foreach ($codes as $i => $hash) {
                    if (Hash::check($code, $hash)) {
                        unset($codes[$i]);
                        DB::table('developer_preferences')->where('user_id', $userId)->update(['recovery_codes' => json_encode(array_values($codes)), 'updated_at' => now()]);

                        return true;
                    }
                }

return false;
            }
            $counter = intdiv(time(), 30);
            $secret = Crypt::decryptString($s->totp_secret);
            for ($n = $counter - 1; $n <= $counter + 1; $n++) {
                if ($s->totp_last_counter !== null && $n <= $s->totp_last_counter) {
                    continue;
                }
                if (hash_equals($this->code($secret, $n), $code)) {
                    DB::table('developer_preferences')->where('user_id', $userId)->update(['totp_last_counter' => $n, 'updated_at' => now()]);

                    return true;
                }
            }

return false;
        });
    }
}

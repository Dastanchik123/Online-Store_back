<?php

namespace App\Services\Auth;

use App\Contracts\SmsSender;
use App\Models\Setting;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Вход покупателя по номеру телефона и коду из SMS. Регистрации отдельно нет:
 * первый вход с новым номером создаёт покупателя, имя он вводит после.
 *
 * Защита (покупатель её не замечает):
 *  - код из 4 цифр, действует 5 минут, 5 попыток ввода;
 *  - повторная отправка не раньше чем через 60 секунд;
 *  - не больше 5 SMS на номер и 20 SMS с одного IP в час.
 */
class PhoneLoginService
{
    public const TOKEN_NAME = 'phone_login';

    public const CODE_TTL_MINUTES   = 5;
    public const MAX_ATTEMPTS       = 5;
    public const RESEND_SECONDS     = 60;
    public const MAX_PER_PHONE_HOUR = 5;
    public const MAX_PER_IP_HOUR    = 20;

    public function __construct(private SmsSender $sms)
    {
    }

    /**
     * @return array{phone: string, resend_in: int}
     *
     * @throws ValidationException
     * @throws \App\Exceptions\SmsSendException
     */
    public function sendCode(string $rawPhone, ?string $ip): array
    {
        $phone = $this->normalizeOrFail($rawPhone);

        $this->ensureCustomerPhone($phone);
        $this->ensureCanSend($phone, $ip);

        $code = (string) random_int(1000, 9999);

        $record = DB::table('phone_login_codes')->insertGetId([
            'phone'      => $phone,
            'code_hash'  => Hash::make($code),
            'ip'         => $ip,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->sms->send($phone, $this->smsText($code));
        } catch (\Throwable $e) {
            // Код не дошёл — не засчитываем его в лимиты и не даём им войти
            DB::table('phone_login_codes')->where('id', $record)->delete();
            throw $e;
        }

        // Прежние коды этого номера больше не действуют — только последний
        DB::table('phone_login_codes')
            ->where('phone', $phone)
            ->where('id', '!=', $record)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        return ['phone' => $phone, 'resend_in' => self::RESEND_SECONDS];
    }

    /**
     * Проверить код и войти. Новый номер — новый покупатель.
     *
     * @return array{user: User, token: string, needs_name: bool}
     *
     * @throws ValidationException
     */
    public function verify(string $rawPhone, string $code): array
    {
        $phone = $this->normalizeOrFail($rawPhone);
        $code  = preg_replace('/\D+/', '', $code);

        // Ошибки — после транзакции: исключение внутри неё откатило бы и
        // счётчик неверных попыток
        $result = DB::transaction(function () use ($phone, $code) {
            $record = DB::table('phone_login_codes')
                ->where('phone', $phone)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $record || now()->greaterThan($record->expires_at)) {
                return ['code', 'Код устарел. Нажмите «Отправить ещё раз».'];
            }
            if ($record->attempts >= self::MAX_ATTEMPTS) {
                return ['code', 'Слишком много попыток. Получите новый код.'];
            }

            if (! Hash::check($code, $record->code_hash)) {
                DB::table('phone_login_codes')->where('id', $record->id)->increment('attempts');
                $left = self::MAX_ATTEMPTS - $record->attempts - 1;

                return ['code', $left > 0 ? "Неверный код. Осталось попыток: {$left}." : 'Неверный код. Получите новый код.'];
            }

            DB::table('phone_login_codes')->where('id', $record->id)->update(['consumed_at' => now()]);

            $user = User::withTrashed()->where('phone', $phone)->first();

            if ($user?->trashed()) {
                return ['phone', 'Вход с этим номером недоступен. Обратитесь в магазин.'];
            }

            if (! $user) {
                $user = User::create([
                    'name'     => '',
                    'phone'    => $phone,
                    'role'     => 'user',
                    'password' => Hash::make(Str::random(40)),
                ]);
            }

            if (! $user->phone_verified_at) {
                $user->forceFill(['phone_verified_at' => now()])->save();
            }

            return [
                'user'       => $user->fresh(),
                'token'      => $user->createToken(self::TOKEN_NAME)->plainTextToken,
                'needs_name' => trim((string) $user->name) === '',
            ];
        });

        if (array_is_list($result)) {
            [$field, $message] = $result;
            throw ValidationException::withMessages([$field => [$message]]);
        }

        return $result;
    }

    // ---------------------------------------------------------------

    private function normalizeOrFail(string $rawPhone): string
    {
        $phone = Phone::normalize($rawPhone);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => ['Введите номер полностью: 9 цифр после +996.']]);
        }

        return $phone;
    }

    // Сотрудники входят по паролю: SMS-вход не должен открывать админку
    // тому, у кого на руках чужой SIM-номер сотрудника
    private function ensureCustomerPhone(string $phone): void
    {
        $role = User::where('phone', $phone)->value('role');

        if ($role !== null && $role !== 'user') {
            throw ValidationException::withMessages(['phone' => ['Сотрудники входят по email и паролю.']]);
        }
    }

    private function ensureCanSend(string $phone, ?string $ip): void
    {
        $last = DB::table('phone_login_codes')->where('phone', $phone)->latest('id')->value('created_at');
        if ($last) {
            $wait = self::RESEND_SECONDS - now()->diffInSeconds($last);
            if ($wait > 0) {
                throw ValidationException::withMessages(['phone' => ["Код уже отправлен. Повторно — через {$wait} сек."]]);
            }
        }

        $hourAgo = now()->subHour();

        if (DB::table('phone_login_codes')->where('phone', $phone)->where('created_at', '>', $hourAgo)->count() >= self::MAX_PER_PHONE_HOUR) {
            throw ValidationException::withMessages(['phone' => ['Слишком много SMS на этот номер. Попробуйте через час.']]);
        }

        if ($ip && DB::table('phone_login_codes')->where('ip', $ip)->where('created_at', '>', $hourAgo)->count() >= self::MAX_PER_IP_HOUR) {
            throw ValidationException::withMessages(['phone' => ['Слишком много попыток входа. Попробуйте позже.']]);
        }
    }

    // Одна SMS: кириллица — до 70 символов
    private function smsText(string $code): string
    {
        $shop = trim((string) (config('services.nikita_sms.signature')
            ?: Setting::where('key', 'site_name')->value('value')));
        $text = "Код для входа: {$code}";

        return $shop !== '' && mb_strlen("{$text}. {$shop}") <= 70 ? "{$text}. {$shop}" : $text;
    }
}

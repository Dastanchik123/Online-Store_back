<?php

namespace Tests\Feature;

use App\Contracts\SmsSender;
use App\Exceptions\SmsSendException;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\NikitaSmsSender;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Вход покупателя по номеру телефона и коду из SMS
class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    private LogSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new LogSmsSender();
        $this->app->instance(SmsSender::class, $this->sms);
    }

    private function sendCode(string $phone = '0555 12-34-56')
    {
        return $this->postJson('/api/auth/phone/send-code', ['phone' => $phone]);
    }

    private function lastCode(): string
    {
        preg_match('/\d{4}/', end($this->sms->sent)['text'], $m);

        return $m[0];
    }

    private function verify(string $code, string $phone = '+996555123456')
    {
        return $this->postJson('/api/auth/phone/verify', ['phone' => $phone, 'code' => $code]);
    }

    public function test_phone_is_normalized_from_common_formats()
    {
        foreach (['555123456', '0555123456', '0555 12-34-56', '996555123456', '+996 (555) 123 456'] as $raw) {
            $this->assertSame('+996555123456', Phone::normalize($raw), $raw);
        }
        foreach (['', '12345', '055512345', '+7 999 123 45 67', '000123456'] as $bad) {
            $this->assertNull(Phone::normalize($bad), $bad);
        }
    }

    public function test_new_customer_logs_in_with_code_and_sets_name()
    {
        $this->sendCode()->assertOk()->assertJson(['phone' => '+996555123456', 'resend_in' => 60]);
        $this->assertSame('+996555123456', $this->sms->sent[0]['phone']);
        $this->assertLessThanOrEqual(70, mb_strlen($this->sms->sent[0]['text']), 'Одна SMS кириллицей');

        $this->verify('0000' === $this->lastCode() ? '1111' : '0000')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Неверный код. Осталось попыток: 4.');

        $response = $this->verify($this->lastCode())->assertOk();
        $this->assertTrue($response->json('needs_name'));
        $this->assertNotEmpty($response->json('token'));

        $user = User::where('phone', '+996555123456')->first();
        $this->assertSame('user', $user->role);
        $this->assertNull($user->email);
        $this->assertNotNull($user->phone_verified_at);

        $token = $response->json('token');
        $this->withToken($token)->postJson('/api/user/profile', ['name' => 'Айгуль'])->assertOk();
        $this->withToken($token)->getJson('/api/user')->assertOk()->assertJsonPath('name', 'Айгуль');

        // Код одноразовый
        $this->verify($this->lastCode())->assertStatus(422);
    }

    public function test_customer_added_at_pos_logs_in_to_same_account()
    {
        RolePermission::firstOrCreate(['role' => 'cashier', 'permission' => 'pos.access']);
        $cashier = User::factory()->create(['role' => 'cashier']);

        $created = $this->actingAs($cashier)->postJson('/api/pos/customers', ['name' => 'Бакыт', 'phone' => '0555 123 456'])
            ->assertStatus(201);
        // Повторно тот же номер — существующий клиент, не дубль
        $this->actingAs($cashier)->postJson('/api/pos/customers', ['name' => 'Другой', 'phone' => '+996555123456'])
            ->assertOk()->assertJsonPath('existing', true);
        $this->assertSame(1, User::where('phone', '+996555123456')->count());

        // Кассир находит его по номеру, набранному «как привык»
        $this->actingAs($cashier)->getJson('/api/pos/customers?search=0555 12')
            ->assertOk()->assertJsonPath('data.0.id', $created->json('customer.id'));

        $this->app['auth']->forgetGuards();
        $this->sendCode('555123456')->assertOk();
        $response = $this->verify($this->lastCode())->assertOk();

        $this->assertFalse($response->json('needs_name'));
        $this->assertSame($created->json('customer.id'), $response->json('user.id'));
    }

    public function test_staff_cannot_log_in_by_sms()
    {
        User::factory()->create(['role' => 'admin', 'phone' => '+996555123456']);

        $this->sendCode()->assertStatus(422)->assertJsonPath('errors.phone.0', 'Сотрудники входят по email и паролю.');
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_invalid_phone_is_rejected_without_sms()
    {
        $this->sendCode('12345')->assertStatus(422);
        $this->assertCount(0, $this->sms->sent);
    }

    public function test_resend_waits_60_seconds_and_old_code_stops_working()
    {
        $this->sendCode()->assertOk();
        $first = $this->lastCode();

        $this->sendCode()->assertStatus(422);
        $this->assertCount(1, $this->sms->sent);

        $this->travel(61)->seconds();
        $this->sendCode()->assertOk();
        $second = $this->lastCode();

        if ($first !== $second) {
            $this->verify($first)->assertStatus(422);
        }
        $this->verify($second)->assertOk();
    }

    public function test_sms_per_phone_per_hour_is_limited()
    {
        for ($i = 0; $i < 5; $i++) {
            $this->sendCode()->assertOk();
            $this->travel(61)->seconds();
        }

        $this->sendCode()->assertStatus(422)->assertJsonPath('errors.phone.0', 'Слишком много SMS на этот номер. Попробуйте через час.');
        $this->assertCount(5, $this->sms->sent);
    }

    public function test_code_is_blocked_after_five_wrong_attempts()
    {
        $this->sendCode()->assertOk();
        $right = $this->lastCode();
        $wrong = $right === '1234' ? '4321' : '1234';

        for ($i = 0; $i < 5; $i++) {
            $this->verify($wrong)->assertStatus(422);
        }

        $this->verify($right)->assertStatus(422)->assertJsonPath('errors.code.0', 'Слишком много попыток. Получите новый код.');
        $this->assertSame(0, User::count());
    }

    public function test_code_expires_after_five_minutes()
    {
        $this->sendCode()->assertOk();
        $code = $this->lastCode();

        $this->travel(6)->minutes();
        $this->verify($code)->assertStatus(422)->assertJsonPath('errors.code.0', 'Код устарел. Нажмите «Отправить ещё раз».');
    }

    public function test_failed_sms_does_not_leave_usable_code_or_count_toward_limit()
    {
        $failing = new class implements SmsSender {
            public function send(string $phone, string $text): void
            {
                throw new SmsSendException('шлюз недоступен');
            }
        };
        $this->app->instance(SmsSender::class, $failing);

        $this->sendCode()->assertStatus(503);
        $this->assertDatabaseCount('phone_login_codes', 0);

        // Шлюз ожил — можно сразу отправить заново, без ожидания 60 секунд
        $this->app->instance(SmsSender::class, $this->sms);
        $this->sendCode()->assertOk();
    }

    public function test_customer_stays_logged_in_for_a_year_but_staff_token_expires()
    {
        $this->sendCode()->assertOk();
        $customerToken = $this->verify($this->lastCode())->json('token');

        $staff = User::factory()->create(['role' => 'admin', 'email' => 'boss@example.com', 'password' => bcrypt('secret123')]);
        $staffToken = $this->postJson('/api/login', ['email' => 'boss@example.com', 'password' => 'secret123'])->json('token');

        $this->travel(60)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($customerToken)->getJson('/api/user')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($staffToken)->getJson('/api/user')->assertStatus(401);

        $this->travel(320)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($customerToken)->getJson('/api/user')->assertStatus(401);
    }

    public function test_admin_can_add_customer_without_email_and_phone_must_be_unique()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        \App\Models\Role::firstOrCreate(['name' => 'user'], ['label' => 'Покупатель', 'is_system' => true]);

        $this->actingAs($admin)->postJson('/api/users', ['name' => 'Нурлан', 'role' => 'user', 'phone' => '0700 11 22 33'])
            ->assertStatus(201)->assertJsonPath('phone', '+996700112233');

        $this->actingAs($admin)->postJson('/api/users', ['name' => 'Двойник', 'role' => 'user', 'phone' => '+996 700 112 233'])
            ->assertStatus(422)->assertJsonPath('errors.phone.0', 'Этот номер телефона уже есть у другого клиента.');
    }

    public function test_nikita_sender_posts_xml_and_reports_gateway_errors()
    {
        Http::fake([
            'smspro.nikita.kg/*' => Http::sequence()
                ->push('<?xml version="1.0" encoding="UTF-8"?><response><id>X</id><status>11</status><phones>1</phones><smscnt>1</smscnt></response>')
                ->push('<?xml version="1.0" encoding="UTF-8"?><response><id>X</id><status>5</status></response>'),
        ]);

        $sender = new NikitaSmsSender('shop-login', 'p&ss<word>', 'SMSPRO.KG', true);
        $sender->send('+996555123456', 'Код для входа: 1234. Жалпак-Таш');

        Http::assertSent(function (HttpRequest $request) {
            $xml = simplexml_load_string($request->body());

            return $request->url() === 'https://smspro.nikita.kg/api/message'
                && (string) $xml->login === 'shop-login'
                && (string) $xml->pwd === 'p&ss<word>'
                && (string) $xml->sender === 'SMSPRO.KG'
                && (string) $xml->phones->phone === '996555123456'
                && (string) $xml->text === 'Код для входа: 1234. Жалпак-Таш'
                && (string) $xml->test === '1'
                && preg_match('/^[A-Z0-9]{12}$/', (string) $xml->id);
        });

        $this->expectException(SmsSendException::class);
        $sender->send('+996555123456', 'Код для входа: 1234');
    }
}

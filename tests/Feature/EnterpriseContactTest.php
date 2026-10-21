<?php

namespace Tests\Feature;

use App\Mail\EnterpriseContactMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EnterpriseContactTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name'    => 'Ada Lovelace',
            'email'   => 'ada@example.com',
            'message' => 'We need SSO for 80 seats.',
        ];
    }

    public function test_valid_submission_sends_one_mail_to_the_configured_address(): void
    {
        Mail::fake();
        config(['tiers.enterprise_contact_email' => 'sales@example.com']);

        $this->postJson('/contact/enterprise', $this->payload())->assertNoContent();

        Mail::assertSent(EnterpriseContactMail::class, 1);
        Mail::assertSent(
            EnterpriseContactMail::class,
            fn (EnterpriseContactMail $mail) => $mail->hasTo('sales@example.com'),
        );
    }

    public function test_mail_replies_go_to_the_visitor(): void
    {
        Mail::fake();

        $this->postJson('/contact/enterprise', $this->payload())->assertNoContent();

        Mail::assertSent(
            EnterpriseContactMail::class,
            fn (EnterpriseContactMail $mail) => $mail->hasReplyTo('ada@example.com'),
        );
    }

    public function test_filled_honeypot_returns_no_content_and_sends_nothing(): void
    {
        Mail::fake();

        $this->postJson('/contact/enterprise', $this->payload(['website' => 'http://spam.test']))
            ->assertNoContent();

        Mail::assertNothingSent();
    }

    public function test_invalid_email_is_rejected(): void
    {
        Mail::fake();

        $this->postJson('/contact/enterprise', $this->payload(['email' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    public function test_missing_fields_are_rejected(): void
    {
        $this->postJson('/contact/enterprise', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'message']);
    }

    public function test_newline_in_name_is_rejected_to_block_header_injection(): void
    {
        Mail::fake();

        $this->postJson('/contact/enterprise', $this->payload(['name' => "Ada\r\nBcc: victim@example.com"]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        Mail::assertNothingSent();
    }

    public function test_oversized_message_is_rejected(): void
    {
        $this->postJson('/contact/enterprise', $this->payload(['message' => str_repeat('a', 5001)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    }

    public function test_fourth_submission_for_one_email_within_an_hour_is_throttled(): void
    {
        Mail::fake();

        foreach (range(1, 3) as $i) {
            $this->postJson('/contact/enterprise', $this->payload())->assertNoContent();
        }

        $this->postJson('/contact/enterprise', $this->payload())->assertTooManyRequests();
        Mail::assertSent(EnterpriseContactMail::class, 3);
    }

    public function test_unicode_line_separator_in_name_is_rejected(): void
    {
        $this->postJson('/contact/enterprise', $this->payload(['name' => "Ada\u{2028}Bcc: x"]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_overlong_name_is_rejected(): void
    {
        $this->postJson('/contact/enterprise', $this->payload(['name' => str_repeat('a', 101)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_oversized_honeypot_value_is_rejected(): void
    {
        $this->postJson('/contact/enterprise', $this->payload(['website' => str_repeat('a', 256)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('website');
    }

    public function test_mail_subject_and_body_carry_the_visitor_input(): void
    {
        Mail::fake();

        $this->postJson('/contact/enterprise', $this->payload())->assertNoContent();

        Mail::assertSent(EnterpriseContactMail::class, function (EnterpriseContactMail $mail) {
            return $mail->hasSubject('Enterprise enquiry from Ada Lovelace')
                && str_contains($mail->render(), 'We need SSO for 80 seats.')
                && str_contains($mail->render(), 'ada@example.com');
        });
    }

    public function test_sixth_submission_from_one_ip_is_throttled(): void
    {
        Mail::fake();

        foreach (range(1, 5) as $i) {
            $this->postJson('/contact/enterprise', $this->payload(['email' => "ada{$i}@example.com"]))
                ->assertNoContent();
        }

        $this->postJson('/contact/enterprise', $this->payload(['email' => 'ada6@example.com']))
            ->assertTooManyRequests();
    }
}

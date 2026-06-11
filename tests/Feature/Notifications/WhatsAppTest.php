<?php

namespace Tests\Feature\Notifications;

use App\Services\Notifications\WhatsApp;
use Tests\TestCase;

class WhatsAppTest extends TestCase
{
    private function wa(): WhatsApp
    {
        return new WhatsApp;
    }

    public function test_normalize_my_local_format(): void
    {
        $this->assertSame('60123456789', $this->wa()->normalize('012-345 6789'));
    }

    public function test_normalize_keeps_already_prefixed_number(): void
    {
        $this->assertSame('60123456789', $this->wa()->normalize('+60 12-345 6789'));
    }

    public function test_normalize_null_and_empty_give_empty(): void
    {
        $this->assertSame('', $this->wa()->normalize(null));
        $this->assertSame('', $this->wa()->normalize('  '));
    }

    public function test_link_without_text(): void
    {
        $this->assertSame('https://wa.me/60123456789', $this->wa()->link('012-345 6789'));
    }

    public function test_link_with_text_is_url_encoded(): void
    {
        $this->assertSame(
            'https://wa.me/60123456789?text=Hi%20there%20%26%20welcome',
            $this->wa()->link('012-345 6789', 'Hi there & welcome'),
        );
    }
}

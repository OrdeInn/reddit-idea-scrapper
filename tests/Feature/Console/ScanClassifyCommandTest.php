<?php

namespace Tests\Feature\Console;

use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class ScanClassifyCommandTest extends TestCase
{
    public function test_rejects_removed_kimi_provider_option(): void
    {
        $this->artisan('scan:classify', [
            '--provider' => 'kimi',
        ])
            ->expectsOutput('--provider must be one of: gpt, both')
            ->assertExitCode(Command::FAILURE);
    }
}

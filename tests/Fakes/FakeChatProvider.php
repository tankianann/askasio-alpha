<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Providers\Chat\ChatGeneration;
use App\Providers\Chat\ChatProviderInterface;
use Throwable;

final class FakeChatProvider implements ChatProviderInterface
{
    public array $requests = [];

    public function __construct(
        private readonly string $answer = 'Grounded answer [S1].',
        private readonly ?Throwable $failure = null,
    ) {
    }

    public function generate(string $instructions, string $input, array $options = []): ChatGeneration
    {
        $this->requests[] = compact('instructions', 'input', 'options');

        if ($this->failure instanceof Throwable) {
            throw $this->failure;
        }

        return new ChatGeneration($this->answer, 'resp_test', 'fake-chat-model', 120, 20, 40, 5, 160);
    }
}

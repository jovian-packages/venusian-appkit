<?php

declare(strict_types=1);

use Jovian\Toolkits\Appkit\Input\AppkitInputEngine;
use Jovian\Toolkits\Appkit\Input\GameControllerPadSource;
use Jovian\Toolkits\Appkit\Providers\VenusianAppkitServiceProvider;
use Surface\Bridge\ToolkitManager;
use Surface\Contracts\HumanInput\Key;
use Surface\HumanInput\HumanInputManager;

afterEach(function (): void {
    driver()->closeAllStaged();
    pumpFor(0.05);
});

it('registers the appkit engine and the gamecontroller pad source: HumanInput follows the session and reads its keys', function (): void {
    $toolkits = new class extends ToolkitManager {
        public function __construct()
        {
            $this->drivers = ['appkit' => driver()];
        }

        public function getDefaultDriver(): string
        {
            return 'appkit';
        }
    };
    session();
    $input = new HumanInputManager($toolkits, inputFrame(), 'mac', 'gamecontroller', fn (object $mail) => null);
    VenusianAppkitServiceProvider::input($input, $toolkits);
    $window = inputWindow('provided');

    $input->poll();
    session()->application()->postEventAtStart(keyEvent($window, 0x0C, true, 'q'), false);
    session()->pump(0);

    try {
        expect(array_keys($input->engines()))->toBe(['appkit'])
            ->and($input->engines()['appkit'])->toBeInstanceOf(AppkitInputEngine::class)
            ->and($input->pads())->toBeInstanceOf(GameControllerPadSource::class)
            ->and($input->keyboard()->isPressed(Key::Q))->toBeTrue()
            ->and(array_filter(array_keys($input->gameControllers()), fn (string $id): bool => ! str_starts_with($id, 'gc-')))->toBe([]);
    } finally {
        $input->destroy();
    }
});

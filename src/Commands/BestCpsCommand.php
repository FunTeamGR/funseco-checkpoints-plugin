<?php
namespace Funseco\Checkpoints\Commands;

use Funseco\Checkpoints\BestCheckpoints;
use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;

class BestCpsCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'bestcps';
    protected string $description = 'Toggle show/hide bestcps';

    public function handle(Player $player, array $args): void
    {
        $shown = app(BestCheckpoints::class)->toggle($player);
        \Server::query()->ChatSendServerMessageToLogin(config('checkpoints.messages.'.($shown ? 'best_on' : 'best_off')), $player->login);
    }
}

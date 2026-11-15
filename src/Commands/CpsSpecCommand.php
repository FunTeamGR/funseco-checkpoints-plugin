<?php
namespace Funseco\Checkpoints\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;

/** Behind checkpoints.spectator_tracking (off by default, as XAseco enable_cpsspec = false). */
class CpsSpecCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'cpsspec';
    protected string $description = 'Shows checkpoints of spectated player';

    public static function enabledFor(Player $player): bool
    {
        return (bool) state()->get('checkpoints:cpsspec:'.$player->login, false);
    }

    public function handle(Player $player, array $args): void
    {
        if (!config('checkpoints.spectator_tracking', false)) {
            \Server::query()->ChatSendServerMessageToLogin(CpsCommand::message('no_cpsspec'), $player->login);
            return;
        }
        $on = !self::enabledFor($player);
        state()->forever('checkpoints:cpsspec:'.$player->login, $on);
        \Server::query()->ChatSendServerMessageToLogin(CpsCommand::message($on ? 'spec_on' : 'spec_off'), $player->login);
    }
}

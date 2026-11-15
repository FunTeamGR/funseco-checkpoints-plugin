<?php
namespace Funseco\Checkpoints\Commands;

use Funseco\Checkpoints\CheckpointTracker;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\RecordBook;

class CpsCommand implements Command, Described
{
    use DeclaresCommand;

    protected string $name = 'cps';
    protected string $description = 'Sets local record checkpoints tracking';
    protected string $usage = '[off|record #]';

    public function handle(Player $player, array $args): void
    {
        $tracker = app(CheckpointTracker::class);
        $param = strtolower(trim((string) ($args[0] ?? '')));
        $map = CurrentMap::get();
        $max = $map ? app(CheckpointTracker::class)->lastRank($map) : 0;

        if (!config('checkpoints.enabled', true)) {
            $this->tell($player, self::message('disabled'));
            return;
        }

        if ($param === 'off') {
            $tracker->changeSetting($player, CheckpointTracker::OFF);
            $tracker->hide($player);
            $this->tell($player, self::message('off'));
            return;
        }
        if ($param === '') {
            $setting = CheckpointTracker::OWN_OR_LAST;
            $message = self::message('on');
        } elseif (ctype_digit($param) && (int) $param > 0 && (int) $param <= $max) {
            $setting = (int) $param;
            $message = self::message('record', $param);
        } else {
            $this->tell($player, self::message('no_record', $param));
            return;
        }

        $tracker->changeSetting($player, $setting);
        $tracker->placeholder($player);
        $this->tell($player, $message);
    }

    public static function message(string $key, string ...$values): string
    {
        $text = format_colors((string) config("checkpoints.messages.$key", ''));   // values must not expand {#..}
        $map = [];
        foreach ($values as $i => $value) {
            $map['{'.($i + 1).'}'] = $value;
        }

        return strtr($text, $map);
    }

    private function tell(Player $player, string $message): void
    {
        \Server::query()->ChatSendServerMessageToLogin($message, $player->login);
    }
}

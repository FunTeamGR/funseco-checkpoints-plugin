<?php
namespace Funseco\Checkpoints;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Illuminate\Support\Facades\Event;
use Funseco\Core\SharedKernel\UI\Region;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Funseco\Checkpoints\Commands\BestCpsCommand;
use Funseco\Checkpoints\Commands\CpsCommand;
use Funseco\Checkpoints\Commands\CpsSpecCommand;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\GameStatusChanged;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Core\SharedKernel\Events\PlayerCheckpointPassed;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerDisconnected;
use Funseco\Core\SharedKernel\Events\PlayerFinished;

class CheckpointsServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    private const STUNTS = 4;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/checkpoints.php', 'checkpoints');
        $this->mergeConfigDeep(__DIR__.'/../config/manialinks.php', 'manialinks');
        $this->publishPluginConfig(__DIR__.'/..');
        // All read lazily through config() where used: live.
        $this->app->make(SettingsRegistry::class)->declare('checkpoints', 'checkpoints', [
            Setting::bool('enabled', 'Checkpoint difference panel (/cps)'),
            Setting::bool('auto_enable', 'Compare with own/last record by default'),
            Setting::bool('spectator_tracking', 'Spectator checkpoint tracking (/cpsspec)'),
            Setting::int('max_records', 'Records counted for /cps', min: 1),
            Setting::bool('best.enabled', 'Record best checkpoint times'),
            Setting::bool('best.panel', 'Best checkpoint times panel'),
            Setting::bool('best.strip', 'Best checkpoint times strip'),
            Setting::int('best.per_line', 'Strip cells per line', min: 1, max: 30),
            Setting::float('best.strip_bottom', 'Strip lower edge (y)'),
        ]);
        $this->app->singleton(CheckpointTracker::class);
        $this->app->singleton(\Funseco\Core\SharedKernel\CheckpointReference::class, fn () => app(CheckpointTracker::class));
        $this->app->singleton(BestCheckpoints::class);
    }

    public function boot(): void
    {
        app(ManiaLinkService::class)->addActions(['checkpoints_panel', 'best_checkpoints', 'best_checkpoint_strip']);
        foreach ([CpsCommand::class, CpsSpecCommand::class, BestCpsCommand::class] as $command) {
            \Chat::register($command);
        }

        $tracker = fn () => app(CheckpointTracker::class);

        // The menu's status lights
        $lights = app(\Funseco\Core\SharedKernel\MenuIndicators::class);
        $lights->register('cps.own', fn (\Funseco\Core\Models\Player $p) => $tracker()->trackingEnabled($p));
        $lights->register('cps.spec', fn (\Funseco\Core\Models\Player $p) => \Funseco\Core\Services\Cache\CurrentSpectators::has($p->login));
        $best = fn () => app(BestCheckpoints::class);
        BestCheckpoints::register(app(WidgetRegistry::class));
        Event::listen(StartupEvent::class, fn () => $best()->startup());
        Event::listen(\Funseco\Core\SharedKernel\Events\MapEnded::class, fn () => $best()->ended());

        Event::listen(MapStarted::class, function () use ($tracker, $best) {
            $best()->reset();
            foreach (CurrentPlayers::all() as $player) {
                $tracker()->prepare($player);
            }
        });
        Event::listen(PlayerConnected::class, function (PlayerConnected $e) use ($tracker, $best) {
            $tracker()->prepare($e->player);
            $best()->showTo($e->player);
        });
        // Back from spectating: the best checkpoint times were not sent while spectating.
        Event::listen(\Funseco\Core\SharedKernel\Events\PlayerInfoUpdated::class, function (\Funseco\Core\SharedKernel\Events\PlayerInfoUpdated $e) use ($best) {
            if ($e->spectatorChanged() && !$e->playerInfo->is_spectator) {
                $best()->showTo($e->player);
            }
        });
        Event::listen(PlayerDisconnected::class, fn (PlayerDisconnected $e) => $tracker()->forget($e->player->login));

        Event::listen(PlayerCheckpointPassed::class, function (PlayerCheckpointPassed $e) use ($tracker, $best) {
            if (ServerInfo::getGameMode() === self::STUNTS) {
                return;   // stunt scores, not times
            }
            if (config('checkpoints.enabled', true)) {
                $tracker()->passed($e->player, $e->checkpointIndex, $e->time);
            }
            $best()->passed($e->player, $e->checkpointIndex, $e->time);
        });
        Event::listen(PlayerFinished::class, function (PlayerFinished $e) use ($tracker, $best) {
            $tracker()->finished($e->player, $e->checkpoints->map(fn ($cp) => $cp->score)->values()->all(), $e->score);
            $best()->finished($e->checkpoints->count());
        });

        // XAseco hides the panel when the race ends.
        Event::listen(GameStatusChanged::class, function (GameStatusChanged $e) use ($tracker, $best) {
            if (!$e->isPlaying()) {
                $best()->hide(); // the race is over: EndChallenge (MapEnded) only comes after the podium
                foreach (CurrentPlayers::all() as $player) {
                    $tracker()->hide($player);
                }
            }
        });
    }
}

<?php
namespace Funseco\Checkpoints;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\Services\Cache\CurrentSpectators;
use Funseco\Core\SharedKernel\UI\Region;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;

/**
 * XAseco plugin.best_checkpoint_times (the one the live server ran): who has the fastest time at each
 * checkpoint of the current map, in a panel above the donation bar. The finish is not listed, at most
 * 20 checkpoints in two blocks of ten, the checkpoint beaten last blinks. Spectators and anyone who hid
 * it with /bestcps don't get it; it is cleared when the race ends. The older strip (BestCheckpointStrip)
 * draws from the same times; each can be switched off in `checkpoints.best` (panel, strip).
 */
class BestCheckpoints
{
    private const BEST = 'checkpoints:best';
    private const FINISH = 'checkpoints:finishIndex';
    private const HIDDEN = 'checkpoints:bestHidden';

    private const WIDTH = 38.6;
    private const HEIGHT = 12.0;
    private const LINE = 1.25;
    private const MAX_SHOWN = 18;

    /** Placed by config (manialinks.best_checkpoints.gamemode.<mode>.pos_x/pos_y: the panel's top-left). */
    public static function register(WidgetRegistry $registry): void
    {
        $registry->regionWidget('best_checkpoints', Region::BotCenter, 20, width: self::WIDTH, height: self::HEIGHT);
        BestCheckpointStrip::register($registry);
    }

    /** @return array<int, array{time: int, nickname: string}> by checkpoint index */
    public function all(): array
    {
        return state()->get(self::BEST, []);
    }

    public function passed(Player $player, int $index, int $time): void
    {
        $map = CurrentMap::get();
        $finish = (int) state()->get(self::FINISH, $map && $map->cp_count > 0 ? (int) $map->cp_count - 1 : -1);
        if (!config('checkpoints.best.enabled', true) || $index === $finish || $index >= self::MAX_SHOWN) {
            return;
        }

        $best = $this->all();
        if (isset($best[$index]) && $best[$index]['time'] <= $time) {
            return;
        }
        $best[$index] = ['time' => $time, 'nickname' => (string) $player->nickname];
        ksort($best);
        state()->forever(self::BEST, $best);

        $this->showToAll($index);
    }

    /** The finish arrives as the last checkpoint; once a finisher shows how many there are, drop it (and stop taking it). */
    public function finished(int $checkpointCount): void
    {
        $finish = $checkpointCount - 1;
        state()->forever(self::FINISH, $finish);
        $best = $this->all();
        if ($finish >= 0 && isset($best[$finish])) {
            unset($best[$finish]);
            state()->forever(self::BEST, $best);
            $this->showToAll();
        }
    }

    /** New map: forget the times and send the empty panel over the old one. */
    public function reset(): void
    {
        state()->forget(self::BEST);
        state()->forget(self::FINISH);
        $this->showToAll();
        // The map-start info has no checkpoint count: ask the server, so the finish is skipped from the first run.
        try {
            app(\Funseco\Core\Servers\TmForever\ServerService::class)->currentCheckpointCount()->then(function (int $count) {
                if ($count > 0) {
                    state()->forever(self::FINISH, $count - 1);
                    $this->showToAll();
                }
            });
        } catch (\Throwable) {
        }
    }

    /** End of the race: forget the times and take the panel down for everybody (score screen). */
    public function ended(): void
    {
        state()->forget(self::BEST);
        $this->hide();
    }

    /** Take the panel and the strip down for everybody, keeping the times (the podium must stay clear). */
    public function hide(): void
    {
        foreach (['best_checkpoints', BestCheckpointStrip::NAME] as $name) {
            ManiaLink::displayAll('<manialink id="'.ManiaLink::getAction($name).'"></manialink>');
        }
    }

    /** /bestcps: true when the widget is now shown for the player. */
    public function toggle(Player $player): bool
    {
        $hidden = state()->get(self::HIDDEN, []);
        if (in_array($player->login, $hidden, true)) {
            state()->forever(self::HIDDEN, array_values(array_diff($hidden, [$player->login])));
            $this->showTo($player); // not only from the next checkpoint on
            return true;
        }
        state()->forever(self::HIDDEN, [...$hidden, $player->login]);
        foreach (['best_checkpoints', BestCheckpointStrip::NAME] as $name) {
            ManiaLink::displayLogin($player, '<manialink id="'.ManiaLink::getAction($name).'"></manialink>');
        }

        return false;
    }

    /** After a restart the old panel is still on screen: send the current one over it. */
    public function startup(): void
    {
        $this->showToAll();
        // Restarted mid race: take the best times back from the game server.
        \Server::query()->GetCurrentRanking(300, 0, function ($result) {
            $rows = is_array($result[0] ?? null) ? $result[0] : [];
            $this->restore(array_values(array_filter($rows, 'is_array')));
        });
    }

    /** Fastest time per checkpoint from the rows' BestCheckpoints (GetCurrentRanking). */
    public function restore(array $rows): void
    {
        $best = $this->all();
        foreach ($rows as $row) {
            foreach (array_values((array) ($row['BestCheckpoints'] ?? [])) as $index => $time) {
                $time = (int) $time;
                if ($time <= 0 || $index >= self::MAX_SHOWN || (isset($best[$index]) && $best[$index]['time'] <= $time)) {
                    continue;
                }
                $nick = CurrentPlayers::get((string) ($row['Login'] ?? ''))?->nickname ?? (string) ($row['NickName'] ?? '');
                $best[$index] = ['time' => $time, 'nickname' => (string) $nick];
            }
        }
        if ($best === $this->all()) {
            return;
        }
        ksort($best);
        state()->forever(self::BEST, $best);
        $this->showToAll();
    }

    /** @param int|null $blink the checkpoint that was just beaten */
    public function showToAll(?int $blink = null): void
    {
        $xml = $this->xml($blink).BestCheckpointStrip::xml($this->all());
        foreach (CurrentPlayers::all() as $player) {
            $this->send($player, $xml);
        }
    }

    /** A player who just came in sees the times so far, not an empty screen until the next checkpoint. */
    public function showTo(Player $player): void
    {
        $this->send($player, $this->xml().BestCheckpointStrip::xml($this->all()));
    }

    private function send(Player $player, string $xml): void
    {
        if (!in_array($player->login, state()->get(self::HIDDEN, []), true) && !CurrentSpectators::has($player->login)) {
            ManiaLink::displayLogin($player, $xml);
        }
    }

    public function xml(?int $blink = null): string
    {
        $placement = config('checkpoints.best.panel', true) ? app(WidgetRegistry::class)->placement('best_checkpoints') : null;
        $id = ManiaLink::getAction('best_checkpoints');
        if ($placement === null) {
            return '<manialink id="'.$id.'"></manialink>';
        }

        // The panel starts half a unit inside the frame, so the frame sits at the placement + 0.5.
        $xml = '<manialink id="'.$id.'"><frame posn="'.($placement->x + 0.5).' '.($placement->y + 0.5).' 3">'
            .'<quad posn="-0.5 -0.5 0.11" sizen="'.self::WIDTH.' '.self::HEIGHT.'" style="BgsPlayerCard" substyle="ProgressBar"/>'
            .'<quad posn="17.5 -0.65 0.13" sizen="0.1 '.self::HEIGHT.'" bgcolor="FFF5"/>';

        $map = CurrentMap::get();
        $best = $this->all();
        // Rows = the map's checkpoints (the finish is not one); a map whose count is not known (0) shows what was passed.
        $finishIdx = (int) state()->get(self::FINISH, -1);
        unset($best[$finishIdx]);
        $seen = $best ? max(array_keys($best)) + 1 : 0;
        $shown = min(max($finishIdx > 0 ? $finishIdx : max(0, ($map ? (int) $map->cp_count : 0) - 1), $seen), self::MAX_SHOWN);
        for ($cp = 0; $cp < $shown; $cp++) {
            $x = intdiv($cp, 9) * 18;
            $y = round(-self::LINE * ($cp % 9 + 1), 2);
            $entry = $best[$cp] ?? ['time' => 0, 'nickname' => '---'];
            $scale = '0.9';
            $xml .= '<format style="'.($blink === $cp ? 'TextTitle2Blink' : 'TextStaticMedium').'"/>'
                .'<label posn="'.($x + 1.85).' '.$y.' 0.14" sizen="1.5 0" halign="right" textsize="1" scale="'.$scale.'" text="$FFF'.($cp + 1).'."/>'
                .'<label posn="'.($x + 6.3).' '.$y.' 0.14" sizen="4.3 0" halign="right" textsize="1" scale="'.$scale.'" text="$FFF'.self::time($entry['time']).'"/>'
                .'<label posn="'.($x + 6.8).' '.$y.' 0.14" sizen="11 0" textsize="1" scale="'.$scale.'" text="$FFF'.self::clean($entry['nickname']).'"/>';
        }

        return $xml.'</frame></manialink>';
    }

    /** m:ss.hh (h:mm:ss.hh from an hour on) as the widget shows it. */
    public static function time(int $ms): string
    {
        $h = intdiv($ms, 3600000);
        $m = intdiv($ms % 3600000, 60000);
        $sec = intdiv($ms % 60000, 1000);
        $hs = intdiv($ms % 1000, 10);

        return $h ? sprintf('%d:%02d:%02d.%02d', $h, $m, $sec, $hs) : sprintf('%d:%02d.%02d', $m, $sec, $hs);
    }

    /** Nickname without links and style codes ($s $h $w $i $l $o $n), escaped for the XML. */
    private static function clean(string $nickname): string
    {
        $nickname = preg_replace('/\${1}[LHP]\[.*?\](.*?)\$[LHP]/i', '$1', $nickname);
        $nickname = preg_replace('/\${1}[LHP]\[.*?\]/i', '', $nickname);
        $nickname = preg_replace('/\${1}[SHWILON]/i', '', (string) $nickname);

        return htmlspecialchars(str_replace(["\r", "\n"], ' ', (string) $nickname), ENT_QUOTES);
    }
}

<?php
namespace Funseco\Checkpoints;

use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Models\PlayerExtra;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\RecordBook;
use Funseco\Core\Servers\TmForever\Values\CheckpointCollection;

/**
 * XAseco plugin.checkpoints: each player compares their run with a reference — their own
 * record or the last one (setting 0), record N (setting N), or nothing (-1). A faster finish
 * during the session becomes the new reference.
 */
class CheckpointTracker implements \Funseco\Core\SharedKernel\CheckpointReference
{
    public const OFF = -1;
    public const OWN_OR_LAST = 0;

    public function __construct(private RecordBook $records)
    {
    }

    /** The player's /cps setting (players_extra.cps); unset = auto_enable. */
    public function setting(Player $player): int
    {
        $stored = PlayerExtra::where('player_id', $player->id)->value('cps');

        return $stored !== null ? (int) $stored : (config('checkpoints.auto_enable', true) ? self::OWN_OR_LAST : self::OFF);
    }

    public function changeSetting(Player $player, int $setting): void
    {
        PlayerExtra::updateOrCreate(['player_id' => $player->id], ['cps' => $setting]);
        $this->prepare($player);
        event(new \Funseco\Core\SharedKernel\Events\CheckpointsSettingChanged($player));
    }

    public function trackingEnabled(Player $player): bool
    {
        return $this->setting($player) !== self::OFF;
    }

    public function useReference(Player $player, array $checkpoints): void
    {
        $state = state()->get(self::key($player->login));
        if ($state === null || $state['setting'] === self::OFF || $checkpoints === []) {
            return;
        }
        $state['reference'] = array_values($checkpoints);
        state()->forever(self::key($player->login), $state);
    }

    /** Pick the reference run for the current map (map start, join, /cps). */
    public function prepare(Player $player): void
    {
        $map = CurrentMap::get();
        $setting = $this->setting($player);
        $reference = null;

        if ($map && $setting !== self::OFF) {
            $reference = $setting === self::OWN_OR_LAST
                ? $this->ownOrLast($player, $map)
                : $this->records->checkpointsOfRank($map, min($setting, $this->lastRank($map)));
        }

        state()->forever(self::key($player->login), [
            'setting' => $setting,
            'reference' => $reference ? $reference->map(fn ($cp) => $cp->score)->values()->all() : [],
        ]);
    }

    /** Last rank that counts as a local record. */
    public function lastRank(Map $map): int
    {
        return min($this->records->countOn($map), (int) config('checkpoints.max_records', 50));
    }

    /** The player's own run if it is a local record (top max_records), else the last local record. */
    private function ownOrLast(Player $player, Map $map): ?CheckpointCollection
    {
        $last = $this->records->checkpointsOfRank($map, $this->lastRank($map));
        $own = $this->records->checkpointsFor($player, $map);
        if ($own === null || $own->isEmpty()) {
            return $last;
        }

        return $last === null || $last->isEmpty() || $own->last()->score <= $last->last()->score ? $own : $last;
    }

    public function passed(Player $player, int $index, int $time): void
    {
        $state = state()->get(self::key($player->login));
        if ($state === null) {
            $this->prepare($player);   // e.g. after a controller restart mid-map
            $state = state()->get(self::key($player->login));
        }

        $reference = $state['reference'];
        if ($state['setting'] === self::OFF || !isset($reference[$index])) {
            return;
        }

        $label = $index + 1 === count($reference) ? 'F' : (string) ($index + 1);
        $this->show($player, $label, self::diff($time - $reference[$index]));
    }

    public function finished(Player $player, array $checkpoints, int $score): void
    {
        $state = state()->get(self::key($player->login));
        if ($state === null || $checkpoints === [] || end($checkpoints) !== $score) {
            return;
        }

        $best = $state['reference'] ? end($state['reference']) : PHP_INT_MAX;
        if ($score < $best) {
            $state['reference'] = array_values($checkpoints);
            state()->forever(self::key($player->login), $state);
        }
    }

    public function placeholder(Player $player): void
    {
        $this->show($player, '0', '$00f -.--');
    }

    public function hide(Player $player): void
    {
        ManiaLink::displayLogin($player, '<manialink id="'.ManiaLink::getAction('checkpoints_panel').'"></manialink>');
    }

    public function forget(string $login): void
    {
        state()->forget(self::key($login));
    }

    /** XAseco: '$00f-' faster (blue), '$00f' equal, '$f00+' slower (red), then s.hh */
    public static function diff(int $ms): string
    {
        $sign = $ms < 0 ? '$00f-' : ($ms === 0 ? '$00f' : '$f00+');
        $ms = abs($ms);

        return $sign.sprintf('%d.%02d', intdiv($ms, 1000), intdiv($ms % 1000, 10));
    }

    private function show(Player $player, string $checkpoint, string $diff): void
    {
        $pos = config('manialinks.checkpoints_panel.pos_x').' '.config('manialinks.checkpoints_panel.pos_y');
        ManiaLink::displayLogin($player, '<manialink id="'.ManiaLink::getAction('checkpoints_panel').'"><frame posn="'.$pos.' 0">'
            .'<quad sizen="16 4" style="Bgs1InRace" substyle="NavButton"/>'
            .'<label posn="6.4 -0.7 1" sizen="6.0 4" textsize="3" textcolor="000" halign="right" text="CP'.$checkpoint.':"/>'
            .'<label posn="6.8 -0.7 1" sizen="8.8 4" textsize="3" halign="left" text="$o'.$diff.'"/>'
            .'</frame></manialink>');
    }

    private static function key(string $login): string
    {
        return 'checkpoints:'.$login;
    }
}

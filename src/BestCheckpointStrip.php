<?php
namespace Funseco\Checkpoints;

use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\UI\Grid\Direction;
use Funseco\Core\SharedKernel\UI\Region;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;

/**
 * XAseco plugin.bestcps: the best time at each checkpoint that has one, as a strip of cells under the
 * top-left button row. Independent of the best-checkpoint-times panel (`checkpoints.best.strip`).
 */
final class BestCheckpointStrip
{
    public const NAME = 'best_checkpoint_strip';

    private const CELL_WIDTH = 11.5;
    private const CELL_HEIGHT = 2.25;

    /** Stacks in the top-left under the button row; as big as the times it shows. */
    public static function register(WidgetRegistry $registry): void
    {
        $perLine = fn () => max(1, (int) config('checkpoints.best.per_line', 8));
        $count = fn () => count(app(BestCheckpoints::class)->all());

        // layout.containers.best_cps.margin_top: space above the strip (the game's own rank panel needs ~6.2).
        $registry->container('best_cps', Region::TopLeft, 20, Direction::Column, marginTop: 0.5);
        $registry->widget(self::NAME, 'best_cps', 1,
            width: fn () => min($count(), $perLine()) * self::CELL_WIDTH,
            height: fn () => ceil($count() / $perLine()) * self::CELL_HEIGHT);
    }

    /** @param array<int, array{time: int, nickname: string}> $best by checkpoint index */
    public static function xml(array $best): string
    {
        $id = ManiaLink::getAction(self::NAME);
        $placement = config('checkpoints.best.strip', true) ? app(WidgetRegistry::class)->placement(self::NAME) : null;
        if ($placement === null) {
            return '<manialink id="'.$id.'"></manialink>';
        }

        $perLine = max(1, (int) config('checkpoints.best.per_line', 8));
        // checkpoints.best.strip_bottom (y of the strip's lower edge) anchors the strip there and lets it grow
        // upward, e.g. just above the top-left buttons; otherwise it hangs from the placement.
        $bottom = config('checkpoints.best.strip_bottom');
        $top = $bottom !== null && $bottom !== ''
            ? (float) $bottom + ceil(count($best) / $perLine) * self::CELL_HEIGHT
            : $placement->y;
        // Cells are centred on their posn, so the frame starts half a cell inside the placement.
        $xml = '<manialink id="'.$id.'"><frame posn="'.($placement->x + self::CELL_WIDTH / 2).' '.($top - self::CELL_HEIGHT / 2).' 0"><format textsize="0.5"/>';

        foreach (array_values($best) as $n => $entry) {
            $x = ($n % $perLine) * self::CELL_WIDTH;
            $y = -intdiv($n, $perLine) * self::CELL_HEIGHT;
            $text = '$z'.str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT).'. '.BestCheckpoints::time($entry['time']);
            $xml .= '<quad posn="'.$x.' '.$y.'" sizen="11.5 2.2" halign="center" valign="center" style="Bgs1InRace" substyle="NavButton"/>'
                .'<label posn="'.($x - 5.25).' '.$y.'" sizen="5.5 2" halign="left" valign="center" text="'.$text.'"/>'
                .'<label posn="'.($x - 0.35).' '.$y.'" sizen="5.5 2" halign="left" valign="center" text="'.htmlspecialchars($entry['nickname']).'"/>';
        }

        return $xml.'</frame></manialink>';
    }
}

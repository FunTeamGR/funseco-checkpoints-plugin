<?php
namespace Funseco\Checkpoints\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Funseco\Core\Testing\FakesServer;
use Funseco\Checkpoints\Tests\TestCase;
use Funseco\Checkpoints\BestCheckpoints;
use Funseco\Checkpoints\CheckpointTracker;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Values\Checkpoint;
use Funseco\Core\Servers\TmForever\Values\CheckpointCollection;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Chat\Facades\Chat;
use Funseco\Core\SharedKernel\Events\PlayerCheckpointPassed;
use Funseco\Core\SharedKernel\Events\PlayerFinished;
use Funseco\Core\SharedKernel\RecordBook;

class CheckpointsTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private Player $alice;
    private Player $bob;
    private Map $map;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        config(['sectors.announce_own' => false, 'sectors.announce_records' => false]);
        CurrentPlayers::clear();
        $this->alice = Player::factory()->create(['login' => 'alice', 'nickname' => '$f00Alice']);
        $this->bob = Player::factory()->create(['login' => 'bob', 'nickname' => 'Bob']);
        CurrentPlayers::add($this->alice);
        CurrentPlayers::add($this->bob);
        $this->map = Map::factory()->create(['cp_count' => 3]);
        CurrentMap::set($this->map);
        // alice holds record 2 (10000, 20000, 30000); record 1 is bob's (9000, 18000, 27000)
        $this->books([
            1 => [9000, 18000, 27000],
            2 => [10000, 20000, 30000],
        ], ['alice' => 2, 'bob' => 1]);
    }

    private function cps(int ...$scores): CheckpointCollection
    {
        return new CheckpointCollection(array_map(fn ($s, $i) => new Checkpoint($s, $i), $scores, array_keys($scores)));
    }

    private function books(array $byRank, array $rankOf): void
    {
        $cps = fn (array $scores) => $this->cps(...$scores);
        $this->app->instance(RecordBook::class, new class($byRank, $rankOf, $cps) implements RecordBook {
            public function __construct(private array $byRank, private array $rankOf, private \Closure $cps) {}
            public function ranksFor(Player $player): array { return []; }
            public function checkpointsFor(Player $player, Map $map): ?CheckpointCollection
            {
                $rank = $this->rankOf[$player->login] ?? null;
                return $rank ? ($this->cps)($this->byRank[$rank]) : null;
            }
            public function checkpointsOfRank(Map $map, int $rank): ?CheckpointCollection
            {
                return isset($this->byRank[$rank]) ? ($this->cps)($this->byRank[$rank]) : null;
            }
            public function countOn(Map $map): int { return count($this->byRank); }
            public function recordCounts(int $maxRank = PHP_INT_MAX): array { return []; }
            public function topRanks(array $mapIds, int $limit): array { return []; }
        });
    }

    /** Panel texts ("CP1:" label + diff) sent to $login, in order. */
    private function panels(string $login): array
    {
        $out = [];
        foreach ($this->sent('SendDisplayManialinkPageToLogin') as [$to, $xml]) {
            if ($to === $login && preg_match('/text="(CP[^"]*):".*text="\$o([^"]*)"/', $xml, $m)) {
                $out[] = $m[1].' '.$m[2];
            }
        }
        return $out;
    }

    private function cp(Player $player, int $index, int $time): void
    {
        event(new PlayerCheckpointPassed($player, $index, $time, 0));
    }

    public function test_default_compares_with_own_record()
    {
        app(CheckpointTracker::class)->prepare($this->alice);

        $this->cp($this->alice, 0, 9880);
        $this->cp($this->alice, 1, 20250);
        $this->cp($this->alice, 2, 30000);

        $this->assertSame(['CP1 $00f-0.12', 'CP2 $f00+0.25', 'CPF $00f0.00'], $this->panels('alice'));
    }

    public function test_without_own_record_compares_with_the_last_record()
    {
        $carol = Player::factory()->create(['login' => 'carol']);
        CurrentPlayers::add($carol);
        app(CheckpointTracker::class)->prepare($carol);

        $this->cp($carol, 0, 10500);

        $this->assertSame(['CP1 $f00+0.50'], $this->panels('carol'));
    }

    public function test_only_top_records_count_as_local_records()
    {
        config(['checkpoints.max_records' => 1]);
        $carol = Player::factory()->create(['login' => 'carol']);
        CurrentPlayers::add($carol);
        app(CheckpointTracker::class)->prepare($carol);

        $this->cp($carol, 0, 10500);
        Chat::resolve('/cps 2', 'alice');

        $this->assertSame(['CP1 $f00+1.50'], $this->panels('carol'), 'record 1 is the last that counts, not the slowest stored');
        $this->assertSame([format_colors('{#server}> {#error}No such Local record {#highlite}$i 2')], $this->messages());
    }

    public function test_cps_command_selects_a_record_or_turns_it_off()
    {
        Chat::resolve('/cps 1', 'alice');
        $this->cp($this->alice, 0, 9100);
        Chat::resolve('/cps off', 'alice');
        $this->cp($this->alice, 1, 18000);
        Chat::resolve('/cps 9', 'alice');

        $this->assertSame([
            format_colors('{#server}> Local checkpoints tracking record: {#highlite}1'),
            format_colors('{#server}> Local checkpoints tracking: {#highlite}OFF'),
            format_colors('{#server}> {#error}No such Local record {#highlite}$i 9'),
        ], $this->messages());
        $this->assertSame(['CP0 $00f -.--', 'CP1 $f00+0.10'], $this->panels('alice'), 'placeholder on /cps, then diff vs record 1');
        $this->assertSame(-1, $this->alice->fresh()->extra->cps, 'stored like XAseco players_extra.cps');
    }

    public function test_faster_finish_becomes_the_reference()
    {
        app(CheckpointTracker::class)->prepare($this->alice);

        event(new PlayerFinished($this->alice, $this->map, 29000, $this->cps(9500, 19000, 29000)));
        $this->cp($this->alice, 0, 9600);

        $this->assertSame(['CP1 $f00+0.10'], $this->panels('alice'));
    }

    public function test_cpsspec_is_disabled_like_the_owners_server()
    {
        Chat::resolve('/cpsspec', 'alice');

        $this->assertSame([format_colors('{#server}> {#highlite}/cpsspec{#server} is not currently enabled on this server.')], $this->messages());
    }

    public function test_cpsspec_toggles_only_when_the_config_flag_is_on()
    {
        config(['checkpoints.spectator_tracking' => true]);
        Chat::resolve('/cpsspec', 'alice');
        $this->assertSame([format_colors('{#server}> Spectator checkpoints tracking: {#highlite}ON')], $this->messages());
        $this->assertTrue(\Funseco\Checkpoints\Commands\CpsSpecCommand::enabledFor($this->alice));
    }

    public function test_best_checkpoint_times_widget_keeps_the_best_time_per_checkpoint()
    {
        $this->cp($this->alice, 0, 10000);
        $this->cp($this->bob, 0, 9500);
        $this->cp($this->alice, 1, 20000);
        $this->cp($this->bob, 2, 27000);   // finish checkpoint: not shown

        $best = app(BestCheckpoints::class)->all();
        $this->assertSame([[9500, 'Bob'], [20000, '$f00Alice']], array_map(fn ($b) => [$b['time'], $b['nickname']], $best));

        $xml = app(BestCheckpoints::class)->xml();
        $this->assertStringContainsString('text="$FFF1."', $xml);
        $this->assertStringContainsString('text="$FFF0:09.50"', $xml);
        $this->assertStringContainsString('text="$FFFBob"', $xml);
        $this->assertStringContainsString('text="$FFF2."', $xml);
        $this->assertStringContainsString('text="$FFF0:20.00"', $xml);
        $this->assertStringNotContainsString('text="$FFF3."', $xml, 'the finish is not listed');
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_checkpoints_nobody_passed_yet_are_listed_empty()
    {
        $xml = app(BestCheckpoints::class)->xml();

        $this->assertStringContainsString('text="$FFF0:00.00"', $xml);
        $this->assertStringContainsString('text="$FFF---"', $xml);
        $this->assertStringContainsString('text="$FFF2."', $xml);
    }

    public function test_second_block_starts_after_nine_checkpoints_and_at_most_eighteen_are_shown()
    {
        $this->map->cp_count = 30;
        $xml = app(BestCheckpoints::class)->xml();

        $this->assertStringContainsString('text="$FFF18."', $xml);
        $this->assertStringNotContainsString('text="$FFF19."', $xml);
        $this->assertStringContainsString('<label posn="19.85 -1.25 0.14"', $xml, 'checkpoint 10 starts the second block');
    }

    public function test_the_last_beaten_checkpoint_blinks()
    {
        $this->cp($this->alice, 1, 20000);

        $this->assertStringContainsString('<format style="TextTitle2Blink"/>', app(BestCheckpoints::class)->xml(1));
        $this->assertStringNotContainsString('TextTitle2Blink', app(BestCheckpoints::class)->xml());
    }

    public function test_widget_sits_at_its_configured_spot_above_the_donation_panel()
    {
        $placement = app(\Funseco\Core\SharedKernel\UI\WidgetRegistry::class)->placement('best_checkpoints', 'time_attack');

        $this->assertSame([10.4, -33.5], [$placement->x, $placement->y]);
        $this->assertStringContainsString('<frame posn="10.9 -33 3">', app(BestCheckpoints::class)->xml());
    }

    public function test_spectators_do_not_get_the_widget_and_the_end_of_the_race_clears_it()
    {
        \Funseco\Core\Services\Cache\CurrentSpectators::add($this->bob);
        $this->calls = [];

        app(BestCheckpoints::class)->showToAll();
        $logins = array_column($this->sent('SendDisplayManialinkPageToLogin'), 0);
        $this->assertSame(['alice'], $logins);

        $this->cp($this->alice, 0, 10000);
        $this->calls = [];
        app(BestCheckpoints::class)->ended();

        $this->assertSame([], app(BestCheckpoints::class)->all());
        $this->assertStringContainsString('<manialink id="'.\Funseco\Core\Services\ManiaLink\Facades\ManiaLink::getAction('best_checkpoints').'"></manialink>', implode('', array_column($this->sent('SendDisplayManialinkPage'), 0)));
    }

    public function test_reset_for_a_new_map_clears_the_times_on_screen()
    {
        $this->cp($this->alice, 0, 10000);
        $this->calls = [];

        app(BestCheckpoints::class)->reset();

        $sent = implode('', array_column($this->sent('SendDisplayManialinkPageToLogin'), 1));
        $this->assertStringContainsString('<manialink id="'.\Funseco\Core\Services\ManiaLink\Facades\ManiaLink::getAction('best_checkpoints').'"', $sent);
        $this->assertStringNotContainsString('0:10.00', $sent);
    }

    public function test_best_checkpoint_strip_sits_under_the_button_row_and_is_drawn_there()
    {
        $this->cp($this->alice, 0, 10000);
        $this->cp($this->alice, 1, 20000);
        $grid = app(\Funseco\Core\SharedKernel\UI\WidgetRegistry::class);

        $row = $grid->placement('dedi_cp_button');
        $strip = $grid->placement('best_checkpoint_strip');

        $this->assertSame(round($row->y - $row->height - 0.5, 4), $strip->y);
        $this->assertSame(2.25, $strip->height);
        $xml = \Funseco\Checkpoints\BestCheckpointStrip::xml(app(BestCheckpoints::class)->all());
        $this->assertStringContainsString('<frame posn="'.($strip->x + 5.75).' '.($strip->y - 1.125).' 0">', $xml);
        $this->assertStringContainsString('01. 0:10.00', $xml);
        $this->assertStringContainsString('02. 0:20.00', $xml);
    }

    public function test_strip_can_be_anchored_by_its_lower_edge_and_grow_upward()
    {
        config(['checkpoints.best.strip_bottom' => 39.5, 'checkpoints.best.per_line' => 1]);
        $this->cp($this->alice, 0, 10000);
        $this->cp($this->alice, 1, 20000);
        $strip = app(\Funseco\Core\SharedKernel\UI\WidgetRegistry::class)->placement('best_checkpoint_strip');

        $xml = \Funseco\Checkpoints\BestCheckpointStrip::xml(app(BestCheckpoints::class)->all());

        // two lines: the top of the strip is 2 x 2.25 above the lower edge; the frame sits half a cell below that
        $this->assertStringContainsString('<frame posn="'.($strip->x + 5.75).' '.(39.5 + 4.5 - 1.125).' 0">', $xml);
    }

    public function test_panel_and_strip_xml_is_well_formed()
    {
        $this->cp($this->alice, 0, 10000);
        $this->cp($this->alice, 1, 20000);
        $xml = app(BestCheckpoints::class)->xml(1).\Funseco\Checkpoints\BestCheckpointStrip::xml(app(BestCheckpoints::class)->all());

        libxml_use_internal_errors(true);
        $this->assertNotFalse(simplexml_load_string('<r>'.$xml.'</r>'), implode('; ', array_map(fn ($e) => trim($e->message), libxml_get_errors())));
    }

    public function test_panel_and_strip_can_be_switched_on_and_off_independently()
    {
        $manialink = fn (string $name) => \Funseco\Core\Services\ManiaLink\Facades\ManiaLink::getAction($name);
        $sentTo = fn () => implode('', array_column($this->sent('SendDisplayManialinkPageToLogin'), 1));
        $this->cp($this->alice, 0, 10000);

        config(['checkpoints.best.strip' => false]);
        $this->calls = [];
        app(BestCheckpoints::class)->showToAll();
        $this->assertStringContainsString('text="$FFF1."', $sentTo());                           // the panel's rows
        $this->assertStringContainsString('<manialink id="'.$manialink('best_checkpoint_strip').'"></manialink>', $sentTo());

        config(['checkpoints.best.strip' => true, 'checkpoints.best.panel' => false]);
        $this->calls = [];
        app(BestCheckpoints::class)->showToAll();
        $this->assertStringContainsString('<manialink id="'.$manialink('best_checkpoints').'"></manialink>', $sentTo());
        $this->assertStringContainsString('01. 0:10.00', $sentTo());                            // the strip
    }

    public function test_bestcps_toggles_the_widget_for_a_player()
    {
        Chat::resolve('/bestcps', 'alice');
        $this->cp($this->bob, 0, 9500);

        $this->assertContains('Bestcps widget disabled', $this->messages());
        $shownTo = array_column($this->sent('SendDisplayManialinkPageToLogin'), 0);
        $this->assertContains('bob', $shownTo);
        $this->assertNotContains('alice', array_slice($shownTo, 2), 'alice only got the two hide pages (panel and strip)');
    }
}

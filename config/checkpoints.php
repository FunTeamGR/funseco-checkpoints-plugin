<?php

return [
    // Checkpoint difference panel (/cps). Off disables it for everyone.
    'enabled' => true,
    // Players who never set /cps compare with their own record (or the last one).
    'auto_enable' => true,
    // /cpsspec (spectated player's checkpoints): off = answers "not enabled" (XAseco enable_cpsspec = false);
    // on = the command toggles the player's own setting.
    'spectator_tracking' => false,
    // Only the top records count as local records (rasp.settings.php $maxrecs): the "last
    // record" reference and /cps N go no further.
    'max_records' => 50,

    'best' => [
        // Best checkpoint times of the current map (/bestcps toggles them per player). `enabled` records
        // them; `panel` is the XAseco best_checkpoint_times panel above the donation bar, `strip` the
        // older bestcps strip under the top-left buttons (`per_line` cells per line). Either may be off.
        'enabled' => true,
        'panel' => true,
        'strip' => true,
        'per_line' => (int) env('BESTCPS_PER_LINE', 8),
        // y of the strip's lower edge: it grows upward from there (null = hangs from its layout position).
        'strip_bottom' => env('BESTCPS_STRIP_BOTTOM') !== null ? (float) env('BESTCPS_STRIP_BOTTOM') : null,
    ],

    'messages' => [
        'on'        => '{#server}> Local checkpoints tracking: {#highlite}ON {#server}(your own or the last record)',
        'off'       => '{#server}> Local checkpoints tracking: {#highlite}OFF',
        'record'    => '{#server}> Local checkpoints tracking record: {#highlite}{1}',
        'no_record' => '{#server}> {#error}No such Local record {#highlite}$i {1}',
        'disabled'  => '{#server}> {#error}Checkpoints tracking permanently disabled by server',
        'no_cpsspec' => '{#server}> {#highlite}/cpsspec{#server} is not currently enabled on this server.',
        'spec_on'   => '{#server}> Spectator checkpoints tracking: {#highlite}ON',
        'spec_off'  => '{#server}> Spectator checkpoints tracking: {#highlite}OFF',
        'best_on'   => 'Bestcps widget enabled',
        'best_off'  => 'Bestcps widget disabled',
    ],
];

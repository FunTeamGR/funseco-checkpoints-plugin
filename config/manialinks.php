<?php

/*
 * Widget defaults merged into config('manialinks'); config/manialinks.php of the app wins.
 * Checkpoint difference panel and best checkpoint times panel.
 * The merge is shallow (top-level keys only): an app override of one of these keys
 * replaces its whole subtree, so it must carry every gamemode, not just the changed one.
 */
return [
    'checkpoints_panel' => [
        // Checkpoint difference panel (XAseco display_cpspanel)
        'pos_x' => -7.9,
        'pos_y' => -38.1,
    ],
    'best_checkpoints' => [
        // Best checkpoint times panel (XAseco plugin.best_checkpoint_times): top-left corner of the panel,
        // above the donation bar. Move it per gamemode with pos_x / pos_y.
        'gamemode' => [
            'rounds' => ['pos_x' => 10.4, 'pos_y' => -33.5],
            'time_attack' => ['pos_x' => 10.4, 'pos_y' => -33.5],
            'team' => ['pos_x' => 10.4, 'pos_y' => -33.5],
            'laps' => ['pos_x' => 10.4, 'pos_y' => -33.5],
            'stunts' => ['pos_x' => 10.4, 'pos_y' => -33.5],
            'cup' => ['pos_x' => 10.4, 'pos_y' => -33.5],
        ],
    ],
];

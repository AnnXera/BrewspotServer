<?php

/**
 * Floor plan + reservations settings.
 *
 * The images for every asset live in the client (Nuxt public/floor-plan/); the
 * server only stores and validates the `asset_key`. Adding an asset means adding the
 * image on the client and its key here.
 */
return [

    'table_statuses' => ['available', 'occupied', 'reserved', 'cleaning'],

    'reservation_statuses' => ['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'],

    // Statuses that hold a table for their time window.
    'blocking_reservation_statuses' => ['pending', 'confirmed', 'seated'],

    // Allowed status changes: current => [next, ...].
    'reservation_transitions' => [
        'pending'   => ['confirmed', 'cancelled'],
        'confirmed' => ['seated', 'cancelled', 'no_show'],
        'seated'    => ['completed'],
        'completed' => [],
        'cancelled' => [],
        'no_show'   => [],
    ],

    'limits' => [
        'canvas_min'         => 100,
        'canvas_max'         => 10000,
        'max_tables'         => 200,
        'max_elements'       => 500,
        'max_table_capacity' => 50,
        'max_boundary_points' => 200,
        'element_min_size'   => 1,
    ],

    // Categories the client draws itself (a plain rectangle) instead of loading an
    // image: no asset_key, but a width and height are required. Walls are drawn.
    'drawn_categories' => ['wall'],

    'reservations' => [
        // The end time is chosen per reservation; the default only pre-fills the form.
        'default_duration_minutes' => 90,
        'min_duration_minutes'     => 15,
        'max_duration_minutes'     => 480,
        'max_days_ahead'           => 180,
        // Bookings made for "right now" are still accepted this many minutes late.
        'past_grace_minutes'       => 5,
    ],

    // asset_key => category (+ default capacity for tables).
    'assets' => [
        'table_round_2'   => ['category' => 'table', 'capacity' => 2],
        'table_round_4'   => ['category' => 'table', 'capacity' => 4],
        'table_square_2'  => ['category' => 'table', 'capacity' => 2],
        'table_square_4'  => ['category' => 'table', 'capacity' => 4],
        'table_rect_6'    => ['category' => 'table', 'capacity' => 6],
        'table_rect_8'    => ['category' => 'table', 'capacity' => 8],
        'table_bar_high'  => ['category' => 'table', 'capacity' => 2],

        'door_single'     => ['category' => 'door'],
        'door_double'     => ['category' => 'door'],

        'window_single'   => ['category' => 'window'],
        'window_wide'     => ['category' => 'window'],

        'counter_straight' => ['category' => 'counter'],
        'counter_corner'   => ['category' => 'counter'],
        'counter_pos'      => ['category' => 'counter'],

        'plant_small'     => ['category' => 'plant'],
        'plant_large'     => ['category' => 'plant'],

        'rug_round'       => ['category' => 'decor'],
        'rug_rect'        => ['category' => 'decor'],
    ],
];

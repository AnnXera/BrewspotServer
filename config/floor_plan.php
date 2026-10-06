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
    // image: no asset_key, but a width and height are required. Walls and counters
    // (the labeled "Counter" / "Cashier" bars) are drawn.
    'drawn_categories' => ['wall', 'counter'],

    'reservations' => [
        // The end time is chosen per reservation; the default only pre-fills the form.
        'default_duration_minutes' => 90,
        'min_duration_minutes'     => 15,
        'max_duration_minutes'     => 480,
        'max_days_ahead'           => 180,
        // Bookings made for "right now" are still accepted this many minutes late.
        'past_grace_minutes'       => 5,
    ],

    // asset_key => category (+ default capacity for tables). Keys match the client's
    // app/assets/floor-plan/<asset_key>.svg files; the number is the chair count.
    'assets' => [
        'table_sqr_1' => ['category' => 'table', 'capacity' => 1],
        'table_sqr_2' => ['category' => 'table', 'capacity' => 2],
        'table_sqr_3' => ['category' => 'table', 'capacity' => 3],
        'table_sqr_4' => ['category' => 'table', 'capacity' => 4],
        'table_rec_2' => ['category' => 'table', 'capacity' => 2],
        'table_rec_4' => ['category' => 'table', 'capacity' => 4],

        'door_sgl'    => ['category' => 'door'],
        'door_double' => ['category' => 'door'],
    ],
];

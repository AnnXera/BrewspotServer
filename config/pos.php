<?php

return [
    // Refuse register actions outside the cafe's opening hours (see EnsurePosOpen).
    'enforce_opening_hours' => env('POS_ENFORCE_OPENING_HOURS', true),
];
